<?php
/**
 * Pattern library: presets until customize, then CPT.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Patterns;

use ForWP\Drive\Blocks\Block_Mapping_Settings;
use ForWP\Drive\Blocks\Block_Template_Registry;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves active pattern rules for import and the Patterns screen.
 */
final class Pattern_Library {

	public const OPTION_CUSTOMIZED     = 'forwp_drive_patterns_customized';
	public const OPTION_PRESET_ENABLED = 'forwp_drive_pattern_preset_enabled';

	/**
	 * Ensure the site has an editable CPT library (empty or migrated once).
	 *
	 * Does not dump all code presets as rows — the admin creates patterns.
	 * Legacy option rules (if any) are imported once.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_editable_library() {
		if ( self::is_customized() ) {
			return true;
		}

		$legacy = ( new Block_Mapping_Settings() )->get()['rules'];
		foreach ( $legacy as $legacy_rule ) {
			if ( ! is_array( $legacy_rule ) ) {
				continue;
			}
			$template = sanitize_key( (string) ( $legacy_rule['template'] ?? '' ) );
			if ( '' === $template ) {
				continue;
			}
			$result = self::insert_pattern_from_rule(
				array(
					'id'                   => (string) ( $legacy_rule['id'] ?? '' ),
					'enabled'              => ! empty( $legacy_rule['enabled'] ),
					'template'             => $template,
					'section_headings'     => (string) ( $legacy_rule['section_headings'] ?? '' ),
					'keep_section_heading' => ! empty( $legacy_rule['keep_section_heading'] ),
					'origin'               => 'custom',
					'label'                => '',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		update_option( self::OPTION_CUSTOMIZED, true, false );

		return true;
	}

	/**
	 * Whether the site owns an editable CPT library.
	 */
	public static function is_customized(): bool {
		return (bool) get_option( self::OPTION_CUSTOMIZED, false );
	}

	/**
	 * Light overrides for preset enabled flags (before CPT customize).
	 *
	 * @return array<string, bool>
	 */
	public static function get_preset_enabled_overrides(): array {
		$stored = get_option( self::OPTION_PRESET_ENABLED, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( $stored as $slug => $enabled ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug ) {
				$out[ $slug ] = ! empty( $enabled );
			}
		}

		return $out;
	}

	/**
	 * Persist enabled flags for code presets (pre-customize).
	 *
	 * @param array<string, bool> $overrides Slug => enabled.
	 */
	public static function save_preset_enabled_overrides( array $overrides ): void {
		$clean = array();
		foreach ( $overrides as $slug => $enabled ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug ) {
				$clean[ $slug ] = ! empty( $enabled );
			}
		}
		update_option( self::OPTION_PRESET_ENABLED, $clean, false );
	}

	/**
	 * Rules for admin UI (presets or CPT).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rules(): array {
		if ( self::is_customized() ) {
			return self::rules_from_cpt();
		}

		return Pattern_Preset_Registry::as_rules( self::get_preset_enabled_overrides() );
	}

	/**
	 * Recipe configs for Block_Recipe_Engine.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function active_recipe_configs(): array {
		$configs             = array();
		$has_core_image      = false;
		$core_image_disabled = false;

		foreach ( self::get_rules() as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$template_id = sanitize_key( (string) ( $rule['template'] ?? '' ) );
			if ( Block_Template_Registry::TEMPLATE_CORE_IMAGE === $template_id && empty( $rule['enabled'] ) ) {
				$core_image_disabled = true;
			}

			$config = Block_Mapping_Settings::rule_to_recipe_config( $rule );
			if ( null !== $config ) {
				if ( 'core-image' === (string) ( $config['type'] ?? '' ) ) {
					$has_core_image = true;
				}
				$configs[] = $config;
			}
		}

		if ( ! $has_core_image && ! $core_image_disabled ) {
			/**
			 * Whether to apply the default Core Image marker recipe when no image pattern is enabled.
			 *
			 * @param bool $enabled Default true.
			 */
			if ( (bool) apply_filters( 'forwp_drive_default_core_image_recipe', true ) ) {
				array_unshift( $configs, Block_Mapping_Settings::default_core_image_config() );
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
	 * REST payload for Patterns screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_for_rest(): array {
		$rules = self::get_rules();
		$rows  = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$template                = Block_Template_Registry::get( (string) ( $rule['template'] ?? '' ) );
			$rule['template_label']  = $template ? (string) ( $template['label'] ?? '' ) : (string) ( $rule['label'] ?? '' );
			$rule['template_ready']  = $template ? Block_Template_Registry::is_template_ready( $template ) : false;
			$rule['template_status'] = $template ? Block_Template_Registry::get_status_message( $template ) : '';
			$rows[]                  = $rule;
		}

		return array(
			'customized' => self::is_customized(),
			'rules'      => $rows,
			'presets'    => array_values(
				array_map(
					static function ( $slug, $preset ) {
						$preset['slug'] = $slug;
						return $preset;
					},
					array_keys( Pattern_Preset_Registry::all() ),
					Pattern_Preset_Registry::all()
				)
			),
			'templates'  => Block_Template_Registry::get_for_rest(),
		);
	}

	/**
	 * Copy presets (+ legacy option rules) into CPT and flip the gate.
	 *
	 * @return true|WP_Error
	 */
	public static function begin_customize() {
		if ( self::is_customized() ) {
			return true;
		}

		$rules = Pattern_Preset_Registry::as_rules( self::get_preset_enabled_overrides() );

		// Merge legacy Block Mapping option rules (older installs).
		$legacy = ( new Block_Mapping_Settings() )->get()['rules'];
		foreach ( $legacy as $legacy_rule ) {
			if ( ! is_array( $legacy_rule ) ) {
				continue;
			}
			$template = sanitize_key( (string) ( $legacy_rule['template'] ?? '' ) );
			$found    = false;
			foreach ( $rules as &$rule ) {
				if ( ( $rule['template'] ?? '' ) === $template ) {
					$rule['enabled']              = ! empty( $legacy_rule['enabled'] );
					$rule['section_headings']     = (string) ( $legacy_rule['section_headings'] ?? $rule['section_headings'] );
					$rule['keep_section_heading'] = ! empty( $legacy_rule['keep_section_heading'] );
					$found                        = true;
					break;
				}
			}
			unset( $rule );
			if ( ! $found && '' !== $template ) {
				$rules[] = array(
					'id'                   => (string) ( $legacy_rule['id'] ?? '' ),
					'enabled'              => ! empty( $legacy_rule['enabled'] ),
					'template'             => $template,
					'section_headings'     => (string) ( $legacy_rule['section_headings'] ?? '' ),
					'keep_section_heading' => ! empty( $legacy_rule['keep_section_heading'] ),
					'origin'               => 'custom',
					'label'                => $template,
				);
			}
		}

		foreach ( $rules as $rule ) {
			$result = self::insert_pattern_from_rule( $rule );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		update_option( self::OPTION_CUSTOMIZED, true, false );

		return true;
	}

	/**
	 * Save CPT rules (site must already be customized).
	 *
	 * @param array<int, array<string, mixed>> $rules Rules from REST.
	 * @return true|WP_Error
	 */
	public static function save_custom_rules( array $rules ) {
		if ( ! self::is_customized() ) {
			$started = self::ensure_editable_library();
			if ( is_wp_error( $started ) ) {
				return $started;
			}
		}

		$existing = get_posts(
			array(
				'post_type'      => Pattern_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$keep_ids = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$post_id = isset( $rule['post_id'] ) ? (int) $rule['post_id'] : 0;
			if ( $post_id > 0 ) {
				$updated = self::update_pattern_post( $post_id, $rule );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$keep_ids[] = $post_id;
				continue;
			}

			$created = self::insert_pattern_from_rule( $rule );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$keep_ids[] = (int) $created;
		}

		foreach ( $existing as $id ) {
			$id = (int) $id;
			if ( $id && ! in_array( $id, $keep_ids, true ) ) {
				wp_trash_post( $id );
			}
		}

		return true;
	}

	/**
	 * Load pattern rules from CPT posts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rules_from_cpt(): array {
		$query = new WP_Query(
			array(
				'post_type'      => Pattern_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		$rules = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$rules[] = self::post_to_rule( $post );
		}

		return $rules;
	}

	/**
	 * Map a pattern post to a UI/recipe rule row.
	 *
	 * @param WP_Post $post Pattern post.
	 * @return array<string, mixed>
	 */
	private static function post_to_rule( WP_Post $post ): array {
		$template = sanitize_key( (string) get_post_meta( $post->ID, Pattern_Post_Type::META_TEMPLATE, true ) );
		$origin   = sanitize_key( (string) get_post_meta( $post->ID, Pattern_Post_Type::META_ORIGIN, true ) );
		if ( '' === $origin ) {
			$origin = 'custom';
		}

		return array(
			'id'                   => 'cpt_' . $post->ID,
			'post_id'              => (int) $post->ID,
			'enabled'              => (bool) get_post_meta( $post->ID, Pattern_Post_Type::META_ENABLED, true ),
			'template'             => $template,
			'section_headings'     => (string) get_post_meta( $post->ID, Pattern_Post_Type::META_SECTION_HEADINGS, true ),
			'keep_section_heading' => (bool) get_post_meta( $post->ID, Pattern_Post_Type::META_KEEP_SECTION_HEADING, true ),
			'origin'               => $origin,
			'preset_slug'          => sanitize_key( (string) get_post_meta( $post->ID, Pattern_Post_Type::META_PRESET_SLUG, true ) ),
			'label'                => $post->post_title,
		);
	}

	/**
	 * Insert a CPT pattern from a rule row.
	 *
	 * @param array<string, mixed> $rule Rule row.
	 * @return int|WP_Error Post id.
	 */
	private static function insert_pattern_from_rule( array $rule ) {
		$template = sanitize_key( (string) ( $rule['template'] ?? '' ) );
		if ( '' === $template ) {
			return new WP_Error( 'forwp_drive_pattern_template', __( 'Pattern template is required.', '4wp-drive' ) );
		}

		$title = (string) ( $rule['label'] ?? '' );
		if ( '' === $title ) {
			$def   = Block_Template_Registry::get( $template );
			$title = $def ? (string) ( $def['label'] ?? $template ) : $template;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Pattern_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::write_pattern_meta( (int) $post_id, $rule );

		return (int) $post_id;
	}

	/**
	 * Update an existing pattern post.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $rule    Rule row.
	 * @return true|WP_Error
	 */
	private static function update_pattern_post( int $post_id, array $rule ) {
		$post = get_post( $post_id );
		if ( ! $post || Pattern_Post_Type::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'forwp_drive_pattern_missing', __( 'Pattern not found.', '4wp-drive' ) );
		}

		$title = (string) ( $rule['label'] ?? $post->post_title );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			)
		);
		self::write_pattern_meta( $post_id, $rule );

		return true;
	}

	/**
	 * Write pattern meta fields.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $rule    Rule row.
	 */
	private static function write_pattern_meta( int $post_id, array $rule ): void {
		$origin = sanitize_key( (string) ( $rule['origin'] ?? 'custom' ) );
		if ( ! in_array( $origin, array( 'preset', 'custom' ), true ) ) {
			$origin = 'custom';
		}

		update_post_meta( $post_id, Pattern_Post_Type::META_ENABLED, ! empty( $rule['enabled'] ) ? 1 : 0 );
		update_post_meta( $post_id, Pattern_Post_Type::META_TEMPLATE, sanitize_key( (string) ( $rule['template'] ?? '' ) ) );
		update_post_meta( $post_id, Pattern_Post_Type::META_SECTION_HEADINGS, sanitize_text_field( (string) ( $rule['section_headings'] ?? '' ) ) );
		update_post_meta( $post_id, Pattern_Post_Type::META_KEEP_SECTION_HEADING, ! empty( $rule['keep_section_heading'] ) ? 1 : 0 );
		update_post_meta( $post_id, Pattern_Post_Type::META_ORIGIN, $origin );
		update_post_meta( $post_id, Pattern_Post_Type::META_PRESET_SLUG, sanitize_key( (string) ( $rule['preset_slug'] ?? '' ) ) );
	}
}
