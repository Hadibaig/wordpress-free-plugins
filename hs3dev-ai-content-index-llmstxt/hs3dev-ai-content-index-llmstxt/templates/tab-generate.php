<?php
/**
 * "Generate" tab: trigger generation and preview the resulting files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hs3daci_generated     = HS3DACI_Core::get_generated();
$hs3daci_settings      = HS3DACI_Settings::get_settings();
$hs3daci_physical      = HS3DACI_Diagnostics::check_physical_files();
$hs3daci_ml_active     = HS3DACI_Multilingual::is_active();
$hs3daci_ml_plugin     = HS3DACI_Multilingual::detect_plugin();
$hs3daci_ml_languages  = $hs3daci_ml_active ? HS3DACI_Multilingual::get_languages() : array();
$hs3daci_ml_default    = $hs3daci_ml_active ? HS3DACI_Multilingual::get_default_language() : '';
?>

<?php if ( $hs3daci_physical['llms'] || $hs3daci_physical['llms_full'] ) : ?>
	<div class="notice notice-warning hs3daci-physical-warning">
		<p>
			<strong><?php esc_html_e( 'Heads up:', 'hs3dev-ai-content-index-llmstxt' ); ?></strong>
			<?php
			if ( $hs3daci_physical['llms'] && $hs3daci_physical['llms_full'] ) {
				esc_html_e( 'Physical llms.txt and llms-full.txt files were found in your site\'s root folder. These static files will override what this plugin generates. Delete or rename them via FTP/File Manager, then the live URLs will reflect this plugin\'s output.', 'hs3dev-ai-content-index-llmstxt' );
			} elseif ( $hs3daci_physical['llms'] ) {
				esc_html_e( 'A physical llms.txt file was found in your site\'s root folder. This static file will override what this plugin generates at /llms.txt. Delete or rename it via FTP/File Manager.', 'hs3dev-ai-content-index-llmstxt' );
			} else {
				esc_html_e( 'A physical llms-full.txt file was found in your site\'s root folder. This static file will override what this plugin generates at /llms-full.txt. Delete or rename it via FTP/File Manager.', 'hs3dev-ai-content-index-llmstxt' );
			}
			?>
		</p>
	</div>
<?php endif; ?>

<div class="hs3daci-card">
	<h2><?php esc_html_e( 'Generate LLMS Files', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
	<p><?php esc_html_e( 'Click the button below to build llms.txt and llms-full.txt based on the content and rules configured in the Settings tab.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

	<?php if ( $hs3daci_ml_active && ! empty( $hs3daci_ml_languages ) ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: detected multilingual plugin name */
				esc_html__( 'Multilingual site detected (%s). Choose a language to generate for, or generate all languages at once.', 'hs3dev-ai-content-index-llmstxt' ),
				esc_html( 'wpml' === $hs3daci_ml_plugin ? 'WPML' : 'Polylang' )
			);
			?>
		</p>

		<select id="hs3daci-language-select">
			<option value=""><?php esc_html_e( 'All languages', 'hs3dev-ai-content-index-llmstxt' ); ?></option>
			<?php foreach ( $hs3daci_ml_languages as $hs3daci_lang_code ) : ?>
				<option value="<?php echo esc_attr( $hs3daci_lang_code ); ?>" <?php selected( $hs3daci_lang_code, $hs3daci_ml_default ); ?>>
					<?php echo esc_html( strtoupper( $hs3daci_lang_code ) ); ?><?php echo ( $hs3daci_lang_code === $hs3daci_ml_default ) ? ' (' . esc_html__( 'default', 'hs3dev-ai-content-index-llmstxt' ) . ')' : ''; ?>
				</option>
			<?php endforeach; ?>
		</select>
		<br><br>
	<?php endif; ?>

	<button type="button" class="button button-primary" id="hs3daci-generate-btn">
		<?php esc_html_e( 'Generate Files', 'hs3dev-ai-content-index-llmstxt' ); ?>
	</button>

	<p class="hs3daci-meta-toggle">
		<label>
			<input type="checkbox" id="hs3daci-include-meta" <?php checked( ! empty( $hs3daci_settings['include_meta_description'] ) ); ?>>
			<?php esc_html_e( 'Include meta description in llms.txt', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</label>
		<br>
		<span class="description"><?php esc_html_e( 'Checked: each entry shows heading, URL, and a short description. Unchecked: each entry shows just the heading and URL.', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
	</p>

	<span id="hs3daci-generate-status" class="hs3daci-status"></span>

	<div id="hs3daci-progress-wrap" class="hs3daci-progress-wrap" style="display:none;">
		<div class="hs3daci-progress-track">
			<div id="hs3daci-progress-bar" class="hs3daci-progress-bar"></div>
		</div>
		<span id="hs3daci-progress-text" class="hs3daci-progress-text"></span>
	</div>

	<p class="hs3daci-last-generated" id="hs3daci-last-generated">
		<?php if ( ! empty( $hs3daci_generated['generated_at'] ) ) : ?>
			<?php
			printf(
				/* translators: %s: date and time of last generation */
				esc_html__( 'Last generated: %s', 'hs3dev-ai-content-index-llmstxt' ),
				esc_html( $hs3daci_generated['generated_at'] )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Files have not been generated yet.', 'hs3dev-ai-content-index-llmstxt' ); ?>
		<?php endif; ?>
	</p>
</div>

<div class="hs3daci-card-row">
	<div class="hs3daci-card hs3daci-file-card">
		<h3>llms.txt</h3>
		<p class="hs3daci-file-url"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></p>
		<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" class="button">
			<?php esc_html_e( 'Open', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</a>
		<button type="button" class="button button-secondary hs3daci-view-btn" data-target="llms_txt">
			<?php esc_html_e( 'View', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</button>
		<button type="button" class="button button-secondary hs3daci-verify-btn" data-file-type="llms">
			<?php esc_html_e( 'Verify Live File', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</button>
		<div class="hs3daci-verify-result" data-file-type="llms"></div>
	</div>

	<div class="hs3daci-card hs3daci-file-card">
		<h3>llms-full.txt</h3>
		<p class="hs3daci-file-url"><?php echo esc_html( home_url( '/llms-full.txt' ) ); ?></p>
		<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank" class="button">
			<?php esc_html_e( 'Open', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</a>
		<button type="button" class="button button-secondary hs3daci-view-btn" data-target="llms_full_txt">
			<?php esc_html_e( 'View', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</button>
		<button type="button" class="button button-secondary hs3daci-verify-btn" data-file-type="llms-full">
			<?php esc_html_e( 'Verify Live File', 'hs3dev-ai-content-index-llmstxt' ); ?>
		</button>
		<div class="hs3daci-verify-result" data-file-type="llms-full"></div>
	</div>
</div>

<div id="hs3daci-modal" class="hs3daci-modal" style="display:none;">
	<div class="hs3daci-modal-content">
		<button type="button" class="hs3daci-modal-close" aria-label="<?php esc_attr_e( 'Close', 'hs3dev-ai-content-index-llmstxt' ); ?>">&times;</button>
		<h3 id="hs3daci-modal-title"></h3>
		<textarea id="hs3daci-modal-body" readonly></textarea>
	</div>
</div>

<script type="application/json" id="hs3daci-generated-data"><?php echo wp_json_encode( $hs3daci_generated ); ?></script>

<?php
// ── Diagnostic: queue preview ─────────────────────────────────────────────
// Shows exactly what build_queue() would find per post type, without actually
// generating anything. Helps diagnose why a post type appears enabled in
// settings but produces no items (e.g. WooCommerce product visibility issues).
$hs3daci_diag_settings = HS3DACI_Settings::get_settings();
$hs3daci_diag_rows     = array();

if ( ! empty( $hs3daci_diag_settings['post_types'] ) ) {
	foreach ( $hs3daci_diag_settings['post_types'] as $hs3daci_pt => $hs3daci_cfg ) {
		$hs3daci_exists  = post_type_exists( $hs3daci_pt );
		$hs3daci_enabled = ! empty( $hs3daci_cfg['enabled'] );
		$hs3daci_excl    = isset( $hs3daci_cfg['excluded_ids'] ) ? array_filter( array_map( 'absint', (array) $hs3daci_cfg['excluded_ids'] ) ) : array();
		$hs3daci_count   = 0;

		if ( $hs3daci_enabled && $hs3daci_exists ) {
			$hs3daci_q_args = array(
				'post_type'      => $hs3daci_pt,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => false,
				'fields'         => 'ids',
			);
			if ( ! empty( $hs3daci_excl ) ) {
				$hs3daci_q_args['post__not_in'] = array_values( $hs3daci_excl ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- admin-only diagnostic preview of per-item exclusions.
			}
			$hs3daci_q     = new WP_Query( $hs3daci_q_args );
			$hs3daci_count = $hs3daci_q->found_posts;

			// Sample the first item's URL so we can verify permalink resolution is correct.
			$hs3daci_sample_url = '';
			if ( ! empty( $hs3daci_q->posts ) ) {
				$hs3daci_first_id = $hs3daci_q->posts[0];
				if ( 'product' === $hs3daci_pt && function_exists( 'wc_get_product' ) ) {
					$hs3daci_wc_p       = wc_get_product( $hs3daci_first_id );
					$hs3daci_sample_url = $hs3daci_wc_p ? $hs3daci_wc_p->get_permalink() : get_permalink( $hs3daci_first_id );
				} else {
					$hs3daci_sample_url = get_permalink( $hs3daci_first_id );
				}
			}
			wp_reset_postdata();
		}

		$hs3daci_obj = get_post_type_object( $hs3daci_pt );
		$hs3daci_diag_rows[] = array(
				'slug'       => $hs3daci_pt,
				'label'      => $hs3daci_obj ? $hs3daci_obj->labels->name : $hs3daci_pt,
				'exists'     => $hs3daci_exists,
				'enabled'    => $hs3daci_enabled,
				'excluded'   => count( $hs3daci_excl ),
				'count'      => $hs3daci_count,
				'sample_url' => $hs3daci_sample_url ?? '',
			);
	}
}
?>

<div class="hs3daci-card">
	<h2><?php esc_html_e( 'Content Queue Preview', 'hs3dev-ai-content-index-llmstxt' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Shows exactly how many items each post type will contribute to the next generation run, based on your current Settings. If a post type shows 0 items when you expect more, check whether it is enabled in Settings, whether individual items were excluded, or whether the post type exists on this site.', 'hs3dev-ai-content-index-llmstxt' ); ?></p>

	<table class="widefat striped" style="margin-top:12px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Post Type', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				<th><?php esc_html_e( 'Enabled', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				<th><?php esc_html_e( 'Excluded items', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				<th><?php esc_html_e( 'Will generate', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				<th><?php esc_html_e( 'Sample URL (first item)', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $hs3daci_diag_rows as $hs3daci_row ) : ?>
				<tr>
					<td><?php echo esc_html( $hs3daci_row['label'] ); ?></td>
					<td><code><?php echo esc_html( $hs3daci_row['slug'] ); ?></code></td>
					<td>
						<?php if ( ! $hs3daci_row['exists'] ) : ?>
							<span style="color:#b32d2e;"><?php esc_html_e( 'Not registered', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
						<?php elseif ( $hs3daci_row['enabled'] ) : ?>
							<span style="color:#0a6b2a;">&#10003; <?php esc_html_e( 'Yes', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
						<?php else : ?>
							<span style="color:#646970;"><?php esc_html_e( 'No', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $hs3daci_row['excluded'] > 0 ? $hs3daci_row['excluded'] . ' items excluded' : '—' ); ?></td>
					<td>
						<strong style="color:<?php echo $hs3daci_row['enabled'] && $hs3daci_row['count'] === 0 ? '#b32d2e' : '#1d2327'; ?>;">
							<?php echo esc_html( $hs3daci_row['enabled'] ? number_format_i18n( $hs3daci_row['count'] ) : '—' ); ?>
						</strong>
						<?php if ( $hs3daci_row['enabled'] && $hs3daci_row['count'] === 0 ) : ?>
							<span style="color:#b32d2e;font-size:12px;"> &#9888; <?php esc_html_e( 'No published items found', 'hs3dev-ai-content-index-llmstxt' ); ?></span>
						<?php endif; ?>
					</td>
					<td style="font-size:11px; color:#646970; max-width:220px; word-break:break-all;">
						<?php if ( ! empty( $hs3daci_row['sample_url'] ) ) : ?>
							<a href="<?php echo esc_url( $hs3daci_row['sample_url'] ); ?>" target="_blank"><?php echo esc_html( $hs3daci_row['sample_url'] ); ?></a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	// Taxonomy rows.
	$hs3daci_tax_settings = ! empty( $hs3daci_diag_settings['taxonomies'] ) ? $hs3daci_diag_settings['taxonomies'] : array();
	$hs3daci_tax_rows     = array();
	foreach ( $hs3daci_tax_settings as $hs3daci_tax_slug => $hs3daci_tax_on ) {
		if ( ! $hs3daci_tax_on ) {
			continue;
		}
		$hs3daci_tax_obj   = get_taxonomy( $hs3daci_tax_slug );
		$hs3daci_tax_label = $hs3daci_tax_obj ? $hs3daci_tax_obj->labels->name : $hs3daci_tax_slug;
		$hs3daci_tax_count = 0;
		if ( taxonomy_exists( $hs3daci_tax_slug ) ) {
			$hs3daci_terms     = get_terms( array( 'taxonomy' => $hs3daci_tax_slug, 'hide_empty' => false, 'fields' => 'count' ) );
			$hs3daci_tax_count = is_wp_error( $hs3daci_terms ) ? 0 : (int) $hs3daci_terms;
		}
		$hs3daci_tax_rows[] = array( 'label' => $hs3daci_tax_label, 'slug' => $hs3daci_tax_slug, 'count' => $hs3daci_tax_count );
	}
	if ( ! empty( $hs3daci_tax_rows ) ) : ?>
		<p style="margin-top:12px;"><strong><?php esc_html_e( 'Enabled taxonomies:', 'hs3dev-ai-content-index-llmstxt' ); ?></strong></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Taxonomy', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
					<th><?php esc_html_e( 'Terms (URLs)', 'hs3dev-ai-content-index-llmstxt' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $hs3daci_tax_rows as $hs3daci_tr ) : ?>
					<tr>
						<td><?php echo esc_html( $hs3daci_tr['label'] ); ?></td>
						<td><code><?php echo esc_html( $hs3daci_tr['slug'] ); ?></code></td>
						<td><strong><?php echo esc_html( number_format_i18n( $hs3daci_tr['count'] ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
