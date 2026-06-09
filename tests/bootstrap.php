<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ForWP\Drive\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

$root = dirname( __DIR__ );

if ( file_exists( $root . '/vendor/autoload.php' ) ) {
	require_once $root . '/vendor/autoload.php';
} else {
	require_once $root . '/src/Autoload.php';
	ForWP\Drive\Autoload::register();
}
