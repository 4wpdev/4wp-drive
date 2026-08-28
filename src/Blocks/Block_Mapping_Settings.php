<?php
/**
 * User-facing block mapping settings (stored in wp_options).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-defined collection of document section → block template rules.
 */
final class Block_Mapping_Settings {

	public const OPTION = 'forwp_drive_block_mapping';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'rules' => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		if ( isset( $stored['faq'] ) && ! isset( $stored['rules'] ) ) {
			$stored = self::migrate_legacy_faq( $stored );
		}

		return self::normalize( array_replace_recursive( self::defaults(), $stored ) );
	}

	/**
	 * @param array<string, mixed> $input Raw settings payload.
	 */
	public function save( array $input ): void {
		update_option( self::OPTION, self::normalize( $input ) );
	}

	/**
	 * REST/admin payload.
	 *
	 * @return array<string, mixed>
	 */
	public function get_for_rest(): array {
		$mapping = $this->get();
		$rules   = array();

		foreach ( $mapping['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$template = Block_Template_Registry::get( (string) ( $rule['template'] ?? '' ) );
			$rule['template_label']  = $template ? (string) ( $template['label'] ?? '' ) : '';
			$rule['template_ready']  = $template ? Block_Template_Registry::is_template_ready( $template ) : false;
			$rule['template_status'] = $template ? Block_Template_Registry::get_status_message( $template ) : '';
			$rules[]                 = $rule;
		}

		return array(
			'rules'     => $rules,
			'templates' => Block_Template_Registry::get_for_rest(),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function active_recipe_configs(): array {
		$configs = array();

		foreach ( $this->get()['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$config = self::rule_to_recipe_config( $rule );
			if ( null !== $config ) {
				$configs[] = $config;
			}
		}

		/**
		 * Add programmatic import recipes (agencies).
		 *
		 * @param array<int, array<string, mixed>> $configs Recipe configs.
		 */
		$configs = apply_filters( 'forwp_drive_block_mapping_recipes', $configs );

		return is_array( $configs ) ? $configs : array();
	}

	/**
	 * @param array<string, mixed> $rule Saved mapping rule.
	 * @return array<string, mixed>|null
	 */
	public static function rule_to_recipe_config( array $rule ): ?array {
		if ( empty( $rule['enabled'] ) ) {
			return null;
		}

		$template_id = sanitize_key( (string) ( $rule['template'] ?? '' ) );
		$template    = Block_Template_Registry::get( $template_id );
		if ( null === $template || empty( $template['available'] ) ) {
			return null;
		}

		$headings = self::parse_heading_list( (string) ( $rule['section_headings'] ?? '' ) );
		if ( empty( $headings ) ) {
			return null;
		}

		$parts = array_map(
			static function ( string $heading ): string {
				return preg_quote( $heading, '/' );
			},
			$headings
		);

		return array(
			'type'                 => (string) ( $template['recipe_type'] ?? 'faq-accordion' ),
			'template'             => $template_id,
			'requires_plugins'     => isset( $template['requires_plugins'] ) && is_array( $template['requires_plugins'] )
				? $template['requires_plugins']
				: array(),
			'section_heading'      => array(
				'level' => 2,
				'match' => '^(' . implode( '|', $parts ) . ')$',
			),
			'item_heading_level'   => 3,
			'keep_section_heading' => ! empty( $rule['keep_section_heading'] ),
		);
	}

	/**
	 * @param array<string, mixed> $input Partial REST payload.
	 * @return array<string, mixed>
	 */
	public static function normalize_from_rest( array $input ): array {
		$rules = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();

		return self::normalize(
			array(
				'rules' => $rules,
			)
		);
	}

	/**
	 * @param array<string, mixed> $mapping Mapping array.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $mapping ): array {
		$rules_in = isset( $mapping['rules'] ) && is_array( $mapping['rules'] ) ? $mapping['rules'] : array();
		$rules    = array();

		foreach ( $rules_in as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$template = sanitize_key( (string) ( $rule['template'] ?? Block_Template_Registry::TEMPLATE_4WP_FAQ ) );
			if ( null === Block_Template_Registry::get( $template ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $rule['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = 'rule_' . wp_generate_password( 8, false, false );
			}

			$rules[] = array(
				'id'                   => $id,
				'enabled'              => ! empty( $rule['enabled'] ),
				'template'             => $template,
				'section_headings'     => self::sanitize_heading_list( (string) ( $rule['section_headings'] ?? 'FAQ' ) ),
				'keep_section_heading' => ! empty( $rule['keep_section_heading'] ),
			);
		}

		return array(
			'rules' => array_values( $rules ),
		);
	}

	/**
	 * @param array<string, mixed> $stored Legacy option shape.
	 * @return array<string, mixed>
	 */
	private static function migrate_legacy_faq( array $stored ): array {
		$faq = isset( $stored['faq'] ) && is_array( $stored['faq'] ) ? $stored['faq'] : array();
		if ( empty( $faq['enabled'] ) ) {
			return array( 'rules' => array() );
		}

		return array(
			'rules' => array(
				array(
					'id'                   => 'rule_faq',
					'enabled'              => true,
					'template'             => Block_Template_Registry::TEMPLATE_4WP_FAQ,
					'section_headings'     => (string) ( $faq['section_headings'] ?? 'FAQ, Frequently Asked Questions' ),
					'keep_section_heading' => ! empty( $faq['keep_section_heading'] ),
				),
			),
		);
	}

	/**
	 * @param string $value Comma-separated heading labels.
	 * @return string[]
	 */
	private static function parse_heading_list( string $value ): array {
		$parts = preg_split( '/\s*,\s*/', trim( $value ) ) ?: array();
		$clean = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param string $value Comma-separated heading labels.
	 */
	private static function sanitize_heading_list( string $value ): string {
		$headings = self::parse_heading_list( $value );
		if ( empty( $headings ) ) {
			return 'FAQ';
		}

		return implode( ', ', $headings );
	}
}
