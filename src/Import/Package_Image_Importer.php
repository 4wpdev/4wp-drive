<?php
/**
 * Sideload package-folder images and replace [image:…] markers in post content.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Blocks\Block_Mapping_Settings;
use ForWP\Drive\Blocks\Block_Template_Registry;
use ForWP\Drive\Blocks\Recipes\Core_Image_Recipe;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the default Core Image block template using Drive package files.
 */
final class Package_Image_Importer {

	/**
	 * @var callable
	 */
	private $downloader;

	/**
	 * @param Google_Drive_Client|callable $source Drive client or `function(string $file_id): string|WP_Error`.
	 */
	public function __construct( $source ) {
		if ( $source instanceof Google_Drive_Client ) {
			$this->downloader = static function ( string $file_id ) use ( $source ) {
				return $source->download_file( $file_id );
			};
			return;
		}

		if ( is_callable( $source ) ) {
			$this->downloader = $source;
			return;
		}

		$this->downloader = static function () {
			return new WP_Error( 'forwp_drive_no_image', __( 'Image download is not configured.', '4wp-drive' ) );
		};
	}

	/**
	 * Sideload referenced package images and rewrite post_content markers.
	 *
	 * @param int                  $post_id  WordPress post id.
	 * @param array<string, mixed> $metadata Document metadata (body_html, package_files).
	 * @return string Warning message (empty on full success).
	 */
	public function apply_to_post( int $post_id, array $metadata ): string {
		if ( $post_id <= 0 || ! $this->is_core_image_enabled() ) {
			return '';
		}

		$body = (string) get_post_field( 'post_content', $post_id );
		if ( '' === $body ) {
			$body = (string) ( $metadata['body_html'] ?? '' );
		}

		$tokens = Core_Image_Recipe::extract_tokens( $body );
		if ( empty( $tokens ) ) {
			return '';
		}

		$package = $this->index_package_files( $metadata );
		$warnings = array();
		$attachments = array();

		foreach ( $tokens as $token ) {
			$file = $this->resolve_package_file( $token, $package );
			if ( null === $file ) {
				/* translators: %s: image marker filename */
				$warnings[] = sprintf( __( 'No package file matched [image:%s].', '4wp-drive' ), $token );
				continue;
			}

			$result = Featured_Image_Importer::sideload_from_bytes(
				( $this->downloader )( (string) $file['id'] ),
				(string) $file['name'],
				$post_id,
				(string) get_post_field( 'post_name', $post_id ),
				false
			);

			if ( is_wp_error( $result ) ) {
				$warnings[] = $result->get_error_message();
				continue;
			}

			$url = wp_get_attachment_url( (int) $result );
			if ( ! is_string( $url ) || '' === $url ) {
				/* translators: %s: image marker filename */
				$warnings[] = sprintf( __( 'Uploaded [image:%s] but could not resolve attachment URL.', '4wp-drive' ), $token );
				continue;
			}

			$attachments[ $token ] = array(
				'id'  => (int) $result,
				'url' => $url,
				'alt' => pathinfo( (string) $file['name'], PATHINFO_FILENAME ),
			);
		}

		if ( empty( $attachments ) ) {
			return implode( ' ', $warnings );
		}

		$config = array(
			'type'        => 'core-image',
			'template'    => Block_Template_Registry::TEMPLATE_CORE_IMAGE,
			'attachments' => $attachments,
		);

		$recipe = new Core_Image_Recipe();
		$new    = $recipe->transform( $body, $config );

		if ( $new !== $body ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new,
				)
			);
		}

		return implode( ' ', $warnings );
	}

	/**
	 * Whether the Core Image template is active (default on).
	 */
	private function is_core_image_enabled(): bool {
		$settings = new Block_Mapping_Settings();
		foreach ( $settings->active_recipe_configs() as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $config['type'] ?? '' ) );
			if ( 'core-image' === $type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $metadata Document metadata.
	 * @return array<string, array{id: string, name: string, kind: string}>
	 */
	private function index_package_files( array $metadata ): array {
		$index = array();
		$files = isset( $metadata['package_files'] ) && is_array( $metadata['package_files'] )
			? $metadata['package_files']
			: array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$name = (string) ( $file['name'] ?? '' );
			$id   = (string) ( $file['id'] ?? '' );
			if ( '' === $name || '' === $id ) {
				continue;
			}
			$key           = Core_Image_Recipe::normalize_token( $name );
			$index[ $key ] = array(
				'id'   => $id,
				'name' => $name,
				'kind' => sanitize_key( (string) ( $file['kind'] ?? 'file' ) ),
			);
		}

		// Fallback when package_files missing: featured image only.
		$featured_id   = (string) ( $metadata['image_file_id'] ?? '' );
		$featured_name = (string) ( $metadata['image_file_name'] ?? '' );
		if ( '' !== $featured_id && '' !== $featured_name ) {
			$key = Core_Image_Recipe::normalize_token( $featured_name );
			if ( ! isset( $index[ $key ] ) ) {
				$index[ $key ] = array(
					'id'   => $featured_id,
					'name' => $featured_name,
					'kind' => 'image',
				);
			}
		}

		return $index;
	}

	/**
	 * @param string                                                                 $token   Normalized marker.
	 * @param array<string, array{id: string, name: string, kind: string}> $package Indexed files.
	 * @return array{id: string, name: string, kind: string}|null
	 */
	private function resolve_package_file( string $token, array $package ): ?array {
		if ( isset( $package[ $token ] ) ) {
			return $package[ $token ];
		}

		// Token without extension → unique basename match.
		$matches = array();
		foreach ( $package as $key => $file ) {
			$base = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_FILENAME ) );
			if ( $base === $token || $key === $token . '.jpg' || $key === $token . '.jpeg' || $key === $token . '.png' || $key === $token . '.webp' ) {
				$matches[] = $file;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}
}
