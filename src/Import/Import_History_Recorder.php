<?php
/**
 * Build import-history rows from a finished import.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Admin\GitHub_Settings;
use ForWP\Drive\Sources\GitHub_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Derives folder alias, incoming path, and published path for history.
 */
final class Import_History_Recorder {

	/**
	 * @param array<string, mixed> $args Import context.
	 * @return array<string, mixed>
	 */
	public static function build( array $args ): array {
		$metadata     = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array();
		$source       = sanitize_key( (string) ( $args['source'] ?? '' ) );
		$folder_alias = self::folder_alias( $source, $metadata );
		$package_ref  = self::package_ref( $source, $metadata );

		return array(
			'source'         => $source,
			'post_id'        => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
			'post_type'      => sanitize_key( (string) ( $args['post_type'] ?? 'post' ) ) ?: 'post',
			'imported_at'    => (string) ( $args['imported_at'] ?? '' ),
			'incoming_path'  => self::incoming_path( $source, $metadata, $folder_alias ),
			'published_path' => self::published_path( $source, $metadata, $folder_alias ),
			'folder_alias'   => $folder_alias,
			'site_alias'     => sanitize_title( (string) ( $args['site_alias'] ?? '' ) ),
			'package_ref'    => $package_ref,
			'document_id'    => isset( $args['document_id'] ) ? (int) $args['document_id'] : null,
			'file_id'        => (string) ( $args['file_id'] ?? '' ),
			'mode'           => sanitize_key( (string) ( $args['mode'] ?? 'create' ) ) ?: 'create',
			'summary'        => self::summarize_post(
				isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
				$metadata
			),
		);
	}

	/**
	 * Snapshot of the created/updated post: status, chars, images, taxonomies.
	 *
	 * @param array<string, mixed> $metadata Scan metadata.
	 * @return array<string, mixed>
	 */
	public static function summarize_post( int $post_id, array $metadata = array() ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$content = (string) $post->post_content;
		$text    = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content );
		$chars   = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		$images  = preg_match_all( '/<!--\s*wp:image\b/', $content );
		$images  = is_int( $images ) ? $images : 0;

		$used = isset( $metadata['used_source_file_ids'] ) && is_array( $metadata['used_source_file_ids'] )
			? count( $metadata['used_source_file_ids'] )
			: 0;
		if ( $used > $images ) {
			$images = $used;
		}

		$taxonomies = array();
		if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) ) {
			foreach ( get_object_taxonomies( (string) $post->post_type, 'objects' ) as $taxonomy => $tax_obj ) {
				if ( empty( $tax_obj->public ) ) {
					continue;
				}
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$taxonomies[ (string) $taxonomy ] = array_values( array_map( 'strval', $terms ) );
			}
		}

		return array(
			'status'     => (string) $post->post_status,
			'chars'      => $chars,
			'images'     => $images,
			'featured'   => function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) > 0 : false,
			'taxonomies' => $taxonomies,
		);
	}

	/**
	 * Package folder name on the source (Drive folder or GitHub directory).
	 *
	 * @param array<string, mixed> $metadata Scan metadata.
	 */
	public static function folder_alias( string $source, array $metadata ): string {
		$name = trim( (string) ( $metadata['package_folder_name'] ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}

		$package = self::trim_path( (string) ( $metadata['package_folder_id'] ?? '' ) );
		if ( '' !== $package && GitHub_Source::SLUG === $source ) {
			$parts = explode( '/', $package );

			return (string) end( $parts );
		}

		$path = self::trim_path( (string) ( $metadata['github_path'] ?? '' ) );
		if ( '' !== $path ) {
			$parts = explode( '/', $path );
			if ( count( $parts ) > 1 ) {
				return $parts[ count( $parts ) - 2 ];
			}
		}

		return '';
	}

	/**
	 * Source-native package id or path (Drive folder id, GitHub directory).
	 *
	 * @param array<string, mixed> $metadata Scan metadata.
	 */
	public static function package_ref( string $source, array $metadata ): string {
		$package = trim( (string) ( $metadata['package_folder_id'] ?? '' ) );
		if ( '' !== $package ) {
			return $package;
		}

		if ( GitHub_Source::SLUG === $source ) {
			return self::trim_path( (string) ( $metadata['github_path'] ?? '' ) );
		}

		return '';
	}

	/**
	 * Where the package lived before import.
	 *
	 * @param array<string, mixed> $metadata Scan metadata.
	 */
	public static function incoming_path( string $source, array $metadata, string $folder_alias ): string {
		if ( GitHub_Source::SLUG === $source ) {
			$package = self::trim_path( (string) ( $metadata['package_folder_id'] ?? '' ) );
			if ( '' !== $package ) {
				return $package;
			}

			return self::trim_path( (string) ( $metadata['github_path'] ?? '' ) );
		}

		if ( '' !== $folder_alias ) {
			return 'incoming/' . $folder_alias;
		}

		return 'incoming';
	}

	/**
	 * Where the package was archived after import.
	 *
	 * @param array<string, mixed> $metadata Scan metadata.
	 */
	public static function published_path( string $source, array $metadata, string $folder_alias ): string {
		if ( GitHub_Source::SLUG === $source ) {
			$cfg      = GitHub_Settings::get_public();
			$dest     = self::trim_path( (string) ( $cfg['published'] ?? 'published' ) );
			$scan     = self::trim_path( (string) ( $cfg['incoming'] ?? '' ) );
			$incoming = self::incoming_path( $source, $metadata, $folder_alias );
			$rel      = self::strip_prefix( $incoming, $scan );

			return '' === $dest ? $rel : trim( $dest . '/' . $rel, '/' );
		}

		if ( '' !== $folder_alias ) {
			return 'published/' . $folder_alias;
		}

		return 'published';
	}

	private static function trim_path( string $path ): string {
		return trim( str_replace( '\\', '/', $path ), '/' );
	}

	private static function strip_prefix( string $path, string $prefix ): string {
		$path   = self::trim_path( $path );
		$prefix = self::trim_path( $prefix );
		if ( '' !== $prefix && ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) ) {
			return ltrim( substr( $path, strlen( $prefix ) ), '/' );
		}

		return $path;
	}
}
