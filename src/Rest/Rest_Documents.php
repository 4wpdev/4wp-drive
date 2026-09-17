<?php
/**
 * REST: document inbox and import.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Rest;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Import\Featured_Image_Chooser;
use ForWP\Drive\Import\Google_Doc_Content;
use ForWP\Drive\Import\Import_Runner;
use ForWP\Drive\Import\Import_Target_Resolver;
use ForWP\Drive\Multilingual\Language_Provider_Registry;
use ForWP\Drive\Parse\Template_Config;
use ForWP\Drive\Source_Registry;
use WP_Post_Type;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Document inbox routes.
 */
final class Rest_Documents {

	private const NAMESPACE = 'forwp-drive/v1';

	/**
	 * Register REST hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_media_binary' ), 10, 4 );
	}

	/**
	 * Register document inbox routes.
	 *
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
					'permission_callback' => array( self::class, 'can_view_inbox' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/browse',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'browse_folder' ),
					'permission_callback' => array( self::class, 'can_view_inbox' ),
					'args'                => array(
						'source'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'folder_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'path'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'name'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/media',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_media' ),
					'permission_callback' => array( self::class, 'can_view_inbox' ),
					'args'                => array(
						'source'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'file_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'name'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_file_name',
						),
					),
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
					'permission_callback' => array( self::class, 'can_view_inbox' ),
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
					'permission_callback' => array( self::class, 'can_import' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import-targets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_import_targets' ),
					'permission_callback' => array( self::class, 'can_import' ),
					'args'                => array(
						'slug'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						),
						'title'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'lang'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'post_type' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
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
					'permission_callback' => array( self::class, 'can_manage_inbox' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\d+)/source',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'select_source_file' ),
					'permission_callback' => array( self::class, 'can_import' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\d+)/body',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'update_body' ),
					'permission_callback' => array( self::class, 'can_import' ),
				),
			)
		);
	}

	/**
	 * List or preview inbox documents for the configured import post type.
	 *
	 * @return bool
	 */
	public static function can_view_inbox(): bool {
		$pto = self::import_post_type_object();
		if ( ! $pto ) {
			return false;
		}

		return current_user_can( $pto->cap->edit_posts );
	}

	/**
	 * Import or update posts for the configured post type.
	 *
	 * @return bool
	 */
	public static function can_import(): bool {
		$pto = self::import_post_type_object();
		if ( ! $pto ) {
			return false;
		}

		return current_user_can( $pto->cap->edit_posts ) && current_user_can( 'upload_files' );
	}

	/**
	 * Reject changes shared inbox workflow state.
	 *
	 * @return bool
	 */
	public static function can_manage_inbox(): bool {
		$pto = self::import_post_type_object();
		if ( ! $pto ) {
			return false;
		}

		return current_user_can( $pto->cap->edit_others_posts );
	}

	/**
	 * Post type object for the configured import destination.
	 *
	 * @return WP_Post_Type|null
	 */
	private static function import_post_type_object(): ?WP_Post_Type {
		$post_type = ( new Template_Config() )->get_import_post_type();
		$object    = get_post_type_object( $post_type );

		if ( $object instanceof WP_Post_Type ) {
			return $object;
		}

		$fallback = get_post_type_object( 'post' );

		return $fallback instanceof WP_Post_Type ? $fallback : null;
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
		$filter_source = sanitize_key( (string) $request->get_param( 'source' ) );

		$items = array();
		foreach ( $rows as $row ) {
			if ( '' !== $filter_source && (string) $row->source !== $filter_source ) {
				continue;
			}
			$items[] = self::serialize_row( $repo, $row );
		}

		$last_sync = Settings::instance()->get_last_sync();
		$folders   = Settings::instance()->get_folder_ids();

		$browse_trees = array();
		foreach ( Source_Registry::all() as $slug => $source ) {
			$slug = (string) $slug;
			if ( '' !== $filter_source && $slug !== $filter_source ) {
				continue;
			}
			if ( ! $source->is_ready() || ! method_exists( $source, 'browse_tree' ) ) {
				continue;
			}
			$tree = $source->browse_tree();
			if ( is_wp_error( $tree ) ) {
				$browse_trees[ $slug ] = array(
					'folders' => array(),
					'files'   => array(),
					'error'   => $tree->get_error_message(),
				);
				continue;
			}
			$browse_trees[ $slug ] = is_array( $tree ) ? $tree : array(
				'folders' => array(),
				'files'   => array(),
			);
		}

		return new WP_REST_Response(
			array(
				'documents'        => $items,
				'browse_trees'     => $browse_trees,
				'last_sync'        => $last_sync,
				'incoming_id'      => isset( $folders['incoming'] ) ? (string) $folders['incoming'] : '',
				'drive_connection' => Google_OAuth::instance()->get_connection_payload(),
				'source_status'    => Source_Registry::get_inbox_status(),
				'multilingual'     => Language_Provider_Registry::get_rest_payload(),
			),
			200
		);
	}

	/**
	 * GET one folder listing for lazy inbox tree.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function browse_folder( WP_REST_Request $request ): WP_REST_Response {
		$slug   = sanitize_key( (string) $request->get_param( 'source' ) );
		$source = Source_Registry::get( $slug );
		if ( ! $source || ! method_exists( $source, 'browse_folder' ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'This source does not support folder browse.', '4wp-drive' ) ),
				400
			);
		}

		$node = $source->browse_folder(
			(string) $request->get_param( 'folder_id' ),
			(string) $request->get_param( 'path' ),
			(string) $request->get_param( 'name' )
		);

		if ( is_wp_error( $node ) ) {
			return new WP_REST_Response(
				array( 'message' => $node->get_error_message() ),
				400
			);
		}

		return new WP_REST_Response( $node, 200 );
	}

	/**
	 * Stream an image from Drive or GitHub for the inbox workspace.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_media( WP_REST_Request $request ): WP_REST_Response {
		$fetched = Media_Preview::fetch(
			(string) $request->get_param( 'source' ),
			(string) $request->get_param( 'file_id' ),
			(string) $request->get_param( 'name' )
		);

		if ( is_wp_error( $fetched ) ) {
			$data   = $fetched->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			if ( $status < 400 ) {
				$status = 400;
			}

			return new WP_REST_Response(
				array( 'message' => $fetched->get_error_message() ),
				$status
			);
		}

		$response = new WP_REST_Response( $fetched['bytes'], 200 );
		$response->header( 'Content-Type', $fetched['mime'] );
		$response->header(
			'Content-Disposition',
			'inline; filename="' . sanitize_file_name( $fetched['name'] ) . '"'
		);
		$response->header( 'Cache-Control', 'private, max-age=120' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Serve `/media` as raw bytes instead of JSON.
	 *
	 * @param bool                      $served  Whether the request has already been served.
	 * @param WP_REST_Response|WP_Error $result  Result to send to the client.
	 * @param WP_REST_Request           $request Request used to generate the response.
	 * @param \WP_REST_Server           $server  Server instance.
	 * @return bool
	 */
	public static function serve_media_binary( $served, $result, $request, $server ): bool {
		if ( $served ) {
			return true;
		}
		if ( ! $request instanceof WP_REST_Request ) {
			return (bool) $served;
		}
		if ( '/forwp-drive/v1/media' !== $request->get_route() ) {
			return (bool) $served;
		}
		if ( $result instanceof \WP_Error ) {
			return false;
		}
		if ( ! $result instanceof WP_REST_Response ) {
			return false;
		}
		if ( $result->get_status() < 200 || $result->get_status() >= 300 ) {
			return false;
		}
		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return false;
		}

		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image payload.

		return true;
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

		return new WP_REST_Response(
			array_merge(
				self::serialize_row( $repo, $row, true ),
				array(
					'multilingual' => Language_Provider_Registry::get_rest_payload(),
				)
			),
			200
		);
	}

	/**
	 * POST import.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function import_document( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request['id'];
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$options = array(
			'mode' => isset( $params['mode'] ) ? sanitize_key( (string) $params['mode'] ) : 'create',
		);
		if ( isset( $params['target_post_id'] ) ) {
			$options['target_post_id'] = (int) $params['target_post_id'];
		}
		if ( isset( $params['language'] ) ) {
			$options['language'] = sanitize_key( (string) $params['language'] );
		}
		if ( isset( $params['featured_image_file_id'] ) ) {
			$options['featured_image_file_id'] = sanitize_text_field( (string) $params['featured_image_file_id'] );
		}
		if ( isset( $params['post_type'] ) ) {
			$options['post_type'] = sanitize_key( (string) $params['post_type'] );
		}
		$options['keep_document_fonts'] = ! empty( $params['keep_document_fonts'] );

		$result = ( new Import_Runner() )->import( $id, $options );

		if ( is_wp_error( $result ) ) {
			$status = 500;
			$data   = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}

			return new WP_REST_Response(
				array( 'message' => $result->get_error_message() ),
				$status
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET import target posts for update mode.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function list_import_targets( WP_REST_Request $request ): WP_REST_Response {
		$config    = new Template_Config();
		$post_type = $config->resolve_import_post_type( (string) $request->get_param( 'post_type' ) );
		$result    = Import_Target_Resolver::suggest(
			$post_type,
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'title' ),
			(string) $request->get_param( 'search' ),
			30,
			(string) $request->get_param( 'lang' )
		);

		return new WP_REST_Response(
			array(
				'post_type'    => $post_type,
				'targets'      => $result['targets'],
				'suggested_id' => $result['suggested_id'],
				'multilingual' => Language_Provider_Registry::get_rest_payload(),
			),
			200
		);
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

		$metadata = $repo->decode_metadata( $row );
		$source   = Source_Registry::get( (string) $row->source );
		$warning  = '';

		if ( $source ) {
			$moved = $source->move_after_import( (string) $row->file_id, 'failed', $metadata );
			if ( is_wp_error( $moved ) ) {
				$warning = $moved->get_error_message();
			}
		}

		$update = array(
			'status'     => Document_Status::REJECTED,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( '' !== $warning ) {
			$update['error_message'] = $warning;
		}
		$repo->update( $id, $update );

		$response = array( 'message' => __( 'Document rejected.', '4wp-drive' ) );
		if ( '' !== $warning ) {
			$response['warning'] = $warning;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Choose which package file is the article body.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function select_source_file( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request['id'];
		$params  = $request->get_json_params();
		$file_id = is_array( $params ) ? sanitize_text_field( (string) ( $params['file_id'] ?? '' ) ) : '';
		$repo    = new Document_Repository();
		$row     = $repo->find( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'message' => __( 'Not found.', '4wp-drive' ) ), 404 );
		}

		if ( '' === $file_id ) {
			return new WP_REST_Response( array( 'message' => __( 'Missing file_id.', '4wp-drive' ) ), 400 );
		}

		$source = Source_Registry::get( (string) $row->source );
		if ( ! $source || ! method_exists( $source, 'rescan_source_file' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'This source cannot switch files.', '4wp-drive' ) ), 400 );
		}

		$meta = $repo->decode_metadata( $row );
		$item = array(
			'file_id'      => (string) $row->file_id,
			'file_name'    => (string) $row->file_name,
			'content_hash' => (string) $row->content_hash,
			'metadata'     => $meta,
		);

		$rescanned = $source->rescan_source_file( $item, $file_id );
		if ( ! is_array( $rescanned ) ) {
			return new WP_REST_Response( array( 'message' => __( 'That file is not in this package.', '4wp-drive' ) ), 400 );
		}

		$new_meta = isset( $rescanned['metadata'] ) && is_array( $rescanned['metadata'] ) ? $rescanned['metadata'] : $meta;
		$new_meta['selected_file_id'] = $file_id;

		$repo->update(
			$id,
			array(
				'file_id'       => (string) ( $rescanned['file_id'] ?? $file_id ),
				'file_name'     => (string) ( $rescanned['file_name'] ?? $row->file_name ),
				'content_hash'  => (string) ( $rescanned['content_hash'] ?? $row->content_hash ),
				'metadata_json' => wp_json_encode( $new_meta ),
				'updated_at'    => current_time( 'mysql', true ),
			)
		);

		$fresh = $repo->find( $id );

		return new WP_REST_Response( self::serialize_row( $repo, $fresh, true ), 200 );
	}

	/**
	 * Persist preview body (image pins insert [image:] markers).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function update_body( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request['id'];
		$params = $request->get_json_params();
		$html   = is_array( $params ) ? (string) ( $params['body_html'] ?? '' ) : '';
		$repo   = new Document_Repository();
		$row    = $repo->find( $id );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'message' => __( 'Not found.', '4wp-drive' ) ), 404 );
		}

		$meta              = $repo->decode_metadata( $row );
		$meta['body_html'] = wp_kses_post( $html );

		$repo->update(
			$id,
			array(
				'metadata_json' => wp_json_encode( $meta ),
				'updated_at'    => current_time( 'mysql', true ),
			)
		);

		$fresh = $repo->find( $id );

		return new WP_REST_Response( self::serialize_row( $repo, $fresh, true ), 200 );
	}

	/**
	 * Serialize inbox row for REST response.
	 *
	 * @param Document_Repository $repo Repository.
	 * @param object              $row  Row.
	 * @param bool                $full Include body preview.
	 * @return array<string, mixed>
	 */
	private static function serialize_row( Document_Repository $repo, $row, bool $full = false ): array {
		$meta = $repo->decode_metadata( $row );

		$package_files = array();
		if ( isset( $meta['package_files'] ) && is_array( $meta['package_files'] ) ) {
			foreach ( $meta['package_files'] as $file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}
				$name = sanitize_text_field( (string) ( $file['name'] ?? '' ) );
				$kind = sanitize_key( (string) ( $file['kind'] ?? 'file' ) );
				$file_id = sanitize_text_field( (string) ( $file['id'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$entry = array(
					'name' => $name,
					'kind' => $kind ? $kind : 'file',
				);
				if ( '' !== $file_id ) {
					$entry['id'] = $file_id;
				}
				$package_files[] = $entry;
			}
		}

		$docs_count   = 0;
		$images_count = 0;
		foreach ( $package_files as $file ) {
			if ( 'document' === $file['kind'] ) {
				++$docs_count;
			} elseif ( 'image' === $file['kind'] ) {
				++$images_count;
			}
		}

		$suggested_featured = Featured_Image_Chooser::suggest(
			Featured_Image_Chooser::images_from_metadata( $meta )
		);
		$image_file_id      = (string) ( $meta['image_file_id'] ?? '' );
		$image_file_name    = (string) ( $meta['image_file_name'] ?? '' );
		if ( '' === $image_file_id && null !== $suggested_featured ) {
			$image_file_id   = $suggested_featured['id'];
			$image_file_name = $suggested_featured['name'];
		}

		$data = array(
			'id'                    => (int) $row->id,
			'source'                => (string) $row->source,
			'file_id'               => (string) $row->file_id,
			'file_name'             => (string) $row->file_name,
			'status'                => (string) $row->status,
			'title'                 => (string) ( $meta['title'] ?? '' ),
			'slug'                  => (string) ( $meta['slug'] ?? '' ),
			'date'                  => (string) ( $meta['date'] ?? '' ),
			'author'                => (string) ( $meta['author'] ?? '' ),
			'category'              => (string) ( $meta['category'] ?? '' ),
			'tags'                  => $meta['tags'] ?? array(),
			'has_image'             => '' !== $image_file_id,
			'image_name'            => $image_file_name,
			'image_file_id'         => $image_file_id,
			'selected_file_id'      => (string) ( $meta['selected_file_id'] ?? $row->file_id ),
			'package_folder_id'     => (string) ( $meta['package_folder_id'] ?? '' ),
			'package_folder_name'   => (string) ( $meta['package_folder_name'] ?? '' ),
			'package_files'         => $package_files,
			'package_docs'          => $docs_count,
			'package_images'        => $images_count,
			'scan_error'            => (string) ( $meta['scan_error'] ?? $row->error_message ?? '' ),
			'detected_at'           => (string) $row->detected_at,
			'wp_post_id'            => $row->wp_post_id ? (int) $row->wp_post_id : null,
		);

		if ( $full ) {
			$stored = isset( $meta['body_html'] ) ? (string) $meta['body_html'] : '';
			$clean  = Google_Doc_Content::strip_presentational_markup( $stored );
			$data['body_html']                   = $clean;
			$data['body_html_with_fonts']        = $stored;
			$data['keep_document_fonts_available'] = $stored !== $clean && false !== stripos( $stored, 'font-family' );
			$data['body']                        = isset( $meta['body'] ) ? (string) $meta['body'] : '';
		}

		return $data;
	}
}
