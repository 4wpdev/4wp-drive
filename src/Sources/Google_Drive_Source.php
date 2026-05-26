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

		$incoming_id = Settings::instance()->get_folder_id( 'incoming' );
		$client      = new Google_Drive_Client( Google_OAuth::instance() );
		$files       = $client->list_documents_in_folder( $incoming_id );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$parser  = new Template_Parser();
		$results = array();

		foreach ( $files as $file ) {
			$file_id = (string) ( $file['id'] ?? '' );
			if ( '' === $file_id ) {
				continue;
			}

			$file_name = (string) ( $file['name'] ?? '' );
			$html      = $client->export_document_html( $file_id );

			if ( is_wp_error( $html ) ) {
				$results[] = array(
					'file_id'       => $file_id,
					'file_name'     => $file_name,
					'content_hash'  => 'export-error-' . md5( $file_id ),
					'export_failed' => true,
					'metadata'      => array(
						'title'      => $file_name ?: __( 'Untitled', '4wp-drive' ),
						'scan_error' => $html->get_error_message(),
					),
				);
				continue;
			}

			$hash = md5( (string) $html );
			$meta = $parser->parse( (string) $html );

			if ( '' === $meta['title'] ) {
				$meta['title'] = $file_name ?: __( 'Untitled', '4wp-drive' );
			}

			$results[] = array(
				'file_id'      => $file_id,
				'file_name'    => $file_name,
				'content_hash' => $hash,
				'metadata'     => $meta,
			);
		}

		return $results;
	}

	/**
	 * @inheritDoc
	 */
	public function move_after_import( string $file_id, string $target_role ) {
		$folders = Settings::instance()->get_folder_ids();
		$from    = $folders['incoming'] ?? '';
		$to      = $folders[ $target_role ] ?? '';

		if ( '' === $from || '' === $to ) {
			return new WP_Error( 'forwp_drive_folders', __( 'Folder mapping is incomplete.', '4wp-drive' ) );
		}

		$client = new Google_Drive_Client( Google_OAuth::instance() );
		$moved  = $client->move_file( $file_id, $to, $from );

		return is_wp_error( $moved ) ? $moved : true;
	}
}
