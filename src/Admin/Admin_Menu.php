<?php
/**
 * Admin menu and asset loading.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers 4WP Drive admin screens.
 */
final class Admin_Menu {

	private const MENU_SLUG = 'forwp-drive-inbox';

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( '4WP Drive', '4wp-drive' ),
			__( '4WP Drive', '4wp-drive' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_inbox' ),
			'dashicons-cloud-upload',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Inbox', '4wp-drive' ),
			__( 'Inbox', '4wp-drive' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_inbox' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', '4wp-drive' ),
			__( 'Settings', '4wp-drive' ),
			'manage_options',
			'forwp-drive-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			null,
			__( 'Google OAuth', '4wp-drive' ),
			__( 'Google OAuth', '4wp-drive' ),
			'manage_options',
			'forwp-drive-oauth',
			'__return_null'
		);
	}

	/**
	 * @param string $hook_suffix Current screen hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'forwp-drive' ) ) {
			return;
		}

		$style_deps = array();
		if ( '4wp-drive_page_forwp-drive-settings' === $hook_suffix ) {
			wp_enqueue_style( 'wp-components' );
			$style_deps[] = 'wp-components';
		}

		wp_enqueue_style(
			'forwp-drive-admin',
			FORWP_DRIVE_URL . 'assets/admin.css',
			array(),
			FORWP_DRIVE_VERSION
		);

		if ( '4wp-drive_page_forwp-drive-settings' === $hook_suffix ) {
			wp_enqueue_style(
				'forwp-drive-admin-settings',
				FORWP_DRIVE_URL . 'assets/admin-settings.css',
				array_merge( array( 'forwp-drive-admin' ), $style_deps ),
				FORWP_DRIVE_VERSION
			);
		}

		Preview_Styles::enqueue_for_screen( $hook_suffix );

		wp_enqueue_script(
			'forwp-drive-admin',
			FORWP_DRIVE_URL . 'assets/admin.js',
			array(),
			FORWP_DRIVE_VERSION,
			true
		);

		$rest_path = wp_parse_url( rest_url( 'forwp-drive/v1/' ), PHP_URL_PATH );
		if ( ! is_string( $rest_path ) || '' === $rest_path ) {
			$rest_path = '/wp-json/forwp-drive/v1/';
		}

		wp_localize_script(
			'forwp-drive-admin',
			'forwpDriveAdmin',
			array(
				'restUrl'  => rest_url( 'forwp-drive/v1/' ),
				'restPath' => trailingslashit( $rest_path ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'strings'  => array(
					'importConfirm'      => __( 'Import this document as a draft?', '4wp-drive' ),
					'rejectConfirm'      => __( 'Reject this document?', '4wp-drive' ),
					'syncRunning'        => __( 'Syncing…', '4wp-drive' ),
					'importRunning'      => __( 'Importing…', '4wp-drive' ),
					'disconnectConfirm'  => __( 'Disconnect Google Drive from this site?', '4wp-drive' ),
					'disconnectRunning'  => __( 'Disconnecting…', '4wp-drive' ),
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public function render_inbox(): void {
		require FORWP_DRIVE_PATH . 'views/inbox-page.php';
	}

	/**
	 * @return void
	 */
	public function render_settings(): void {
		require FORWP_DRIVE_PATH . 'views/settings-page.php';
	}
}
