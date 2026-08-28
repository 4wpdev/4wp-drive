<?php
/**
 * Recipe type registry.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

use ForWP\Drive\Blocks\Recipes\Faq_Accordion_Recipe;

defined( 'ABSPATH' ) || exit;

/**
 * Maps recipe type ids to implementations.
 */
final class Block_Recipe_Registry {

	/**
	 * @return array<string, class-string<Block_Recipe_Interface>>
	 */
	public static function type_map(): array {
		/**
		 * Register block recipe implementations.
		 *
		 * @param array<string, class-string<Block_Recipe_Interface>> $map type => class.
		 */
		$map = apply_filters(
			'forwp_drive_block_recipe_types',
			array(
				'faq-accordion' => Faq_Accordion_Recipe::class,
			)
		);

		return is_array( $map ) ? $map : array();
	}

	/**
	 * @param array<string, mixed> $config Recipe config.
	 */
	public static function create( array $config ): ?Block_Recipe_Interface {
		$type = sanitize_key( (string) ( $config['type'] ?? $config['id'] ?? '' ) );
		if ( '' === $type ) {
			return null;
		}

		$map = self::type_map();
		if ( ! isset( $map[ $type ] ) || ! is_string( $map[ $type ] ) ) {
			return null;
		}

		$class = $map[ $type ];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$recipe = new $class();
		if ( ! $recipe instanceof Block_Recipe_Interface ) {
			return null;
		}

		return $recipe;
	}
}
