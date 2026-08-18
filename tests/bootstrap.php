<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ForWP\Drive\Tests
 */

require_once __DIR__ . '/wp-stubs.php';

$forwp_drive_plugin_root = dirname( __DIR__ );

if ( file_exists( $forwp_drive_plugin_root . '/vendor/autoload.php' ) ) {
	require_once $forwp_drive_plugin_root . '/vendor/autoload.php';
} else {
	require_once $forwp_drive_plugin_root . '/src/Autoload.php';
	ForWP\Drive\Autoload::register();
}
