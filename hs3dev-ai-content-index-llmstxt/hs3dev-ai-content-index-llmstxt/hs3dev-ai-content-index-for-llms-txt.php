<?php
/**
 * Plugin Name:       HS3Dev AI Content Index for llms.txt
 * Plugin URI:        https://hadi-mirza.com/hs3dev-ai-content-index-for-llms-txt/
 * Description:       Generate llms.txt and llms-full.txt files for your WordPress site, with full control over which posts, pages and products are included. Choose content by post type or individually, pull summaries from your SEO plugin's meta description, auto-regenerate on a schedule, and track which AI crawlers (GPTBot, ClaudeBot, PerplexityBot, etc.) are reading your files.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.0
 * Author:            Mirza Hadi
 * Author URI:        https://hadi-mirza.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hs3dev-ai-content-index-llmstxt
 *
 * Developer:  Mirza Hadi
 * Brand:      HS3Dev
 * Role:       Full Stack Developer & Technical Problem Solver
 * Email:      mirzahadi@hotmail.com
 * Website:    hadi-mirza.com
 * LinkedIn:   linkedin.com/in/hadibaig
 * GitHub:     github.com/Hadibaig
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Block direct access.
}

define( 'HS3DACI_VERSION', '0.6.9' );
define( 'HS3DACI_PATH', plugin_dir_path( __FILE__ ) );
define( 'HS3DACI_URL', plugin_dir_url( __FILE__ ) );

require_once HS3DACI_PATH . 'includes/class-hs3daci-settings.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-generator.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-logger.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-cron.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-diagnostics.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-multilingual.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-admin.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-ajax.php';
require_once HS3DACI_PATH . 'includes/class-hs3daci-file-server.php';

/**
 * Runs the actual per-site setup: settings defaults, logs table, cron
 * schedule and the /llms.txt + /llms-full.txt rewrite rules. Called once
 * per site, whether on single-site activation, network activation (looped
 * across all sites), or when a new site is created later on an
 * already-network-activated install.
 *
 * The rewrite rules are registered and flushed here, inside the per-site
 * context, because rewrite rules are stored per site. switch_to_blog()
 * re-initialises $wp_rewrite, so the rules must be added after the switch
 * and flushed before restore_current_blog().
 */
function hs3daci_setup_site() {
	HS3DACI_Settings::set_defaults();
	HS3DACI_Logger::create_table();
	HS3DACI_Cron::activate();

	$file_server = new HS3DACI_File_Server();
	$file_server->add_rewrite_rules();
	// Soft flush: regenerates this site's stored rules without touching
	// .htaccess, which is both unnecessary and unsafe inside a loop.
	flush_rewrite_rules( false );
}

/**
 * Runs on plugin activation. On a multisite network activation, loops
 * over every existing site so each one gets its own settings/table/cron,
 * since wp_options and the logs table are both per-site by default.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function hs3daci_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		// 'number' => 0 removes get_sites()'s default limit of 100 sites.
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			hs3daci_setup_site();
			restore_current_blog();
		}
	} else {
		hs3daci_setup_site();
	}
}
register_activation_hook( __FILE__, 'hs3daci_activate' );

/**
 * Ensures a newly created site on an already network-activated install
 * gets its own settings defaults, logs table, and cron schedule too,
 * rather than only sites that existed at the time of activation.
 *
 * @param WP_Site $new_site Newly created site object.
 */
function hs3daci_setup_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	switch_to_blog( $new_site->blog_id );
	hs3daci_setup_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'hs3daci_setup_new_site' );

/**
 * Runs on plugin deactivation. On network deactivation, clears scheduled
 * cron events on every site, since each site has its own.
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 */
function hs3daci_deactivate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			HS3DACI_Cron::deactivate();
			// Our rules are still registered in memory at this point, so a
			// flush would just save them again. Dropping the stored rules
			// makes WordPress rebuild them on the next request without us.
			delete_option( 'rewrite_rules' );
			restore_current_blog();
		}
	} else {
		HS3DACI_Cron::deactivate();
		delete_option( 'rewrite_rules' );
	}
}
register_deactivation_hook( __FILE__, 'hs3daci_deactivate' );

/**
 * Boots the plugin.
 */
function hs3daci_init() {
	new HS3DACI_Admin();
	new HS3DACI_Ajax();
	new HS3DACI_File_Server();
	new HS3DACI_Cron();
}
add_action( 'plugins_loaded', 'hs3daci_init' );
