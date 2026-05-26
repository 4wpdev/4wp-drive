<?php
/**
 * Plugin options for folders and sync.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Option accessors for 4WP Drive.
 */
final class Settings {

	public const FOLDER_IDS_OPTION = 'forwp_drive_folder_ids';

	public const SYNC_INTERVAL_OPTION = 'forwp_drive_sync_interval';

	public const LAST_SYNC_OPTION = 'forwp_drive_last_sync';

	public const READY_COUNT_OPTION = 'forwp_drive_ready_count';

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * @return array{root?: string, incoming?: string, published?: string, failed?: string}
	 */
	public function get_folder_ids(): array {
		$ids = get_option( self::FOLDER_IDS_OPTION, array() );

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * @param array<string, string> $ids Folder role => Drive folder id.
	 */
	public function set_folder_ids( array $ids ): void {
		update_option( self::FOLDER_IDS_OPTION, $ids, false );
	}

	/**
	 * @param string $role incoming|published|failed|root.
	 */
	public function get_folder_id( string $role ): string {
		$ids = $this->get_folder_ids();

		return isset( $ids[ $role ] ) ? (string) $ids[ $role ] : '';
	}

	/**
	 * Cron schedule name (hourly, twicedaily, etc.).
	 */
	public function get_sync_interval(): string {
		$interval = get_option( self::SYNC_INTERVAL_OPTION, 'hourly' );

		return is_string( $interval ) && '' !== $interval ? $interval : 'hourly';
	}

	/**
	 * @param string $interval WP cron schedule key.
	 */
	public function set_sync_interval( string $interval ): void {
		update_option( self::SYNC_INTERVAL_OPTION, sanitize_key( $interval ), false );
	}

	/**
	 * @param array<string, mixed>|null $payload Last sync summary.
	 */
	public function set_last_sync( ?array $payload ): void {
		if ( null === $payload ) {
			delete_option( self::LAST_SYNC_OPTION );
			return;
		}
		update_option( self::LAST_SYNC_OPTION, $payload, false );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_last_sync(): ?array {
		$data = get_option( self::LAST_SYNC_OPTION, null );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param int $count Ready documents count for admin notice.
	 */
	public function set_ready_count( int $count ): void {
		update_option( self::READY_COUNT_OPTION, max( 0, $count ), false );
	}

	public function get_ready_count(): int {
		return (int) get_option( self::READY_COUNT_OPTION, 0 );
	}
}
