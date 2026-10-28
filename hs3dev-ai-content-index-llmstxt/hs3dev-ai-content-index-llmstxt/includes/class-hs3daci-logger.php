<?php
/**
 * Logs requests to llms.txt / llms-full.txt and provides analytics summaries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS3DACI_Logger {

	const TABLE = 'hs3daci_logs';

	/**
	 * Creates (or updates) the logs table.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			file_type VARCHAR(20) NOT NULL,
			bot_name VARCHAR(60) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			requested_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY file_type (file_type),
			KEY bot_name (bot_name),
			KEY requested_at (requested_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Records a single request.
	 *
	 * @param string $file_type Either 'llms' or 'llms-full'.
	 */
	public static function log( $file_type ) {
		global $wpdb;

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot_name   = self::detect_bot( $user_agent );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert into the plugin's own log table; write-only, so caching does not apply.
			$wpdb->prefix . self::TABLE,
			array(
				'file_type'    => $file_type,
				'bot_name'     => $bot_name,
				'user_agent'   => mb_substr( $user_agent, 0, 255 ),
				'requested_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Matches a user agent string against a list of known AI / search crawlers.
	 *
	 * @param string $user_agent Raw user agent string.
	 *
	 * @return string Human-readable bot name, or "Other / Unknown".
	 */
	public static function detect_bot( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return 'Other / Unknown';
		}

		$known_bots = array(
			'GPTBot'             => 'GPTBot (OpenAI)',
			'ChatGPT-User'       => 'ChatGPT-User (OpenAI)',
			'OAI-SearchBot'      => 'OAI-SearchBot (OpenAI)',
			'ClaudeBot'          => 'ClaudeBot (Anthropic)',
			'Claude-Web'         => 'Claude-Web (Anthropic)',
			'anthropic-ai'       => 'Anthropic AI',
			'PerplexityBot'      => 'PerplexityBot',
			'Perplexity-User'    => 'Perplexity-User',
			'Google-Extended'    => 'Google-Extended (AI training)',
			'Googlebot'          => 'Googlebot',
			'Bingbot'            => 'Bingbot',
			'CCBot'              => 'CCBot (Common Crawl)',
			'Bytespider'         => 'Bytespider (ByteDance)',
			'Meta-ExternalAgent' => 'Meta AI',
			'facebookexternalhit'=> 'Meta (Facebook)',
			'Amazonbot'          => 'Amazonbot',
			'Applebot'           => 'Applebot',
			'cohere-ai'          => 'Cohere AI',
			'YouBot'             => 'YouBot',
		);

		foreach ( $known_bots as $needle => $label ) {
			if ( false !== stripos( $user_agent, $needle ) ) {
				return $label;
			}
		}

		return 'Other / Unknown';
	}

	/**
	 * Returns an analytics summary for the last N days.
	 *
	 * @param int $days Number of days to include.
	 *
	 * @return array
	 */
	public static function get_summary( $days = 30 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Analytics summary queries; not cached since the underlying data
		// changes on every request to /llms.txt or /llms-full.txt and this
		// is only run on the admin-only Analytics tab, not on front-end page loads.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE requested_at >= %s', $table, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_file = $wpdb->get_results( $wpdb->prepare( 'SELECT file_type, COUNT(*) as total FROM %i WHERE requested_at >= %s GROUP BY file_type', $table, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_bot = $wpdb->get_results( $wpdb->prepare( 'SELECT bot_name, COUNT(*) as total, MAX(requested_at) as last_seen FROM %i WHERE requested_at >= %s GROUP BY bot_name ORDER BY total DESC', $table, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$daily = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(requested_at) as day, COUNT(*) as total FROM %i WHERE requested_at >= %s GROUP BY DATE(requested_at) ORDER BY day ASC', $table, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$recent = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY requested_at DESC LIMIT 50', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'total'   => $total,
			'by_file' => $by_file,
			'by_bot'  => $by_bot,
			'daily'   => $daily,
			'recent'  => $recent,
		);
	}

	/**
	 * Deletes all log entries.
	 */
	public static function clear_logs() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
