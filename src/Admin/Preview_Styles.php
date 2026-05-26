<?php
/**
 * Enqueue frontend single-post styles for the Drive inbox preview.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Admin;

use ForWP\Drive\Parse\Template_Config;

defined( 'ABSPATH' ) || exit;

/**
 * Loads active theme CSS so inbox preview matches the configured import CPT single view.
 */
final class Preview_Styles {

	/**
	 * @param string $hook_suffix Admin screen hook.
	 */
	public static function enqueue_for_screen( string $hook_suffix ): void {
		if ( 'toplevel_page_forwp-drive-inbox' !== $hook_suffix ) {
			return;
		}

		self::enqueue_theme_single_styles();
	}

	/**
	 * Theme styles used on the frontend single template for the import post type.
	 */
	private static function enqueue_theme_single_styles(): void {
		if ( function_exists( 'wp_enqueue_global_styles' ) ) {
			wp_enqueue_global_styles();
		}

		$theme     = wp_get_theme();
		$version   = $theme->get( 'Version' ) ?: FORWP_DRIVE_VERSION;
		$theme_uri = get_stylesheet_directory_uri();
		$theme_dir = get_stylesheet_directory();

		wp_enqueue_style(
			'forwp-drive-preview-theme',
			get_stylesheet_uri(),
			array(),
			$version
		);

		$bundled = array(
			'custom.css'   => array( 'forwp-drive-preview-theme' ),
			'single.css'   => array( 'forwp-drive-preview-theme' ),
			'articles.css' => array( 'forwp-drive-preview-theme' ),
		);

		foreach ( $bundled as $file => $deps ) {
			$path = $theme_dir . '/assets/css/' . $file;
			if ( ! is_readable( $path ) ) {
				continue;
			}

			wp_enqueue_style(
				'forwp-drive-preview-' . sanitize_key( basename( $file, '.css' ) ),
				$theme_uri . '/assets/css/' . $file,
				$deps,
				(string) filemtime( $path )
			);
		}

		/**
		 * Add theme or CPT-specific styles for the Drive document preview.
		 *
		 * @param string $post_type Post type selected in import settings.
		 */
		do_action( 'forwp_drive_enqueue_preview_styles', ( new Template_Config() )->get_import_post_type() );

		$preview_deps = array( 'forwp-drive-admin', 'forwp-drive-preview-theme' );
		foreach ( array( 'single', 'custom', 'articles' ) as $slug ) {
			$handle = 'forwp-drive-preview-' . $slug;
			if ( wp_style_is( $handle, 'registered' ) ) {
				$preview_deps[] = $handle;
			}
		}

		wp_enqueue_style(
			'forwp-drive-admin-preview',
			FORWP_DRIVE_URL . 'assets/admin-preview.css',
			$preview_deps,
			FORWP_DRIVE_VERSION
		);
	}
}
