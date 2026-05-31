<?php
/**
 * Low-level Google Drive API via wp_remote_*.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Api;

use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Import\Docx_Content;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Drive v3 REST client.
 */
final class Google_Drive_Client {

	private const API_BASE = 'https://www.googleapis.com/drive/v3';

	/**
	 * @var Google_OAuth
	 */
	private $oauth;

	public function __construct( Google_OAuth $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * Reserved workflow folder names under the Drive root.
	 */
	public const SYSTEM_FOLDER_NAMES = array( 'incoming', 'published', 'failed' );

	/**
	 * List child folders in a parent folder.
	 *
	 * @param string        $parent_id     Parent folder id.
	 * @param array<string> $exclude_names Folder names to skip (case-insensitive).
	 * @return array<int, array{id: string, name: string}>|WP_Error
	 */
	public function list_child_folders( string $parent_id, array $exclude_names = array() ) {
		$q = sprintf(
			"'%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'",
			$parent_id
		);

		$response = $this->request(
			'GET',
			'/files',
			array(
				'q'        => $q,
				'fields'   => 'files(id,name)',
				'pageSize' => 100,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$exclude = array_map( 'strtolower', $exclude_names );
		$folders = array();

		foreach ( (array) ( $response['files'] ?? array() ) as $file ) {
			$name = (string) ( $file['name'] ?? '' );
			if ( '' === $name || in_array( strtolower( $name ), $exclude, true ) ) {
				continue;
			}

			$id = (string) ( $file['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$folders[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		return $folders;
	}

	/**
	 * List image files in a folder.
	 *
	 * @param string $folder_id Parent folder id.
	 * @return array<int, array{id: string, name: string, mimeType?: string}>|WP_Error
	 */
	public function list_images_in_folder( string $folder_id ) {
		$q = sprintf(
			"'%s' in parents and trashed = false and mimeType contains 'image/'",
			$folder_id
		);

		$response = $this->request(
			'GET',
			'/files',
			array(
				'q'        => $q,
				'fields'   => 'files(id,name,mimeType,modifiedTime)',
				'pageSize' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['files'] ) && is_array( $response['files'] ) ? $response['files'] : array();
	}

	/**
	 * Fetch file metadata.
	 *
	 * @param string $file_id File id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_file( string $file_id ) {
		$response = $this->request(
			'GET',
			'/files/' . rawurlencode( $file_id ),
			array(
				'fields' => 'id,name,parents,mimeType',
			)
		);

		return is_wp_error( $response ) ? $response : $response;
	}

	/**
	 * Download raw file bytes (images, Office files, etc.).
	 *
	 * @param string $file_id File id.
	 * @return string|WP_Error
	 */
	public function download_file( string $file_id ) {
		$token = $this->oauth->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = sprintf(
			'https://www.googleapis.com/drive/v3/files/%s?alt=media',
			rawurlencode( $file_id )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'forwp_drive_download_failed', __( 'Failed to download file from Google Drive.', '4wp-drive' ), array( 'status' => $code ) );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Rename a Drive file.
	 *
	 * @param string $file_id File id.
	 * @param string $name    New filename.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_file_name( string $file_id, string $name ) {
		return $this->request(
			'PATCH',
			'/files/' . rawurlencode( $file_id ),
			array(
				'fields' => 'id,name',
			),
			array(
				'name' => $name,
			)
		);
	}

	/**
	 * List Google Docs in a folder.
	 *
	 * @param string $folder_id Parent folder id.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_documents_in_folder( string $folder_id ) {
		$q = sprintf(
			"'%s' in parents and trashed = false and ("
			. "mimeType = 'application/vnd.google-apps.document' "
			. "or mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'"
			. ')',
			$folder_id
		);

		$response = $this->request(
			'GET',
			'/files',
			array(
				'q'        => $q,
				'fields'   => 'files(id,name,mimeType,modifiedTime,md5Checksum)',
				'pageSize' => 50,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['files'] ) && is_array( $response['files'] ) ? $response['files'] : array();
	}

	/**
	 * Export a Google Doc as HTML.
	 *
	 * @param string $file_id File id.
	 * @return string|WP_Error
	 */
	public function export_document_html( string $file_id ) {
		$token = $this->oauth->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = sprintf(
			'https://www.googleapis.com/drive/v3/files/%s/export?mimeType=%s',
			rawurlencode( $file_id ),
			rawurlencode( 'text/html' )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = is_array( $body ) && isset( $body['error']['message'] )
				? (string) $body['error']['message']
				: __( 'Failed to export document as HTML.', '4wp-drive' );

			return new WP_Error( 'forwp_drive_export_failed', $message, array( 'status' => $code ) );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch document content as HTML (Google Docs) or plain text (.docx).
	 *
	 * @param string $file_id   Drive file id.
	 * @param string $mime_type Optional mimeType from list_files.
	 * @return string|WP_Error
	 */
	public function fetch_document_content( string $file_id, string $mime_type = '' ) {
		if ( '' === $mime_type ) {
			$file = $this->get_file( $file_id );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$mime_type = (string) ( $file['mimeType'] ?? '' );
		}

		if ( 'application/vnd.google-apps.document' === $mime_type ) {
			return $this->export_document_html( $file_id );
		}

		if ( Docx_Content::MIME === $mime_type ) {
			$via_google = $this->export_docx_via_google_conversion( $file_id );
			if ( ! is_wp_error( $via_google ) ) {
				return $via_google;
			}

			// Fallback when Drive conversion is unavailable (quota, permissions, etc.).
			$binary = $this->download_file( $file_id );
			if ( is_wp_error( $binary ) ) {
				return $binary;
			}

			return Docx_Content::extract_html_document( $binary );
		}

		return new WP_Error(
			'forwp_drive_unsupported_mime',
			__( 'Unsupported document type. Use a Google Doc or .docx file.', '4wp-drive' )
		);
	}

	/**
	 * Convert a .docx on Drive to a temporary Google Doc and export HTML (best formatting fidelity).
	 *
	 * @param string $file_id Original .docx file id (unchanged on Drive).
	 * @return string|WP_Error
	 */
	public function export_docx_via_google_conversion( string $file_id ) {
		$copy = $this->request(
			'POST',
			'/files/' . rawurlencode( $file_id ) . '/copy',
			array(
				'fields'          => 'id',
				'supportsAllDrives' => 'true',
			),
			array(
				'name'     => 'forwp-drive-temp-' . substr( md5( $file_id . (string) time() ), 0, 8 ),
				'mimeType' => 'application/vnd.google-apps.document',
			)
		);

		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		$copy_id = (string) ( $copy['id'] ?? '' );
		if ( '' === $copy_id ) {
			return new WP_Error( 'forwp_drive_docx_convert', __( 'Google Drive could not convert .docx to Google Docs format.', '4wp-drive' ) );
		}

		$html = $this->export_document_html( $copy_id );
		$this->trash_file( $copy_id );

		return $html;
	}

	/**
	 * Move a file to Drive trash (used for temporary conversion copies).
	 *
	 * @param string $file_id File id.
	 */
	public function trash_file( string $file_id ): void {
		$this->request( 'PATCH', '/files/' . rawurlencode( $file_id ), array(), array( 'trashed' => true ) );
	}

	/**
	 * Move file from one folder to another.
	 *
	 * @param string $file_id        File id.
	 * @param string $add_parent_id  Destination folder.
	 * @param string $remove_parent_id Source folder.
	 * @return array<string, mixed>|WP_Error
	 */
	public function move_file( string $file_id, string $add_parent_id, string $remove_parent_id ) {
		return $this->request(
			'PATCH',
			'/files/' . rawurlencode( $file_id ),
			array(
				'addParents'    => $add_parent_id,
				'removeParents' => $remove_parent_id,
				'fields'        => 'id, parents',
			)
		);
	}

	/**
	 * Find or create a child folder by name.
	 *
	 * @param string $parent_id Parent folder id.
	 * @param string $name      Folder name.
	 * @return string|WP_Error Folder id.
	 */
	public function find_or_create_folder( string $parent_id, string $name ) {
		$q = sprintf(
			"'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and name = '%s' and trashed = false",
			$parent_id,
			str_replace( "'", "\\'", $name )
		);

		$response = $this->request(
			'GET',
			'/files',
			array(
				'q'      => $q,
				'fields' => 'files(id)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['files'][0]['id'] ) ) {
			return (string) $response['files'][0]['id'];
		}

		$create = $this->request(
			'POST',
			'/files',
			array(),
			array(
				'name'     => $name,
				'mimeType' => 'application/vnd.google-apps.folder',
				'parents'  => array( $parent_id ),
			)
		);

		if ( is_wp_error( $create ) ) {
			return $create;
		}

		return isset( $create['id'] ) ? (string) $create['id'] : new WP_Error( 'forwp_drive_folder_create', __( 'Could not create Drive folder.', '4wp-drive' ) );
	}

	/**
	 * @param string               $method HTTP method.
	 * @param string               $path   API path.
	 * @param array<string, mixed> $query  Query args.
	 * @param array<string, mixed> $body   JSON body for POST/PATCH.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, array $query = array(), array $body = array() ) {
		$token = $this->oauth->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: __( 'Google Drive API error.', '4wp-drive' );

			return new WP_Error( 'forwp_drive_api_error', $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
