<?php
/**
 * Import a ready document as a WordPress draft.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Api\GitHub_Client;
use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Database\Import_History_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Import\Featured_Image_Chooser;
use ForWP\Drive\Import\Package_Image_Importer;
use ForWP\Drive\Multilingual\Language_Provider_Registry;
use ForWP\Drive\Source_Registry;
use ForWP\Drive\Sources\GitHub_Source;
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
		if ( isset( $options['featured_image_file_id'] ) ) {
			$metadata = Featured_Image_Chooser::apply_override(
				$metadata,
				(string) $options['featured_image_file_id']
			);
		}
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
			$this->fail( $document_id, (string) $row->file_id, $post_id->get_error_message(), $metadata, (string) $row->source );

			return $post_id;
		}

		if ( '' !== $lang ) {
			Language_Provider_Registry::get_active()->assign_post_language( (int) $post_id, $lang );
		}

		$downloader    = $this->make_file_downloader( (string) $row->source );
		$image_warning = $this->maybe_attach_featured_image( $metadata, (int) $post_id, $downloader );
		$body_warning  = $this->maybe_apply_package_images( $metadata, (int) $post_id, $downloader );
		$metadata['slug'] = (string) get_post_field( 'post_name', $post_id );

		$source = Source_Registry::get( (string) $row->source );
		if ( $source ) {
			$moved = $source->move_after_import( (string) $row->file_id, 'published', $metadata );
			if ( is_wp_error( $moved ) ) {
				$imported_at = current_time( 'mysql', true );
				$this->repository->update(
					$document_id,
					array(
						'status'        => Document_Status::IMPORTED,
						'wp_post_id'    => $post_id,
						'imported_at'   => $imported_at,
						'updated_at'    => $imported_at,
						'error_message' => $moved->get_error_message(),
					)
				);

				$this->record_history( $row, $document_id, (int) $post_id, $mode, $metadata, $imported_at );

				return array(
					'post_id'  => $post_id,
					'edit_url' => get_edit_post_link( $post_id, 'raw' ),
					'mode'     => $mode,
					'updated'  => $updated,
					'warning'  => trim( $moved->get_error_message() . ( $image_warning ? ' ' . $image_warning : '' ) . ( $body_warning ? ' ' . $body_warning : '' ) ),
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

		$this->record_history( $row, $document_id, (int) $post_id, $mode, $metadata, $now );

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

		$combined_warning = trim( ( $image_warning ? $image_warning . ' ' : '' ) . $body_warning );
		if ( $combined_warning ) {
			$response['warning'] = $combined_warning;
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $metadata Parsed document metadata.
	 * @param int                  $post_id  Created post id.
	 * @param callable             $download File downloader.
	 */
	private function maybe_apply_package_images( array $metadata, int $post_id, callable $download ): string {
		return ( new Package_Image_Importer( $download ) )->apply_to_post( $post_id, $metadata );
	}

	/**
	 * @param array<string, mixed> $metadata Parsed document metadata.
	 * @param int                  $post_id  Created post id.
	 * @param callable             $download File downloader.
	 */
	private function maybe_attach_featured_image( array $metadata, int $post_id, callable $download ): string {
		$image_id = (string) ( $metadata['image_file_id'] ?? '' );
		if ( '' === $image_id ) {
			return '';
		}

		$slug   = (string) get_post_field( 'post_name', $post_id );
		$result = Featured_Image_Importer::sideload_from_bytes(
			$download( $image_id ),
			(string) ( $metadata['image_file_name'] ?? '' ),
			$post_id,
			$slug,
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return '';
	}

	/**
	 * Append one import-history row (source, post, aliases, incoming → published).
	 *
	 * @param object               $row         Inbox document row.
	 * @param array<string, mixed> $metadata    Scan metadata.
	 */
	private function record_history( $row, int $document_id, int $post_id, string $mode, array $metadata, string $imported_at ): void {
		( new Import_History_Repository() )->insert(
			Import_History_Recorder::build(
				array(
					'source'      => (string) ( $row->source ?? '' ),
					'post_id'     => $post_id,
					'post_type'   => (string) ( get_post_type( $post_id ) ?: 'post' ),
					'site_alias'  => (string) get_post_field( 'post_name', $post_id ),
					'document_id' => $document_id,
					'file_id'     => (string) ( $row->file_id ?? '' ),
					'mode'        => $mode,
					'metadata'    => $metadata,
					'imported_at' => $imported_at,
				)
			)
		);
	}

	/**
	 * Download bytes for a source file id (Drive id or `gh:owner/repo:path`).
	 *
	 * @return callable(string): (string|WP_Error)
	 */
	private function make_file_downloader( string $source_slug ): callable {
		if ( GitHub_Source::SLUG === $source_slug ) {
			$client = new GitHub_Client();

			return static function ( string $file_id ) use ( $client ) {
				$path = GitHub_Source::path_from_file_id( $file_id );
				if ( '' === $path ) {
					return new WP_Error( 'forwp_drive_github_path', __( 'Could not resolve GitHub file path.', '4wp-drive' ) );
				}

				return $client->get_file_contents( $path );
			};
		}

		$drive = new Google_Drive_Client( Google_OAuth::instance() );

		return static function ( string $file_id ) use ( $drive ) {
			return $drive->download_file( $file_id );
		};
	}

	/**
	 * @param int                  $document_id Row id.
	 * @param string               $file_id     Drive file id.
	 * @param string               $message     Error message.
	 * @param array<string, mixed> $metadata    Scan metadata for package moves.
	 * @param string               $source_slug Storage source slug.
	 */
	private function fail( int $document_id, string $file_id, string $message, array $metadata = array(), string $source_slug = '' ): void {
		$this->repository->update(
			$document_id,
			array(
				'status'        => Document_Status::FAILED,
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			)
		);

		$source = Source_Registry::get( $source_slug );
		if ( ! $source ) {
			$source = Source_Registry::get_default();
		}
		if ( $source ) {
			$source->move_after_import( $file_id, 'failed', $metadata );
		}
	}
}
