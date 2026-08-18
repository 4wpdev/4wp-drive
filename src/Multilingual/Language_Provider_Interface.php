<?php
/**
 * Multilingual plugin contract for Drive import.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Detect languages and assign/filter posts during import.
 */
interface Language_Provider_Interface {

	/**
	 * Provider slug (polylang, wpml, single).
	 */
	public function get_id(): string;

	/**
	 * Human-readable provider name.
	 */
	public function get_label(): string;

	/**
	 * Whether this provider is active on the site.
	 */
	public function is_available(): bool;

	/**
	 * Whether the plugin package is present (may be inactive).
	 */
	public function is_installed(): bool;

	/**
	 * Site languages for the import UI.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function get_languages(): array;

	/**
	 * True when the admin must pick a language (more than one configured).
	 */
	public function requires_manual_selection(): bool;

	/**
	 * Validate a language code from the import request.
	 *
	 * @param string $lang_code Language slug.
	 */
	public function is_valid_language( string $lang_code ): bool;

	/**
	 * Language assigned to a post.
	 *
	 * @param int $post_id Post id.
	 */
	public function get_post_language( int $post_id ): string;

	/**
	 * Assign language after create/update import.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $lang_code Language slug.
	 */
	public function assign_post_language( int $post_id, string $lang_code ): void;

	/**
	 * Limit WP_Query to one language.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @param string               $lang_code  Language slug.
	 * @return array<string, mixed>
	 */
	public function apply_language_to_query_args( array $query_args, string $lang_code ): array;
}
