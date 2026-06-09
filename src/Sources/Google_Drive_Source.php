<?php
/**
 * Google Drive storage source.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Sources;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Contracts\Storage_Source_Interface;
use ForWP\Drive\Import\Featured_Image_Importer;
use ForWP\Drive\Parse\Template_Parser;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Google Drive implementation.
 */
final class Google_Drive_Source implements Storage_Source_Interface {

	public const SLUG = 'google_drive';

	/**
	 * @inheritDoc
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Google Drive', '4wp-drive' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_ready(): bool {
		$oauth = Google_OAuth::instance();

		return $oauth->has_client_config() && $oauth->is_connected() && '' !== Settings::instance()->get_folder_id( 'incoming' );
	}

	/**
	 * @inheritDoc
	 */
	public function resolve_folders( string $root_id ) {
		$client = new Google_Drive_Client( Google_OAuth::instance() );

		$incoming = $client->find_or_create_folder( $root_id, 'incoming' );
		if ( is_wp_error( $incoming ) ) {
			return $incoming;
		}

		$published = $client->find_or_create_folder( $root_id, 'published' );
		if ( is_wp_error( $published ) ) {
			return $published;
		}

		$failed = $client->find_or_create_folder( $root_id, 'failed' );
		if ( is_wp_error( $failed ) ) {
			return $failed;
		}

		return array(
			'root'      => $root_id,
			'incoming'  => $incoming,
			'published' => $published,
			'failed'    => $failed,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function scan_incoming() {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'forwp_drive_not_ready', __( 'Google Drive is not configured.', '4wp-drive' ) );
		}

		$settings    = Settings::instance();
		$incoming_id = $settings->get_folder_id( 'incoming' );
		$client      = new Google_Drive_Client( Google_OAuth::instance() );
		$parser      = new Template_Parser();
		$results     = array();
		$seen_docs   = array();

		if ( '' === $incoming_id ) {
			return array();
		}

		$incoming_folders = $client->list_child_folders( $incoming_id, array() );
		if ( is_wp_error( $incoming_folders ) ) {
			return $incoming_folders;
		}

		foreach ( $incoming_folders as $folder ) {
			$item = $this->scan_package_folder( $client, $parser, (string) $folder['id'], (string) $folder['name'] );
			if ( null === $item ) {
				continue;
			}

			$file_id = (string) ( $item['file_id'] ?? '' );
			if ( '' === $file_id || isset( $seen_docs[ $file_id ] ) ) {
				continue;
			}

			$seen_docs[ $file_id ] = true;
			$results[]             = $item;
		}

		$flat = $client->list_documents_in_folder( $incoming_id );
		if ( is_wp_error( $flat ) ) {
			return $flat;
		}

		foreach ( $flat as $file ) {
			$file_id = (string) ( $file['id'] ?? '' );
			if ( '' === $file_id || isset( $seen_docs[ $file_id ] ) ) {
				continue;
			}

			$item = $this->scan_document_file(
				$client,
				$parser,
				$file_id,
				(string) ( $file['name'] ?? '' ),
				'',
				null,
				(string) ( $file['mimeType'] ?? '' )
			);

			if ( null !== $item ) {
				$seen_docs[ $file_id ] = true;
				$results[]             = $item;
			}
		}

		return $results;
	}

	/**
	 * @inheritDoc
	 */
	public function move_after_import( string $file_id, string $target_role, array $metadata = array() ) {
		$folders = Settings::instance()->get_folder_ids();
		$to      = $folders[ $target_role ] ?? '';

		if ( '' === $to ) {
			return new WP_Error( 'forwp_drive_folders', __( 'Folder mapping is incomplete.', '4wp-drive' ) );
		}

		$client            = new Google_Drive_Client( Google_OAuth::instance() );
		$package_folder_id = (string) ( $metadata['package_folder_id'] ?? '' );
		$image_file_id     = (string) ( $metadata['image_file_id'] ?? '' );
		$slug              = (string) ( $metadata['slug'] ?? '' );

		if ( '' !== $image_file_id && '' !== $slug ) {
			$image_name = (string) ( $metadata['image_file_name'] ?? '' );
			$drive_name = Featured_Image_Importer::build_filename( $slug, $image_name );
			$renamed    = $client->update_file_name( $image_file_id, $drive_name );
			if ( is_wp_error( $renamed ) ) {
				return $renamed;
			}
		}

		if ( '' !== $package_folder_id && $package_folder_id !== ( $folders['incoming'] ?? '' ) ) {
			$from = $this->resolve_remove_parent( $client, $package_folder_id, $folders );

			return is_wp_error( $from )
				? $from
				: $client->move_file( $package_folder_id, $to, $from );
		}

		$from = $folders['incoming'] ?? '';
		if ( '' === $from ) {
			return new WP_Error( 'forwp_drive_folders', __( 'Folder mapping is incomplete.', '4wp-drive' ) );
		}

		$moved = $client->move_file( $file_id, $to, $from );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		if ( '' !== $image_file_id && $from ) {
			$image_moved = $client->move_file( $image_file_id, $to, $from );
			if ( is_wp_error( $image_moved ) ) {
				return $image_moved;
			}
		}

		return true;
	}

	/**
	 * Scan one article package folder (document + optional image).
	 *
	 * @param Google_Drive_Client $client      Drive client.
	 * @param Template_Parser     $parser      Template parser.
	 * @param string              $folder_id   Package folder id.
	 * @param string              $folder_name Folder label for display.
	 * @return array<string, mixed>|null
	 */
	private function scan_package_folder( Google_Drive_Client $client, Template_Parser $parser, string $folder_id, string $folder_name ): ?array {
		$docs = $client->list_documents_in_folder( $folder_id );
		if ( is_wp_error( $docs ) || empty( $docs ) ) {
			return null;
		}

		$doc    = $docs[0];
		$doc_id = (string) ( $doc['id'] ?? '' );
		if ( '' === $doc_id ) {
			return null;
		}

		$images = $client->list_images_in_folder( $folder_id );
		$image  = ( is_array( $images ) && ! empty( $images ) ) ? $images[0] : null;

		return $this->scan_document_file(
			$client,
			$parser,
			$doc_id,
			(string) ( $doc['name'] ?? $folder_name ),
			$folder_id,
			$image,
			(string) ( $doc['mimeType'] ?? '' )
		);
	}

	/**
	 * Export and parse a document; attach package/image metadata when present.
	 *
	 * @param Google_Drive_Client       $client      Drive client.
	 * @param Template_Parser           $parser      Template parser.
	 * @param string                    $file_id     Document file id.
	 * @param string                    $file_name   Document filename.
	 * @param string                    $package_folder_id Article subfolder in incoming (empty for flat docs).
	 * @param array<string, mixed>|null $image           Drive image file row.
	 * @return array<string, mixed>|null
	 */
	private function scan_document_file(
		Google_Drive_Client $client,
		Template_Parser $parser,
		string $file_id,
		string $file_name,
		string $package_folder_id,
		?array $image,
		string $mime_type = ''
	): ?array {
		$raw = $client->fetch_document_content( $file_id, $mime_type );

		$meta_extra = array(
			'image_file_id'   => is_array( $image ) ? (string) ( $image['id'] ?? '' ) : '',
			'image_file_name' => is_array( $image ) ? (string) ( $image['name'] ?? '' ) : '',
		);
		if ( '' !== $package_folder_id ) {
			$meta_extra['package_folder_id'] = $package_folder_id;
		}

		if ( is_wp_error( $raw ) ) {
			return array(
				'file_id'       => $file_id,
				'file_name'     => $file_name,
				'content_hash'  => 'export-error-' . md5( $file_id ),
				'export_failed' => true,
				'metadata'      => array_merge(
					array(
						'title'      => $file_name ?: __( 'Untitled', '4wp-drive' ),
						'scan_error' => $raw->get_error_message(),
					),
					$meta_extra
				),
			);
		}

		$hash = md5( (string) $raw );
		if ( is_array( $image ) && ! empty( $image['modifiedTime'] ) ) {
			$hash = md5( $hash . (string) $image['modifiedTime'] );
		}

		$meta = $parser->parse( (string) $raw );

		if ( '' === $meta['title'] ) {
			$meta['title'] = $file_name ?: __( 'Untitled', '4wp-drive' );
		}

		$meta = array_merge( $meta, $meta_extra );

		return array(
			'file_id'      => $file_id,
			'file_name'    => $file_name,
			'content_hash' => $hash,
			'metadata'     => $meta,
		);
	}

	/**
	 * Parent folder to remove when moving a package folder into published/failed.
	 *
	 * @param Google_Drive_Client   $client Drive client.
	 * @param string                $folder_id Package folder id.
	 * @param array<string, string> $folders Configured folder ids.
	 * @return string|WP_Error
	 */
	private function resolve_remove_parent( Google_Drive_Client $client, string $folder_id, array $folders ) {
		$file = $client->get_file( $folder_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$parents = isset( $file['parents'] ) && is_array( $file['parents'] ) ? $file['parents'] : array();
		if ( ! empty( $parents[0] ) ) {
			return (string) $parents[0];
		}

		$root = $folders['root'] ?? '';
		if ( '' !== $root ) {
			return $root;
		}

		return new WP_Error( 'forwp_drive_parent', __( 'Could not determine Drive folder parent.', '4wp-drive' ) );
	}
}
