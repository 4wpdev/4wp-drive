<?php
/**
 * Admin Analytics — import history.
 *
 * @package ForWP\Drive
 */

use ForWP\Drive\Source_Registry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$history       = isset( $history ) && is_array( $history ) ? $history : array();
$total         = isset( $total ) ? (int) $total : count( $history );
$page          = isset( $page ) ? (int) $page : 1;
$pages         = isset( $pages ) ? (int) $pages : 1;
$restored      = ! empty( $restored );
$restore_error = isset( $restore_error ) ? (string) $restore_error : '';

$format_gmt = static function ( string $gmt ): string {
	if ( '' === $gmt ) {
		return '—';
	}
	$local = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $gmt ) : $gmt;

	return function_exists( 'mysql2date' )
		? (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $local )
		: $local;
};
?>
<div class="wrap forwp-drive-admin-shell forwp-drive-analytics-page">
	<h1 class="forwp-drive-admin-heading">
		<span class="forwp-drive-admin-heading__text"><?php esc_html_e( '4WP Drive — Analytics', '4wp-drive' ); ?></span>
	</h1>

	<?php if ( $restored ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Package restored to incoming. Run Incoming → Sync to see it in the queue.', '4wp-drive' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $restore_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $restore_error ); ?></p>
		</div>
	<?php endif; ?>

	<p class="forwp-drive-analytics__lead">
		<?php esc_html_e( 'Each import writes a history row: source, post, folder alias, site slug, and where the package moved (incoming → published). Restore sends the package back to incoming without rebuilding it from WordPress.', '4wp-drive' ); ?>
	</p>

	<div class="forwp-drive-admin-app">
		<?php if ( empty( $history ) ) : ?>
			<div class="forwp-drive-analytics-empty">
				<p><?php esc_html_e( 'No imports recorded yet. History appears here after the next successful import.', '4wp-drive' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped forwp-drive-analytics-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Date', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Folder alias', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Site alias', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Post', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Incoming', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Published', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Mode', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Actions', '4wp-drive' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $row ) : ?>
						<?php
						$source_slug = (string) ( $row->source ?? '' );
						$source_obj  = Source_Registry::get( $source_slug );
						$source_label = $source_obj ? $source_obj->get_label() : $source_slug;
						$post_id     = (int) ( $row->post_id ?? 0 );
						$post_type   = (string) ( $row->post_type ?? 'post' );
						$pto         = get_post_type_object( $post_type );
						$type_label  = $pto ? (string) $pto->labels->singular_name : $post_type;
						$post        = $post_id > 0 ? get_post( $post_id ) : null;
						$edit_url    = $post ? get_edit_post_link( $post_id, 'raw' ) : '';
						$is_restored = ! empty( $row->restored_at );
						$mode        = (string) ( $row->mode ?? 'create' );
						?>
						<tr>
							<td><?php echo esc_html( $source_label ); ?></td>
							<td><?php echo esc_html( $format_gmt( (string) ( $row->imported_at ?? '' ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $row->folder_alias ?? '' ) ?: '—' ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $row->site_alias ?? '' ) ?: '—' ); ?></code></td>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>">
										<?php echo esc_html( sprintf( '#%d · %s', $post_id, $type_label ) ); ?>
									</a>
								<?php elseif ( $post_id > 0 ) : ?>
									<?php echo esc_html( sprintf( '#%d · %s', $post_id, $type_label ) ); ?>
									<span class="description"><?php esc_html_e( '(deleted)', '4wp-drive' ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( (string) ( $row->incoming_path ?? '' ) ?: '—' ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $row->published_path ?? '' ) ?: '—' ); ?></code></td>
							<td><?php echo esc_html( 'update' === $mode ? __( 'Update', '4wp-drive' ) : __( 'Create', '4wp-drive' ) ); ?></td>
							<td>
								<?php if ( $is_restored ) : ?>
									<span class="description"><?php esc_html_e( 'Restored', '4wp-drive' ); ?></span>
								<?php else : ?>
									<form method="post" class="forwp-drive-analytics-restore" onsubmit="return confirm('<?php echo esc_js( __( 'Move this package from published back to incoming?', '4wp-drive' ) ); ?>');">
										<?php wp_nonce_field( 'forwp_drive_restore_history' ); ?>
										<input type="hidden" name="history_id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" />
										<button type="submit" name="forwp_drive_restore_history" value="1" class="button button-small">
											<?php esc_html_e( 'Restore to incoming', '4wp-drive' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'current'   => $page,
									'total'     => $pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
