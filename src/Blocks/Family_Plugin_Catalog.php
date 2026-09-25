<?php
/**
 * Known 4WP family plugins Drive can wrap — even when they are not active yet.
 *
 * Add a row here (or via `forwp_drive_family_plugins`) so Patterns can link
 * Install / Activate. Wrap rows still come from each plugin’s
 * `forwp_drive_wrap_capabilities` filter after it is active.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

use ForWP\Drive\Multilingual\Installed_Plugin_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Drive-owned allow-list of sibling plugins.
 */
final class Family_Plugin_Catalog {

	/**
	 * Catalog keyed by plugin slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$plugins = array(
			'4wp-faq'           => array(
				'slug'         => '4wp-faq',
				'label'        => '4WP FAQ',
				'file'         => '4wp-faq/4wp-faq.php',
				'uri'          => 'https://4wp.dev/plugin/4wp-faq/',
				'wporg'        => '',
				'description'  => __( 'FAQ H2 + H3 Q&A → forwp/faq accordion on import.', '4wp-drive' ),
				'wrap_labels'  => array( '4WP FAQ' ),
			),
			'4wp-seo-helper'    => array(
				'slug'         => '4wp-seo-helper',
				'label'        => '4WP SEO Helper',
				'file'         => '4wp-seo-helper/4wp-seo-helper.php',
				'uri'          => 'https://4wp.dev/plugin/4wp-seo-helper/',
				'wporg'        => '',
				'description'  => __( 'TechArticle wraps: Goal, Context, Steps, Common mistakes.', '4wp-drive' ),
				'wrap_labels'  => array(
					'TechArticle Goal',
					'TechArticle Context',
					'TechArticle Steps',
					'TechArticle Common mistakes',
				),
			),
			'4wp-advanced-code' => array(
				'slug'         => '4wp-advanced-code',
				'label'        => '4WP Advanced Code',
				'file'         => '4wp-advanced-code/4wp-advanced-code.php',
				'uri'          => 'https://4wp.dev/plugin/4wp-advanced-code/',
				'wporg'        => '',
				'description'  => __( 'Mermaid fences → 4WP Diagram block on import.', '4wp-drive' ),
				'wrap_labels'  => array( '4WP Diagram' ),
			),
		);

		/**
		 * Register another family plugin for Drive Patterns (install / activate card).
		 *
		 * Each row: slug, label, file (plugin_basename), uri, optional wporg slug,
		 * description, wrap_labels (preview when the plugin is not active).
		 *
		 * @param array<string, array<string, mixed>> $plugins Slug => definition.
		 */
		$filtered = apply_filters( 'forwp_drive_family_plugins', $plugins );

		if ( ! is_array( $filtered ) ) {
			$filtered = $plugins;
		}

		$out = array();
		foreach ( $filtered as $slug => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = self::normalize( is_string( $slug ) ? $slug : '', $row );
			if ( null === $normalized ) {
				continue;
			}
			$out[ $normalized['slug'] ] = $normalized;
		}

		return $out;
	}

	/**
	 * One catalog row.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		$all  = self::all();

		return $all[ $slug ] ?? null;
	}

	public static function plugin_file( string $slug ): string {
		$row = self::get( $slug );
		if ( $row ) {
			return (string) $row['file'];
		}

		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}

		return $slug . '/' . $slug . '.php';
	}

	public static function is_installed( string $slug_or_file ): bool {
		$file = self::resolve_file( $slug_or_file );
		if ( '' === $file ) {
			return false;
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$path = WP_PLUGIN_DIR . '/' . $file;
			if ( is_readable( $path ) ) {
				return true;
			}
		}

		return Installed_Plugin_Detector::is_plugin_present( array( $file ) );
	}

	public static function is_active( string $slug ): bool {
		$slug = sanitize_key( $slug );
		if ( '4wp-faq' === $slug && defined( 'FORWP_FAQ_VERSION' ) ) {
			return true;
		}
		if ( '4wp-seo-helper' === $slug && defined( 'FORWP_SEO_HELPER_VERSION' ) ) {
			return true;
		}
		if ( '4wp-advanced-code' === $slug && defined( 'FORWP_ADVANCED_CODE_VERSION' ) ) {
			return true;
		}

		$file = self::plugin_file( $slug );
		if ( '' === $file ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( $file );
	}

	/**
	 * Admin action: activate (if uploaded) or get / install (if missing).
	 *
	 * @return array{url: string, label: string, kind: string}
	 */
	public static function action( string $slug ): array {
		$row  = self::get( $slug );
		$file = self::plugin_file( $slug );
		$uri  = is_array( $row ) ? (string) $row['uri'] : '';
		$wporg = is_array( $row ) ? (string) ( $row['wporg'] ?? '' ) : '';

		if ( self::is_active( $slug ) ) {
			$url = function_exists( 'self_admin_url' ) ? self_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
			return array(
				'url'   => $url,
				'label' => __( 'Plugins', '4wp-drive' ),
				'kind'  => 'active',
			);
		}

		if ( function_exists( 'current_user_can' ) && self::is_installed( $slug ) && $file && current_user_can( 'activate_plugins' ) && function_exists( 'wp_nonce_url' ) && function_exists( 'self_admin_url' ) ) {
			return array(
				'url'   => wp_nonce_url(
					self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ),
					'activate-plugin_' . $file
				),
				'label' => __( 'Activate', '4wp-drive' ),
				'kind'  => 'activate',
			);
		}

		if ( $wporg && function_exists( 'current_user_can' ) && current_user_can( 'install_plugins' ) && function_exists( 'self_admin_url' ) ) {
			return array(
				'url'   => self_admin_url(
					'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $wporg ) . '&TB_iframe=true&width=600&height=550'
				),
				'label' => __( 'Install', '4wp-drive' ),
				'kind'  => 'install',
			);
		}

		return array(
			'url'   => $uri,
			'label' => __( 'Get plugin', '4wp-drive' ),
			'kind'  => 'get',
		);
	}

	/**
	 * @param array<string, mixed> $row Raw catalog row.
	 * @return array<string, mixed>|null
	 */
	private static function normalize( string $slug, array $row ): ?array {
		$slug = sanitize_key( (string) ( $row['slug'] ?? $slug ) );
		if ( '' === $slug ) {
			return null;
		}

		$file = trim( (string) ( $row['file'] ?? '' ) );
		if ( '' === $file ) {
			$file = $slug . '/' . $slug . '.php';
		}

		$labels = array();
		if ( isset( $row['wrap_labels'] ) && is_array( $row['wrap_labels'] ) ) {
			foreach ( $row['wrap_labels'] as $label ) {
				$label = sanitize_text_field( (string) $label );
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}
		}

		$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $slug;
		}

		return array(
			'slug'        => $slug,
			'label'       => $label,
			'file'        => $file,
			'uri'         => esc_url_raw( (string) ( $row['uri'] ?? '' ) ),
			'wporg'       => sanitize_key( (string) ( $row['wporg'] ?? '' ) ),
			'description' => sanitize_text_field( (string) ( $row['description'] ?? '' ) ),
			'wrap_labels' => $labels,
		);
	}

	private static function resolve_file( string $slug_or_file ): string {
		$slug_or_file = trim( $slug_or_file );
		if ( '' === $slug_or_file ) {
			return '';
		}
		if ( false !== strpos( $slug_or_file, '/' ) ) {
			return ltrim( $slug_or_file, '/' );
		}

		return self::plugin_file( $slug_or_file );
	}
}
