<?php
/**
 * Dashboard setup widget (WooCommerce-style) when Drive is not configured.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Admin;

use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Source_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Onboarding widget on wp-admin/index.php.
 */
final class Setup_Dashboard_Widget {

	private const USER_META_DISMISSED = 'forwp_drive_setup_widget_dismissed';

	private const TOTAL_STEPS = 3;

	/**
	 * @return void
	 */
	public static function boot(): void {
		$widget = new self();
		add_action( 'wp_dashboard_setup', array( $widget, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $widget, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $widget, 'maybe_dismiss' ) );
	}

	/**
	 * @return void
	 */
	public function register_widget(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		wp_add_dashboard_widget(
			'forwp_drive_setup',
			__( '4WP Drive setup', '4wp-drive' ),
			array( $this, 'render_widget' )
		);

		global $wp_meta_boxes;

		if ( ! isset( $wp_meta_boxes['dashboard']['normal']['core']['forwp_drive_setup'] ) ) {
			return;
		}

		$widget = $wp_meta_boxes['dashboard']['normal']['core']['forwp_drive_setup'];
		unset( $wp_meta_boxes['dashboard']['normal']['core']['forwp_drive_setup'] );
		$wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
			array( 'forwp_drive_setup' => $widget ),
			$wp_meta_boxes['dashboard']['normal']['core']
		);
	}

	/**
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix || ! $this->should_show() ) {
			return;
		}

		wp_enqueue_style(
			'forwp-drive-dashboard-setup',
			FORWP_DRIVE_URL . 'assets/dashboard-setup.css',
			array(),
			FORWP_DRIVE_VERSION
		);
	}

	/**
	 * @return void
	 */
	public function maybe_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['forwp_drive_dismiss_setup'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'forwp_drive_dismiss_setup' );

		update_user_meta( get_current_user_id(), self::USER_META_DISMISSED, '1' );

		wp_safe_redirect( remove_query_arg( array( 'forwp_drive_dismiss_setup', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * @return void
	 */
	public function render_widget(): void {
		$progress = $this->get_progress();
		require FORWP_DRIVE_PATH . 'views/dashboard-setup-widget.php';
	}

	/**
	 * Whether setup is complete (Drive source ready).
	 */
	public static function is_setup_complete(): bool {
		$source = Source_Registry::get_default();

		return $source ? $source->is_ready() : false;
	}

	/**
	 * @return bool
	 */
	private function should_show(): bool {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( self::is_setup_complete() ) {
			return false;
		}

		return ! (bool) get_user_meta( get_current_user_id(), self::USER_META_DISMISSED, true );
	}

	/**
	 * Current step and copy for the widget.
	 *
	 * @return array{
	 *     current: int,
	 *     total: int,
	 *     progress: float,
	 *     message: string,
	 *     cta_label: string,
	 *     cta_url: string
	 * }
	 */
	private function get_progress(): array {
		$oauth   = Google_OAuth::instance();
		$steps   = array(
			$oauth->has_client_config(),
			$oauth->is_connected(),
			'' !== Settings::instance()->get_folder_id( 'incoming' ),
		);
		$total   = self::TOTAL_STEPS;
		$current = $total;

		foreach ( $steps as $index => $done ) {
			if ( ! $done ) {
				$current = $index + 1;
				break;
			}
		}

		$completed = 0;
		foreach ( $steps as $done ) {
			if ( $done ) {
				++$completed;
			}
		}

		$settings_url = admin_url( 'admin.php?page=forwp-drive-settings' );
		$copy         = $this->get_step_copy( $current, $settings_url );

		return array(
			'current'     => $current,
			'total'       => $total,
			'progress'    => min( 1.0, $completed / $total ),
			'message'     => $copy['message'],
			'cta_label'   => $copy['cta_label'],
			'cta_url'     => $copy['cta_url'],
			'dismiss_url' => wp_nonce_url(
				add_query_arg( 'forwp_drive_dismiss_setup', '1', admin_url( 'index.php' ) ),
				'forwp_drive_dismiss_setup'
			),
		);
	}

	/**
	 * @param int    $step         Current step (1-based).
	 * @param string $settings_url Settings admin URL.
	 * @return array{message: string, cta_label: string, cta_url: string}
	 */
	private function get_step_copy( int $step, string $settings_url ): array {
		switch ( $step ) {
			case 1:
				return array(
					'message'   => __( 'You\'re almost there! Add your Google OAuth Client ID and Secret to connect 4WP Drive to your documents.', '4wp-drive' ),
					'cta_label' => __( 'Add credentials', '4wp-drive' ),
					'cta_url'   => $settings_url,
				);
			case 2:
				return array(
					'message'   => __( 'Credentials saved. Connect your Google account so 4WP Drive can read files from your Drive folders.', '4wp-drive' ),
					'cta_label' => __( 'Connect your Drive', '4wp-drive' ),
					'cta_url'   => $settings_url,
				);
			default:
				return array(
					'message'   => __( 'Last step: set your Drive root folder ID and create incoming/published subfolders to start syncing Google Docs.', '4wp-drive' ),
					'cta_label' => __( 'Set up folders', '4wp-drive' ),
					'cta_url'   => $settings_url,
				);
		}
	}
}
