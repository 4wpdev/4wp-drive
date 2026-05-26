<?php
/**
 * Admin notices for ready documents.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Notifications;

use ForWP\Drive\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows inbox notification in wp-admin.
 */
final class Admin_Notifier {

	/**
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * @param int $count Ready document count.
	 */
	public static function mark_pending( int $count ): void {
		Settings::instance()->set_ready_count( $count );
	}

	/**
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$count = Settings::instance()->get_ready_count();
		if ( $count < 1 ) {
			return;
		}

		$url = admin_url( 'admin.php?page=forwp-drive-inbox' );
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %1$d: document count, %2$s: inbox URL */
					wp_kses_post( __( '<strong>4WP Drive:</strong> %1$d document(s) ready for import. <a href="%2$s">Open Inbox</a>', '4wp-drive' ) ),
					(int) $count,
					esc_url( $url )
				);
				?>
			</p>
		</div>
		<?php
	}
}
