<?php
/**
 * Diagnostics: detects conditions that would make the live llms.txt /
 * llms-full.txt URLs not actually reflect what this plugin generated,
 * and surfaces a clear message to the user instead of silent confusion.
 *
 * Two distinct problems are checked for:
 * 1. A physical llms.txt/llms-full.txt file sitting in the site root,
 *    written by another plugin (or manually). Static files are served by
 *    the web server before WordPress even loads, so they always win over
 *    our virtual/dynamic version regardless of rewrite rules.
 * 2. A caching layer (host page cache, CDN, Cloudflare, etc.) serving a
 *    stale cached response for the public URL, separate from what our
 *    own database copy contains right now.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS3DACI_Diagnostics {

	/**
	 * Checks the actual filesystem for a physical llms.txt / llms-full.txt
	 * file in the WordPress root directory.
	 *
	 * @return array{ llms: bool, llms_full: bool } Whether each file exists physically.
	 */
	public static function check_physical_files() {
		return array(
			'llms'      => file_exists( ABSPATH . 'llms.txt' ),
			'llms_full' => file_exists( ABSPATH . 'llms-full.txt' ),
		);
	}

	/**
	 * Fetches the live public URL (with a cache-busting query string) and
	 * compares it against what's currently stored by this plugin, so the
	 * user gets a clear verdict instead of needing to manually test URLs.
	 *
	 * @param string $file_type Either 'llms' or 'llms-full'.
	 *
	 * @return array{
	 *     matches: bool|null,
	 *     live_excerpt: string,
	 *     error: string,
	 *     url: string
	 * }
	 */
	public static function verify_live_url( $file_type ) {
		$path = ( 'llms-full' === $file_type ) ? 'llms-full.txt' : 'llms.txt';
		$url  = home_url( '/' . $path );

		// Cache-busting query string, same technique used to manually confirm this earlier.
		$bust_url = add_query_arg( 'hs3daci_check', wp_rand( 1000, 999999 ), $url );

		$response = wp_remote_get(
			$bust_url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array(
					'Cache-Control' => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'matches'      => null,
				'live_excerpt' => '',
				'error'        => $response->get_error_message(),
				'url'          => $url,
			);
		}

		$live_body = wp_remote_retrieve_body( $response );
		$stored    = HS3DACI_Core::get_generated();
		$stored_body = ( 'llms-full' === $file_type ) ? $stored['llms_full_txt'] : $stored['llms_txt'];

		// Compare trimmed content; minor whitespace differences from transport shouldn't count as a mismatch.
		$matches = ( trim( $live_body ) === trim( $stored_body ) );

		return array(
			'matches'      => $matches,
			'live_excerpt' => mb_substr( trim( $live_body ), 0, 200 ),
			'error'        => '',
			'url'          => $url,
		);
	}
}
