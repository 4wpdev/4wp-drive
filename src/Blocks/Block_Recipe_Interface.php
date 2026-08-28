<?php
/**
 * Contract for HTML-to-block import recipes.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Transforms document body HTML using profile recipe config.
 */
interface Block_Recipe_Interface {

	/**
	 * Whether required plugins/blocks are available.
	 *
	 * @param array<string, mixed> $config Recipe config.
	 */
	public function requirements_met( array $config ): bool;

	/**
	 * Transform body HTML.
	 *
	 * @param string               $body_html Source HTML.
	 * @param array<string, mixed> $config    Recipe config.
	 */
	public function transform( string $body_html, array $config ): string;
}
