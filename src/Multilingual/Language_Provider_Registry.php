<?php
/**
 * Detect and expose the active multilingual provider.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Polylang for import; WPML is registered in Settings as planned.
 */
final class Language_Provider_Registry {

	/**
	 * @var Language_Provider_Interface|null
	 */
	private static $active = null;

	/**
	 * @return list<Language_Provider_Interface>
	 */
	public static function get_providers(): array {
		$providers = array(
			new Polylang_Provider(),
		);

		/**
		 * Register multilingual providers for Drive import.
		 *
		 * @param Language_Provider_Interface[] $providers Provider instances.
		 */
		$providers = apply_filters( 'forwp_drive_language_providers', $providers );

		return is_array( $providers ) ? $providers : array();
	}

	/**
	 * Active provider for this request.
	 */
	public static function get_active(): Language_Provider_Interface {
		if ( null !== self::$active ) {
			return self::$active;
		}

		foreach ( self::get_providers() as $provider ) {
			if ( $provider->is_available() ) {
				self::$active = $provider;
				return self::$active;
			}
		}

		self::$active = new Single_Site_Provider();

		return self::$active;
	}

	/**
	 * REST payload for inbox import UI.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_rest_payload(): array {
		$provider  = self::get_active();
		$languages = $provider->get_languages();

		return array(
			'provider_id'        => $provider->get_id(),
			'provider_label'     => $provider->get_label(),
			'requires_selection' => $provider->requires_manual_selection(),
			'languages'          => $languages,
		);
	}

	/**
	 * Rows for Settings multilingual registry (mirrors storage source cards).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_admin_status_rows(): array {
		$active    = self::get_active();
		$active_id = $active->get_id();
		$rows      = array();

		foreach ( self::get_providers() as $provider ) {
			$available = $provider->is_available();
			$installed = $provider->is_installed();
			$is_active = $active_id === $provider->get_id();

			$rows[] = array(
				'slug'         => $provider->get_id(),
				'label'        => $provider->get_label(),
				'implemented'  => true,
				'planned'      => false,
				'installed'    => $installed,
				'available'    => $available,
				'active'       => $is_active,
				'languages'    => $available ? $provider->get_languages() : array(),
				'status'       => self::build_admin_status_message( $provider, $is_active, $available, $installed ),
			);
		}

		$wpml = new Wpml_Provider();
		$rows[] = array(
			'slug'        => $wpml->get_id(),
			'label'       => $wpml->get_label(),
			'implemented' => false,
			'planned'     => true,
			'installed'   => $wpml->is_installed(),
			'available'   => false,
			'active'      => false,
			'languages'   => array(),
			'status'      => $wpml->is_installed()
				? __( 'Planned — WPML is installed on this site but not yet supported for Drive import. Use Polylang for multilingual import today.', '4wp-drive' )
				: __( 'Planned — WPML integration is on the roadmap. Polylang is supported in this release.', '4wp-drive' ),
		);

		$single = new Single_Site_Provider();
		$rows[] = array(
			'slug'        => $single->get_id(),
			'label'       => $single->get_label(),
			'implemented' => true,
			'planned'     => false,
			'installed'   => false,
			'available'   => true,
			'active'      => 'single' === $active_id,
			'languages'   => 'single' === $active_id ? $single->get_languages() : array(),
			'status'      => self::build_admin_status_message( $single, 'single' === $active_id, true, false ),
		);

		/**
		 * Filter multilingual provider rows on the Settings screen.
		 *
		 * @param array<int, array<string, mixed>> $rows Registry rows.
		 */
		$filtered = apply_filters( 'forwp_drive_language_provider_admin_rows', $rows );

		return is_array( $filtered ) ? $filtered : $rows;
	}

	/**
	 * Human-readable integration line for a provider card.
	 *
	 * @param Language_Provider_Interface $provider  Provider instance.
	 * @param bool                        $is_active Whether this provider handles import.
	 * @param bool                        $available Whether the plugin is loaded and usable.
	 * @param bool                        $installed Whether the plugin package is on disk.
	 */
	private static function build_admin_status_message(
		Language_Provider_Interface $provider,
		bool $is_active,
		bool $available,
		bool $installed
	): string {
		if ( 'single' === $provider->get_id() ) {
			if ( $is_active ) {
				return __( 'Active — single-language site. Imports do not show a language picker in the Inbox.', '4wp-drive' );
			}

			return __( 'Fallback when no multilingual plugin is active on this site.', '4wp-drive' );
		}

		if ( ! $available && $installed ) {
			return __( 'Installed but inactive — activate the plugin to enable multilingual import.', '4wp-drive' );
		}

		if ( ! $available ) {
			return __( 'Not installed on this site. Install and configure the plugin to enable this integration.', '4wp-drive' );
		}

		if ( ! $is_active ) {
			$active = self::get_active();

			return sprintf(
				/* translators: %s: active multilingual provider label */
				__( 'Active on site but not used for import — %s takes priority.', '4wp-drive' ),
				$active->get_label()
			);
		}

		$languages = $provider->get_languages();
		$names     = array();
		foreach ( $languages as $language ) {
			if ( ! empty( $language['name'] ) ) {
				$names[] = (string) $language['name'];
			}
		}

		if ( $provider->requires_manual_selection() ) {
			$base = __( 'Active — pick content language in the Inbox before each import. Update mode lists only posts in that language.', '4wp-drive' );
			if ( ! empty( $names ) ) {
				return $base . ' ' . sprintf(
					/* translators: %s: comma-separated language names */
					__( 'Site languages: %s.', '4wp-drive' ),
					implode( ', ', $names )
				);
			}

			return $base;
		}

		if ( ! empty( $names ) ) {
			return sprintf(
				/* translators: %s: language name */
				__( 'Active — one language configured (%s). No manual selection in the Inbox.', '4wp-drive' ),
				$names[0]
			);
		}

		return __( 'Active — one language configured. No manual selection in the Inbox.', '4wp-drive' );
	}

	/**
	 * Reset cached provider (tests).
	 */
	public static function reset(): void {
		self::$active = null;
	}
}
