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
 * Scans the configured Incoming path (repo root by default) for Markdown packages.
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

	/**
	 * Browse Incoming + published + failed for the inbox tree (includes empty role folders).
	 *
	 * @return array{folders: array<string, array<string, mixed>>, files: array<int, array<string, mixed>>}|WP_Error
	 */
	public function browse_tree() {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'forwp_drive_github_not_ready', __( 'GitHub is not configured.', '4wp-drive' ) );
		}

		$cfg    = GitHub_Settings::get_public();
		$client = new GitHub_Client();

		$incoming  = trim( (string) $cfg['incoming'], '/' );
		$published = trim( (string) $cfg['published'], '/' );
		$failed    = trim( (string) $cfg['failed'], '/' );
		if ( '' === $published ) {
			$published = 'published';
		}
		if ( '' === $failed ) {
			$failed = 'failed';
		}

		$skip = array_filter( array( $published, $failed ) );
		$root = array(
			'folders' => array(),
			'files'   => array(),
		);

		if ( '' === $incoming ) {
			$this->fill_browse_level( $client, $root, '', $skip, 0 );
		} else {
			$root['folders'][ $incoming ] = $this->browse_role_node( $client, $incoming, $incoming, 'incoming', 0 );
		}

		$root['folders'][ $published ] = $this->browse_role_node( $client, $published, $published, 'published', 0 );
		$root['folders'][ $failed ]    = $this->browse_role_node( $client, $failed, $failed, 'failed', 0 );

		return $root;
	}

	/**
	 * @param array<string, mixed> $node   Tree node (folders/files).
	 * @param array<int, string>   $skip   Top-level dir names to omit (role folders added separately).
	 * @param int                  $depth  Nesting depth (0 = role / root listing).
	 */
	private function fill_browse_level( GitHub_Client $client, array &$node, string $path, array $skip, int $depth ): void {
		$entries = $client->list_path( $path );
		if ( is_wp_error( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$type = (string) ( $entry['type'] ?? '' );
			$name = (string) ( $entry['name'] ?? '' );
			$full = (string) ( $entry['path'] ?? '' );
			if ( '' === $name || '' === $full ) {
				continue;
			}

			if ( 'dir' === $type ) {
				if ( 0 === $depth && in_array( $name, $skip, true ) ) {
					continue;
				}
				$child = array(
					'name'    => $name,
					'path'    => $full,
					'role'    => '',
					'folders' => array(),
					'files'   => array(),
				);
				// Recurse into nested folders (packages, published/failed trees).
				if ( $depth < 5 ) {
					$this->fill_browse_level( $client, $child, $full, array(), $depth + 1 );
				}
				$node['folders'][ $name ] = $child;
				continue;
			}

			if ( 'file' !== $type ) {
				continue;
			}

			$kind  = 'file';
			$lower = strtolower( $name );
			if ( preg_match( '/\.(png|jpe?g|gif|webp|svg)$/', $lower ) ) {
				$kind = 'image';
			} elseif ( preg_match( '/\.(md|mdx|markdown)$/', $lower ) ) {
				$kind = 'document';
			}

			$node['files'][] = array(
				'id'   => $this->file_id_for_path( $full ),
				'name' => $name,
				'kind' => $kind,
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function browse_role_node( GitHub_Client $client, string $path, string $name, string $role, int $depth ): array {
		$node = array(
			'name'    => $name,
			'path'    => $path,
			'role'    => $role,
			'folders' => array(),
			'files'   => array(),
		);
		$this->fill_browse_level( $client, $node, $path, array(), $depth );

		return $node;
	}

	public function scan_incoming() {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'forwp_drive_github_not_ready', __( 'GitHub is not configured.', '4wp-drive' ) );
		}

		$cfg     = GitHub_Settings::get_public();
		$client  = new GitHub_Client();
		$parser  = new Template_Parser();
		$entries = $client->list_path( $cfg['incoming'] );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$skip = array_filter(
			array(
				trim( (string) $cfg['published'], '/' ),
				trim( (string) $cfg['failed'], '/' ),
			)
		);

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

			// When Incoming is repo root, never re-scan published/failed trees.
			if ( 'dir' === $type && in_array( $name, $skip, true ) ) {
				continue;
			}

			if ( 'dir' === $type ) {
				$found = $this->scan_dir_for_packages( $client, $parser, $path, $name, 0 );
				foreach ( $found as $item ) {
					$file_id = (string) ( $item['file_id'] ?? '' );
					if ( '' === $file_id || isset( $seen[ $file_id ] ) ) {
						continue;
					}
					$seen[ $file_id ] = true;
					$results[]        = $item;
				}
				continue;
			}

			$item = $this->scan_flat_file( $client, $parser, $entry );
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
	 * Discover packages at this path or nested under it (e.g. LMS4WP/articles, LMS4WP/courses/…).
	 *
	 * A directory with article files is one package. A container with only subfolders is walked.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scan_dir_for_packages( GitHub_Client $client, Template_Parser $parser, string $path, string $name, int $depth ): array {
		if ( $depth > 5 ) {
			return array();
		}

		$package = $this->scan_package_dir( $client, $parser, $path, $name );
		if ( null !== $package ) {
			return array( $package );
		}

		$listing = $client->list_path( $path );
		if ( is_wp_error( $listing ) || empty( $listing ) ) {
			return array();
		}

		$results = array();
		foreach ( $listing as $entry ) {
			if ( ! is_array( $entry ) || 'dir' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$child_path = (string) ( $entry['path'] ?? '' );
			$child_name = (string) ( $entry['name'] ?? '' );
			if ( '' === $child_path || '' === $child_name ) {
				continue;
			}
			foreach ( $this->scan_dir_for_packages( $client, $parser, $child_path, $child_name, $depth + 1 ) as $item ) {
				$results[] = $item;
			}
		}

		return $results;
	}

	/**
	 * Move the imported article; if it was alone in its package folder, move the whole folder.
	 *
	 * @param array<string, mixed> $metadata Scan metadata.
	 * @return true|WP_Error
	 */
	public function move_after_import( string $file_id, string $target_role, array $metadata = array() ) {
		$cfg  = GitHub_Settings::get_public();
		$dest = $cfg[ $target_role ] ?? '';
		if ( '' === $dest ) {
			return new WP_Error( 'forwp_drive_github_folders', __( 'GitHub folder mapping is incomplete.', '4wp-drive' ) );
		}

		$paths = $this->paths_to_move_after_import( $file_id, $metadata, $target_role );
		if ( empty( $paths ) ) {
			return new WP_Error( 'forwp_drive_github_path', __( 'Could not resolve GitHub file path.', '4wp-drive' ) );
		}

		$client = new GitHub_Client();
		$ok     = true;
		foreach ( $paths as $from ) {
			$to    = self::remap_under_role( $from, (string) $cfg['incoming'], $dest );
			$moved = $client->move_file( $from, $to );
			if ( is_wp_error( $moved ) ) {
				$ok = $moved;
			}
		}

		return true === $ok ? true : $ok;
	}

	/**
	 * Paths to move after import or reject.
	 *
	 * published: MD + used images; whole folder only if every file there was used.
	 * failed (reject): only the rejected article MD — never sibling files / whole package.
	 *
	 * @param array<string, mixed> $metadata    Scan metadata.
	 * @param string               $target_role published|failed.
	 * @return array<int, string>
	 */
	private function paths_to_move_after_import( string $file_id, array $metadata, string $target_role = 'published' ): array {
		$article = self::path_from_file_id( $file_id );
		if ( '' === $article ) {
			$article = trim( str_replace( '\\', '/', (string) ( $metadata['github_path'] ?? '' ) ), '/' );
		}
		$selected = (string) ( $metadata['selected_file_id'] ?? '' );
		if ( '' !== $selected ) {
			$selected_path = self::path_from_file_id( $selected );
			if ( '' !== $selected_path ) {
				$article = $selected_path;
			}
		}

		// Reject / fail: only the article, leave the rest of the package in Incoming.
		if ( 'failed' === $target_role ) {
			return '' !== $article ? array( $article ) : array();
		}

		$used_ids = isset( $metadata['used_source_file_ids'] ) && is_array( $metadata['used_source_file_ids'] )
			? $metadata['used_source_file_ids']
			: array();

		$used_paths = array();
		foreach ( $used_ids as $id ) {
			$path = self::path_from_file_id( (string) $id );
			if ( '' !== $path ) {
				$used_paths[ $path ] = $path;
			}
		}

		if ( '' !== $article ) {
			$used_paths[ $article ] = $article;
		}

		$featured = (string) ( $metadata['image_file_id'] ?? '' );
		if ( '' !== $featured ) {
			$featured_path = self::path_from_file_id( $featured );
			if ( '' !== $featured_path ) {
				$used_paths[ $featured_path ] = $featured_path;
			}
		}

		$package = trim( str_replace( '\\', '/', (string) ( $metadata['package_folder_id'] ?? '' ) ), '/' );
		if ( '' === $package ) {
			return array_values( $used_paths );
		}

		$client  = new GitHub_Client();
		$listing = $client->list_path( $package );
		if ( is_wp_error( $listing ) || empty( $listing ) ) {
			return array_values( $used_paths );
		}

		$folder_files = array();
		foreach ( $listing as $entry ) {
			if ( ! is_array( $entry ) || 'file' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$from = trim( (string) ( $entry['path'] ?? '' ), '/' );
			if ( '' !== $from ) {
				$folder_files[ $from ] = $from;
			}
		}

		if ( empty( $folder_files ) ) {
			return array_values( $used_paths );
		}

		// Whole folder only when every file there was used in this import.
		$unused = array_diff_key( $folder_files, $used_paths );
		if ( empty( $unused ) ) {
			return array_values( $folder_files );
		}

		$move = array();
		foreach ( $used_paths as $path ) {
			if ( isset( $folder_files[ $path ] ) || 0 === strpos( $path, $package . '/' ) || $path === $package ) {
				$move[ $path ] = $path;
			}
		}
		if ( '' !== $article ) {
			$move[ $article ] = $article;
		}

		return array_values( $move );
	}

	/**
	 * Map a path under Incoming into published/failed (Incoming may be repo root).
	 */
	private static function remap_under_role( string $from_path, string $incoming, string $dest ): string {
		$from     = trim( str_replace( '\\', '/', $from_path ), '/' );
		$incoming = trim( str_replace( '\\', '/', $incoming ), '/' );
		$dest     = trim( str_replace( '\\', '/', $dest ), '/' );

		if ( '' !== $incoming && ( $from === $incoming || 0 === strpos( $from, $incoming . '/' ) ) ) {
			$rel = trim( substr( $from, strlen( $incoming ) ), '/' );
		} else {
			$rel = $from;
		}

		return trim( $dest . '/' . $rel, '/' );
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

		$package_folder = (string) ( $meta['package_folder_id'] ?? '' );
		$package_files  = isset( $meta['package_files'] ) && is_array( $meta['package_files'] ) ? $meta['package_files'] : array();

		// Refresh sibling list from GitHub so the UI matches the live tree (not a stale sync snapshot).
		if ( '' !== $package_folder ) {
			$listing = $client->list_path( $package_folder );
			if ( ! is_wp_error( $listing ) && is_array( $listing ) ) {
				$refreshed = $this->package_files_from_listing( $listing );
				if ( ! empty( $refreshed ) ) {
					$package_files = $refreshed;
				}
			}
		}

		return $this->parse_markdown_file(
			$client,
			$parser,
			$path,
			$name,
			$package_folder,
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

		$article_rows  = array();
		$image_rows    = array();
		$package_files = $this->package_files_from_listing( $listing );

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
				$image_rows[] = array(
					'id'   => $id,
					'name' => $name,
				);
				continue;
			}

			if ( Importable_Document::is_article( $kind ) ) {
				$article_rows[] = $row;
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
	 * Build package_files entries from a GitHub contents listing.
	 *
	 * @param array<int, mixed> $listing Contents API rows.
	 * @return array<int, array<string, string>>
	 */
	private function package_files_from_listing( array $listing ): array {
		$package_files = array();

		foreach ( $listing as $entry ) {
			if ( ! is_array( $entry ) || 'file' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}

			$name = (string) ( $entry['name'] ?? '' );
			$path = (string) ( $entry['path'] ?? '' );
			if ( '' === $name || '' === $path ) {
				continue;
			}

			$id   = $this->file_id_for_path( $path );
			$kind = Importable_Document::kind( '', $name );

			if ( Importable_Document::KIND_IMAGE === $kind ) {
				$package_files[] = array(
					'id'   => $id,
					'name' => $name,
					'kind' => 'image',
				);
				continue;
			}

			if ( Importable_Document::is_article( $kind ) ) {
				$package_files[] = array(
					'id'   => $id,
					'name' => $name,
					'kind' => 'document',
					'mime' => '',
				);
			}
		}

		return $package_files;
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
			$meta_extra['package_folder_id']   = $package_folder_id;
			$parts                             = explode( '/', trim( str_replace( '\\', '/', $package_folder_id ), '/' ) );
			$meta_extra['package_folder_name'] = (string) end( $parts );
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
