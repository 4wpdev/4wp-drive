<?php
/**
 * Database schema for drive documents.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades custom tables.
 */
final class Schema {

	/**
	 * Table name without prefix.
	 */
	public const TABLE = 'forwp_drive_documents';

	/**
	 * Plugin activation: create table and schedule sync.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_table();
		\ForWP\Drive\Sync\Sync_Scheduler::activate();
	}

	/**
	 * Create or update the documents table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(32) NOT NULL DEFAULT 'google_drive',
			file_id varchar(128) NOT NULL,
			file_name varchar(255) NOT NULL DEFAULT '',
			content_hash varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'detected',
			wp_post_id bigint(20) unsigned DEFAULT NULL,
			folder_role varchar(20) NOT NULL DEFAULT 'incoming',
			metadata_json longtext,
			error_message text,
			detected_at datetime NOT NULL,
			imported_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_id (file_id),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Full table name with prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}
}
