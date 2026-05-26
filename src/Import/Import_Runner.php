<?php
/**
 * Import a ready document as a WordPress draft.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Source_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates import + Drive file move.
 */
final class Import_Runner {

	/**
	 * @var Document_Repository
	 */
	private $repository;

	public function __construct( ?Document_Repository $repository = null ) {
		$this->repository = $repository ?? new Document_Repository();
	}

	/**
	 * @param int $document_id Row id.
	 * @return array{post_id: int, edit_url: string}|WP_Error
	 */
	public function import( int $document_id ) {
		$row = $this->repository->find( $document_id );
		if ( ! $row ) {
			return new WP_Error( 'forwp_drive_not_found', __( 'Document not found.', '4wp-drive' ), array( 'status' => 404 ) );
		}

		if ( ! Document_Status::can_import( (string) $row->status ) ) {
			return new WP_Error( 'forwp_drive_invalid_status', __( 'Document cannot be imported in its current state.', '4wp-drive' ) );
		}

		$this->repository->update(
			$document_id,
			array(
				'status'     => Document_Status::IMPORTING,
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		$metadata = $this->repository->decode_metadata( $row );
		$creator  = new Post_Creator();
		$post_id  = $creator->create_draft( $metadata );

		if ( is_wp_error( $post_id ) ) {
			$this->fail( $document_id, (string) $row->file_id, $post_id->get_error_message() );

			return $post_id;
		}

		$source = Source_Registry::get( (string) $row->source );
		if ( $source ) {
			$moved = $source->move_after_import( (string) $row->file_id, 'published' );
			if ( is_wp_error( $moved ) ) {
				$this->repository->update(
					$document_id,
					array(
						'status'        => Document_Status::IMPORTED,
						'wp_post_id'    => $post_id,
						'imported_at'   => current_time( 'mysql', true ),
						'updated_at'    => current_time( 'mysql', true ),
						'error_message' => $moved->get_error_message(),
					)
				);

				return array(
					'post_id'  => $post_id,
					'edit_url' => get_edit_post_link( $post_id, 'raw' ),
					'warning'  => $moved->get_error_message(),
				);
			}
		}

		$now = current_time( 'mysql', true );
		$this->repository->update(
			$document_id,
			array(
				'status'      => Document_Status::IMPORTED,
				'wp_post_id'  => $post_id,
				'imported_at' => $now,
				'updated_at'  => $now,
			)
		);

		/**
		 * Fires after a document is imported.
		 *
		 * @param int $post_id     WordPress post id.
		 * @param int $document_id Document row id.
		 */
		do_action( 'forwp_drive_document_imported', $post_id, $document_id );

		return array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * @param int    $document_id Row id.
	 * @param string $file_id     Drive file id.
	 * @param string $message     Error message.
	 */
	private function fail( int $document_id, string $file_id, string $message ): void {
		$this->repository->update(
			$document_id,
			array(
				'status'        => Document_Status::FAILED,
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			)
		);

		$source = Source_Registry::get_default();
		if ( $source ) {
			$source->move_after_import( $file_id, 'failed' );
		}
	}
}
