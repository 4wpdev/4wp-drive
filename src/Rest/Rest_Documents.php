<?php
/**
 * REST: document inbox and import.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Rest;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Import\Import_Runner;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Document inbox routes.
 */
final class Rest_Documents {

	private const NAMESPACE = 'forwp-drive/v1';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/documents',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_documents' ),
					'permission_callback' => array( self::class, 'can_edit' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_document' ),
					'permission_callback' => array( self::class, 'can_edit' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\d+)/import',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'import_document' ),
					'permission_callback' => array( self::class, 'can_edit' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\d+)/reject',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'reject_document' ),
					'permission_callback' => array( self::class, 'can_edit' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET documents list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function list_documents( WP_REST_Request $request ): WP_REST_Response {
		$status   = $request->get_param( 'status' );
		$statuses = Document_Status::inbox_statuses();

		if ( is_string( $status ) && '' !== $status ) {
			$statuses = array( sanitize_key( $status ) );
		}

		$repo = new Document_Repository();
		$rows = $repo->list_by_statuses( $statuses, 50 );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::serialize_row( $repo, $row );
		}

		$last_sync = Settings::instance()->get_last_sync();
		$folders   = Settings::instance()->get_folder_ids();

		return new WP_REST_Response(
			array(
				'documents'   => $items,
				'last_sync'   => $last_sync,
				'incoming_id' => isset( $folders['incoming'] ) ? (string) $folders['incoming'] : '',
			),
			200
		);
	}

	/**
	 * GET single document preview.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_document( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request['id'];
		$repo = new Document_Repository();
		$row  = $repo->find( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'message' => __( 'Not found.', '4wp-drive' ) ), 404 );
		}

		return new WP_REST_Response( self::serialize_row( $repo, $row, true ), 200 );
	}

	/**
	 * POST import.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function import_document( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request['id'];
		$result = ( new Import_Runner() )->import( $id );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST reject.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function reject_document( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request['id'];
		$repo = new Document_Repository();
		$row  = $repo->find( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'message' => __( 'Not found.', '4wp-drive' ) ), 404 );
		}

		$repo->update(
			$id,
			array(
				'status'     => Document_Status::REJECTED,
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		return new WP_REST_Response( array( 'message' => __( 'Document rejected.', '4wp-drive' ) ), 200 );
	}

	/**
	 * @param Document_Repository $repo Repository.
	 * @param object              $row  Row.
	 * @param bool                $full Include body preview.
	 * @return array<string, mixed>
	 */
	private static function serialize_row( Document_Repository $repo, $row, bool $full = false ): array {
		$meta = $repo->decode_metadata( $row );

		$data = array(
			'id'          => (int) $row->id,
			'file_id'     => (string) $row->file_id,
			'file_name'   => (string) $row->file_name,
			'status'      => (string) $row->status,
			'title'       => (string) ( $meta['title'] ?? '' ),
			'slug'        => (string) ( $meta['slug'] ?? '' ),
			'date'        => (string) ( $meta['date'] ?? '' ),
			'author'      => (string) ( $meta['author'] ?? '' ),
			'category'    => (string) ( $meta['category'] ?? '' ),
			'tags'        => $meta['tags'] ?? array(),
			'has_image'   => ! empty( $meta['image_file_id'] ),
			'image_name'  => (string) ( $meta['image_file_name'] ?? '' ),
			'scan_error'  => (string) ( $meta['scan_error'] ?? $row->error_message ?? '' ),
			'detected_at' => (string) $row->detected_at,
			'wp_post_id'  => $row->wp_post_id ? (int) $row->wp_post_id : null,
		);

		if ( $full ) {
			$data['body_html'] = isset( $meta['body_html'] ) ? (string) $meta['body_html'] : '';
			$data['body']      = isset( $meta['body'] ) ? (string) $meta['body'] : '';
		}

		return $data;
	}
}
