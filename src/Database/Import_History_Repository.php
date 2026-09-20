<?php
/**
 * Persistence for import history rows.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Database;

use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Import\Import_History_Recorder;

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

		Schema::maybe_upgrade();

		$imported_at = (string) ( $row['imported_at'] ?? '' );
		if ( '' === $imported_at ) {
			$imported_at = current_time( 'mysql', true );
		}

		$document_id = isset( $row['document_id'] ) ? (int) $row['document_id'] : 0;
		$summary     = isset( $row['summary_json'] ) ? (string) $row['summary_json'] : '';
		if ( '' === $summary && isset( $row['summary'] ) && is_array( $row['summary'] ) ) {
			$encoded = wp_json_encode( $row['summary'] );
			$summary = is_string( $encoded ) ? $encoded : '';
		}

		$fields  = array(
			'source'         => (string) ( $row['source'] ?? '' ),
			'post_id'        => (int) ( $row['post_id'] ?? 0 ),
			'post_type'      => (string) ( $row['post_type'] ?? 'post' ),
			'imported_at'    => $imported_at,
			'incoming_path'  => (string) ( $row['incoming_path'] ?? '' ),
			'published_path' => (string) ( $row['published_path'] ?? '' ),
			'folder_alias'   => (string) ( $row['folder_alias'] ?? '' ),
			'site_alias'     => (string) ( $row['site_alias'] ?? '' ),
			'package_ref'    => (string) ( $row['package_ref'] ?? '' ),
			'file_id'        => (string) ( $row['file_id'] ?? '' ),
			'mode'           => (string) ( $row['mode'] ?? 'create' ),
			'summary_json'   => $summary,
		);
		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $document_id > 0 ) {
			$fields['document_id'] = $document_id;
			$formats[]             = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( Schema::history_table_name(), $fields, $formats );

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

		Schema::maybe_upgrade();
		if ( ! Schema::history_table_exists() ) {
			return array();
		}

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

		Schema::maybe_upgrade();
		if ( ! Schema::history_table_exists() ) {
			return 0;
		}

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

	/**
	 * @return object|null
	 */
	public function find_by_document_id( int $document_id ) {
		global $wpdb;

		if ( $document_id <= 0 || ! Schema::history_table_exists() ) {
			return null;
		}

		$table = Schema::history_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from Schema::history_table_name().
				"SELECT * FROM {$table} WHERE document_id = %d LIMIT 1",
				$document_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Write missing history rows from already-imported inbox documents (drafts and published).
	 */
	public function backfill_from_documents(): int {
		Schema::maybe_upgrade();
		if ( ! Schema::history_table_exists() ) {
			return 0;
		}

		$docs = ( new Document_Repository() )->list_by_statuses( array( Document_Status::IMPORTED ), 500 );
		if ( empty( $docs ) ) {
			return 0;
		}

		$inserted = 0;
		$decoder  = new Document_Repository();

		foreach ( $docs as $doc ) {
			$document_id = (int) ( $doc->id ?? 0 );
			$post_id     = (int) ( $doc->wp_post_id ?? 0 );
			if ( $post_id <= 0 || $this->find_by_document_id( $document_id ) ) {
				continue;
			}

			$metadata = $decoder->decode_metadata( $doc );
			$built    = Import_History_Recorder::build(
				array(
					'source'      => (string) ( $doc->source ?? '' ),
					'post_id'     => $post_id,
					'post_type'   => (string) ( get_post_type( $post_id ) ?: 'post' ),
					'site_alias'  => (string) get_post_field( 'post_name', $post_id ),
					'document_id' => $document_id,
					'file_id'     => (string) ( $doc->file_id ?? '' ),
					'mode'        => 'create',
					'metadata'    => $metadata,
					'imported_at' => (string) ( $doc->imported_at ?? '' ),
				)
			);
			$built['summary'] = Import_History_Recorder::summarize_post( $post_id, $metadata );

			if ( $this->insert( $built ) > 0 ) {
				++$inserted;
			}
		}

		return $inserted;
	}
}
