<?php
/**
 * Import a ready document as a WordPress draft.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Multilingual\Language_Provider_Registry;
use ForWP\Drive\Source_Registry;
use ForWP\Drive\Parse\Template_Config;
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
	 * @param int                  $document_id Row id.
	 * @param array<string, mixed> $options     mode (create|update), target_post_id.
	 * @return array{post_id: int, edit_url: string, mode: string, updated?: bool}|WP_Error
	 */
	public function import( int $document_id, array $options = array() ) {
		$row = $this->repository->find( $document_id );
		if ( ! $row ) {
			return new WP_Error( 'forwp_drive_not_found', __( 'Document not found.', '4wp-drive' ), array( 'status' => 404 ) );
		}

		if ( ! Document_Status::can_import( (string) $row->status ) ) {
			return new WP_Error( 'forwp_drive_invalid_status', __( 'Document cannot be imported in its current state.', '4wp-drive' ) );
		}

		$lang = Import_Language_Resolver::resolve(
			isset( $options['language'] ) ? (string) $options['language'] : ''
		);
		if ( is_wp_error( $lang ) ) {
			return $lang;
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
		$config   = new Template_Config();
		$mode     = isset( $options['mode'] ) ? sanitize_key( (string) $options['mode'] ) : 'create';
		$updated  = false;

		if ( 'update' === $mode ) {
			$target_id = isset( $options['target_post_id'] ) ? (int) $options['target_post_id'] : 0;
			$target_id = Import_Target_Resolver::resolve_for_import( $target_id, $config->get_import_post_type(), $lang );
			if ( is_wp_error( $target_id ) ) {
				$this->repository->update(
					$document_id,
					array(
						'status'     => Document_Status::READY,
						'updated_at' => current_time( 'mysql', true ),
					)
				);

				return $target_id;
			}

			if ( ! current_user_can( 'edit_post', $target_id ) ) {
				$this->repository->update(
					$document_id,
					array(
						'status'     => Document_Status::READY,
						'updated_at' => current_time( 'mysql', true ),
					)
				);

				return new WP_Error(
					'forwp_drive_cannot_edit_target',
					__( 'You do not have permission to edit the selected post.', '4wp-drive' ),
					array( 'status' => 403 )
				);
			}

			$post_id = $creator->update_existing( $target_id, $metadata );
			$updated = true;
		} else {
			$pto = get_post_type_object( $config->get_import_post_type() );
			if ( $pto && ! current_user_can( $pto->cap->create_posts ) ) {
				$this->repository->update(
					$document_id,
					array(
						'status'     => Document_Status::READY,
						'updated_at' => current_time( 'mysql', true ),
					)
				);

				return new WP_Error(
					'forwp_drive_cannot_create',
					__( 'You do not have permission to create posts for this import type.', '4wp-drive' ),
					array( 'status' => 403 )
				);
			}

			$post_id = $creator->create_draft( $metadata );
		}

		if ( is_wp_error( $post_id ) ) {
			$this->fail( $document_id, (string) $row->file_id, $post_id->get_error_message(), $metadata );

			return $post_id;
		}

		if ( '' !== $lang ) {
			Language_Provider_Registry::get_active()->assign_post_language( (int) $post_id, $lang );
		}

		$image_warning    = $this->maybe_attach_featured_image( $metadata, (int) $post_id );
		$metadata['slug'] = (string) get_post_field( 'post_name', $post_id );

		$source = Source_Registry::get( (string) $row->source );
		if ( $source ) {
			$moved = $source->move_after_import( (string) $row->file_id, 'published', $metadata );
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
					'mode'     => $mode,
					'updated'  => $updated,
					'warning'  => trim( $moved->get_error_message() . ( $image_warning ? ' ' . $image_warning : '' ) ),
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

		$response = array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'mode'     => $mode,
			'updated'  => $updated,
		);

		if ( $image_warning ) {
			$response['warning'] = $image_warning;
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $metadata Parsed document metadata.
	 * @param int                  $post_id  Created post id.
	 */
	private function maybe_attach_featured_image( array $metadata, int $post_id ): string {
		$image_id = (string) ( $metadata['image_file_id'] ?? '' );
		if ( '' === $image_id ) {
			return '';
		}

		$slug   = (string) get_post_field( 'post_name', $post_id );
		$client = new Google_Drive_Client( Google_OAuth::instance() );
		$result = ( new Featured_Image_Importer( $client ) )->attach_from_drive(
			$image_id,
			(string) ( $metadata['image_file_name'] ?? '' ),
			$post_id,
			$slug
		);

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return '';
	}

	/**
	 * @param int                  $document_id Row id.
	 * @param string               $file_id     Drive file id.
	 * @param string               $message     Error message.
	 * @param array<string, mixed> $metadata    Scan metadata for package moves.
	 */
	private function fail( int $document_id, string $file_id, string $message, array $metadata = array() ): void {
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
			$source->move_after_import( $file_id, 'failed', $metadata );
		}
	}
}
