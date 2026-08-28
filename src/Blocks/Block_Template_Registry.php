<?php
/**
 * Built-in block templates for Drive body mapping.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of import block templates the admin can add to their collection.
 */
final class Block_Template_Registry {

	public const TEMPLATE_4WP_FAQ         = '4wp-faq';
	public const TEMPLATE_CORE_ACCORDION  = 'core-accordion';
	public const TEMPLATE_CUSTOM_CTA      = 'custom-cta';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Register block templates for Drive import mapping.
		 *
		 * @param array<string, array<string, mixed>> $templates Template id => definition.
		 */
		$templates = apply_filters(
			'forwp_drive_block_templates',
			array(
				self::TEMPLATE_4WP_FAQ        => array(
					'label'            => __( '4WP FAQ', '4wp-drive' ),
					'description'      => __( 'Wraps matched sections in forwp/faq + core accordion (needs 4WP FAQ plugin).', '4wp-drive' ),
					'recipe_type'      => 'faq-accordion',
					'requires_plugins' => array( '4wp-faq/4wp-faq.php' ),
					'available'        => true,
					'doc_hint'         => __( 'Heading 2 = section title, Heading 3 = question, paragraphs/lists = answer.', '4wp-drive' ),
				),
				self::TEMPLATE_CORE_ACCORDION => array(
					'label'            => __( 'Core Accordion', '4wp-drive' ),
					'description'      => __( 'Outputs core/accordion only — no FAQ wrapper or JSON-LD.', '4wp-drive' ),
					'recipe_type'      => 'faq-accordion',
					'requires_plugins' => array(),
					'available'        => true,
					'doc_hint'         => __( 'Same heading pattern as 4WP FAQ: H2 section, H3 questions, body content for answers.', '4wp-drive' ),
				),
				self::TEMPLATE_CUSTOM_CTA     => array(
					'label'            => __( 'Custom CTA', '4wp-drive' ),
					'description'      => __( 'Site-specific call-to-action block — coming in a later release.', '4wp-drive' ),
					'recipe_type'      => 'custom-cta',
					'requires_plugins' => array(),
					'available'        => false,
					'doc_hint'         => '',
				),
			)
		);

		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * @param string $template_id Template slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $template_id ): ?array {
		$template_id = sanitize_key( $template_id );
		$templates   = self::all();

		if ( '' === $template_id || ! isset( $templates[ $template_id ] ) ) {
			return null;
		}

		$template           = $templates[ $template_id ];
		$template['id']     = $template_id;
		$template['available'] = ! empty( $template['available'] );

		return $template;
	}

	/**
	 * REST/admin list with readiness hints.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_rest(): array {
		$rows = array();

		foreach ( self::all() as $id => $template ) {
			$template['id']        = sanitize_key( (string) $id );
			$template['available'] = ! empty( $template['available'] );
			$template['ready']     = self::is_template_ready( $template );
			$template['status']    = self::get_status_message( $template );
			$rows[]                = $template;
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $template Template definition.
	 */
	public static function is_template_ready( array $template ): bool {
		if ( empty( $template['available'] ) ) {
			return false;
		}

		$required = isset( $template['requires_plugins'] ) && is_array( $template['requires_plugins'] )
			? $template['requires_plugins']
			: array();

		foreach ( $required as $plugin ) {
			$plugin = (string) $plugin;
			if ( '' === $plugin ) {
				continue;
			}

			if ( '4wp-faq/4wp-faq.php' === $plugin && defined( 'FORWP_FAQ_VERSION' ) ) {
				continue;
			}

			if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( $plugin ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $template Template definition.
	 */
	public static function get_for_rest_status( array $template ): string {
		return self::get_status_message( $template );
	}

	/**
	 * @param array<string, mixed> $template Template definition.
	 */
	public static function get_status_message( array $template ): string {
		if ( empty( $template['available'] ) ) {
			return (string) ( $template['description'] ?? '' );
		}

		if ( self::is_template_ready( $template ) ) {
			return '';
		}

		$required = isset( $template['requires_plugins'] ) && is_array( $template['requires_plugins'] )
			? $template['requires_plugins']
			: array();

		if ( in_array( '4wp-faq/4wp-faq.php', $required, true ) ) {
			return __( 'Install and activate the 4WP FAQ plugin to use this template.', '4wp-drive' );
		}

		return __( 'This template is not ready on this site yet.', '4wp-drive' );
	}
}
