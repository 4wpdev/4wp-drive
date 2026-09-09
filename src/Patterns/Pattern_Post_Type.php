<?php
/**
 * Drive Patterns CPT (editable library after customize).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Patterns;

defined( 'ABSPATH' ) || exit;

/**
 * Registers forwp_drive_pattern — site-owned patterns after first customize.
 */
final class Pattern_Post_Type {

	public const POST_TYPE = 'forwp_drive_pattern';

	public const META_ENABLED              = '_forwp_drive_pattern_enabled';
	public const META_TEMPLATE             = '_forwp_drive_pattern_template';
	public const META_SECTION_HEADINGS     = '_forwp_drive_pattern_section_headings';
	public const META_KEEP_SECTION_HEADING = '_forwp_drive_pattern_keep_h2';
	public const META_ORIGIN               = '_forwp_drive_pattern_origin';
	public const META_PRESET_SLUG          = '_forwp_drive_pattern_preset_slug';

	/**
	 * Hook CPT registration.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Register the hidden Patterns post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array(
					'name'          => __( 'Drive Patterns', '4wp-drive' ),
					'singular_name' => __( 'Drive Pattern', '4wp-drive' ),
				),
				'public'           => false,
				'show_ui'          => false,
				'show_in_menu'     => false,
				'show_in_rest'     => false,
				'capability_type'  => 'post',
				'map_meta_cap'     => true,
				'hierarchical'     => false,
				'supports'         => array( 'title' ),
				'has_archive'      => false,
				'rewrite'          => false,
				'query_var'        => false,
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Flush rewrite rules after CPT registration (activation).
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::register();
		flush_rewrite_rules( false );
	}
}
