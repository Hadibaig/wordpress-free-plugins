<?php
/**
 * Multilingual support for WPML and Polylang.
 *
 * When either plugin is active, generation runs once per active language
 * and stores a separate copy of llms.txt / llms-full.txt for each, so a
 * French visitor (or AI crawler requesting the French version) sees French
 * titles, summaries, and URLs rather than a single mixed-language file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS3DACI_Multilingual {

	/**
	 * Detects which supported multilingual plugin is active, if any.
	 *
	 * @return string 'wpml', 'polylang', or '' if neither is active.
	 */
	public static function detect_plugin() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'wpml_get_active_languages_filter' ) ) {
			return 'wpml';
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			return 'polylang';
		}

		return '';
	}

	/**
	 * Returns whether a supported multilingual plugin is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return '' !== self::detect_plugin();
	}

	/**
	 * Returns the list of active language codes, e.g. [ 'en', 'fr', 'de' ].
	 *
	 * @return string[]
	 */
	public static function get_languages() {
		$plugin = self::detect_plugin();

		if ( 'wpml' === $plugin ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.

			if ( is_array( $languages ) ) {
				return array_keys( $languages );
			}

			return array();
		}

		if ( 'polylang' === $plugin ) {
			$languages = function_exists( 'pll_languages_list' ) ? pll_languages_list() : array();

			return is_array( $languages ) ? $languages : array();
		}

		return array();
	}

	/**
	 * Returns the default/primary site language code.
	 *
	 * @return string
	 */
	public static function get_default_language() {
		$plugin = self::detect_plugin();

		if ( 'wpml' === $plugin && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
			if ( $default ) {
				return $default;
			}
		}

		if ( 'polylang' === $plugin && function_exists( 'pll_default_language' ) ) {
			return pll_default_language();
		}

		return self::get_languages()[0] ?? '';
	}

	/**
	 * Temporarily switches the active language context so that get_posts(),
	 * get_permalink(), get_the_title() etc. return language-specific results
	 * for the given language code. Caller is responsible for calling
	 * restore_language() afterward.
	 *
	 * @param string $lang_code Language code to switch to.
	 */
	public static function switch_to_language( $lang_code ) {
		$plugin = self::detect_plugin();

		if ( 'wpml' === $plugin ) {
			do_action( 'wpml_switch_language', $lang_code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
		} elseif ( 'polylang' === $plugin && function_exists( 'pll_current_language' ) ) {
			// Polylang reads the language from a global; switching it directly
			// via its public filter keeps query filtering consistent.
			add_filter( 'pll_current_language', function () use ( $lang_code ) {
				return $lang_code;
			}, 999 );

			if ( function_exists( 'PLL' ) && isset( PLL()->curlang ) ) {
				$lang_obj = PLL()->model->get_language( $lang_code );
				if ( $lang_obj ) {
					PLL()->curlang = $lang_obj;
				}
			}
		}
	}

	/**
	 * Restores the original/default language context after a call to
	 * switch_to_language().
	 */
	public static function restore_language() {
		$plugin = self::detect_plugin();

		if ( 'wpml' === $plugin ) {
			do_action( 'wpml_switch_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
		}
		// Polylang's filter-based override above is request-scoped and
		// intentionally left in place; each generation run finishes within
		// a single request/batch cycle.
	}
}
