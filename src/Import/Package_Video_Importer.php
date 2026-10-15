<?php
/**
 * Sideload package-folder videos and replace [video:…] markers in post content.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Blocks\Block_Mapping_Settings;
use ForWP\Drive\Blocks\Block_Template_Registry;
use ForWP\Drive\Blocks\Recipes\Core_Video_Recipe;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the Core Video block template using Drive package files.
 */
final class Package_Video_Importer {

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
			return new WP_Error( 'forwp_drive_no_video', __( 'Video download is not configured.', '4wp-drive' ) );
		};
	}

	/**
	 * Sideload referenced package videos and rewrite post_content markers.
	 *
	 * @param int                  $post_id  WordPress post id.
	 * @param array<string, mixed> $metadata Document metadata (body_html, package_files).
	 * @return array{warning: string, used_file_ids: array<int, string>}
	 */
	public function apply_to_post( int $post_id, array $metadata ): array {
		$empty = array(
			'warning'       => '',
			'used_file_ids' => array(),
		);

		if ( $post_id <= 0 || ! $this->is_core_video_enabled() ) {
			return $empty;
		}

		$body = (string) get_post_field( 'post_content', $post_id );
		if ( '' === $body ) {
			$body = (string) ( $metadata['body_html'] ?? '' );
		}

		$body = Core_Video_Recipe::normalize_loose_markers( $body );

		$tokens     = Core_Video_Recipe::extract_tokens( $body );
		$has_remote = Core_Video_Recipe::body_has_remote_video( $body );
		if ( empty( $tokens ) && ! $has_remote ) {
			return $empty;
		}

		$package     = $this->index_package_files( $metadata );
		$warnings    = array();
		$attachments = array();
		$used_ids    = array();

		foreach ( $tokens as $token ) {
			$file = $this->resolve_package_file( $token, $package );
			if ( null === $file ) {
				/* translators: %s: video marker filename */
				$warnings[] = sprintf( __( 'No package file matched [video:%s].', '4wp-drive' ), $token );
				continue;
			}

			$file_id = (string) $file['id'];
			if ( '' !== $file_id ) {
				$used_ids[ $file_id ] = $file_id;
			}

			$result = Featured_Image_Importer::sideload_from_bytes(
				( $this->downloader )( $file_id ),
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
				/* translators: %s: video marker filename */
				$warnings[] = sprintf( __( 'Uploaded [video:%s] but could not resolve attachment URL.', '4wp-drive' ), $token );
				continue;
			}

			$attachments[ $token ] = array(
				'id'  => (int) $result,
				'url' => $url,
			);
		}

		if ( empty( $attachments ) && ! $has_remote ) {
			return array(
				'warning'       => implode( ' ', $warnings ),
				'used_file_ids' => array_values( $used_ids ),
			);
		}

		$config = array(
			'type'        => 'core-video',
			'template'    => Block_Template_Registry::TEMPLATE_CORE_VIDEO,
			'attachments' => $attachments,
		);

		$recipe = new Core_Video_Recipe();
		$new    = $recipe->transform( $body, $config );

		if ( $new !== $body ) {
			$kses_on = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			if ( $kses_on ) {
				kses_remove_filters();
			}
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $new ),
				)
			);
			if ( $kses_on ) {
				kses_init_filters();
			}
		}

		return array(
			'warning'       => implode( ' ', $warnings ),
			'used_file_ids' => array_values( $used_ids ),
		);
	}

	/**
	 * Whether the Core Video template is active (default on).
	 */
	private function is_core_video_enabled(): bool {
		$settings = new Block_Mapping_Settings();
		foreach ( $settings->active_recipe_configs() as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $config['type'] ?? '' ) );
			if ( 'core-video' === $type ) {
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
			$key           = Core_Video_Recipe::normalize_token( $name );
			$index[ $key ] = array(
				'id'   => $id,
				'name' => $name,
				'kind' => sanitize_key( (string) ( $file['kind'] ?? 'file' ) ),
			);
		}

		return $index;
	}

	/**
	 * @param string                                                      $token   Normalized marker.
	 * @param array<string, array{id: string, name: string, kind: string}> $package Indexed files.
	 * @return array{id: string, name: string, kind: string}|null
	 */
	private function resolve_package_file( string $token, array $package ): ?array {
		if ( isset( $package[ $token ] ) ) {
			return $package[ $token ];
		}

		$matches = array();
		foreach ( $package as $key => $file ) {
			$base = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_FILENAME ) );
			if (
				$base === $token
				|| $key === $token . '.mp4'
				|| $key === $token . '.webm'
				|| $key === $token . '.mov'
				|| $key === $token . '.m4v'
				|| $key === $token . '.ogg'
				|| $key === $token . '.ogv'
			) {
				$matches[] = $file;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}
}
