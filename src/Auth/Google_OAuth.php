<?php
/**
 * Google OAuth 2.0 for Drive API.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Auth;

use ForWP\Drive\Parse\Template_Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Authorization code flow for administrators.
 */
final class Google_OAuth {

	private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Drive scope: read/write files the app accesses.
	 */
	private const SCOPE = 'https://www.googleapis.com/auth/drive';

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @var Token_Store
	 */
	private $store;

	/**
	 * @var Credentials_Store
	 */
	private $credentials;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->store       = new Token_Store();
		$this->credentials = new Credentials_Store();
	}

	/**
	 * @return void
	 */
	public function boot(): void {
		// Before admin auth_redirect — loopback callback (127.0.0.1) has no .local auth cookies.
		add_action( 'init', array( $this, 'maybe_handle_callback' ), 5 );
	}

	/**
	 * Whether Google OAuth app credentials are configured.
	 */
	public function has_client_config(): bool {
		return $this->credentials->has_credentials();
	}

	/**
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->store->is_connected();
	}

	/**
	 * @return Credentials_Store
	 */
	public function credentials(): Credentials_Store {
		return $this->credentials;
	}

	/**
	 * Build authorization URL.
	 *
	 * @return string|WP_Error
	 */
	public function get_auth_url() {
		if ( ! $this->has_client_config() ) {
			return new WP_Error(
				'forwp_drive_no_client',
				__( 'Save Google OAuth Client ID and Client Secret in settings first.', '4wp-drive' )
			);
		}

		$state = wp_generate_password( 32, false );
		set_transient(
			$this->pending_state_key( $state ),
			array(
				'user_id'    => get_current_user_id(),
				'return_url' => admin_url( 'admin.php?page=forwp-drive-settings' ),
			),
			10 * MINUTE_IN_SECONDS
		);

		$params = array(
			'client_id'     => $this->get_client_id(),
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return self::AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Handle Google OAuth redirect (runs on init, before admin login redirect).
	 *
	 * @return void
	 */
	public function maybe_handle_callback(): void {
		if ( ! $this->is_oauth_callback_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			$args = array(
				'oauth_error' => $error,
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['error_description'] ) ) {
				$args['oauth_message'] = rawurlencode(
					sanitize_text_field( wp_unslash( $_GET['error_description'] ) )
				);
			}
			$this->redirect_settings(
				$args,
				$this->consume_pending_return_url()
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( '' === $code || '' === $state || ! $this->is_valid_oauth_state( $state ) ) {
			return;
		}

		$pending = get_transient( $this->pending_state_key( $state ) );
		delete_transient( $this->pending_state_key( $state ) );

		if ( ! is_array( $pending ) || empty( $pending['return_url'] ) ) {
			$this->redirect_settings( array( 'oauth_error' => 'invalid_state' ), null );
		}

		$result = $this->exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			$this->redirect_settings(
				array(
					'oauth_error'   => 'token_exchange',
					'oauth_message' => rawurlencode( $result->get_error_message() ),
				),
				isset( $pending['return_url'] ) ? (string) $pending['return_url'] : null
			);
		}

		$this->redirect_settings(
			array( 'connected' => '1' ),
			isset( $pending['return_url'] ) ? (string) $pending['return_url'] : null
		);
	}

	/**
	 * Whether the current request is the OAuth admin callback.
	 */
	private function is_oauth_callback_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'forwp-drive-oauth' !== $_GET['page'] ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		return false !== strpos( $uri, 'wp-admin/admin.php' );
	}

	/**
	 * Human-readable OAuth error for the settings admin notice.
	 *
	 * @param string $code           OAuth error code from query args.
	 * @param string $custom_message Optional detail (Google error_description or token API message).
	 */
	public static function format_admin_error_message( string $code, string $custom_message = '' ): string {
		if ( '' !== $custom_message ) {
			return rawurldecode( $custom_message );
		}

		$messages = array(
			'access_denied'         => __( 'Google authorization was cancelled.', '4wp-drive' ),
			'invalid_state'         => __( 'OAuth session expired or the authorization link was reused. Click Connect again from Storage sources.', '4wp-drive' ),
			'redirect_uri_mismatch' => __( 'Redirect URI mismatch. Add the exact URI from Documentation to Google Cloud Console, then save OAuth redirect in Storage sources.', '4wp-drive' ),
			'invalid_client'        => __( 'Invalid OAuth client. Verify Client ID and Client Secret, then Save credentials.', '4wp-drive' ),
			'invalid_grant'         => __( 'Authorization code expired or already used. Click Connect Google Drive again.', '4wp-drive' ),
			'token_exchange'        => __( 'Could not exchange the authorization code. Check Client Secret and redirect URI.', '4wp-drive' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		if ( '' === $code ) {
			return __( 'Google authorization failed. Check credentials and redirect URI.', '4wp-drive' );
		}

		return sprintf(
			/* translators: %s: Google OAuth error code */
			__( 'Google authorization failed (%1$s). Check credentials and redirect URI.', '4wp-drive' ),
			$code
		);
	}

	/**
	 * @param string $state OAuth state token.
	 */
	private function pending_state_key( string $state ): string {
		return 'forwp_drive_oauth_pending_' . $state;
	}

	/**
	 * OAuth state must be an unguessable single-use token (not a session nonce).
	 *
	 * @param string $state State from Google callback.
	 */
	private function is_valid_oauth_state( string $state ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9]{32}$/', $state );
	}

	/**
	 * Return URL saved when connect started (and clear pending transient).
	 */
	private function consume_pending_return_url(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $state ) {
			return null;
		}

		$key     = $this->pending_state_key( $state );
		$pending = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $pending ) || empty( $pending['return_url'] ) ) {
			return null;
		}

		return (string) $pending['return_url'];
	}

	/**
	 * @param array<string, string> $args       Query args for settings URL.
	 * @param string|null           $return_url Where the admin started connect (e.g. taxspoc.localhost).
	 */
	private function redirect_settings( array $args, ?string $return_url = null ): void {
		$base = is_string( $return_url ) && '' !== $return_url
			? remove_query_arg( array( 'connected', 'oauth_error', 'oauth_message' ), $return_url )
			: admin_url( 'admin.php?page=forwp-drive-settings' );

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	/**
	 * @param string $code Authorization code.
	 * @return true|WP_Error
	 */
	public function exchange_code( string $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'redirect_uri'  => $this->get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		return $this->store_token_response( $response );
	}

	/**
	 * Get a valid access token (refresh when expired).
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		$tokens = $this->store->get();
		if ( ! is_array( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'forwp_drive_not_connected', __( 'Google Drive is not connected.', '4wp-drive' ) );
		}

		$expires = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;
		if ( ! empty( $tokens['access_token'] ) && $expires > time() + 60 ) {
			return (string) $tokens['access_token'];
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'refresh_token' => $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$stored = $this->store_token_response( $response, $tokens['refresh_token'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$fresh = $this->store->get();

		return is_array( $fresh ) && ! empty( $fresh['access_token'] )
			? (string) $fresh['access_token']
			: new WP_Error( 'forwp_drive_token_refresh', __( 'Could not refresh access token.', '4wp-drive' ) );
	}

	/**
	 * Disconnect Google account (tokens only; keeps app credentials).
	 */
	public function disconnect(): void {
		$this->store->delete();
	}

	/**
	 * Remove stored API credentials and disconnect OAuth tokens.
	 *
	 * @return true|\WP_Error
	 */
	public function clear_credentials() {
		$deleted = $this->credentials->delete();
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$this->disconnect();

		return true;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response HTTP response.
	 * @param string|null                    $preserve_refresh Keep existing refresh token.
	 * @return true|WP_Error
	 */
	private function store_token_response( $response, ?string $preserve_refresh = null ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error_description'] )
				? (string) $body['error_description']
				: __( 'Google token request failed.', '4wp-drive' );

			return new WP_Error( 'forwp_drive_token_error', $message );
		}

		$refresh = $preserve_refresh ?? ( $body['refresh_token'] ?? '' );
		if ( '' === $refresh ) {
			return new WP_Error(
				'forwp_drive_no_refresh',
				__( 'No refresh token received. Disconnect the app in your Google Account and connect again.', '4wp-drive' )
			);
		}

		$this->store->save(
			array(
				'access_token'  => $body['access_token'] ?? '',
				'refresh_token' => $refresh,
				'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
			)
		);

		return true;
	}

	/**
	 * OAuth redirect URI (override for local hosts Google rejects, e.g. taxspoc.localhost).
	 */
	public function get_redirect_uri(): string {
		if ( defined( 'FORWP_DRIVE_OAUTH_REDIRECT_URI' ) && is_string( FORWP_DRIVE_OAUTH_REDIRECT_URI ) ) {
			return FORWP_DRIVE_OAUTH_REDIRECT_URI;
		}

		return ( new Template_Config() )->get_effective_redirect_uri();
	}

	/**
	 * Help links for the settings screen.
	 *
	 * @return array<string, string>
	 */
	public static function get_setup_links(): array {
		return array(
			'console'     => 'https://console.cloud.google.com/',
			'drive_api'   => 'https://console.cloud.google.com/apis/library/drive.googleapis.com',
			'credentials' => 'https://console.cloud.google.com/apis/credentials',
			'oauth_doc'   => 'https://developers.google.com/identity/protocols/oauth2/web-server',
		);
	}

	private function get_client_id(): string {
		return $this->credentials->get_client_id();
	}

	private function get_client_secret(): string {
		return $this->credentials->get_client_secret();
	}
}
