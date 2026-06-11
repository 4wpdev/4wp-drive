<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ForWP\Drive\Tests
 */

require_once __DIR__ . '/wp-stubs.php';

$root = dirname( __DIR__ );

if ( file_exists( $root . '/vendor/autoload.php' ) ) {
	require_once $root . '/vendor/autoload.php';
} else {
	require_once $root . '/src/Autoload.php';
	ForWP\Drive\Autoload::register();
}
