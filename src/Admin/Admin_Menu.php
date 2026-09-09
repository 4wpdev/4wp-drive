<?php
/**
 * Admin menu and asset loading.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Admin;

use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Multilingual\Language_Provider_Registry;
use ForWP\Drive\Source_Registry;

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
		$count      = $this->incoming_count();
		$menu_title = __( '4WP Drive', '4wp-drive' );
		if ( $count > 0 ) {
			$menu_title .= ' ' . $this->incoming_badge_html( $count );
		}

		add_menu_page(
			__( '4WP Drive', '4wp-drive' ),
			$menu_title,
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_inbox' ),
			'dashicons-cloud-upload',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Incoming', '4wp-drive' ),
			__( 'Incoming', '4wp-drive' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_inbox' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Patterns', '4wp-drive' ),
			__( 'Patterns', '4wp-drive' ),
			'manage_options',
			'forwp-drive-patterns',
			array( $this, 'render_patterns' )
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

		$is_settings = '4wp-drive_page_forwp-drive-settings' === $hook_suffix;
		$is_patterns = '4wp-drive_page_forwp-drive-patterns' === $hook_suffix;

		$style_deps = array();
		if ( $is_settings || $is_patterns ) {
			wp_enqueue_style( 'wp-components' );
			$style_deps[] = 'wp-components';
		}

		$admin_css_ver     = FORWP_DRIVE_VERSION;
		$settings_css_ver  = FORWP_DRIVE_VERSION;
		$admin_js_ver      = FORWP_DRIVE_VERSION;
		$admin_css_path    = FORWP_DRIVE_PATH . 'assets/admin.css';
		$settings_css_path = FORWP_DRIVE_PATH . 'assets/admin-settings.css';
		$admin_js_path     = FORWP_DRIVE_PATH . 'assets/admin.js';
		if ( is_readable( $admin_css_path ) ) {
			$admin_css_ver = (string) filemtime( $admin_css_path );
		}
		if ( is_readable( $settings_css_path ) ) {
			$settings_css_ver = (string) filemtime( $settings_css_path );
		}
		if ( is_readable( $admin_js_path ) ) {
			$admin_js_ver = (string) filemtime( $admin_js_path );
		}

		wp_enqueue_style(
			'forwp-drive-admin',
			FORWP_DRIVE_URL . 'assets/admin.css',
			array(),
			$admin_css_ver
		);

		if ( $is_settings || $is_patterns ) {
			wp_enqueue_style(
				'forwp-drive-admin-settings',
				FORWP_DRIVE_URL . 'assets/admin-settings.css',
				array_merge( array( 'forwp-drive-admin' ), $style_deps ),
				$settings_css_ver
			);
		}

		Preview_Styles::enqueue_for_screen( $hook_suffix );

		wp_enqueue_script(
			'forwp-drive-admin',
			FORWP_DRIVE_URL . 'assets/admin.js',
			array(),
			$admin_js_ver,
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
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'multilingual' => Language_Provider_Registry::get_rest_payload(),
				'sources'      => Source_Registry::get_admin_status_rows(),
				'activeSource' => 'google_drive',
				'strings'      => array(
					'importConfirm'           => __( 'Import this document as a draft?', '4wp-drive' ),
					'updateConfirm'           => __( 'Update the selected post with this document content?', '4wp-drive' ),
					'updateTargetRequired'    => __( 'Select an existing post to update.', '4wp-drive' ),
					'languageRequired'        => __( 'Select a content language for this import.', '4wp-drive' ),
					'selectLanguageFirst'     => __( 'Select a language to list matching posts.', '4wp-drive' ),
					'selectLanguagePlaceholder' => __( 'Select language…', '4wp-drive' ),
					'rejectConfirm'           => __( 'Reject this document?', '4wp-drive' ),
					'dialogConfirm'           => __( 'Confirm', '4wp-drive' ),
					'dialogCancel'            => __( 'Cancel', '4wp-drive' ),
					'dialogImportTitle'       => __( 'Import document', '4wp-drive' ),
					'dialogUpdateTitle'       => __( 'Update post', '4wp-drive' ),
					'dialogRejectTitle'       => __( 'Reject document', '4wp-drive' ),
					'dialogDisconnectTitle'   => __( 'Disconnect Drive', '4wp-drive' ),
					'dialogClearCredsTitle'   => __( 'Clear credentials', '4wp-drive' ),
					'destCoreImage'           => __( 'core/image', '4wp-drive' ),
					'destFeaturedImage'       => __( 'Featured image', '4wp-drive' ),
					'destArticle'             => __( 'Article', '4wp-drive' ),
					'featuredImageSuggested'  => __( 'Suggested', '4wp-drive' ),
					'removeImageMarker'       => __( 'Remove', '4wp-drive' ),
					'previewAndImport'        => __( 'Preview & import', '4wp-drive' ),
					'importAsDraft'           => __( 'Import as draft', '4wp-drive' ),
					'queueImport'             => __( 'Import', '4wp-drive' ),
					'updateExistingPost'      => __( 'Update existing post', '4wp-drive' ),
					'syncRunning'             => __( 'Syncing…', '4wp-drive' ),
					'importRunning'           => __( 'Importing…', '4wp-drive' ),
					'disconnectConfirm'       => __( 'Disconnect Google Drive from this site?', '4wp-drive' ),
					'disconnectRunning'       => __( 'Disconnecting…', '4wp-drive' ),
					'clearCredentialsConfirm' => __( 'Clear saved Client ID and Client Secret? This also disconnects your Drive account.', '4wp-drive' ),
					'clearCredentialsRunning' => __( 'Clearing…', '4wp-drive' ),
					'reconnectDrive'          => __( 'Reconnect Google Drive', '4wp-drive' ),
					'openInDrive'             => __( 'Open in Drive', '4wp-drive' ),
					'editInGoogleDocs'        => __( 'Edit in Google Docs', '4wp-drive' ),
					'reject'                  => __( 'Reject', '4wp-drive' ),
					'openFolder'              => __( 'Open folder', '4wp-drive' ),
					'packageFolder'           => __( 'Folder', '4wp-drive' ),
					'packageDocOne'           => __( '1 doc', '4wp-drive' ),
					'packageDocsMany'         => __( '%d docs', '4wp-drive' ),
					'packageImageOne'         => __( '1 image', '4wp-drive' ),
					'packageImagesMany'       => __( '%d images', '4wp-drive' ),
					'packageMore'             => __( '+%d more', '4wp-drive' ),
					'openSettings'            => __( 'Open Settings', '4wp-drive' ),
					'connectionProblemTitle'  => __( 'Google Drive connection problem', '4wp-drive' ),
					'inboxStaleNote'          => __( 'The inbox below may be outdated until Drive access is restored and you sync again.', '4wp-drive' ),
					'sourceSoon'              => __( 'This connector is on the roadmap. Use Google Drive or GitHub Markdown, or check Settings → Storage sources.', '4wp-drive' ),
					'syncLabel'               => __( 'Sync', '4wp-drive' ),
					'syncFromDrive'           => __( 'Sync from source', '4wp-drive' ),
					'menuPlugin'              => __( '4WP Drive', '4wp-drive' ),
					'menuIncoming'            => __( 'Incoming', '4wp-drive' ),
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
	public function render_patterns(): void {
		require FORWP_DRIVE_PATH . 'views/patterns-page.php';
	}

	/**
	 * @return void
	 */
	public function render_settings(): void {
		require FORWP_DRIVE_PATH . 'views/settings-page.php';
	}

	/**
	 * Ready-to-import documents (all sources).
	 */
	private function incoming_count(): int {
		return ( new Document_Repository() )->count_by_statuses( Document_Status::inbox_statuses() );
	}

	/**
	 * Plugins-style count pill (dark circle on current/hover in the Modern scheme).
	 */
	private function incoming_badge_html( int $count ): string {
		return sprintf(
			'<span class="update-plugins count-%1$d"><span class="plugin-count">%2$s</span></span>',
			$count,
			esc_html( number_format_i18n( $count ) )
		);
	}
}
