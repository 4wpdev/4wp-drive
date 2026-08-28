<?php
/**
 * Applies saved block mapping rules to import body HTML.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates HTML to block markup conversion from Drive settings.
 */
final class Block_Recipe_Engine {

	/**
	 * @param string               $body_html Parsed document body HTML.
	 * @param array<string, mixed> $context   Optional parse context.
	 */
	public function apply( string $body_html, array $context = array() ): string {
		$settings = new Block_Mapping_Settings();
		$configs  = $settings->active_recipe_configs();

		foreach ( $configs as $config ) {
			if ( ! is_array( $config ) || empty( $config ) ) {
				continue;
			}

			$recipe = Block_Recipe_Registry::create( $config );
			if ( null === $recipe || ! $recipe->requirements_met( $config ) ) {
				continue;
			}

			$body_html = $recipe->transform( $body_html, $config );
		}

		/**
		 * Filter import body HTML before post creation.
		 *
		 * @param string               $body_html Body HTML.
		 * @param array<string, mixed> $context   Parse context.
		 */
		return (string) apply_filters( 'forwp_drive_import_body_html', $body_html, $context );
	}
}
