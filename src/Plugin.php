<?php
/**
 * Plugin bootstrap.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive;

use ForWP\Drive\Admin\Admin_Menu;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Rest\Rest_Documents;
use ForWP\Drive\Rest\Rest_Oauth;
use ForWP\Drive\Rest\Rest_Settings;
use ForWP\Drive\Notifications\Admin_Notifier;
use ForWP\Drive\Sync\Sync_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Admin_Menu::instance()->boot();
		Admin_Notifier::boot();
		Google_OAuth::instance()->boot();
		Sync_Scheduler::boot();
		Rest_Settings::register();
		Rest_Documents::register();
		Rest_Oauth::register();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'forwp-drive sync', array( 'ForWP\\Drive\\Cli\\Sync_Command', 'sync' ) );
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( '4wp-drive', false, dirname( plugin_basename( FORWP_DRIVE_FILE ) ) . '/languages' );
	}
}
