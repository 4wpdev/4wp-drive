<?php
/**
 * Admin Analytics — import history.
 *
 * @package ForWP\Drive
 */

use ForWP\Drive\Source_Registry;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$history = isset( $history ) && is_array( $history ) ? $history : array();
$total   = isset( $total ) ? (int) $total : count( $history );
$page    = isset( $page ) ? (int) $page : 1;
$pages   = isset( $pages ) ? (int) $pages : 1;

$format_gmt = static function ( string $gmt, string $part = 'datetime' ): string {
	if ( '' === $gmt ) {
		return '—';
	}
	$local = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $gmt ) : $gmt;
	if ( ! function_exists( 'mysql2date' ) ) {
		return $local;
	}
	if ( 'date' === $part ) {
		return (string) mysql2date( get_option( 'date_format' ), $local );
	}
	if ( 'time' === $part ) {
		return (string) mysql2date( get_option( 'time_format' ), $local );
	}

	return (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $local );
};

$decode_summary = static function ( $row ): array {
	$raw = isset( $row->summary_json ) ? (string) $row->summary_json : '';
	if ( '' === $raw ) {
		return array();
	}
	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : array();
};

$status_label = static function ( string $status ): string {
	$map = array(
		'draft'   => __( 'Draft', '4wp-drive' ),
		'publish' => __( 'Published', '4wp-drive' ),
		'pending' => __( 'Pending', '4wp-drive' ),
		'private' => __( 'Private', '4wp-drive' ),
		'future'  => __( 'Scheduled', '4wp-drive' ),
		'trash'   => __( 'Trash', '4wp-drive' ),
	);

	return $map[ $status ] ?? ( $status !== '' ? $status : '—' );
};

$tax_label = static function ( string $taxonomy ): string {
	if ( function_exists( 'get_taxonomy' ) ) {
		$obj = get_taxonomy( $taxonomy );
		if ( $obj && ! empty( $obj->labels->name ) ) {
			return (string) $obj->labels->name;
		}
	}

	$map = array(
		'category' => __( 'Categories', '4wp-drive' ),
		'post_tag' => __( 'Tags', '4wp-drive' ),
	);

	return $map[ $taxonomy ] ?? $taxonomy;
};

$summary_rows = static function ( array $summary ) use ( $tax_label ): array {
	$rows   = array();
	$chars  = isset( $summary['chars'] ) ? (int) $summary['chars'] : 0;
	$images = isset( $summary['images'] ) ? (int) $summary['images'] : 0;
	$rows[] = array(
		'label' => __( 'Chars', '4wp-drive' ),
		'value' => (string) number_format_i18n( $chars ),
	);
	$rows[] = array(
		'label' => __( 'Images', '4wp-drive' ),
		'value' => (string) number_format_i18n( $images ),
	);
	if ( ! empty( $summary['featured'] ) ) {
		$rows[] = array(
			'label' => __( 'Featured', '4wp-drive' ),
			'value' => __( 'yes', '4wp-drive' ),
		);
	}
	$tax = isset( $summary['taxonomies'] ) && is_array( $summary['taxonomies'] ) ? $summary['taxonomies'] : array();
	foreach ( $tax as $taxonomy => $names ) {
		if ( ! is_array( $names ) || empty( $names ) ) {
			continue;
		}
		if ( function_exists( 'get_taxonomy' ) ) {
			$obj = get_taxonomy( (string) $taxonomy );
			if ( $obj && empty( $obj->public ) ) {
				continue;
			}
		}
		$rows[] = array(
			'label' => $tax_label( (string) $taxonomy ),
			'value' => implode( ', ', array_map( 'strval', $names ) ),
		);
	}

	return $rows;
};
?>
<div class="wrap forwp-drive-admin-shell forwp-drive-analytics-page">
	<h1 class="forwp-drive-admin-heading">
		<span class="forwp-drive-admin-heading__text"><?php esc_html_e( '4WP Drive — Analytics', '4wp-drive' ); ?></span>
	</h1>

	<p class="forwp-drive-analytics__lead">
		<?php esc_html_e( 'Every successful import is logged here — drafts and published.', '4wp-drive' ); ?>
	</p>

	<div class="forwp-drive-admin-app">
		<?php if ( empty( $history ) ) : ?>
			<div class="forwp-drive-analytics-empty">
				<p><?php esc_html_e( 'No imports recorded yet. History appears after a successful import (draft or published).', '4wp-drive' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped forwp-drive-analytics-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Time', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Type', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Post ID', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Type', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Status', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Add / UPD', '4wp-drive' ); ?></th>
						<th><?php esc_html_e( 'Details', '4wp-drive' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $row ) : ?>
						<?php
						$source_slug  = (string) ( $row->source ?? '' );
						$source_obj   = Source_Registry::get( $source_slug );
						$source_label = $source_obj ? $source_obj->get_label() : $source_slug;
						$post_id      = (int) ( $row->post_id ?? 0 );
						$post_type    = (string) ( $row->post_type ?? 'post' );
						$pto          = get_post_type_object( $post_type );
						$type_label   = $pto ? (string) $pto->labels->singular_name : $post_type;
						$post         = $post_id > 0 ? get_post( $post_id ) : null;
						$edit_url     = $post ? get_edit_post_link( $post_id, 'raw' ) : '';
						$mode         = (string) ( $row->mode ?? 'create' );
						$summary      = $decode_summary( $row );
						$live_status  = $post ? (string) $post->post_status : (string) ( $summary['status'] ?? '' );
						$imported_at  = (string) ( $row->imported_at ?? '' );
						$detail_rows  = $summary_rows( $summary );
						?>
						<tr>
							<td><?php echo esc_html( $format_gmt( $imported_at, 'date' ) ); ?></td>
							<td><?php echo esc_html( $format_gmt( $imported_at, 'time' ) ); ?></td>
							<td><?php echo esc_html( $source_label ); ?></td>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) $post_id ); ?></a>
								<?php elseif ( $post_id > 0 ) : ?>
									<?php echo esc_html( (string) $post_id ); ?>
									<span class="description"><?php esc_html_e( '(deleted)', '4wp-drive' ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $type_label ); ?></td>
							<td><?php echo esc_html( $status_label( $live_status ) ); ?></td>
							<td><?php echo esc_html( 'update' === $mode ? __( 'UPD', '4wp-drive' ) : __( 'Add', '4wp-drive' ) ); ?></td>
							<td>
								<?php if ( empty( $detail_rows ) ) : ?>
									—
								<?php else : ?>
									<div class="forwp-drive-analytics-summary">
										<?php foreach ( $detail_rows as $detail ) : ?>
											<div class="forwp-drive-analytics-summary__row">
												<span class="forwp-drive-analytics-summary__label"><?php echo esc_html( (string) $detail['label'] ); ?></span>
												<span class="forwp-drive-analytics-summary__value"><?php echo esc_html( (string) $detail['value'] ); ?></span>
											</div>
										<?php endforeach; ?>
									</div>
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
