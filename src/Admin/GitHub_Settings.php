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
	 * @return array{owner: string, repo: string, branch: string, incoming: string, published: string, failed: string, has_token: bool, repo_url: string, incoming_url: string}
	 */
	public static function get_public(): array {
		$data  = self::get_raw();
		$owner = (string) ( $data['owner'] ?? '' );
		$repo  = (string) ( $data['repo'] ?? '' );
		list( $owner, $repo ) = self::normalize_owner_repo( $owner, $repo );

		// Heal legacy rows where repo was a full clone URL / SSH remote.
		$raw_owner = (string) ( $data['owner'] ?? '' );
		$raw_repo  = (string) ( $data['repo'] ?? '' );
		if ( ( $owner !== $raw_owner || $repo !== $raw_repo ) && ( '' !== $owner || '' !== $repo ) ) {
			$data['owner'] = $owner;
			$data['repo']  = $repo;
			update_option( self::OPTION, $data, false );
		}

		$public = array(
			'owner'     => $owner,
			'repo'      => $repo,
			'branch'    => (string) ( $data['branch'] ?? 'main' ) ?: 'main',
			'incoming'  => '',
			'published' => self::sanitize_path( (string) ( $data['published'] ?? 'published' ), 'published' ),
			'failed'    => self::sanitize_path( (string) ( $data['failed'] ?? 'failed' ), 'failed' ),
			'has_token' => '' !== self::get_token(),
		);

		// Empty Incoming path = repository root (legacy default was "incoming/").
		$raw_incoming = array_key_exists( 'incoming', $data ) ? (string) $data['incoming'] : 'incoming';
		if ( 'incoming' === $raw_incoming ) {
			$raw_incoming = '';
			$data['incoming'] = '';
			$data['owner']    = $owner;
			$data['repo']     = $repo;
			update_option( self::OPTION, $data, false );
		}
		$public['incoming'] = self::sanitize_incoming_path( $raw_incoming );

		$public['repo_url']     = self::repo_web_url_from( $public );
		$public['incoming_url'] = self::incoming_web_url_from( $public );

		return $public;
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

		list( $owner, $repo ) = self::normalize_owner_repo(
			(string) ( $input['owner'] ?? '' ),
			(string) ( $input['repo'] ?? '' )
		);

		update_option(
			self::OPTION,
			array(
				'owner'     => $owner,
				'repo'      => $repo,
				'branch'    => sanitize_text_field( (string) ( $input['branch'] ?? 'main' ) ) ?: 'main',
				'incoming'  => self::sanitize_incoming_path( (string) ( $input['incoming'] ?? '' ) ),
				'published' => self::sanitize_path( (string) ( $input['published'] ?? 'published' ), 'published' ),
				'failed'    => self::sanitize_path( (string) ( $input['failed'] ?? 'failed' ), 'failed' ),
				'token'     => $token_store,
			),
			false
		);
	}

	/**
	 * Remove the stored PAT. Owner/repo stay so a new token can be saved.
	 */
	public static function clear_token(): void {
		$current = self::get_raw();
		$current['token'] = '';
		update_option( self::OPTION, $current, false );
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
		return self::incoming_web_url_from( self::get_public() );
	}

	/**
	 * @param array{owner?: string, repo?: string, branch?: string, incoming?: string} $cfg Public settings.
	 */
	private static function incoming_web_url_from( array $cfg ): string {
		$owner = (string) ( $cfg['owner'] ?? '' );
		$repo  = (string) ( $cfg['repo'] ?? '' );
		if ( '' === $owner || '' === $repo ) {
			return '';
		}

		$path = trim( str_replace( '\\', '/', (string) ( $cfg['incoming'] ?? '' ) ), '/' );
		$branch = (string) ( $cfg['branch'] ?? 'main' ) ?: 'main';

		if ( '' === $path ) {
			return sprintf(
				'https://github.com/%s/%s/tree/%s',
				rawurlencode( $owner ),
				rawurlencode( $repo ),
				rawurlencode( $branch )
			);
		}

		return sprintf(
			'https://github.com/%s/%s/tree/%s/%s',
			rawurlencode( $owner ),
			rawurlencode( $repo ),
			rawurlencode( $branch ),
			str_replace( '%2F', '/', rawurlencode( $path ) )
		);
	}

	/**
	 * @param array{owner?: string, repo?: string} $cfg Public settings.
	 */
	private static function repo_web_url_from( array $cfg ): string {
		$owner = (string) ( $cfg['owner'] ?? '' );
		$repo  = (string) ( $cfg['repo'] ?? '' );
		if ( '' === $owner || '' === $repo ) {
			return '';
		}

		return sprintf(
			'https://github.com/%s/%s',
			rawurlencode( $owner ),
			rawurlencode( $repo )
		);
	}

	/**
	 * Accept plain names or pasted remotes/URLs.
	 *
	 * @return array{0: string, 1: string} owner, repo
	 */
	public static function normalize_owner_repo( string $owner, string $repo ): array {
		$owner = trim( $owner );
		$repo  = trim( $repo );

		$parsed = self::parse_github_remote( $repo );
		if ( null === $parsed ) {
			$parsed = self::parse_github_remote( $owner );
			if ( null !== $parsed ) {
				// Full remote was pasted into Owner; clear mistaken repo if empty/same.
				if ( '' === $repo || $repo === $owner ) {
					return array( $parsed[0], $parsed[1] );
				}
				return array( $parsed[0], self::sanitize_repo_name( $repo ) );
			}
		} else {
			return array(
				'' !== $owner && ! self::looks_like_remote( $owner ) ? self::sanitize_owner_name( $owner ) : $parsed[0],
				$parsed[1],
			);
		}

		// owner/repo in either field.
		if ( preg_match( '#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$#', $repo, $m ) ) {
			return array(
				'' !== $owner && ! self::looks_like_remote( $owner ) ? self::sanitize_owner_name( $owner ) : $m[1],
				$m[2],
			);
		}
		if ( preg_match( '#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$#', $owner, $m ) && ( '' === $repo || $repo === $owner ) ) {
			return array( $m[1], $m[2] );
		}

		return array(
			self::sanitize_owner_name( $owner ),
			self::sanitize_repo_name( $repo ),
		);
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private static function parse_github_remote( string $value ): ?array {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		// git@github.com:owner/repo.git
		if ( preg_match( '#^git@github\.com:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$#i', $value, $m ) ) {
			return array( $m[1], $m[2] );
		}

		// ssh://git@github.com/owner/repo.git
		if ( preg_match( '#^ssh://git@github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#i', $value, $m ) ) {
			return array( $m[1], $m[2] );
		}

		// https://github.com/owner/repo(.git)(/...)
		if ( preg_match( '#^https?://(?:www\.)?github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?(?:/.*)?$#i', $value, $m ) ) {
			return array( $m[1], $m[2] );
		}

		return null;
	}

	private static function looks_like_remote( string $value ): bool {
		return null !== self::parse_github_remote( $value ) || false !== strpos( $value, 'github.com' );
	}

	private static function sanitize_owner_name( string $owner ): string {
		$owner = sanitize_text_field( $owner );
		$owner = preg_replace( '/[^A-Za-z0-9_.-]/', '', $owner );

		return is_string( $owner ) ? $owner : '';
	}

	private static function sanitize_repo_name( string $repo ): string {
		$repo = sanitize_text_field( $repo );
		$repo = preg_replace( '/\.git$/i', '', $repo );
		$repo = preg_replace( '/[^A-Za-z0-9_.-]/', '', $repo );

		return is_string( $repo ) ? $repo : '';
	}

	/**
	 * Incoming path: empty string = repository root.
	 */
	private static function sanitize_incoming_path( string $path ): string {
		$path = trim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path || '.' === $path || '/' === $path ) {
			return '';
		}
		$path = preg_replace( '/[^a-zA-Z0-9._\-\/]/', '', $path );

		return is_string( $path ) ? $path : '';
	}

	/**
	 * Non-empty folder path (published / failed).
	 */
	private static function sanitize_path( string $path, string $fallback = 'published' ): string {
		$path = trim( str_replace( '\\', '/', $path ), '/' );
		$path = preg_replace( '/[^a-zA-Z0-9._\-\/]/', '', $path );

		return is_string( $path ) && '' !== $path ? $path : $fallback;
	}
}
