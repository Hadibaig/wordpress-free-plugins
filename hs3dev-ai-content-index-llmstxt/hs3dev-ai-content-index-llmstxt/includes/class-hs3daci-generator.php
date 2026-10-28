<?php
/**
 * Builds the actual llms.txt and llms-full.txt content based on settings.
 *
 * Generation is batched: a queue of post IDs is built once and stored in a
 * transient, then processed in fixed-size chunks across multiple requests
 * so large sites (thousands of posts/products) don't hit PHP's
 * max_execution_time or memory limits in a single run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the capability required to view/manage this plugin's settings and
 * trigger generation. Defaults to 'manage_options' (Administrators only),
 * but can be relaxed to 'edit_others_posts' (Editors) or 'publish_posts'
 * (Authors) via the Role Capabilities setting, so site owners can delegate
 * this without granting full admin access.
 *
 * @return string
 */
function hs3daci_required_capability() {
	$settings = HS3DACI_Settings::get_settings();
	$cap      = isset( $settings['allowed_role'] ) ? $settings['allowed_role'] : 'manage_options';

	$allowed_caps = array( 'manage_options', 'edit_others_posts', 'publish_posts' );

	return in_array( $cap, $allowed_caps, true ) ? $cap : 'manage_options';
}

/**
 * Detects which supported SEO plugin is currently active, if any.
 *
 * Used to show the user which plugin's meta description will be pulled
 * from, and could be extended later if support for more plugins is added.
 *
 * @return string Plugin label, or empty string if none detected.
 */
function hs3daci_detect_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'Yoast SEO';
	}

	if ( class_exists( 'RankMath' ) ) {
		return 'RankMath';
	}

	if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
		return 'All in One SEO';
	}

	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'SEOPress';
	}

	return '';
}

class HS3DACI_Core {

	const CONTENT_OPTION    = 'hs3daci_generated_content';
	const PROGRESS_OPTION   = 'hs3daci_generation_progress';
	const STAGING_OPTION    = 'hs3daci_staging_full'; // Accumulates llms-full.txt content across batches.
	const BATCH_SIZE        = 50;  // Default: no HTTP fetches, fast in-process rendering.
	const BATCH_SIZE_COMPAT = 5;   // Compatibility mode: each item makes a live HTTP request.
	const ITEMS_PAGE_SIZE   = 50;  // Taxonomy terms stored per options row while a run is in progress.
	const META_OPTION       = 'hs3daci_run_meta'; // Tiny per-run counters (chunk count, item pages).

	/**
	 * Runs a full, non-batched generation in a single call.
	 *
	 * Kept for small sites, WP-CLI, and cron, where there is no AJAX loop
	 * driving batches from the browser. Internally just drains the queue
	 * started by start_generation() in one go.
	 *
	 * @return array
	 */
	public static function generate() {
		self::raise_resource_limits();

		if ( HS3DACI_Multilingual::is_active() ) {
			return self::generate_all_languages();
		}

		self::start_generation();

		do {
			$progress = self::process_batch();
		} while ( ! $progress['done'] );

		return self::get_generated();
	}

	/**
	 * Runs a full generation pass once per active language (WPML/Polylang),
	 * storing a separate llms.txt / llms-full.txt for each. Used by the
	 * synchronous (cron/CLI) path; the AJAX batch path handles multilingual
	 * the same way one language at a time via start_generation( $lang ).
	 *
	 * @return array Generated content for the default language (for backward-compatible callers).
	 */
	protected static function generate_all_languages() {
		$languages = HS3DACI_Multilingual::get_languages();
		$default   = HS3DACI_Multilingual::get_default_language();

		if ( empty( $languages ) ) {
			// Plugin detected but no languages reported; fall back to single-language behavior.
			self::start_generation();
			do {
				$progress = self::process_batch();
			} while ( ! $progress['done'] );

			return self::get_generated();
		}

		foreach ( $languages as $lang_code ) {
			self::start_generation( $lang_code );
			do {
				$progress = self::process_batch( $lang_code );
			} while ( ! $progress['done'] );
		}

		return self::get_generated( $default );
	}

	/**
	 * Attempts to raise PHP's execution time and memory limits for the
	 * duration of a full synchronous generation run (used by cron and
	 * WP-CLI). Wrapped in function_exists/ini_get checks since some hosts
	 * disable these and we don't want a fatal error on locked-down setups.
	 */
	protected static function raise_resource_limits() {
		if ( function_exists( 'set_time_limit' ) ) {
			// Best-effort only: many hosts disable this, hence the function_exists
			// check and @ suppression rather than relying on it being available.
			@set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$current_limit = ini_get( 'memory_limit' );
		if ( $current_limit && wp_convert_hr_to_bytes( $current_limit ) < wp_convert_hr_to_bytes( '256M' ) ) {
			// Best-effort only: some hosts lock memory_limit and silently ignore this.
			@ini_set( 'memory_limit', '256M' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}

	/**
	 * Begins a new generation run: builds the full queue of post IDs and
	 * resets progress/partial-content storage. Cheap operation, safe to run
	 * synchronously even on large sites since it only queries IDs.
	 *
	 * @return array Initial progress state.
	 */
	public static function start_generation( $lang_code = '' ) {
		if ( $lang_code ) {
			HS3DACI_Multilingual::switch_to_language( $lang_code );
		}

		$settings = HS3DACI_Settings::get_settings();

		// Ensure WooCommerce's rewrite rules are fully registered before
		// building the queue. Without this, wc_get_product()->get_permalink()
		// can return the shop archive URL instead of individual product URLs
		// on sites that haven't recently flushed their rewrite rules.
		if ( function_exists( 'WC' ) ) {
			WC()->query->init_query_vars();
			WC()->query->add_endpoints();
		}

		$post_queue     = self::build_queue( $settings );
		$taxonomy_items = self::build_taxonomy_items( $settings );

		if ( $lang_code ) {
			HS3DACI_Multilingual::restore_language();
		}

		// Lean transient design: store posts and terms as two separate flat integer
		// arrays rather than one array of {type, id} objects. On a 4,000-item site
		// the tagged-object approach serialises to ~200KB; two flat integer arrays
		// serialise to ~50KB — a 75% reduction with identical semantics.
		// Posts are always fully drained first, then terms (deterministic order).
		$progress = array(
			'post_queue' => $post_queue,    // Flat array of integer post IDs.
			'term_queue' => array_keys( $taxonomy_items ), // Flat array of integer term indexes.
			'lang_code'  => $lang_code,
			'total'      => count( $post_queue ) + count( $taxonomy_items ),
			'processed'  => 0,
			'done'       => false,
		);

		// Remove anything left over from a previous (or abandoned) run so this
		// run starts fresh.
		self::cleanup_run( $lang_code );

		// Store the term list once, split into small fixed-size pages, so each
		// batch only ever reads the one page it needs instead of the whole list.
		$item_pages = array_chunk( array_values( $taxonomy_items ), self::ITEMS_PAGE_SIZE );
		foreach ( $item_pages as $page => $page_items ) {
			update_option( self::items_page_key( $lang_code, $page ), $page_items, false );
		}

		update_option(
			self::meta_key( $lang_code ),
			array(
				'chunks'     => 0,
				'item_pages' => count( $item_pages ),
			),
			false
		);

		// TRANSIENT DESIGN NOTE: This transient stores ONLY the lean queue
		// ({type, id} pairs — small integers and short strings), counters, and
		// language code. No rendered content, no generated text, and no output
		// accumulation of any kind is stored in this transient. All generated output
		// (post-type sections, taxonomy sections, llms-full.txt content) streams to
		// separate non-autoloaded wp_options rows per batch. The transient therefore
		// stays small and flat regardless of how many posts or categories the site has.
		// See process_batch() for the per-batch output streaming pattern.
		set_transient( self::progress_key( $lang_code ), $progress, HOUR_IN_SECONDS );

		return array(
			'total'     => $progress['total'],
			'processed' => 0,
			'done'      => 0 === $progress['total'],
		);
	}

	/**
	 * Returns the transient key used to store in-progress generation state,
	 * namespaced by language when multilingual support is in use.
	 *
	 * @param string $lang_code Language code, or empty for the default/single-language run.
	 *
	 * @return string
	 */
	protected static function progress_key( $lang_code = '' ) {
		return $lang_code ? self::PROGRESS_OPTION . '_' . $lang_code : self::PROGRESS_OPTION;
	}

	/**
	 * Returns the option key used to stream llms-full.txt content across batches.
	 * Kept separate from the progress transient so the transient stays small
	 * even when processing thousands of large product/page records.
	 *
	 * @param string $lang_code Language code, or empty for the default run.
	 *
	 * @return string
	 */
	protected static function staging_key( $lang_code = '' ) {
		return $lang_code ? self::STAGING_OPTION . '_' . $lang_code : self::STAGING_OPTION;
	}

	/**
	 * Returns the option key used to store finished content, namespaced by
	 * language when multilingual support is in use.
	 *
	 * @param string $lang_code Language code, or empty for the default/single-language run.
	 *
	 * @return string
	 */
	protected static function content_key( $lang_code = '' ) {
		return $lang_code ? self::CONTENT_OPTION . '_' . $lang_code : self::CONTENT_OPTION;
	}

	/**
	 * Option key for the incrementally-built grouped_index (post-type lines).
	 * Written per-batch as an option, not stored in the transient, to keep the
	 * transient small and flat regardless of how many posts are processed.
	 */
	protected static function grouped_key( $lang_code = '' ) {
		return self::CONTENT_OPTION . '_grouped' . ( $lang_code ? '_' . $lang_code : '' );
	}

	/**
	 * Option key for the incrementally-built taxonomy_index (term lines).
	 * Same pattern as grouped_key.
	 */
	protected static function taxonomy_key( $lang_code = '' ) {
		return self::CONTENT_OPTION . '_taxonomy' . ( $lang_code ? '_' . $lang_code : '' );
	}

	/**
	 * Option key for the small per-run counters row.
	 *
	 * @param string $lang_code Language code, or empty for the default run.
	 *
	 * @return string
	 */
	protected static function meta_key( $lang_code = '' ) {
		return self::META_OPTION . ( $lang_code ? '_' . $lang_code : '' );
	}

	/**
	 * Option key for one append-only output chunk. Each batch writes exactly
	 * one chunk containing only that batch's output, so no batch ever has to
	 * read back or rewrite what earlier batches produced.
	 *
	 * @param string $lang_code Language code, or empty for the default run.
	 * @param int    $chunk     Zero-based chunk number.
	 *
	 * @return string
	 */
	protected static function chunk_key( $lang_code, $chunk ) {
		return self::STAGING_OPTION . '_chunk' . ( $lang_code ? '_' . $lang_code : '' ) . '_' . (int) $chunk;
	}

	/**
	 * Option key for one page of the taxonomy term list.
	 *
	 * @param string $lang_code Language code, or empty for the default run.
	 * @param int    $page      Zero-based page number.
	 *
	 * @return string
	 */
	protected static function items_page_key( $lang_code, $page ) {
		return self::taxonomy_key( $lang_code ) . '_items_' . (int) $page;
	}

	/**
	 * Returns a single term item from the paged term list. Only the page that
	 * contains the requested index is loaded, and only the most recently used
	 * page is kept in memory.
	 *
	 * @param string $lang_code  Language code, or empty for the default run.
	 * @param int    $term_index Index into the original term list.
	 *
	 * @return array|null
	 */
	protected static function get_taxonomy_item( $lang_code, $term_index ) {
		static $cached_key  = '';
		static $cached_page = array();

		$page = (int) floor( $term_index / self::ITEMS_PAGE_SIZE );
		$key  = self::items_page_key( $lang_code, $page );

		if ( $key !== $cached_key ) {
			$data        = get_option( $key, array() );
			$cached_page = is_array( $data ) ? $data : array();
			$cached_key  = $key;
		}

		$offset = $term_index % self::ITEMS_PAGE_SIZE;

		return isset( $cached_page[ $offset ] ) ? $cached_page[ $offset ] : null;
	}

	/**
	 * Deletes every temporary option a generation run creates (output chunks,
	 * term pages, counters) plus the single-row options used by versions up to
	 * 0.6.7, so nothing is left behind after a finished, cancelled or
	 * restarted run.
	 *
	 * @param string $lang_code Language code, or empty for the default run.
	 */
	protected static function cleanup_run( $lang_code = '' ) {
		$meta = get_option( self::meta_key( $lang_code ), array() );

		$chunks = isset( $meta['chunks'] ) ? (int) $meta['chunks'] : 0;
		for ( $i = 0; $i < $chunks; $i++ ) {
			delete_option( self::chunk_key( $lang_code, $i ) );
		}

		$pages = isset( $meta['item_pages'] ) ? (int) $meta['item_pages'] : 0;
		for ( $i = 0; $i < $pages; $i++ ) {
			delete_option( self::items_page_key( $lang_code, $i ) );
		}

		delete_option( self::meta_key( $lang_code ) );

		// Single-row options used by earlier versions.
		delete_option( self::staging_key( $lang_code ) );
		delete_option( self::grouped_key( $lang_code ) );
		delete_option( self::taxonomy_key( $lang_code ) );
		delete_option( self::taxonomy_key( $lang_code ) . '_items' );
	}

	/**
	 * Processes one batch of posts and terms from the queue and appends that
	 * batch's output as a new chunk option. When the queue is empty, reads the
	 * chunks back once, finalizes both files into their permanent options,
	 * and clears the temporary options and progress transient.
	 *
	 * @return array Progress state: total, processed, done.
	 */
	public static function process_batch( $lang_code = '' ) {
		$progress = get_transient( self::progress_key( $lang_code ) );

		if ( false === $progress ) {
			return array(
				'total'     => 0,
				'processed' => 0,
				'done'      => true,
			);
		}

		if ( $lang_code ) {
			HS3DACI_Multilingual::switch_to_language( $lang_code );
		}

		$settings         = HS3DACI_Settings::get_settings();
		$batch_size       = ! empty( $settings['compatibility_mode'] ) ? self::BATCH_SIZE_COMPAT : self::BATCH_SIZE;

		// Drain posts first (post_queue), then terms (term_queue).
		// Using two flat integer arrays rather than one array of {type,id} objects
		// keeps the serialised transient small — ~50KB on a 4,000-item site vs
		// ~200KB with the previous tagged-object approach.
		$post_batch = array_splice( $progress['post_queue'], 0, $batch_size );
		$remaining  = $batch_size - count( $post_batch );
		$term_batch = ( $remaining > 0 && ! empty( $progress['term_queue'] ) )
			? array_splice( $progress['term_queue'], 0, $remaining )
			: array();

		// This batch's output only. Earlier batches' output is never loaded
		// here: each batch appends one new chunk row (see below) and the
		// chunks are read back exactly once, at finalisation.
		$batch_grouped = array();
		$batch_terms   = array();

		$batch_start      = microtime( true );
		$time_limit       = 20;
		$batch_full_lines = array();

		// Process post IDs.
		foreach ( $post_batch as $post_id ) {
			if ( ( microtime( true ) - $batch_start ) > $time_limit ) {
				// Put unprocessed post IDs back at the front.
				$remaining_posts        = array_slice( $post_batch, array_search( $post_id, $post_batch, true ) );
				$progress['post_queue'] = array_merge( $remaining_posts, $progress['post_queue'] );
				break;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				$progress['processed']++;
				continue;
			}

			$post_type = $post->post_type;
			if ( ! isset( $batch_grouped[ $post_type ] ) ) {
				$batch_grouped[ $post_type ] = array();
			}
			$batch_grouped[ $post_type ][] = self::build_llms_txt_line( $post, $settings );
			$batch_full_lines[]            = self::build_full_txt_block( $post );
			$progress['processed']++;
		}

		// Process term indexes.
		foreach ( $term_batch as $term_index ) {
			if ( ( microtime( true ) - $batch_start ) > $time_limit ) {
				$remaining_terms        = array_slice( $term_batch, array_search( $term_index, $term_batch, true ) );
				$progress['term_queue'] = array_merge( $remaining_terms, $progress['term_queue'] );
				break;
			}

			$term_item = self::get_taxonomy_item( $lang_code, (int) $term_index );
			if ( $term_item ) {
				$taxonomy = $term_item['taxonomy'];
				if ( ! isset( $batch_terms[ $taxonomy ] ) ) {
					$batch_terms[ $taxonomy ] = array();
				}
				$batch_terms[ $taxonomy ][] = '- [' . $term_item['name'] . '](' . $term_item['url'] . ')';
			}
			$progress['processed']++;
		}

		// Append this batch's output as ONE new, small, non-autoloaded row.
		// Nothing already stored is read or rewritten, so total database I/O
		// grows linearly with the amount of content instead of quadratically.
		if ( ! empty( $batch_full_lines ) || ! empty( $batch_grouped ) || ! empty( $batch_terms ) ) {
			$meta  = get_option( self::meta_key( $lang_code ), array() );
			$chunk = isset( $meta['chunks'] ) ? (int) $meta['chunks'] : 0;

			update_option(
				self::chunk_key( $lang_code, $chunk ),
				array(
					'full'    => implode( "\n", $batch_full_lines ),
					'grouped' => $batch_grouped,
					'terms'   => $batch_terms,
				),
				false
			);

			$meta['chunks'] = $chunk + 1;
			update_option( self::meta_key( $lang_code ), $meta, false );
		}

		$done             = empty( $progress['post_queue'] ) && empty( $progress['term_queue'] );
		$progress['done'] = $done;

		if ( $done ) {
			// Read the chunks back once, in order, and assemble the final files.
			$meta   = get_option( self::meta_key( $lang_code ), array() );
			$chunks = isset( $meta['chunks'] ) ? (int) $meta['chunks'] : 0;

			$grouped_index  = array();
			$taxonomy_index = array();
			$full_parts     = array();

			for ( $i = 0; $i < $chunks; $i++ ) {
				$chunk_data = get_option( self::chunk_key( $lang_code, $i ), array() );
				if ( ! is_array( $chunk_data ) ) {
					continue;
				}

				if ( ! empty( $chunk_data['full'] ) ) {
					$full_parts[] = $chunk_data['full'];
				}

				if ( ! empty( $chunk_data['grouped'] ) ) {
					foreach ( $chunk_data['grouped'] as $post_type => $type_lines ) {
						if ( ! isset( $grouped_index[ $post_type ] ) ) {
							$grouped_index[ $post_type ] = array();
						}
						$grouped_index[ $post_type ] = array_merge( $grouped_index[ $post_type ], $type_lines );
					}
				}

				if ( ! empty( $chunk_data['terms'] ) ) {
					foreach ( $chunk_data['terms'] as $taxonomy => $term_lines ) {
						if ( ! isset( $taxonomy_index[ $taxonomy ] ) ) {
							$taxonomy_index[ $taxonomy ] = array();
						}
						$taxonomy_index[ $taxonomy ] = array_merge( $taxonomy_index[ $taxonomy ], $term_lines );
					}
				}
			}

			$site_name       = ! empty( $settings['company_name'] ) ? $settings['company_name'] : get_bloginfo( 'name' );
			$full_txt_header = '# ' . $site_name . ' - Full Content' . "\n\n";
			$full_txt_body   = implode( "\n", $full_parts );

			$llms_txt     = self::assemble_llms_txt( $settings, $grouped_index, $taxonomy_index );
			$llms_full    = $full_txt_header . ltrim( $full_txt_body );
			$generated_at = current_time( 'mysql' );

			update_option( self::content_key( $lang_code ) . '_llms_txt', $llms_txt, false );
			update_option( self::content_key( $lang_code ) . '_llms_full', $llms_full, false );

			$meta = array(
				'llms_txt'      => true,
				'llms_full_txt' => true,
				'generated_at'  => $generated_at,
			);
			update_option( self::content_key( $lang_code ), $meta, false );

			// Clean up all temporary run options.
			self::cleanup_run( $lang_code );
			delete_transient( self::progress_key( $lang_code ) );
		} else {
			// Update the transient with the trimmed queues and new counters only.
			// post_queue and term_queue are flat integer arrays — no accumulated
			// output data, no {type,id} object wrappers. On a 4,000-item site
			// this serialises to ~50KB vs ~200KB with the old tagged-object approach.
			set_transient( self::progress_key( $lang_code ), $progress, HOUR_IN_SECONDS );
		}

		if ( $lang_code ) {
			HS3DACI_Multilingual::restore_language();
		}

		return array(
			'total'     => $progress['total'],
			'processed' => $progress['processed'],
			'done'      => $done,
		);
	}

	/**
	 * Cancels any generation run currently in progress.
	 */
	public static function cancel_generation( $lang_code = '' ) {
		delete_transient( self::progress_key( $lang_code ) );
		self::cleanup_run( $lang_code );
	}

	/**
	 * Returns the last generated content (or empty placeholders).
	 *
	 * @return array
	 */
	public static function get_generated( $lang_code = '' ) {
		$defaults = array(
			'llms_txt'      => '',
			'llms_full_txt' => '',
			'generated_at'  => '',
		);

		// Try the new split-option format first (post v0.6.3).
		$meta = get_option( self::content_key( $lang_code ), array() );
		if ( ! empty( $meta['generated_at'] ) ) {
			return array(
				'llms_txt'      => get_option( self::content_key( $lang_code ) . '_llms_txt', '' ),
				'llms_full_txt' => get_option( self::content_key( $lang_code ) . '_llms_full', '' ),
				'generated_at'  => $meta['generated_at'],
			);
		}

		// Backward compatibility: older versions stored everything in one option.
		if ( ! empty( $meta['llms_txt'] ) ) {
			return wp_parse_args( $meta, $defaults );
		}

		return $defaults;
	}

	/**
	 * Builds the flat list of post IDs to process, based on enabled post
	 * types, per-item exclusions, and excluded URLs. This is the only part
	 * of generation that runs as a single (cheap, ID-only) query per post
	 * type; the expensive per-post content rendering happens in batches.
	 *
	 * @param array $settings Plugin settings.
	 *
	 * @return int[]
	 */
	protected static function build_queue( $settings ) {
		$ids = array();

		if ( empty( $settings['post_types'] ) || ! is_array( $settings['post_types'] ) ) {
			return $ids;
		}

		$exclude_urls = self::parse_url_list( $settings['exclude_urls'] ?? '' );

		foreach ( $settings['post_types'] as $post_type => $config ) {

			if ( empty( $config['enabled'] ) || ! post_type_exists( $post_type ) ) {
				continue;
			}

			$excluded_ids = isset( $config['excluded_ids'] ) ? array_map( 'absint', (array) $config['excluded_ids'] ) : array();

			$query_args = array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			);

			if ( ! empty( $excluded_ids ) ) {
				// post__not_in is intentional here: per-item exclusion (letting
				// users uncheck individual posts/pages/products while keeping
				// the rest of that post type included) is a core feature of
				// this plugin, and there's no equivalent non-exclusionary
				// WP_Query parameter that achieves the same result. This runs
				// against a single site's own content, not VIP-scale datasets.
				$query_args['post__not_in'] = $excluded_ids; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}

			$type_ids = get_posts( $query_args );

			if ( ! empty( $exclude_urls ) ) {
				$current_post_type = $post_type; // capture for closure.
				$type_ids          = array_filter(
					$type_ids,
					function ( $id ) use ( $exclude_urls, $current_post_type ) {
						if ( 'product' === $current_post_type && function_exists( 'wc_get_product' ) ) {
							$product = wc_get_product( $id );
							$url     = $product ? $product->get_permalink() : get_permalink( $id );
						} else {
							$url = get_permalink( $id );
						}
						return ! in_array( $url, $exclude_urls, true );
					}
				);
			}

			$ids = array_merge( $ids, array_values( $type_ids ) );
		}

		return $ids;
	}

	/**
	 * Collects all taxonomy term URLs that should be included,
	 * based on the enabled taxonomies setting. Returns an array of
	 * simple objects (id, name, url, taxonomy) since terms aren't WP_Posts.
	 *
	 * @param array $settings Plugin settings.
	 *
	 * @return array[]
	 */
	protected static function build_taxonomy_items( $settings ) {
		$items = array();

		if ( empty( $settings['taxonomies'] ) || ! is_array( $settings['taxonomies'] ) ) {
			return $items;
		}

		$exclude_urls = self::parse_url_list( $settings['exclude_urls'] ?? '' );

		foreach ( $settings['taxonomies'] as $taxonomy => $enabled ) {
			if ( ! $enabled || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'all',
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$url = get_term_link( $term );

				// get_term_link() returns WP_Error when rewrite rules aren't
				// flushed (common on WooCommerce sites after permalink changes).
				// Build a fallback URL so taxonomy terms still get included.
				if ( is_wp_error( $url ) ) {
					$tax_obj = get_taxonomy( $taxonomy );
					if ( $tax_obj && ! empty( $tax_obj->rewrite['slug'] ) ) {
						$url = home_url( '/' . $tax_obj->rewrite['slug'] . '/' . $term->slug . '/' );
					} else {
						$url = home_url( '/?taxonomy=' . $taxonomy . '&term=' . $term->slug );
					}
				}

				if ( in_array( $url, $exclude_urls, true ) ) {
					continue;
				}
				$items[] = array(
					'id'       => $term->term_id,
					'name'     => $term->name,
					'url'      => $url,
					'taxonomy' => $taxonomy,
				);
			}
		}

		return $items;
	}

	/**
	 * Returns the correct permalink for any post, with special handling for
	 * WooCommerce products.
	 *
	 * Standard get_permalink() for WooCommerce products is unreliable in
	 * admin AJAX context because it depends on WooCommerce's rewrite rules
	 * being fully initialised. When they aren't, it falls back to the shop
	 * archive URL (/products/) instead of the individual product URL.
	 * Using wc_get_product()->get_permalink() bypasses the rewrite system
	 * entirely and always returns the correct individual product URL.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected static function get_post_permalink( $post ) {
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				return $product->get_permalink();
			}
		}

		return get_permalink( $post );
	}

	/**
	 * Builds a single llms.txt list-item line for one post.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 *
	 * @return string
	 */
	protected static function build_llms_txt_line( $post, $settings ) {
		$title = get_the_title( $post );
		$url   = self::get_post_permalink( $post );

		// Skip items where URL resolution failed completely.
		if ( empty( $url ) || $url === home_url( '/' ) ) {
			return '';
		}

		$line = '- [' . $title . '](' . $url . ')';

		if ( ! empty( $settings['include_meta_description'] ) ) {
			$summary = self::get_short_summary( $post, $settings );
			if ( $summary ) {
				$line .= ': ' . $summary;
			}
		}

		return $line;
	}

	/**
	 * Builds the llms-full.txt block (heading, URL, full content) for one post.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected static function build_full_txt_block( $post ) {
		$lines   = array();
		$lines[] = '## ' . get_the_title( $post );
		$lines[] = 'URL: ' . self::get_post_permalink( $post );
		$lines[] = '';
		$lines[] = self::get_clean_content( $post );
		$lines[] = '';
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/**
	 * Assembles the final llms.txt from the header/settings plus the
	 * per-post-type grouped lines collected across all batches.
	 *
	 * @param array $settings      Plugin settings.
	 * @param array $grouped_index post_type => string[] of list-item lines.
	 *
	 * @return string
	 */
	protected static function assemble_llms_txt( $settings, $grouped_index, $taxonomy_index = array() ) {
		$lines = array();

		$site_name = ! empty( $settings['company_name'] ) ? $settings['company_name'] : get_bloginfo( 'name' );
		$lines[]   = '# ' . $site_name;

		$description = ! empty( $settings['company_description'] ) ? $settings['company_description'] : get_bloginfo( 'description' );
		if ( $description ) {
			$lines[] = '';
			$lines[] = '> ' . $description;
		}

		if ( ! empty( $settings['company_email'] ) ) {
			$lines[] = '';
			$lines[] = 'Contact: ' . $settings['company_email'];
		}

		if ( ! empty( $settings['ai_policy'] ) ) {
			$lines[] = '';
			$lines[] = '## AI Usage Policy';
			$lines[] = $settings['ai_policy'];
		}

		// Post type sections.
		foreach ( $grouped_index as $post_type => $type_lines ) {
			$obj   = get_post_type_object( $post_type );
			$label = $obj ? $obj->labels->name : $post_type;

			// Filter out any empty lines from failed permalink resolutions.
			$type_lines = array_values( array_filter( $type_lines ) );
			if ( empty( $type_lines ) ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . $label;
			$lines   = array_merge( $lines, $type_lines );
		}

		// Taxonomy sections (product categories, tags, custom taxonomies).
		foreach ( $taxonomy_index as $taxonomy => $term_lines ) {
			$tax_obj = get_taxonomy( $taxonomy );
			$label   = $tax_obj ? $tax_obj->labels->name : $taxonomy;

			$lines[] = '';
			$lines[] = '## ' . $label;
			$lines   = array_merge( $lines, $term_lines );
		}

		// Manually added URLs.
		$include_urls = self::parse_url_list( $settings['include_urls'] ?? '' );
		if ( ! empty( $include_urls ) ) {
			$lines[] = '';
			$lines[] = '## Additional Pages';
			foreach ( $include_urls as $url ) {
				$lines[] = '- ' . $url;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Assembles the final llms-full.txt from the header plus per-post blocks
	 * collected across all batches.
	 *
	 * @param array    $settings   Plugin settings.
	 * @param string[] $full_lines Pre-built per-post blocks.
	 *
	 * @return string
	 */
	protected static function assemble_llms_full_txt( $settings, $full_lines ) {
		$lines = array();

		$site_name = ! empty( $settings['company_name'] ) ? $settings['company_name'] : get_bloginfo( 'name' );
		$lines[]   = '# ' . $site_name . ' - Full Content';
		$lines[]   = '';

		foreach ( $full_lines as $block ) {
			$lines[] = $block;
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Returns a short plain-text summary for a post, using the best available source:
	 * 1. SEO plugin meta description (Yoast, RankMath, AIOSEO, SEOPress), if set.
	 * 2. The manually written post excerpt, if set.
	 * 3. An auto-trimmed snippet of the rendered content, as a last resort.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings (passed in to avoid re-fetching per post in a batch).
	 *
	 * @return string
	 */
	protected static function get_short_summary( $post, $settings = null ) {
		if ( null === $settings ) {
			$settings = HS3DACI_Settings::get_settings();
		}

		if ( ! empty( $settings['use_seo_meta'] ) ) {
			$meta_description = self::get_seo_meta_description( $post->ID );
			if ( $meta_description ) {
				return $meta_description;
			}
		}

		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		return wp_trim_words( self::get_clean_content( $post ), 25, '...' );
	}

	/**
	 * Checks common SEO plugin post-meta keys for a manually set meta description.
	 *
	 * Supports Yoast SEO, RankMath, All in One SEO, and SEOPress. Returns the
	 * first non-empty value found, or an empty string if none of these
	 * plugins are active or no description was set for this item.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	protected static function get_seo_meta_description( $post_id ) {
		$meta_keys = array(
			'_yoast_wpseo_metadesc',  // Yoast SEO.
			'rank_math_description',  // RankMath.
			'_aioseo_description',    // All in One SEO.
			'_seopress_titles_desc',  // SEOPress.
		);

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! empty( $value ) ) {
				return wp_strip_all_tags( $value );
			}
		}

		return '';
	}

	/**
	 * Returns the rendered post content as clean plain text.
	 *
	 * Two modes:
	 * - Default: runs content through the_content filter in-process, so
	 *   output from page builders (Elementor, WPBakery, UX Builder, etc.)
	 *   is rendered correctly rather than dumping raw shortcodes. Fast,
	 *   no extra HTTP requests, works for the vast majority of builders.
	 * - Compatibility mode: fetches the actual published URL with
	 *   wp_remote_get() and extracts the main content from the returned
	 *   HTML. Slower (one HTTP request per item) but guarantees an exact
	 *   match to what a visitor actually sees, for builders that don't
	 *   render correctly through the_content alone.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return string
	 */
	protected static function get_clean_content( $post ) {
		$settings = HS3DACI_Settings::get_settings();

		if ( ! empty( $settings['compatibility_mode'] ) ) {
			$fetched = self::get_content_via_live_fetch( $post );
			if ( false !== $fetched ) {
				return $fetched;
			}
			// Fall through to the fast path if the fetch failed for any reason.
		}

		$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\n\s*\n/', "\n\n", $content );

		return trim( $content );
	}

	/**
	 * Fetches a post's live published URL and extracts plain text from its
	 * main content area. Used by "compatibility mode" for page builders
	 * that don't render correctly through the_content in-process.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return string|false Clean text, or false if the fetch/parse failed.
	 */
	protected static function get_content_via_live_fetch( $post ) {
		$url = get_permalink( $post );

		if ( ! $url ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				// Short timeout: if the server is under load from the generation
				// process itself, self-referencing HTTP requests can queue
				// indefinitely. 8 seconds is enough for a normal page load;
				// if it takes longer the server is too busy and we fall through
				// to the faster in-process rendering path instead.
				'timeout'   => 8,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			return false;
		}

		return self::extract_main_content( $html );
	}

	/**
	 * Extracts plain text from the most likely "main content" container in
	 * a page's rendered HTML, falling back to the full body if no
	 * recognizable container is found. Uses DOMDocument rather than regex
	 * since regex-based HTML parsing is unreliable for nested markup.
	 *
	 * @param string $html Raw HTML of the page.
	 *
	 * @return string
	 */
	protected static function extract_main_content( $html ) {
		$doc = new DOMDocument();

		// Suppress warnings from malformed HTML, which is common and not fatal here.
		$internal_errors = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_use_internal_errors( $internal_errors );

		$xpath = new DOMXPath( $doc );

		// Strip elements that are never part of the actual article content.
		$strip_tags = array( 'script', 'style', 'nav', 'header', 'footer', 'noscript', 'form' );
		foreach ( $strip_tags as $tag ) {
			$nodes = $doc->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Try common main-content selectors, in priority order, before falling back to <body>.
		$candidates = array(
			"//main",
			"//*[@id='content']",
			"//*[@id='main']",
			"//*[contains(@class, 'entry-content')]",
			"//*[contains(@class, 'post-content')]",
			"//*[contains(@class, 'page-content')]",
			"//article",
		);

		$target = null;
		foreach ( $candidates as $query ) {
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length > 0 ) {
				$target = $nodes->item( 0 );
				break;
			}
		}

		if ( ! $target ) {
			$body_nodes = $doc->getElementsByTagName( 'body' );
			$target     = $body_nodes->length > 0 ? $body_nodes->item( 0 ) : null;
		}

		if ( ! $target ) {
			return '';
		}

		$text = $target->textContent;
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n\s*\n/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Splits a textarea value into an array of trimmed, non-empty lines.
	 *
	 * @param string $raw Raw textarea content.
	 *
	 * @return string[]
	 */
	protected static function parse_url_list( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}

		// Split on any line-ending style (\n, \r\n, \r) — textarea submissions
		// from real browsers, especially after copy-pasting from Windows/Excel/CMS
		// exports, commonly include \r\n or stray \r characters. Splitting on a
		// bare \n only (the previous behaviour) silently failed to separate URLs
		// in that case, causing the whole include_urls / exclude_urls list to be
		// dropped or merged into unusable single lines.
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) );
	}
}
