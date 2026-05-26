<?php
/**
 * REST: OAuth disconnect.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Rest;

use ForWP\Drive\Auth\Google_OAuth;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth routes.
 */
final class Rest_Oauth {

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
			'/oauth/disconnect',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'disconnect' ),
					'permission_callback' => static function (): bool {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);
	}

	/**
	 * POST disconnect.
	 */
	public static function disconnect(): WP_REST_Response {
		Google_OAuth::instance()->disconnect();

		return new WP_REST_Response(
			array( 'message' => __( 'Disconnected from Google Drive.', '4wp-drive' ) ),
			200
		);
	}
}
