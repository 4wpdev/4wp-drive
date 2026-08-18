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
	 * Preview panel root; theme CSS must stay inside this node in wp-admin.
	 *
	 * @var string
	 */
	private const PREVIEW_SCOPE = '#forwp-drive-preview-body';

	/**
	 * Enqueue preview styles on the Drive inbox admin screen.
	 *
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
		wp_register_style(
			'forwp-drive-preview-theme-scoped',
			false,
			array( 'forwp-drive-admin' ),
			FORWP_DRIVE_VERSION
		);
		wp_enqueue_style( 'forwp-drive-preview-theme-scoped' );

		$css = self::collect_scoped_theme_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'forwp-drive-preview-theme-scoped', $css );
		}

		/**
		 * Add theme or CPT-specific styles for the Drive document preview.
		 *
		 * Prefer `wp_add_inline_style( 'forwp-drive-preview-theme-scoped', Preview_Styles::scope_css( $css ) )`
		 * so rules do not leak into wp-admin chrome.
		 *
		 * @param string $post_type Post type selected in import settings.
		 */
		do_action( 'forwp_drive_enqueue_preview_styles', ( new Template_Config() )->get_import_post_type() );

		wp_enqueue_style(
			'forwp-drive-admin-preview',
			FORWP_DRIVE_URL . 'assets/admin-preview.css',
			array( 'forwp-drive-admin', 'forwp-drive-preview-theme-scoped' ),
			FORWP_DRIVE_VERSION
		);
	}

	/**
	 * Limit theme CSS to the preview panel so wp-admin chrome is unaffected.
	 *
	 * @param string $css Raw stylesheet contents.
	 */
	public static function scope_css( string $css ): string {
		$css = trim( $css );
		if ( '' === $css ) {
			return '';
		}

		return sprintf( '@scope (%s) { %s }', self::PREVIEW_SCOPE, $css );
	}

	/**
	 * Read active theme styles and wrap them for preview-only use.
	 */
	private static function collect_scoped_theme_css(): string {
		$parts = array();

		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$global = wp_get_global_stylesheet( array( 'variables', 'presets', 'styles' ) );
			if ( is_string( $global ) && '' !== trim( $global ) ) {
				$parts[] = $global;
			}
		}

		$theme_dir  = get_stylesheet_directory();
		$style_path = $theme_dir . '/style.css';
		if ( is_readable( $style_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file.
			$theme_css = self::strip_theme_header( (string) file_get_contents( $style_path ) );
			if ( '' !== $theme_css ) {
				$parts[] = $theme_css;
			}
		}

		$bundled = array( 'custom.css', 'single.css', 'articles.css' );
		foreach ( $bundled as $file ) {
			$path = $theme_dir . '/assets/css/' . $file;
			if ( ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file.
			$file_css = trim( (string) file_get_contents( $path ) );
			if ( '' !== $file_css ) {
				$parts[] = $file_css;
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		return self::scope_css( implode( "\n", $parts ) );
	}

	/**
	 * Remove the theme file header comment from style.css.
	 *
	 * @param string $css Theme stylesheet contents.
	 */
	private static function strip_theme_header( string $css ): string {
		return trim( (string) preg_replace( '/^\/\*[\s\S]*?\*\/\s*/', '', $css ) );
	}
}
