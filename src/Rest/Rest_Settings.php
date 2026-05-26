<?php
/**
 * REST: settings and manual sync.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Rest;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Parse\Template_Config;
use ForWP\Drive\Source_Registry;
use ForWP\Drive\Sync\Incoming_Scanner;
use ForWP\Drive\Sync\Sync_Scheduler;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Settings routes.
 */
final class Rest_Settings {

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
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'update_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sync/run',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'run_sync' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET settings.
	 */
	public static function get_settings(): WP_REST_Response {
		$oauth       = Google_OAuth::instance();
		$credentials = $oauth->credentials();
		$source      = Source_Registry::get_default();
		$folders     = Settings::instance()->get_folder_ids();
		$auth_url    = $oauth->get_auth_url();
		$template    = new Template_Config();
		$post_type   = $template->get_import_post_type();

		return new WP_REST_Response(
			array(
				'sources'                    => Source_Registry::get_admin_status_rows(),
				'connected'                  => $oauth->is_connected(),
				'has_client_config'          => $oauth->has_client_config(),
				'google_client_id'           => $credentials->get_client_id(),
				'has_client_secret'          => $credentials->has_stored_secret(),
				'credentials_locked'         => $credentials->is_locked_by_wp_config(),
				'source_ready'               => $source ? $source->is_ready() : false,
				'folder_ids'                 => $folders,
				'last_sync'                  => Settings::instance()->get_last_sync(),
				'ready_count'                => Settings::instance()->get_ready_count(),
				'auth_url'                   => is_wp_error( $auth_url ) ? '' : $auth_url,
				'auth_url_error'             => is_wp_error( $auth_url ) ? $auth_url->get_error_message() : '',
				'redirect_uri'               => $oauth->get_redirect_uri(),
				'oauth_redirect_uri'         => $template->get_oauth_redirect_uri(),
				'oauth_redirect_uri_default' => admin_url( 'admin.php?page=forwp-drive-oauth' ),
				'oauth_redirect_uri_suggested' => $template->get_suggested_oauth_redirect_uri(),
				'local_dev_redirect_help'    => $template->needs_localhost_redirect_help(),
				'oauth_redirect_locked'      => defined( 'FORWP_DRIVE_OAUTH_REDIRECT_URI' ),
				'site_host'                  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'import_post_type'           => $post_type,
				'post_types'                 => Template_Config::get_importable_post_types(),
				'taxonomies'                 => Template_Config::get_taxonomies_for_post_type( $post_type ),
				'meta_fields'                => Template_Config::get_available_meta_fields(),
				'template_fields'            => $template->get_fields(),
				'sample_template'            => $template->build_sample_document(),
				'setup_links'                => Google_OAuth::get_setup_links(),
			),
			200
		);
	}

	/**
	 * POST settings — credentials and/or folder mapping.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$oauth    = Google_OAuth::instance();
		$messages = array();
		$errors   = array();

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( array_key_exists( 'google_client_id', $params ) || array_key_exists( 'google_client_secret', $params ) ) {
			$client_id     = sanitize_text_field( (string) ( $params['google_client_id'] ?? '' ) );
			$client_secret = (string) ( $params['google_client_secret'] ?? '' );

			$saved = $oauth->credentials()->save( $client_id, $client_secret );
			if ( is_wp_error( $saved ) ) {
				$errors[] = $saved->get_error_message();
			} else {
				$messages[] = __( 'Google API credentials saved.', '4wp-drive' );
			}
		}

		$root_id = isset( $params['root_folder_id'] ) ? sanitize_text_field( (string) $params['root_folder_id'] ) : '';
		if ( '' !== $root_id ) {
			$source = Source_Registry::get_default();
			if ( ! $source ) {
				$errors[] = __( 'No storage source.', '4wp-drive' );
			} elseif ( ! $oauth->is_connected() ) {
				$errors[] = __( 'Connect Google Drive before configuring folders.', '4wp-drive' );
			} else {
				$folders = $source->resolve_folders( $root_id );
				if ( is_wp_error( $folders ) ) {
					$errors[] = $folders->get_error_message();
				} else {
					Settings::instance()->set_folder_ids( $folders );
					Sync_Scheduler::schedule();
					$messages[] = __( 'Folders configured.', '4wp-drive' );
				}
			}
		}

		$template = new Template_Config();

		if ( array_key_exists( 'oauth_redirect_uri', $params ) ) {
			$template->set_oauth_redirect_uri( esc_url_raw( (string) $params['oauth_redirect_uri'] ) );
			$messages[] = __( 'OAuth redirect URI saved.', '4wp-drive' );
		}

		if ( array_key_exists( 'import_post_type', $params ) ) {
			$template->set_import_post_type( sanitize_key( (string) $params['import_post_type'] ) );
			$messages[] = __( 'Import post type saved.', '4wp-drive' );
		}

		if ( array_key_exists( 'template_fields', $params ) && is_array( $params['template_fields'] ) ) {
			$template->set_fields( $params['template_fields'] );
			$messages[] = __( 'Document template saved.', '4wp-drive' );
		}

		if ( empty( $messages ) && empty( $errors ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Nothing to save.', '4wp-drive' ) ),
				400
			);
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'message'  => implode( ' ', $errors ),
					'messages' => $messages,
					'errors'   => $errors,
				),
				500
			);
		}

		$auth_url = $oauth->get_auth_url();

		$post_type = $template->get_import_post_type();

		return new WP_REST_Response(
			array(
				'message'           => implode( ' ', $messages ),
				'messages'          => $messages,
				'has_client_config' => $oauth->has_client_config(),
				'auth_url'          => is_wp_error( $auth_url ) ? '' : $auth_url,
				'redirect_uri'      => $oauth->get_redirect_uri(),
				'folder_ids'        => Settings::instance()->get_folder_ids(),
				'import_post_type'  => $post_type,
				'template_fields'   => $template->get_fields(),
				'taxonomies'        => Template_Config::get_taxonomies_for_post_type( $post_type ),
				'sample_template'   => $template->build_sample_document(),
			),
			200
		);
	}

	/**
	 * POST manual sync.
	 */
	public static function run_sync(): WP_REST_Response {
		$lock = get_transient( 'forwp_drive_sync_lock' );
		if ( $lock ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Sync already ran recently. Please wait.', '4wp-drive' ) ),
				429
			);
		}

		set_transient( 'forwp_drive_sync_lock', 1, 60 );

		$result = ( new Incoming_Scanner() )->run();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}
}
