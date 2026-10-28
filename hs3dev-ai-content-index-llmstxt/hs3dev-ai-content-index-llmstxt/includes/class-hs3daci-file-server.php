<?php
/**
 * Serves the generated llms.txt and llms-full.txt files at the site root
 * without requiring physical files to be written to disk.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS3DACI_File_Server {

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_file' ) );
	}

	/**
	 * Registers rewrite rules so /llms.txt and /llms-full.txt are routed through WordPress.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?hs3daci_file=llms', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?hs3daci_file=llms-full', 'top' );

		// Subdirectory-style language prefixes (e.g. /fr/llms.txt), common
		// with WPML and Polylang when "different languages in directories" is used.
		add_rewrite_rule( '^([a-z]{2})/llms\.txt$', 'index.php?hs3daci_file=llms&hs3daci_lang=$matches[1]', 'top' );
		add_rewrite_rule( '^([a-z]{2})/llms-full\.txt$', 'index.php?hs3daci_file=llms-full&hs3daci_lang=$matches[1]', 'top' );
	}

	/**
	 * Registers the custom query var used by the rewrite rules above.
	 *
	 * @param string[] $vars Existing public query vars.
	 *
	 * @return string[]
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'hs3daci_file';
		$vars[] = 'hs3daci_lang';

		return $vars;
	}

	/**
	 * Outputs the requested file content and stops further template loading.
	 */
	public function maybe_serve_file() {
		$file = get_query_var( 'hs3daci_file' );

		if ( empty( $file ) ) {
			return;
		}

		$settings = HS3DACI_Settings::get_settings();
		if ( ! empty( $settings['enable_logging'] ) ) {
			HS3DACI_Logger::log( $file );
		}

		$lang_code = $this->resolve_requested_language();
		$data      = HS3DACI_Core::get_generated( $lang_code );

		$content = ( 'llms-full' === $file ) ? $data['llms_full_txt'] : $data['llms_txt'];

		// If no language-specific file was ever generated (e.g. a language
		// was added after the last run), fall back to the default/unprefixed file
		// rather than serving an empty response.
		if ( '' === trim( $content ) && $lang_code ) {
			$data    = HS3DACI_Core::get_generated();
			$content = ( 'llms-full' === $file ) ? $data['llms_full_txt'] : $data['llms_txt'];
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text output.
		exit;
	}

	/**
	 * Determines which language's content to serve, checking (in order):
	 * a subdirectory-style URL match (/fr/llms.txt), a ?lang= query string,
	 * or the multilingual plugin's own detected current language. Returns
	 * an empty string if no multilingual plugin is active or no language
	 * could be determined, meaning the default/single content should be used.
	 *
	 * @return string
	 */
	protected function resolve_requested_language() {
		if ( ! HS3DACI_Multilingual::is_active() ) {
			return '';
		}

		$from_rewrite = get_query_var( 'hs3daci_lang' );
		if ( $from_rewrite ) {
			return sanitize_key( $from_rewrite );
		}

		if ( ! empty( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only language selector, not a state-changing action.
			return sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only language selector, not a state-changing action.
		}

		$plugin = HS3DACI_Multilingual::detect_plugin();

		if ( 'polylang' === $plugin && function_exists( 'pll_current_language' ) ) {
			$current = pll_current_language();
			if ( $current ) {
				return $current;
			}
		}

		if ( 'wpml' === $plugin && defined( 'ICL_LANGUAGE_CODE' ) ) {
			return ICL_LANGUAGE_CODE;
		}

		return '';
	}
}
