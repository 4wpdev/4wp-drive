<?php
/**
 * Authenticated inbox preview of storage images (Drive / GitHub).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Rest;

use ForWP\Drive\Api\GitHub_Client;
use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Sources\GitHub_Source;
use ForWP\Drive\Sources\Google_Drive_Source;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Download and validate image bytes for the inbox workspace.
 */
final class Media_Preview {

	private const MAX_BYTES = 20971520;

	/**
	 * @return array{bytes: string, mime: string, name: string}|WP_Error
	 */
	public static function fetch( string $source_slug, string $file_id, string $name = '' ) {
		$file_id = trim( $file_id );
		$name    = sanitize_file_name( $name );
		if ( '' === $file_id ) {
			return new WP_Error( 'forwp_drive_media_id', __( 'Missing file id.', '4wp-drive' ) );
		}

		$bytes = self::download( $source_slug, $file_id );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error( 'forwp_drive_media_size', __( 'Image is too large to preview.', '4wp-drive' ) );
		}

		$mime = self::detect_mime( $name, $bytes );
		if ( is_wp_error( $mime ) ) {
			return $mime;
		}

		if ( '' === $name ) {
			$name = 'image';
		}

		return array(
			'bytes' => $bytes,
			'mime'  => $mime,
			'name'  => $name,
		);
	}

	/**
	 * @return string|WP_Error
	 */
	public static function detect_mime( string $name, string $bytes ) {
		if ( '' === $bytes ) {
			return new WP_Error( 'forwp_drive_media_empty', __( 'Empty file.', '4wp-drive' ) );
		}

		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = is_array( $info ) && ! empty( $info['mime'] ) ? strtolower( (string) $info['mime'] ) : '';
		if ( self::is_allowed_mime( $mime ) ) {
			return $mime;
		}

		return new WP_Error( 'forwp_drive_media_type', __( 'This file is not a previewable image.', '4wp-drive' ) );
	}

	/**
	 * @return string|WP_Error
	 */
	private static function download( string $source_slug, string $file_id ) {
		if ( GitHub_Source::SLUG === $source_slug || 0 === strpos( $file_id, 'gh:' ) ) {
			$path = GitHub_Source::path_from_file_id( $file_id );
			if ( '' === $path ) {
				return new WP_Error( 'forwp_drive_github_path', __( 'Could not resolve GitHub file path.', '4wp-drive' ) );
			}

			return ( new GitHub_Client() )->get_file_contents( $path );
		}

		if ( Google_Drive_Source::SLUG !== $source_slug && '' !== $source_slug ) {
			return new WP_Error( 'forwp_drive_media_source', __( 'This source cannot preview files.', '4wp-drive' ) );
		}

		return ( new Google_Drive_Client( Google_OAuth::instance() ) )->download_file( $file_id );
	}

	private static function is_allowed_mime( string $mime ): bool {
		return in_array(
			$mime,
			array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
				'image/avif',
			),
			true
		);
	}
}
