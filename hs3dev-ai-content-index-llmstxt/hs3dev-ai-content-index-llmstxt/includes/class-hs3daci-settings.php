<?php
/**
 * Handles reading, writing and defaulting plugin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS3DACI_Settings {

	const OPTION_KEY = 'hs3daci_settings';

	/**
	 * Returns the default settings array.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'company_email'       => '',
			'company_name'        => get_bloginfo( 'name' ),
			'company_description' => get_bloginfo( 'description' ),
			'post_types'          => array(
				'post' => array(
					'enabled'      => true,
					'excluded_ids' => array(),
				),
				'page' => array(
					'enabled'      => false,
					'excluded_ids' => array(),
				),
			),
			'update_frequency'         => 'manual',
			'include_urls'             => '',
			'exclude_urls'             => '',
			'taxonomies'               => array(),
			'ai_policy'                => '',
			'enable_logging'           => true,
			'use_seo_meta'             => true,
			'include_meta_description' => true,
			'compatibility_mode'       => false,
			'allowed_role'             => 'manage_options',
		);
	}

	/**
	 * Saves default settings if no settings exist yet.
	 */
	public static function set_defaults() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, self::get_defaults() );
		}
	}

	/**
	 * Returns the current settings, merged with defaults for any missing keys.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::get_defaults() );
	}

	/**
	 * Overwrites the stored settings with a new array (replaces, not merges).
	 *
	 * @param array $new_settings Settings to store.
	 *
	 * @return array The settings that were saved.
	 */
	public static function update_settings( $new_settings ) {
		$merged = wp_parse_args( $new_settings, self::get_defaults() );
		update_option( self::OPTION_KEY, $merged );

		return $merged;
	}

	/**
	 * Convenience getter for a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}
}
