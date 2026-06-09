<?php
/**
 * Persistence for synced Drive documents.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Database;

use ForWP\Drive\Documents\Document_Status;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for forwp_drive_documents.
 */
final class Document_Repository {

	/**
	 * Find by Drive file id.
	 *
	 * @param string $file_id Google file id.
	 * @return object|null Row object.
	 */
	public function find_by_file_id( string $file_id ) {
		global $wpdb;

		$table = Schema::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is controlled.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE file_id = %s LIMIT 1",
				$file_id
			)
		);
	}

	/**
	 * Find by primary key.
	 *
	 * @param int $id Row id.
	 * @return object|null Row object.
	 */
	public function find( int $id ) {
		global $wpdb;

		$table = Schema::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			)
		);
	}

	/**
	 * List documents by status.
	 *
	 * @param string[] $statuses Status values.
	 * @param int      $limit    Max rows.
	 * @return object[]
	 */
	public function list_by_statuses( array $statuses, int $limit = 50 ): array {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return array();
		}

		$table    = Schema::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status IN ({$placeholders}) ORDER BY detected_at DESC LIMIT %d",
			array_merge( $statuses, array( $limit ) )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built via $wpdb->prepare() above.
		$rows = $wpdb->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows in given statuses.
	 *
	 * @param string[] $statuses Status values.
	 */
	public function count_by_statuses( array $statuses ): int {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table        = Schema::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})",
			$statuses
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built via $wpdb->prepare() above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Mark inbox-ready rows as removed when they no longer appear in a Drive scan.
	 *
	 * @param string[] $seen_file_ids File ids returned by the latest scan.
	 * @param string   $source        Source slug.
	 */
	public function mark_missing_as_removed( array $seen_file_ids, string $source ): int {
		$rows = $this->list_by_statuses( Document_Status::inbox_statuses(), 500 );

		if ( empty( $rows ) ) {
			return 0;
		}

		$seen = array_fill_keys( $seen_file_ids, true );
		$now  = current_time( 'mysql', true );
		$count = 0;

		foreach ( $rows as $row ) {
			if ( (string) $row->source !== $source ) {
				continue;
			}

			if ( isset( $seen[ (string) $row->file_id ] ) ) {
				continue;
			}

			$this->update(
				(int) $row->id,
				array(
					'status'     => Document_Status::REMOVED,
					'updated_at' => $now,
				)
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Upsert after sync scan.
	 *
	 * @param array<string, mixed> $data Row fields.
	 * @return int Row id.
	 */
	public function upsert_from_scan( array $data ): int {
		$existing = $this->find_by_file_id( (string) $data['file_id'] );

		if ( $existing ) {
			$this->update(
				(int) $existing->id,
				array(
					'file_name'     => $data['file_name'] ?? $existing->file_name,
					'content_hash'  => $data['content_hash'] ?? $existing->content_hash,
					'status'        => $data['status'] ?? $existing->status,
					'folder_role'   => $data['folder_role'] ?? $existing->folder_role,
					'metadata_json' => $data['metadata_json'] ?? $existing->metadata_json,
					'error_message' => array_key_exists( 'error_message', $data ) ? $data['error_message'] : $existing->error_message,
					'wp_post_id'    => Document_Status::READY === ( $data['status'] ?? '' ) ? null : $existing->wp_post_id,
					'imported_at'   => Document_Status::READY === ( $data['status'] ?? '' ) ? null : $existing->imported_at,
					'updated_at'    => current_time( 'mysql', true ),
				)
			);

			return (int) $existing->id;
		}

		return $this->insert( $data );
	}

	/**
	 * Insert row.
	 *
	 * @param array<string, mixed> $data Row fields.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = Schema::table_name();

		$wpdb->insert(
			$table,
			array(
				'source'        => $data['source'] ?? 'google_drive',
				'file_id'       => $data['file_id'],
				'file_name'     => $data['file_name'] ?? '',
				'content_hash'  => $data['content_hash'] ?? '',
				'status'        => $data['status'] ?? Document_Status::DETECTED,
				'wp_post_id'    => $data['wp_post_id'] ?? null,
				'folder_role'   => $data['folder_role'] ?? 'incoming',
				'metadata_json' => $data['metadata_json'] ?? null,
				'error_message' => $data['error_message'] ?? null,
				'detected_at'   => $data['detected_at'] ?? $now,
				'imported_at'   => $data['imported_at'] ?? null,
				'updated_at'    => $data['updated_at'] ?? $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update row by id.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Fields to update.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( empty( $data ) ) {
			return false;
		}

		$table = Schema::table_name();

		return false !== $wpdb->update( $table, $data, array( 'id' => $id ), null, array( '%d' ) );
	}

	/**
	 * Decode metadata_json column.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	public function decode_metadata( $row ): array {
		if ( ! $row || empty( $row->metadata_json ) ) {
			return array();
		}

		$decoded = json_decode( (string) $row->metadata_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
