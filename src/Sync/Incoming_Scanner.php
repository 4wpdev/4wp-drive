<?php
/**
 * Scan incoming Drive folder and upsert document rows.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Sync;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Notifications\Admin_Notifier;
use ForWP\Drive\Source_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sync job handler.
 */
final class Incoming_Scanner {

	/**
	 * @var Document_Repository
	 */
	private $repository;

	public function __construct( ?Document_Repository $repository = null ) {
		$this->repository = $repository ?? new Document_Repository();
	}

	/**
	 * Run full incoming scan.
	 *
	 * @return array<string, mixed>|WP_Error Summary.
	 */
	public function run() {
		$source = Source_Registry::get_default();
		if ( ! $source || ! $source->is_ready() ) {
			return new WP_Error( 'forwp_drive_not_ready', __( 'Drive source is not ready.', '4wp-drive' ) );
		}

		$items = $source->scan_incoming();
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$new_ready     = 0;
		$scanned       = 0;
		$export_errors = 0;
		$seen_file_ids = array();

		foreach ( $items as $item ) {
			++$scanned;
			$file_id = (string) $item['file_id'];
			if ( '' === $file_id ) {
				continue;
			}

			$seen_file_ids[] = $file_id;
			$hash            = (string) $item['content_hash'];
			$existing        = $this->repository->find_by_file_id( $file_id );

			if ( $existing && $existing->content_hash === $hash && Document_Status::READY === $existing->status ) {
				$stored = $this->repository->decode_metadata( $existing );
				if ( $this->stored_parse_looks_valid( $stored ) ) {
					$stored_html = isset( $stored['body_html'] ) ? (string) $stored['body_html'] : '';
					$stored_body = isset( $stored['body'] ) ? trim( (string) $stored['body'] ) : '';
					$has_text    = '' !== trim( wp_strip_all_tags( $stored_html ) ) || '' !== $stored_body;
					if ( $has_text ) {
						continue;
					}
				}
			}

			$metadata      = $item['metadata'] ?? array();
			$export_failed = ! empty( $item['export_failed'] );
			if ( $export_failed ) {
				++$export_errors;
			}

			$was_new = ! $existing || Document_Status::READY !== $existing->status;

			$this->repository->upsert_from_scan(
				array(
					'source'        => $source->get_slug(),
					'file_id'       => $file_id,
					'file_name'     => (string) ( $item['file_name'] ?? '' ),
					'content_hash'  => $hash,
					'status'        => Document_Status::READY,
					'folder_role'   => 'incoming',
					'metadata_json' => wp_json_encode( $metadata ),
					'error_message' => $export_failed ? (string) ( $metadata['scan_error'] ?? '' ) : null,
					'detected_at'   => $existing && $existing->detected_at ? $existing->detected_at : current_time( 'mysql', true ),
					'updated_at'    => current_time( 'mysql', true ),
				)
			);

			if ( $was_new ) {
				++$new_ready;
			}
		}

		$removed = $this->repository->mark_missing_as_removed( $seen_file_ids, $source->get_slug() );

		$ready_count = $this->repository->count_by_statuses( Document_Status::inbox_statuses() );
		Settings::instance()->set_ready_count( $ready_count );

		if ( $new_ready > 0 ) {
			Admin_Notifier::mark_pending( $ready_count );
		}

		$summary = array(
			'scanned'       => $scanned,
			'new_ready'     => $new_ready,
			'removed'       => $removed,
			'ready_total'   => $ready_count,
			'export_errors' => $export_errors,
			'timestamp'     => current_time( 'mysql', true ),
		);

		Settings::instance()->set_last_sync( $summary );

		return $summary;
	}

	/**
	 * Detect metadata that was never split from the post body (stale or broken parse).
	 *
	 * @param array<string, mixed> $stored Decoded metadata_json.
	 */
	private function stored_parse_looks_valid( array $stored ): bool {
		$slug      = trim( (string) ( $stored['slug'] ?? '' ) );
		$body_html = (string) ( $stored['body_html'] ?? '' );
		$body      = (string) ( $stored['body'] ?? '' );
		$plain     = trim( wp_strip_all_tags( $body_html . "\n" . $body ) );

		if ( '' === $plain ) {
			return false;
		}

		if ( preg_match( '/^(?:Title|Slug|Date|Category|Tags|Region|Country|SEO Title|SEO Description)\s*:/im', $plain ) ) {
			return false;
		}

		if ( '' === $slug && preg_match( '/\bSlug\s*:/i', $plain ) ) {
			return false;
		}

		return true;
	}
}
