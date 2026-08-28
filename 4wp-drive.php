<?php
/**
 * Plugin Name:       4WP Drive
 * Plugin URI:        https://4wp.dev/plugin/4wp-drive/
 * Description:       Import Google Docs from Drive into WordPress drafts—or update existing posts and pages from the Inbox. Editorial pipeline: incoming → inbox → publish.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            4wpdev
 * Author URI:        https://4wp.dev/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       4wp-drive
 *
 * @package ForWP\Drive
 */

defined( 'ABSPATH' ) || exit;

define( 'FORWP_DRIVE_VERSION', '1.3.0' );
define( 'FORWP_DRIVE_FILE', __FILE__ );
define( 'FORWP_DRIVE_PATH', plugin_dir_path( __FILE__ ) );
define( 'FORWP_DRIVE_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( FORWP_DRIVE_PATH . 'vendor/autoload.php' ) ) {
	require_once FORWP_DRIVE_PATH . 'vendor/autoload.php';
} else {
	require_once FORWP_DRIVE_PATH . 'src/Autoload.php';
	ForWP\Drive\Autoload::register();
}

register_activation_hook( __FILE__, array( 'ForWP\\Drive\\Database\\Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ForWP\\Drive\\Sync\\Sync_Scheduler', 'deactivate' ) );

ForWP\Drive\Plugin::instance()->boot();
