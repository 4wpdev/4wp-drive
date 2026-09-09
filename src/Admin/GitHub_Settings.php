<?php
/**
 * Encrypted GitHub PAT + repo settings.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Admin;

use ForWP\Drive\Auth\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Option accessors for the GitHub storage source.
 */
final class GitHub_Settings {

	public const OPTION = 'forwp_drive_github';

	/**
	 * @return array{owner: string, repo: string, branch: string, incoming: string, published: string, failed: string, has_token: bool}
	 */
	public static function get_public(): array {
		$data = self::get_raw();

		return array(
			'owner'     => (string) ( $data['owner'] ?? '' ),
			'repo'      => (string) ( $data['repo'] ?? '' ),
			'branch'    => (string) ( $data['branch'] ?? 'main' ),
			'incoming'  => (string) ( $data['incoming'] ?? 'incoming' ),
			'published' => (string) ( $data['published'] ?? 'published' ),
			'failed'    => (string) ( $data['failed'] ?? 'failed' ),
			'has_token' => '' !== self::get_token(),
		);
	}

	public static function get_token(): string {
		$data    = self::get_raw();
		$encoded = (string) ( $data['token'] ?? '' );
		if ( '' === $encoded ) {
			return '';
		}

		$plain = Crypto::decrypt( $encoded );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * @param array<string, mixed> $input Settings payload.
	 */
	public static function save( array $input ): void {
		$current = self::get_raw();
		$token   = (string) ( $input['token'] ?? '' );
		if ( '' === $token && ! empty( $current['token'] ) ) {
			$token_store = (string) $current['token'];
		} else {
			$token_store = '' === $token ? '' : Crypto::encrypt( $token );
		}

		update_option(
			self::OPTION,
			array(
				'owner'     => sanitize_text_field( (string) ( $input['owner'] ?? '' ) ),
				'repo'      => sanitize_text_field( (string) ( $input['repo'] ?? '' ) ),
				'branch'    => sanitize_text_field( (string) ( $input['branch'] ?? 'main' ) ) ?: 'main',
				'incoming'  => self::sanitize_path( (string) ( $input['incoming'] ?? 'incoming' ) ),
				'published' => self::sanitize_path( (string) ( $input['published'] ?? 'published' ) ),
				'failed'    => self::sanitize_path( (string) ( $input['failed'] ?? 'failed' ) ),
				'token'     => $token_store,
			),
			false
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_raw(): array {
		$data = get_option( self::OPTION, array() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Browser URL for the mapped incoming path (empty until owner/repo are set).
	 */
	public static function incoming_web_url(): string {
		$cfg = self::get_public();
		if ( '' === $cfg['owner'] || '' === $cfg['repo'] ) {
			return '';
		}

		$path = trim( str_replace( '\\', '/', $cfg['incoming'] ), '/' );

		return sprintf(
			'https://github.com/%s/%s/tree/%s/%s',
			rawurlencode( $cfg['owner'] ),
			rawurlencode( $cfg['repo'] ),
			rawurlencode( $cfg['branch'] ),
			str_replace( '%2F', '/', rawurlencode( $path ) )
		);
	}

	private static function sanitize_path( string $path ): string {
		$path = trim( str_replace( '\\', '/', $path ), '/' );
		$path = preg_replace( '/[^a-zA-Z0-9._\-\/]/', '', $path );

		return is_string( $path ) && '' !== $path ? $path : 'incoming';
	}
}
