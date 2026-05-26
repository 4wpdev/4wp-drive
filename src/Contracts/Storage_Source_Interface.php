<?php
/**
 * Storage source contract (Google Drive, roadmap: GitHub, Dropbox, etc.).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Contracts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for document storage backends.
 */
interface Storage_Source_Interface {

	/**
	 * Unique source slug.
	 */
	public function get_slug(): string;

	/**
	 * Human-readable label.
	 */
	public function get_label(): string;

	/**
	 * Whether this source is configured and connected.
	 */
	public function is_ready(): bool;

	/**
	 * Ensure folder structure exists; return folder ids by role.
	 *
	 * @param string $root_id Root folder id (admin-selected).
	 * @return array{root: string, incoming: string, published: string, failed: string}|WP_Error
	 */
	public function resolve_folders( string $root_id );

	/**
	 * Scan incoming folder and return parsed document payloads.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error Each item: file_id, file_name, content_hash, metadata.
	 */
	public function scan_incoming();

	/**
	 * Move file from incoming to published (or failed).
	 *
	 * @param string $file_id   Drive file id.
	 * @param string $target_role published|failed.
	 * @return true|WP_Error
	 */
	public function move_after_import( string $file_id, string $target_role );
}
