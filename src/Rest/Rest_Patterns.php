<?php
/**
 * REST: Drive Patterns library.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Rest;

use ForWP\Drive\Patterns\Pattern_Library;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Patterns routes — editable CPT library (create / update / delete).
 */
final class Rest_Patterns {

	private const NAMESPACE = 'forwp-drive/v1';

	/**
	 * Register REST bootstrap hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register pattern routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/patterns',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_patterns' ),
					'permission_callback' => array( Rest_Settings::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save_patterns' ),
					'permission_callback' => array( Rest_Settings::class, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * GET patterns — ensure editable library, then return rules.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_patterns() {
		$ready = Pattern_Library::ensure_editable_library();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return new WP_REST_Response( Pattern_Library::get_for_rest() );
	}

	/**
	 * POST patterns — create / update / delete via full rules list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_patterns( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$rules = isset( $params['rules'] ) && is_array( $params['rules'] ) ? $params['rules'] : array();

		$result = Pattern_Library::save_custom_rules( $rules );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array_merge(
				Pattern_Library::get_for_rest(),
				array(
					'message' => __( 'Patterns saved.', '4wp-drive' ),
				)
			)
		);
	}
}
