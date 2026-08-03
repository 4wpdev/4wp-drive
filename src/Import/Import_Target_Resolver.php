<?php
/**
 * Resolve existing posts for update-import mode.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Suggest and validate WordPress posts to update from Drive.
 */
final class Import_Target_Resolver {

	/**
	 * Suggest import targets for a document (slug/title match + search list).
	 *
	 * @param string $post_type Configured import post type.
	 * @param string $slug      Parsed document slug.
	 * @param string $title     Parsed document title.
	 * @param string $search    Optional admin search string.
	 * @param int    $limit     Max rows in picker list.
	 * @return array{targets: array<int, array<string, mixed>>, suggested_id: int|null}
	 */
	public static function suggest(
		string $post_type,
		string $slug = '',
		string $title = '',
		string $search = '',
		int $limit = 30
	): array {
		$post_type = sanitize_key( $post_type );
		if ( ! post_type_exists( $post_type ) ) {
			return array(
				'targets'      => array(),
				'suggested_id' => null,
			);
		}

		$suggested = null;

		if ( '' !== $slug ) {
			$by_slug = self::find_by_slug( $slug, $post_type );
			if ( $by_slug instanceof WP_Post ) {
				$suggested = (int) $by_slug->ID;
			}
		}

		if ( null === $suggested && '' !== $title ) {
			$by_title = self::find_by_title( $title, $post_type );
			if ( $by_title instanceof WP_Post ) {
				$suggested = (int) $by_title->ID;
			}
		}

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => self::importable_statuses(),
			'posts_per_page'         => max( 1, min( 50, $limit ) ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== trim( $search ) ) {
			$query_args['s'] = sanitize_text_field( $search );
		}

		$query   = new WP_Query( $query_args );
		$targets = array();
		$seen    = array();

		if ( $suggested ) {
			$suggested_post = get_post( $suggested );
			if ( $suggested_post instanceof WP_Post ) {
				$row                = self::serialize_post( $suggested_post );
				$targets[]          = $row;
				$seen[ $row['id'] ] = true;
			}
		}

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$id = (int) $post->ID;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$targets[]   = self::serialize_post( $post );
			$seen[ $id ] = true;
		}

		return array(
			'targets'      => $targets,
			'suggested_id' => $suggested,
		);
	}

	/**
	 * Validate a target post id for update import.
	 *
	 * @param int    $post_id   Target post id.
	 * @param string $post_type Expected post type.
	 * @return int|WP_Error
	 */
	public static function resolve_for_import( int $post_id, string $post_type ) {
		$post_id   = max( 0, $post_id );
		$post_type = sanitize_key( $post_type );

		if ( $post_id <= 0 ) {
			return new WP_Error(
				'forwp_drive_missing_target',
				__( 'Select an existing post to update.', '4wp-drive' ),
				array( 'status' => 400 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'forwp_drive_target_not_found',
				__( 'Target post was not found.', '4wp-drive' ),
				array( 'status' => 404 )
			);
		}

		if ( $post->post_type !== $post_type ) {
			return new WP_Error(
				'forwp_drive_target_wrong_type',
				__( 'Target post type does not match the configured import post type.', '4wp-drive' ),
				array( 'status' => 400 )
			);
		}

		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new WP_Error(
				'forwp_drive_target_invalid_status',
				__( 'Target post cannot be updated in its current status.', '4wp-drive' ),
				array( 'status' => 400 )
			);
		}

		return $post_id;
	}

	/**
	 * Find post by path slug.
	 *
	 * @param string $slug      Document or desired slug.
	 * @param string $post_type Post type.
	 * @return WP_Post|null
	 */
	private static function find_by_slug( string $slug, string $post_type ): ?WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$post = get_page_by_path( $slug, OBJECT, $post_type );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Find post by exact title.
	 *
	 * @param string $title     Document title.
	 * @param string $post_type Post type.
	 * @return WP_Post|null
	 */
	private static function find_by_title( string $title, string $post_type ): ?WP_Post {
		$title = trim( $title );
		if ( '' === $title ) {
			return null;
		}

		$by_path = get_page_by_path( sanitize_title( $title ), OBJECT, $post_type );
		if ( $by_path instanceof WP_Post && $by_path->post_title === $title ) {
			return $by_path;
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::importable_statuses(),
				'posts_per_page'         => 100,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && $post->post_title === $title ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Post statuses eligible as update targets.
	 *
	 * @return array<int, string>
	 */
	private static function importable_statuses(): array {
		return array( 'publish', 'draft', 'pending', 'private', 'future' );
	}

	/**
	 * REST payload for one import target post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array{id: int, title: string, slug: string, status: string, edit_url: string, modified: string}
	 */
	private static function serialize_post( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'slug'     => (string) $post->post_name,
			'status'   => (string) $post->post_status,
			'edit_url' => (string) get_edit_post_link( $post, 'raw' ),
			'modified' => (string) $post->post_modified,
		);
	}
}
