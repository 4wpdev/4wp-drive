<?php
/**
 * GitHub repo storage source (Markdown packages).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Sources;

use ForWP\Drive\Admin\GitHub_Settings;
use ForWP\Drive\Api\GitHub_Client;
use ForWP\Drive\Contracts\Storage_Source_Interface;
use ForWP\Drive\Import\Featured_Image_Chooser;
use ForWP\Drive\Import\Importable_Document;
use ForWP\Drive\Import\Markdown_Content;
use ForWP\Drive\Parse\Template_Parser;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Scans repo `incoming/` for Markdown articles and images.
 */
final class GitHub_Source implements Storage_Source_Interface {

	public const SLUG = 'github';

	public function get_slug(): string {
		return self::SLUG;
	}

	public function get_label(): string {
		return __( 'GitHub', '4wp-drive' );
	}

	public function is_ready(): bool {
		$cfg = GitHub_Settings::get_public();

		return $cfg['has_token'] && '' !== $cfg['owner'] && '' !== $cfg['repo'];
	}

	/**
	 * @param string $root_id Unused (paths come from GitHub settings).
	 * @return array{root: string, incoming: string, published: string, failed: string}|WP_Error
	 */
	public function resolve_folders( string $root_id ) {
		$cfg = GitHub_Settings::get_public();

		return array(
			'root'      => $cfg['owner'] . '/' . $cfg['repo'],
			'incoming'  => $cfg['incoming'],
			'published' => $cfg['published'],
			'failed'    => $cfg['failed'],
		);
	}

	public function scan_incoming() {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'forwp_drive_github_not_ready', __( 'GitHub is not configured.', '4wp-drive' ) );
		}

		$cfg    = GitHub_Settings::get_public();
		$client = new GitHub_Client();
		$parser = new Template_Parser();
		$entries = $client->list_path( $cfg['incoming'] );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$results = array();
		$seen    = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$type = (string) ( $entry['type'] ?? '' );
			$path = (string) ( $entry['path'] ?? '' );
			$name = (string) ( $entry['name'] ?? '' );
			if ( '' === $path ) {
				continue;
			}

			if ( 'dir' === $type ) {
				$item = $this->scan_package_dir( $client, $parser, $path, $name );
			} else {
				$item = $this->scan_flat_file( $client, $parser, $entry );
			}

			if ( null === $item ) {
				continue;
			}

			$file_id = (string) ( $item['file_id'] ?? '' );
			if ( '' === $file_id || isset( $seen[ $file_id ] ) ) {
				continue;
			}

			$seen[ $file_id ] = true;
			$results[]        = $item;
		}

		return $results;
	}

	/**
	 * @param array<string, mixed> $metadata Scan metadata.
	 * @return true|WP_Error
	 */
	public function move_after_import( string $file_id, string $target_role, array $metadata = array() ) {
		$cfg  = GitHub_Settings::get_public();
		$dest = $cfg[ $target_role ] ?? '';
		if ( '' === $dest ) {
			return new WP_Error( 'forwp_drive_github_folders', __( 'GitHub folder mapping is incomplete.', '4wp-drive' ) );
		}

		$path = self::path_from_file_id( $file_id );
		if ( '' === $path ) {
			$path = (string) ( $metadata['github_path'] ?? '' );
		}

		$package = (string) ( $metadata['package_folder_id'] ?? '' );
		$client  = new GitHub_Client();

		if ( '' !== $package ) {
			$listing = $client->list_path( $package );
			if ( is_wp_error( $listing ) ) {
				return $listing;
			}

			$ok = true;
			foreach ( $listing as $entry ) {
				if ( ! is_array( $entry ) || 'file' !== ( $entry['type'] ?? '' ) ) {
					continue;
				}
				$from = (string) ( $entry['path'] ?? '' );
				if ( '' === $from ) {
					continue;
				}
				$rel = substr( $from, strlen( $cfg['incoming'] ) );
				$to  = trim( $dest . $rel, '/' );
				$moved = $client->move_file( $from, $to );
				if ( is_wp_error( $moved ) ) {
					$ok = $moved;
				}
			}

			return true === $ok ? true : $ok;
		}

		if ( '' === $path ) {
			return new WP_Error( 'forwp_drive_github_path', __( 'Could not resolve GitHub file path.', '4wp-drive' ) );
		}

		$base = basename( $path );

		return $client->move_file( $path, $dest . '/' . $base );
	}

	/**
	 * Re-parse a package using a chosen article file.
	 *
	 * @param array<string, mixed> $item     Original scan item.
	 * @param string               $file_id  Selected GitHub file id.
	 * @return array<string, mixed>|null
	 */
	public function rescan_source_file( array $item, string $file_id ): ?array {
		$meta = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();
		$path = self::path_from_file_id( $file_id );
		if ( '' === $path ) {
			return null;
		}

		$client = new GitHub_Client();
		$parser = new Template_Parser();
		$name   = basename( $path );
		$image  = null;
		if ( ! empty( $meta['image_file_id'] ) ) {
			$image = array(
				'id'   => (string) $meta['image_file_id'],
				'name' => (string) ( $meta['image_file_name'] ?? '' ),
			);
		}

		$package_files = isset( $meta['package_files'] ) && is_array( $meta['package_files'] ) ? $meta['package_files'] : array();

		return $this->parse_markdown_file(
			$client,
			$parser,
			$path,
			$name,
			(string) ( $meta['package_folder_id'] ?? '' ),
			$image,
			$package_files,
			$file_id
		);
	}

	/**
	 * @param array<string, mixed> $entry Contents API file row.
	 * @return array<string, mixed>|null
	 */
	private function scan_flat_file( GitHub_Client $client, Template_Parser $parser, array $entry ): ?array {
		$name = (string) ( $entry['name'] ?? '' );
		$path = (string) ( $entry['path'] ?? '' );
		$kind = Importable_Document::kind( '', $name );
		if ( Importable_Document::KIND_MARKDOWN !== $kind ) {
			return null;
		}

		return $this->parse_markdown_file( $client, $parser, $path, $name, '', null, array(), $this->file_id_for_path( $path ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function scan_package_dir( GitHub_Client $client, Template_Parser $parser, string $dir_path, string $folder_name ): ?array {
		$listing = $client->list_path( $dir_path );
		if ( is_wp_error( $listing ) || empty( $listing ) ) {
			return null;
		}

		$article_rows = array();
		$image_rows   = array();
		$package_files = array();

		foreach ( $listing as $entry ) {
			if ( ! is_array( $entry ) || 'file' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}

			$name = (string) ( $entry['name'] ?? '' );
			$path = (string) ( $entry['path'] ?? '' );
			$id   = $this->file_id_for_path( $path );
			$kind = Importable_Document::kind( '', $name );
			$row  = array(
				'id'       => $id,
				'name'     => $name,
				'mimeType' => '',
				'path'     => $path,
			);

			if ( Importable_Document::KIND_IMAGE === $kind ) {
				$image_rows[]    = array(
					'id'   => $id,
					'name' => $name,
				);
				$package_files[] = array(
					'id'   => $id,
					'name' => $name,
					'kind' => 'image',
				);
				continue;
			}

			if ( Importable_Document::is_article( $kind ) ) {
				$article_rows[]  = $row;
				$package_files[] = array(
					'id'   => $id,
					'name' => $name,
					'kind' => 'document',
					'mime' => '',
				);
			}
		}

		$picked = Importable_Document::pick_default( $article_rows );
		if ( ! is_array( $picked ) ) {
			return null;
		}

		$suggested = Featured_Image_Chooser::suggest( $image_rows );
		$image     = null;
		if ( null !== $suggested ) {
			$image = array(
				'id'   => $suggested['id'],
				'name' => $suggested['name'],
			);
		}

		return $this->parse_markdown_file(
			$client,
			$parser,
			(string) ( $picked['path'] ?? '' ),
			(string) ( $picked['name'] ?? $folder_name ),
			$dir_path,
			$image,
			$package_files,
			(string) ( $picked['id'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed>|null            $image         Featured image.
	 * @param array<int, array<string, string>>    $package_files Package files.
	 * @return array<string, mixed>|null
	 */
	private function parse_markdown_file(
		GitHub_Client $client,
		Template_Parser $parser,
		string $path,
		string $file_name,
		string $package_folder_id,
		?array $image,
		array $package_files,
		string $file_id
	): ?array {
		if ( '' === $path || '' === $file_id ) {
			return null;
		}

		$raw = $client->get_file_contents( $path );
		$meta_extra = array(
			'github_path'     => $path,
			'image_file_id'   => is_array( $image ) ? (string) ( $image['id'] ?? '' ) : '',
			'image_file_name' => is_array( $image ) ? (string) ( $image['name'] ?? '' ) : '',
			'selected_file_id' => $file_id,
		);
		if ( '' !== $package_folder_id ) {
			$meta_extra['package_folder_id'] = $package_folder_id;
		}
		if ( ! empty( $package_files ) ) {
			$meta_extra['package_files'] = $package_files;
		}

		if ( is_wp_error( $raw ) ) {
			return array(
				'file_id'       => $file_id,
				'file_name'     => $file_name,
				'content_hash'  => 'export-error-' . md5( $file_id ),
				'export_failed' => true,
				'metadata'      => array_merge(
					array(
						'title'      => $file_name ?: __( 'Untitled', '4wp-drive' ),
						'scan_error' => $raw->get_error_message(),
					),
					$meta_extra
				),
			);
		}

		$html = Markdown_Content::to_html_document( (string) $raw );
		$hash = md5( (string) $raw );
		$meta = $parser->parse( $html );

		if ( '' === $meta['title'] ) {
			$meta['title'] = $file_name ?: __( 'Untitled', '4wp-drive' );
		}

		$meta = array_merge( $meta, $meta_extra );

		return array(
			'file_id'      => $file_id,
			'file_name'    => $file_name,
			'content_hash' => $hash,
			'metadata'     => $meta,
		);
	}

	private function file_id_for_path( string $path ): string {
		$cfg = GitHub_Settings::get_public();

		return 'gh:' . $cfg['owner'] . '/' . $cfg['repo'] . ':' . ltrim( $path, '/' );
	}

	/**
	 * Repo-relative path from a `gh:owner/repo:path` file id.
	 */
	public static function path_from_file_id( string $file_id ): string {
		if ( 0 !== strpos( $file_id, 'gh:' ) ) {
			return '';
		}

		$parts = explode( ':', $file_id, 3 );

		return isset( $parts[2] ) ? $parts[2] : '';
	}
}
