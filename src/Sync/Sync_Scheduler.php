<?php
/**
 * WP-Cron scheduler for incoming folder scans.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Registers recurring sync event.
 */
final class Sync_Scheduler {

	public const HOOK = 'forwp_drive_sync_incoming';

	/**
	 * @return void
	 */
	public static function activate(): void {
		self::schedule();
	}

	/**
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * @return void
	 */
	public static function boot(): void {
		add_action( self::HOOK, array( self::class, 'run_sync' ) );
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) );
	}

	/**
	 * Custom 15-minute schedule.
	 *
	 * @param array<string, array<string, int|string>> $schedules Schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function add_interval( array $schedules ): array {
		if ( ! isset( $schedules['forwp_drive_quarter_hour'] ) ) {
			$schedules['forwp_drive_quarter_hour'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (4WP Drive)', '4wp-drive' ),
			);
		}

		return $schedules;
	}

	/**
	 * (Re)schedule cron event.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'forwp_drive_quarter_hour', self::HOOK );
		}
	}

	/**
	 * Cron callback.
	 */
	public static function run_sync(): void {
		$scanner = new Incoming_Scanner();
		$scanner->run();
	}
}
