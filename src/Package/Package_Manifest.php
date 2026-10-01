<?php
/**
 * Package manifest (meta.json) for cross-posting packages.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Reads meta.json from a package folder.
 *
 * A folder with meta.json is a cross-posting package: only the original file is imported,
 * platform variants stay in the folder, and the folder is not moved to published/ after import.
 */
final class Package_Manifest {

	public const FILE_NAME = 'meta.json';

	public const DEFAULT_ORIGINAL = 'original.md';

	public const STATE_IN_PROGRESS = 'in_progress';

	/**
	 * Metadata key holding the manifest summary on a document row.
	 */
	public const META_KEY = 'package_manifest';

	/**
	 * Decoded meta.json.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Parse error (empty when valid).
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data  Decoded meta.json.
	 * @param string               $error Parse error (empty when valid).
	 */
	private function __construct( array $data, string $error = '' ) {
		$this->data  = $data;
		$this->error = $error;
	}

	/**
	 * Parse meta.json. Invalid JSON still yields a manifest (defaults) with an error message,
	 * so the package keeps its package behavior and the problem is visible in Incoming.
	 *
	 * @param string $json Raw meta.json contents.
	 */
	public static function from_json( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new self( array(), __( 'meta.json is not valid JSON.', '4wp-drive' ) );
		}

		if ( ! isset( $data['schema'] ) || (int) $data['schema'] < 1 ) {
			return new self( $data, __( 'meta.json has no valid "schema" version.', '4wp-drive' ) );
		}

		return new self( $data );
	}

	/**
	 * Whether a listing row name is the package manifest.
	 *
	 * @param string $name File name.
	 */
	public static function is_manifest_name( string $name ): bool {
		return self::FILE_NAME === strtolower( $name );
	}

	/**
	 * Schema version (0 when missing).
	 */
	public function schema(): int {
		return isset( $this->data['schema'] ) ? max( 0, (int) $this->data['schema'] ) : 0;
	}

	/**
	 * Parse error message, empty when the manifest is valid.
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * File name of the article imported into WordPress.
	 */
	public function original_file(): string {
		$file = '';
		if ( isset( $this->data['original'] ) && is_array( $this->data['original'] ) ) {
			$file = (string) ( $this->data['original']['file'] ?? '' );
		}

		$file = basename( str_replace( '\\', '/', trim( $file ) ) );

		return '' !== $file ? $file : self::DEFAULT_ORIGINAL;
	}

	/**
	 * Platform keys declared in the manifest.
	 *
	 * @return array<int, string>
	 */
	public function platforms(): array {
		if ( empty( $this->data['platforms'] ) || ! is_array( $this->data['platforms'] ) ) {
			return array();
		}

		return array_values( array_map( 'strval', array_keys( $this->data['platforms'] ) ) );
	}

	/**
	 * Summary stored in document metadata.
	 *
	 * @return array{schema: int, original: string, platforms: array<int, string>}
	 */
	public function to_metadata(): array {
		return array(
			'schema'    => $this->schema(),
			'original'  => $this->original_file(),
			'platforms' => $this->platforms(),
		);
	}

	/**
	 * Whether scan/import metadata belongs to a manifest package.
	 *
	 * @param array<string, mixed> $metadata Document metadata.
	 */
	public static function is_package( array $metadata ): bool {
		return ! empty( $metadata[ self::META_KEY ] ) && is_array( $metadata[ self::META_KEY ] );
	}

	/**
	 * Whether the original was imported and cross-posting is not finished.
	 *
	 * @param array<string, mixed> $metadata Document metadata.
	 */
	public static function is_in_progress( array $metadata ): bool {
		return self::is_package( $metadata )
			&& self::STATE_IN_PROGRESS === ( $metadata[ self::META_KEY ]['state'] ?? '' );
	}

	/**
	 * Whether a file of a manifest package may not be imported (anything but the original).
	 *
	 * @param array<string, mixed> $metadata  Document metadata.
	 * @param string               $file_path File path or name inside the package.
	 */
	public static function is_blocked_file( array $metadata, string $file_path ): bool {
		if ( ! self::is_package( $metadata ) ) {
			return false;
		}

		$original = (string) ( $metadata[ self::META_KEY ]['original'] ?? self::DEFAULT_ORIGINAL );

		return basename( str_replace( '\\', '/', $file_path ) ) !== $original;
	}

	/**
	 * Merge a patch into meta.json data: objects merge key by key, anything else is replaced.
	 *
	 * @param array<string, mixed> $data  Current meta.json data.
	 * @param array<string, mixed> $patch Changes.
	 * @return array<string, mixed>
	 */
	public static function merge( array $data, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			$is_object = is_array( $value ) && array() !== $value && ! wp_is_numeric_array( $value );
			if ( $is_object && isset( $data[ $key ] ) && is_array( $data[ $key ] ) && ! wp_is_numeric_array( $data[ $key ] ) ) {
				$data[ $key ] = self::merge( $data[ $key ], $value );
				continue;
			}
			$data[ $key ] = $value;
		}

		return $data;
	}

	/**
	 * Encode meta.json the way authors write it: 2-space indent, readable slashes and unicode.
	 *
	 * @param array<string, mixed> $data meta.json data.
	 */
	public static function encode( array $data ): string {
		$json = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = (string) preg_replace_callback(
			'/^(?: {4})+/m',
			static function ( array $m ): string {
				return str_repeat( ' ', (int) ( strlen( $m[0] ) / 2 ) );
			},
			$json
		);

		return $json . "\n";
	}

	/**
	 * Mark metadata as imported and in progress.
	 *
	 * @param array<string, mixed> $metadata Document metadata.
	 * @param int                  $post_id  Imported post id.
	 * @return array<string, mixed>
	 */
	public static function mark_in_progress( array $metadata, int $post_id ): array {
		if ( ! self::is_package( $metadata ) ) {
			return $metadata;
		}

		$metadata[ self::META_KEY ]['state']   = self::STATE_IN_PROGRESS;
		$metadata[ self::META_KEY ]['post_id'] = $post_id;

		return $metadata;
	}
}
