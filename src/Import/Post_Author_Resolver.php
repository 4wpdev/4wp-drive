<?php
/**
 * Resolve WordPress user ID from template Author value.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Matches display name, nickname, login, or nicename.
 */
final class Post_Author_Resolver {

	/**
	 * @param string $value Value from document Author line.
	 */
	public static function resolve_user_id( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}

		$login = sanitize_user( $value, true );
		if ( '' !== $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		$nicename = sanitize_title( $value );
		if ( '' !== $nicename ) {
			$user = get_user_by( 'slug', $nicename );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		if ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		$nickname_query = new \WP_User_Query(
			array(
				'meta_key'   => 'nickname',
				'meta_value' => $value,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		$nickname_ids = $nickname_query->get_results();
		if ( ! empty( $nickname_ids ) ) {
			return (int) $nickname_ids[0];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$display_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE display_name = %s LIMIT 1",
				$value
			)
		);
		if ( $display_id ) {
			return (int) $display_id;
		}

		$search_query = new \WP_User_Query(
			array(
				'search'         => $value,
				'search_columns' => array( 'user_login', 'user_nicename', 'display_name' ),
				'number'         => 1,
				'fields'         => 'ID',
			)
		);
		$search_ids = $search_query->get_results();
		if ( ! empty( $search_ids ) ) {
			return (int) $search_ids[0];
		}

		return 0;
	}
}
