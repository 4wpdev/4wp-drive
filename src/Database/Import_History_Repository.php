<?php
/**
 * Persistence for import history rows.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Reader/writer for forwp_drive_import_history.
 */
final class Import_History_Repository {

	/**
	 * @param array<string, mixed> $row History fields from Import_History_Recorder::build().
	 * @return int Inserted id, or 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$imported_at = (string) ( $row['imported_at'] ?? '' );
		if ( '' === $imported_at ) {
			$imported_at = current_time( 'mysql', true );
		}

		$document_id = isset( $row['document_id'] ) ? (int) $row['document_id'] : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			Schema::history_table_name(),
			array(
				'source'         => (string) ( $row['source'] ?? '' ),
				'post_id'        => (int) ( $row['post_id'] ?? 0 ),
				'post_type'      => (string) ( $row['post_type'] ?? 'post' ),
				'imported_at'    => $imported_at,
				'incoming_path'  => (string) ( $row['incoming_path'] ?? '' ),
				'published_path' => (string) ( $row['published_path'] ?? '' ),
				'folder_alias'   => (string) ( $row['folder_alias'] ?? '' ),
				'site_alias'     => (string) ( $row['site_alias'] ?? '' ),
				'package_ref'    => (string) ( $row['package_ref'] ?? '' ),
				'document_id'    => $document_id > 0 ? $document_id : null,
				'file_id'        => (string) ( $row['file_id'] ?? '' ),
				'mode'           => (string) ( $row['mode'] ?? 'create' ),
				'restored_at'    => null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return object|null
	 */
	public function find( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Schema::history_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from Schema::history_table_name().
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			)
		);

		return $row ?: null;
	}

	/**
	 * Newest first.
	 *
	 * @return object[]
	 */
	public function list( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table  = Schema::history_table_name();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from Schema::history_table_name().
				"SELECT * FROM {$table} ORDER BY imported_at DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count(): int {
		global $wpdb;

		$table = Schema::history_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function mark_restored( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			Schema::history_table_name(),
			array( 'restored_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
