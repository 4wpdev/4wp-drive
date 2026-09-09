<?php
/**
 * Choose which package image becomes the featured image.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristics + override resolution for Drive package images.
 */
final class Featured_Image_Chooser {

	/**
	 * Pick the best default featured image from package image rows.
	 *
	 * Preference: cover / featured / hero in the filename, then first image.
	 *
	 * @param array<int, array<string, mixed>> $images Rows with id + name.
	 * @return array{id: string, name: string}|null
	 */
	public static function suggest( array $images ): ?array {
		$normalized = array();
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$id   = (string) ( $image['id'] ?? '' );
			$name = (string) ( $image['name'] ?? '' );
			if ( '' === $id || '' === $name ) {
				continue;
			}
			$normalized[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		if ( empty( $normalized ) ) {
			return null;
		}

		foreach ( $normalized as $image ) {
			if ( self::name_suggests_featured( $image['name'] ) ) {
				return $image;
			}
		}

		return $normalized[0];
	}

	/**
	 * Resolve an explicit import override against package files.
	 *
	 * @param array<string, mixed> $metadata             Document metadata.
	 * @param string               $featured_image_file_id Requested Drive file id.
	 * @return array<string, mixed> Metadata with image_file_* updated when valid.
	 */
	public static function apply_override( array $metadata, string $featured_image_file_id ): array {
		$featured_image_file_id = sanitize_text_field( $featured_image_file_id );
		if ( '' === $featured_image_file_id ) {
			return $metadata;
		}

		$images = self::images_from_metadata( $metadata );
		foreach ( $images as $image ) {
			if ( $image['id'] === $featured_image_file_id ) {
				$metadata['image_file_id']   = $image['id'];
				$metadata['image_file_name'] = $image['name'];
				return $metadata;
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata Document metadata.
	 * @return array<int, array{id: string, name: string}>
	 */
	public static function images_from_metadata( array $metadata ): array {
		$out = array();
		if ( isset( $metadata['package_files'] ) && is_array( $metadata['package_files'] ) ) {
			foreach ( $metadata['package_files'] as $file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}
				if ( 'image' !== sanitize_key( (string) ( $file['kind'] ?? '' ) ) ) {
					continue;
				}
				$id   = (string) ( $file['id'] ?? '' );
				$name = (string) ( $file['name'] ?? '' );
				if ( '' === $id || '' === $name ) {
					continue;
				}
				$out[] = array(
					'id'   => $id,
					'name' => $name,
				);
			}
		}

		if ( empty( $out ) ) {
			$id   = (string) ( $metadata['image_file_id'] ?? '' );
			$name = (string) ( $metadata['image_file_name'] ?? '' );
			if ( '' !== $id && '' !== $name ) {
				$out[] = array(
					'id'   => $id,
					'name' => $name,
				);
			}
		}

		return $out;
	}

	/**
	 * @param string $name Filename.
	 */
	public static function name_suggests_featured( string $name ): bool {
		$base = strtolower( pathinfo( $name, PATHINFO_FILENAME ) );
		if ( '' === $base ) {
			return false;
		}

		return (bool) preg_match(
			'/(^|[-_\s])(cover|featured|hero|thumbnail|thumb)([-_\s]|$)/i',
			$base
		);
	}
}
