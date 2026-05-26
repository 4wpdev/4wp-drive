<?php
/**
 * Encrypted storage for OAuth tokens.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts tokens with site salts (OpenSSL when available).
 */
final class Token_Store {

	public const OPTION_KEY = 'forwp_drive_google_tokens';

	/**
	 * @return array<string, mixed>|null
	 */
	public function get(): ?array {
		$stored = get_option( self::OPTION_KEY, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$json = Crypto::decrypt( $stored );
		if ( ! is_string( $json ) ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param array<string, mixed> $tokens Token payload.
	 */
	public function save( array $tokens ): void {
		$json = wp_json_encode( $tokens );
		if ( ! is_string( $json ) ) {
			return;
		}

		update_option( self::OPTION_KEY, Crypto::encrypt( $json ), false );
	}

	/**
	 * Remove stored tokens.
	 */
	public function delete(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Whether a refresh token is stored.
	 */
	public function is_connected(): bool {
		$tokens = $this->get();

		return is_array( $tokens ) && ! empty( $tokens['refresh_token'] );
	}
}
