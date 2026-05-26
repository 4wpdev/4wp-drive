<?php
/**
 * Encrypt/decrypt sensitive option values.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Site-salt encryption helper.
 */
final class Crypto {

	/**
	 * @param string $plain Plaintext.
	 */
	public static function encrypt( string $plain ): string {
		if ( function_exists( 'openssl_encrypt' ) ) {
			$key = hash( 'sha256', wp_salt( 'auth' ), true );
			$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
			$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $enc ) {
				return base64_encode( $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		return base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $encoded Encrypted payload.
	 */
	public static function decrypt( string $encoded ): ?string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return null;
		}

		if ( function_exists( 'openssl_decrypt' ) ) {
			$key = hash( 'sha256', wp_salt( 'auth' ), true );
			$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
			$dec = openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $dec ) {
				return $dec;
			}
		}

		return $raw;
	}
}
