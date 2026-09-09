<?php
/**
 * Code-level pattern presets (not stored in DB until customize).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Patterns;

use ForWP\Drive\Blocks\Block_Template_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Opinionated presets we ship from practice.
 */
final class Pattern_Preset_Registry {

	/**
	 * All code presets, filterable.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Register Drive pattern presets (code, until the site customizes).
		 *
		 * @param array<string, array<string, mixed>> $presets Slug => definition.
		 */
		$presets = apply_filters(
			'forwp_drive_pattern_presets',
			array(
				'core-image'     => array(
					'label'                => __( 'Core Image', '4wp-drive' ),
					'description'          => __( 'Package folder images via [image:filename] markers.', '4wp-drive' ),
					'template'             => Block_Template_Registry::TEMPLATE_CORE_IMAGE,
					'section_headings'     => '',
					'keep_section_heading' => false,
					'default_enabled'      => true,
					'doc_hint'             => __( 'Put images in the article subfolder. In the Google Doc write [image:exact-filename.jpeg].', '4wp-drive' ),
				),
				'4wp-faq'        => array(
					'label'                => __( '4WP FAQ', '4wp-drive' ),
					'description'          => __( 'H2 section + H3 questions → forwp/faq accordion.', '4wp-drive' ),
					'template'             => Block_Template_Registry::TEMPLATE_4WP_FAQ,
					'section_headings'     => 'FAQ, Frequently Asked Questions',
					'keep_section_heading' => false,
					'default_enabled'      => false,
					'doc_hint'             => __( 'Heading 2 = FAQ · Heading 3 = question · Normal text = answer.', '4wp-drive' ),
				),
				'core-accordion' => array(
					'label'                => __( 'Core Accordion', '4wp-drive' ),
					'description'          => __( 'Same Doc pattern as FAQ, core/accordion only.', '4wp-drive' ),
					'template'             => Block_Template_Registry::TEMPLATE_CORE_ACCORDION,
					'section_headings'     => 'FAQ, Frequently Asked Questions',
					'keep_section_heading' => false,
					'default_enabled'      => false,
					'doc_hint'             => __( 'Same heading pattern as 4WP FAQ without the FAQ wrapper.', '4wp-drive' ),
				),
				'core-quote'     => array(
					'label'                => __( 'Core Quote', '4wp-drive' ),
					'description'          => __( 'Pull-quote sections — planned preset.', '4wp-drive' ),
					'template'             => '',
					'section_headings'     => 'Quote',
					'keep_section_heading' => false,
					'default_enabled'      => false,
					'available'            => false,
					'doc_hint'             => '',
				),
			)
		);

		return is_array( $presets ) ? $presets : array();
	}

	/**
	 * One preset by slug.
	 *
	 * @param string $slug Preset slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$slug    = sanitize_key( $slug );
		$presets = self::all();
		if ( '' === $slug || ! isset( $presets[ $slug ] ) || ! is_array( $presets[ $slug ] ) ) {
			return null;
		}

		$row         = $presets[ $slug ];
		$row['slug'] = $slug;
		if ( ! isset( $row['available'] ) ) {
			$row['available'] = true;
		}

		return $row;
	}

	/**
	 * Preset rows shaped like mapping rules for the admin UI / recipes.
	 *
	 * @param array<string, bool> $enabled_overrides Optional slug => enabled.
	 * @return array<int, array<string, mixed>>
	 */
	public static function as_rules( array $enabled_overrides = array() ): array {
		$rules = array();

		foreach ( self::all() as $slug => $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			if ( isset( $preset['available'] ) && empty( $preset['available'] ) ) {
				continue;
			}

			$template = sanitize_key( (string) ( $preset['template'] ?? '' ) );
			if ( '' === $template || null === Block_Template_Registry::get( $template ) ) {
				continue;
			}

			$enabled = array_key_exists( $slug, $enabled_overrides )
				? ! empty( $enabled_overrides[ $slug ] )
				: ! empty( $preset['default_enabled'] );

			$rules[] = array(
				'id'                   => 'preset_' . sanitize_key( (string) $slug ),
				'enabled'              => $enabled,
				'template'             => $template,
				'section_headings'     => (string) ( $preset['section_headings'] ?? '' ),
				'keep_section_heading' => ! empty( $preset['keep_section_heading'] ),
				'origin'               => 'preset',
				'preset_slug'          => sanitize_key( (string) $slug ),
				'label'                => (string) ( $preset['label'] ?? $slug ),
				'description'          => (string) ( $preset['description'] ?? '' ),
				'doc_hint'             => (string) ( $preset['doc_hint'] ?? '' ),
			);
		}

		return $rules;
	}
}
