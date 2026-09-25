<?php
/**
 * Resolve existing posts for update-import mode.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Multilingual\Language_Provider_Registry;
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
	 * @param string $lang      Content language slug (multilingual sites).
	 * @return array{targets: array<int, array<string, mixed>>, suggested_id: int|null}
	 */
	public static function suggest(
		string $post_type,
		string $slug = '',
		string $title = '',
		string $search = '',
		int $limit = 30,
		string $lang = ''
	): array {
		$post_type = sanitize_key( $post_type );
		if ( ! post_type_exists( $post_type ) ) {
			return array(
				'targets'      => array(),
				'suggested_id' => null,
			);
		}

		$lang      = sanitize_key( $lang );
		$suggested = null;

		if ( '' !== $slug ) {
			$by_slug = self::find_by_slug( $slug, $post_type, $lang );
			if ( $by_slug instanceof WP_Post && self::post_matches_language( $by_slug, $lang ) ) {
				$suggested = (int) $by_slug->ID;
			}
		}

		if ( null === $suggested && '' !== $title ) {
			$by_title = self::find_by_title( $title, $post_type, $lang );
			if ( $by_title instanceof WP_Post && self::post_matches_language( $by_title, $lang ) ) {
				$suggested = (int) $by_title->ID;
			}
		}

		$limit = max( 1, min( 50, $limit ) );
		$search = trim( $search );

		if ( '' !== $search ) {
			$ids     = self::search_post_ids( $post_type, $search, $lang, $limit );
			$targets = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post instanceof WP_Post ) {
					$targets[] = self::serialize_post( $post );
				}
			}

			return array(
				'targets'      => $targets,
				'suggested_id' => null,
			);
		}

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => self::importable_statuses(),
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query_args = self::apply_language_to_query( $query_args, $lang );

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
	 * @param string $lang      Expected content language.
	 * @return int|WP_Error
	 */
	public static function resolve_for_import( int $post_id, string $post_type, string $lang = '' ) {
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

		$lang = sanitize_key( $lang );
		if ( '' !== $lang && ! self::post_matches_language( $post, $lang ) ) {
			return new WP_Error(
				'forwp_drive_target_wrong_language',
				__( 'Target post is not in the selected language.', '4wp-drive' ),
				array( 'status' => 400 )
			);
		}

		return $post_id;
	}

	/**
	 * Search importable posts by title, slug, or ID.
	 *
	 * @return list<int>
	 */
	private static function search_post_ids( string $post_type, string $search, string $lang, int $limit ): array {
		global $wpdb;

		$search = sanitize_text_field( $search );
		if ( '' === $search ) {
			return array();
		}

		$statuses = self::importable_statuses();
		$in       = implode(
			',',
			array_map(
				static function ( string $status ) use ( $wpdb ): string {
					return $wpdb->prepare( '%s', $status );
				},
				$statuses
			)
		);

		$title_like = '%' . $wpdb->esc_like( $search ) . '%';
		$slug_like  = '%' . $wpdb->esc_like( sanitize_title( $search ) ) . '%';
		$clauses    = 'post_title LIKE %s OR post_name LIKE %s';
		$params     = array( $post_type, $title_like, $slug_like );

		if ( ctype_digit( $search ) ) {
			$clauses .= ' OR ID = %d';
			$params[] = (int) $search;
		}

		$params[] = $limit;
		$sql      = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$in}) AND ({$clauses}) ORDER BY post_modified DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		$ids = array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );

		if ( '' === $lang ) {
			return $ids;
		}

		$filtered = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && self::post_matches_language( $post, $lang ) ) {
				$filtered[] = $id;
			}
		}

		return $filtered;
	}

	/**
	 * Find post by path slug.
	 *
	 * @param string $slug      Document or desired slug.
	 * @param string $post_type Post type.
	 * @param string $lang      Content language slug.
	 * @return WP_Post|null
	 */
	private static function find_by_slug( string $slug, string $post_type, string $lang = '' ): ?WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$provider = Language_Provider_Registry::get_active();
		if ( $provider->requires_manual_selection() && '' !== sanitize_key( $lang ) ) {
			$query = new WP_Query(
				self::apply_language_to_query(
					array(
						'post_type'              => $post_type,
						'name'                   => $slug,
						'post_status'            => self::importable_statuses(),
						'posts_per_page'         => 1,
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					),
					$lang
				)
			);
			$post = $query->posts[0] ?? null;

			return $post instanceof WP_Post ? $post : null;
		}

		$post = get_page_by_path( $slug, OBJECT, $post_type );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Find post by exact title.
	 *
	 * @param string $title     Document title.
	 * @param string $post_type Post type.
	 * @param string $lang      Content language slug.
	 * @return WP_Post|null
	 */
	private static function find_by_title( string $title, string $post_type, string $lang = '' ): ?WP_Post {
		$title = trim( $title );
		if ( '' === $title ) {
			return null;
		}

		$by_path = get_page_by_path( sanitize_title( $title ), OBJECT, $post_type );
		if ( $by_path instanceof WP_Post && $by_path->post_title === $title ) {
			return $by_path;
		}

		$posts = get_posts(
			self::apply_language_to_query(
				array(
					'post_type'              => $post_type,
					'post_status'            => self::importable_statuses(),
					'posts_per_page'         => 100,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				),
				$lang
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
	 * @return array{id: int, title: string, slug: string, post_type: string, post_type_label: string, status: string, edit_url: string, view_url: string, modified: string}
	 */
	private static function serialize_post( WP_Post $post ): array {
		$provider  = Language_Provider_Registry::get_active();
		$lang      = $provider->get_post_language( (int) $post->ID );
		$lang_name = $lang;
		$pto       = get_post_type_object( $post->post_type );
		$type_label = $pto && isset( $pto->labels->singular_name )
			? (string) $pto->labels->singular_name
			: (string) $post->post_type;

		foreach ( $provider->get_languages() as $language ) {
			if ( $language['code'] === $lang ) {
				$lang_name = $language['name'];
				break;
			}
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => (string) $post->post_title,
			'slug'            => (string) $post->post_name,
			'post_type'       => (string) $post->post_type,
			'post_type_label' => $type_label,
			'status'          => (string) $post->post_status,
			'language'        => $lang,
			'language_name'   => (string) $lang_name,
			'edit_url'        => (string) get_edit_post_link( $post, 'raw' ),
			'view_url'        => self::view_url_for_post( $post ),
			'modified'        => (string) $post->post_modified,
		);
	}

	/**
	 * Frontend URL for confirming the target before overwrite (permalink or preview).
	 */
	private static function view_url_for_post( WP_Post $post ): string {
		if ( 'publish' === $post->post_status ) {
			$url = get_permalink( $post );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		$preview = get_preview_post_link( $post );
		return is_string( $preview ) ? $preview : '';
	}

	/**
	 * @param WP_Post $post Post object.
	 * @param string  $lang Expected language slug.
	 */
	private static function post_matches_language( WP_Post $post, string $lang ): bool {
		$lang = sanitize_key( $lang );
		if ( '' === $lang ) {
			return true;
		}

		$provider = Language_Provider_Registry::get_active();
		if ( ! $provider->requires_manual_selection() ) {
			return true;
		}

		return $provider->get_post_language( (int) $post->ID ) === $lang;
	}

	/**
	 * @param array<string, mixed> $query_args Query args.
	 * @param string               $lang       Language slug.
	 * @return array<string, mixed>
	 */
	private static function apply_language_to_query( array $query_args, string $lang ): array {
		$lang = sanitize_key( $lang );
		if ( '' === $lang ) {
			return $query_args;
		}

		return Language_Provider_Registry::get_active()->apply_language_to_query_args( $query_args, $lang );
	}
}
