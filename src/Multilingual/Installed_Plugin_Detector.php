<?php
/**
 * Detect installed (possibly inactive) plugins by bootstrap file.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only helper for multilingual provider cards.
 */
final class Installed_Plugin_Detector {

	/**
	 * @param list<string> $plugin_files Plugin paths relative to wp-content/plugins.
	 */
	public static function is_plugin_present( array $plugin_files ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) {
			return false;
		}

		foreach ( $plugin_files as $plugin_file ) {
			if ( isset( $plugins[ (string) $plugin_file ] ) ) {
				return true;
			}
		}

		return false;
	}
}
