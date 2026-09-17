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
		$new_ready     = 0;
		$scanned       = 0;
		$export_errors      = 0;
		$export_error_items = array();
		$removed       = 0;
		$any_ready     = false;

		foreach ( Source_Registry::all() as $source ) {
			if ( ! $source->is_ready() ) {
				continue;
			}

			$any_ready = true;
			$items     = $source->scan_incoming();
			if ( is_wp_error( $items ) ) {
				return $items;
			}

			$seen_file_ids = array();

			foreach ( $items as $item ) {
				++$scanned;
				$file_id = (string) ( $item['file_id'] ?? '' );
				if ( '' === $file_id ) {
					continue;
				}

				$metadata = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();
				$existing = $this->repository->find_by_file_id( $file_id );
				if ( ! $existing ) {
					$package_id = (string) ( $metadata['package_folder_id'] ?? '' );
					if ( '' !== $package_id ) {
						$existing = $this->find_by_package_folder( $package_id, $source->get_slug() );
					}
				}

				$item = $this->maybe_rescan_selected( $source, $item, $existing );
				$file_id  = (string) ( $item['file_id'] ?? $file_id );
				$metadata = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : $metadata;

				$seen_file_ids[] = $file_id;
				foreach ( $this->extra_seen_file_ids( $metadata ) as $extra_id ) {
					$seen_file_ids[] = $extra_id;
				}

				$hash = (string) ( $item['content_hash'] ?? '' );

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

				$export_failed = ! empty( $item['export_failed'] );
				if ( $export_failed ) {
					++$export_errors;
					$scan_error = (string) ( $metadata['scan_error'] ?? '' );
					$export_error_items[] = array(
						'file_id'   => $file_id,
						'file_name' => (string) ( $item['file_name'] ?? '' ),
						'message'   => $scan_error,
					);
				}

				$was_new = ! $existing || Document_Status::READY !== $existing->status;

				if ( $existing ) {
					$this->repository->update(
						(int) $existing->id,
						array(
							'file_id'       => $file_id,
							'file_name'     => (string) ( $item['file_name'] ?? $existing->file_name ),
							'content_hash'  => $hash,
							'status'        => Document_Status::READY,
							'folder_role'   => 'incoming',
							'metadata_json' => wp_json_encode( $metadata ),
							'error_message' => $export_failed ? (string) ( $metadata['scan_error'] ?? '' ) : null,
							'updated_at'    => current_time( 'mysql', true ),
						)
					);
				} else {
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
							'detected_at'   => current_time( 'mysql', true ),
							'updated_at'    => current_time( 'mysql', true ),
						)
					);
				}

				if ( $was_new ) {
					++$new_ready;
				}
			}

			$removed += $this->repository->mark_missing_as_removed( array_values( array_unique( $seen_file_ids ) ), $source->get_slug() );
		}

		if ( ! $any_ready ) {
			return new WP_Error( 'forwp_drive_not_ready', __( 'No storage source is ready.', '4wp-drive' ) );
		}

		$ready_count = $this->repository->count_by_statuses( Document_Status::inbox_statuses() );
		Settings::instance()->set_ready_count( $ready_count );

		if ( $new_ready > 0 ) {
			Admin_Notifier::mark_pending( $ready_count );
		}

		$summary = array(
			'scanned'            => $scanned,
			'new_ready'          => $new_ready,
			'removed'            => $removed,
			'ready_total'        => $ready_count,
			'export_errors'      => $export_errors,
			'export_error_items' => array_slice( $export_error_items, 0, 20 ),
			'timestamp'          => current_time( 'mysql', true ),
		);

		Settings::instance()->set_last_sync( $summary );

		return $summary;
	}

	/**
	 * @param object $source  Storage source.
	 * @param array<string, mixed> $item Scan item.
	 * @param object|null $existing Existing row.
	 * @return array<string, mixed>
	 */
	private function maybe_rescan_selected( $source, array $item, $existing ): array {
		if ( ! $existing || ! is_object( $source ) || ! method_exists( $source, 'rescan_source_file' ) ) {
			return $item;
		}

		$stored   = $this->repository->decode_metadata( $existing );
		$selected = (string) ( $stored['selected_file_id'] ?? '' );
		$current  = (string) ( $item['file_id'] ?? '' );
		if ( '' === $selected || $selected === $current ) {
			return $item;
		}

		$rescanned = $source->rescan_source_file( $item, $selected );

		return is_array( $rescanned ) ? $rescanned : $item;
	}

	/**
	 * @param array<string, mixed> $metadata Metadata.
	 * @return string[]
	 */
	private function extra_seen_file_ids( array $metadata ): array {
		$ids = array();
		if ( ! empty( $metadata['package_files'] ) && is_array( $metadata['package_files'] ) ) {
			foreach ( $metadata['package_files'] as $file ) {
				if ( is_array( $file ) && ! empty( $file['id'] ) && 'document' === ( $file['kind'] ?? '' ) ) {
					$ids[] = (string) $file['id'];
				}
			}
		}

		return $ids;
	}

	/**
	 * @return object|null
	 */
	private function find_by_package_folder( string $package_folder_id, string $source ) {
		$rows = $this->repository->list_by_statuses( Document_Status::inbox_statuses(), 200 );
		foreach ( $rows as $row ) {
			if ( (string) $row->source !== $source ) {
				continue;
			}
			$meta = $this->repository->decode_metadata( $row );
			if ( (string) ( $meta['package_folder_id'] ?? '' ) === $package_folder_id ) {
				return $row;
			}
		}

		return null;
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
