<?php
/**
 * Handles automatic regeneration of llms.txt / llms-full.txt based on the
 * "Update Frequency" setting (on save, daily, or weekly).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS3DACI_Cron {

	const SINGLE_HOOK    = 'hs3daci_run_generate';
	const RECURRING_HOOK = 'hs3daci_scheduled_generate';
	const WEEKLY_SCHEDULE = 'hs3daci_weekly';

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );

		add_action( self::SINGLE_HOOK, array( 'HS3DACI_Core', 'generate' ) );
		add_action( self::RECURRING_HOOK, array( 'HS3DACI_Core', 'generate' ) );

		add_action( 'save_post', array( $this, 'maybe_schedule_on_save' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'maybe_schedule_on_delete' ) );

		add_action( 'update_option_' . HS3DACI_Settings::OPTION_KEY, array( $this, 'reschedule' ), 10, 2 );
	}

	/**
	 * Registers a "weekly" cron schedule, since WordPress core only ships
	 * with hourly, twicedaily and daily.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public function add_weekly_schedule( $schedules ) {
		$schedules[ self::WEEKLY_SCHEDULE ] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'hs3dev-ai-content-index-llmstxt' ),
		);

		return $schedules;
	}

	/**
	 * Schedules a one-off regeneration shortly after a relevant post is saved,
	 * if "Update Frequency" is set to "on_save". Debounced so multiple quick
	 * saves only trigger one regeneration.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function maybe_schedule_on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$this->maybe_schedule_for_post_type( $post->post_type );
	}

	/**
	 * Schedules a regeneration when an included post is deleted.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function maybe_schedule_on_delete( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( $post_type ) {
			$this->maybe_schedule_for_post_type( $post_type );
		}
	}

	/**
	 * Schedules a single regeneration event if the given post type is
	 * included and "on save" regeneration is enabled.
	 *
	 * @param string $post_type Post type slug.
	 */
	protected function maybe_schedule_for_post_type( $post_type ) {
		$settings = HS3DACI_Settings::get_settings();

		if ( 'on_save' !== $settings['update_frequency'] ) {
			return;
		}

		if ( empty( $settings['post_types'][ $post_type ]['enabled'] ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::SINGLE_HOOK ) ) {
			// Small delay so several saves in quick succession only trigger one rebuild.
			wp_schedule_single_event( time() + 30, self::SINGLE_HOOK );
		}
	}

	/**
	 * Re-applies cron scheduling whenever settings are saved, in case the
	 * Update Frequency value changed.
	 *
	 * @param array $old_value Previous settings.
	 * @param array $new_value New settings.
	 */
	public function reschedule( $old_value, $new_value ) {
		$frequency = isset( $new_value['update_frequency'] ) ? $new_value['update_frequency'] : 'manual';
		self::apply_schedule( $frequency );
	}

	/**
	 * Clears any existing recurring event and schedules a new one matching
	 * the given frequency (no-op for "manual" and "on_save").
	 *
	 * @param string $frequency One of manual, on_save, daily, weekly.
	 */
	public static function apply_schedule( $frequency ) {
		$timestamp = wp_next_scheduled( self::RECURRING_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::RECURRING_HOOK );
		}

		if ( 'daily' === $frequency ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::RECURRING_HOOK );
		} elseif ( 'weekly' === $frequency ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, self::WEEKLY_SCHEDULE, self::RECURRING_HOOK );
		}
	}

	/**
	 * Runs on plugin activation: sets up the recurring schedule based on
	 * the currently saved setting.
	 */
	public static function activate() {
		$settings = HS3DACI_Settings::get_settings();
		self::apply_schedule( $settings['update_frequency'] );
	}

	/**
	 * Runs on plugin deactivation: clears all scheduled events.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::RECURRING_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::RECURRING_HOOK );
		}

		$timestamp = wp_next_scheduled( self::SINGLE_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::SINGLE_HOOK );
		}
	}
}
