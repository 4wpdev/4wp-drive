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
	 * Inbox / queue table name without prefix.
	 */
	public const TABLE = 'forwp_drive_documents';

	/**
	 * Import history table name without prefix.
	 */
	public const HISTORY_TABLE = 'forwp_drive_import_history';

	/**
	 * Bump when custom tables change so existing installs run dbDelta.
	 */
	public const DB_VERSION = 2;

	public const DB_VERSION_OPTION = 'forwp_drive_db_version';

	/**
	 * Plugin activation: create tables and schedule sync.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::maybe_upgrade();
		\ForWP\Drive\Patterns\Pattern_Post_Type::activate();
		\ForWP\Drive\Sync\Sync_Scheduler::activate();
	}

	/**
	 * Create or upgrade custom tables when the schema version changes.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		self::create_table();
		self::create_history_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
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
	 * Append-only import history (source → published, folder alias ↔ site slug).
	 */
	public static function create_history_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::HISTORY_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(32) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(32) NOT NULL DEFAULT 'post',
			imported_at datetime NOT NULL,
			incoming_path varchar(512) NOT NULL DEFAULT '',
			published_path varchar(512) NOT NULL DEFAULT '',
			folder_alias varchar(255) NOT NULL DEFAULT '',
			site_alias varchar(200) NOT NULL DEFAULT '',
			package_ref varchar(191) NOT NULL DEFAULT '',
			document_id bigint(20) unsigned DEFAULT NULL,
			file_id varchar(191) NOT NULL DEFAULT '',
			mode varchar(20) NOT NULL DEFAULT 'create',
			restored_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source (source),
			KEY post_id (post_id),
			KEY folder_alias (folder_alias),
			KEY site_alias (site_alias),
			KEY imported_at (imported_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Full inbox table name with prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Full history table name with prefix.
	 */
	public static function history_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::HISTORY_TABLE;
	}
}
