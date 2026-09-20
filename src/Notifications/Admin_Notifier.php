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

	private const USER_META_DISMISSED_COUNT = 'forwp_drive_ready_notice_dismissed_count';
	private const AJAX_ACTION               = 'forwp_drive_dismiss_ready_notice';

	/**
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'ajax_dismiss' ) );
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

		if ( self::is_inbox_screen() ) {
			return;
		}

		$count = Settings::instance()->get_ready_count();
		if ( $count < 1 ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id && (int) get_user_meta( $user_id, self::USER_META_DISMISSED_COUNT, true ) === $count ) {
			return;
		}

		$url   = admin_url( 'admin.php?page=forwp-drive-inbox' );
		$nonce = wp_create_nonce( self::AJAX_ACTION );
		?>
		<div
			id="forwp-drive-ready-notice"
			class="notice notice-info is-dismissible"
			data-count="<?php echo esc_attr( (string) $count ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
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
		<script>
		(function () {
			var notice = document.getElementById('forwp-drive-ready-notice');
			if (!notice) {
				return;
			}
			notice.addEventListener('click', function (event) {
				var target = event.target;
				if (!(target instanceof Element) || !target.closest('.notice-dismiss')) {
					return;
				}
				var body = new FormData();
				body.append('action', '<?php echo esc_js( self::AJAX_ACTION ); ?>');
				body.append('nonce', notice.getAttribute('data-nonce') || '');
				body.append('count', notice.getAttribute('data-count') || '0');
				var ajaxUrl = (typeof window.ajaxurl === 'string') ? window.ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
				fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
			});
		})();
		</script>
		<?php
	}

	/**
	 * Persist dismiss until the ready-document count changes.
	 *
	 * @return void
	 */
	public static function ajax_dismiss(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}

		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( null, 403 );
		}

		$count = isset( $_POST['count'] ) ? (int) $_POST['count'] : 0;
		if ( $count < 1 ) {
			$count = Settings::instance()->get_ready_count();
		}

		update_user_meta( $user_id, self::USER_META_DISMISSED_COUNT, $count );
		wp_send_json_success();
	}

	private static function is_inbox_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		return 'forwp-drive-inbox' === $page;
	}
}
