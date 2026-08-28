<?php
/**
 * Create WordPress draft posts from parsed metadata.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Import\Post_Author_Resolver;
use ForWP\Drive\Import\Post_Date_Parser;
use ForWP\Drive\Import\Seo_Meta_Applicator;
use ForWP\Drive\Parse\Template_Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Maps parsed document metadata to wp_insert_post.
 */
final class Post_Creator {

	/**
	 * @var Template_Config
	 */
	private $config;

	public function __construct( ?Template_Config $config = null ) {
		$this->config = $config ?? new Template_Config();
	}

	/**
	 * Update an existing post with parsed document content.
	 *
	 * @param int                  $post_id  Target post id.
	 * @param array<string, mixed> $metadata Parsed template fields.
	 * @return int|WP_Error Post id.
	 */
	public function update_existing( int $post_id, array $metadata ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error( 'forwp_drive_target_not_found', __( 'Target post was not found.', '4wp-drive' ) );
		}

		$post_type = $this->config->get_import_post_type();
		if ( $post->post_type !== $post_type ) {
			return new WP_Error(
				'forwp_drive_target_wrong_type',
				__( 'Target post type does not match the configured import post type.', '4wp-drive' )
			);
		}

		$title = isset( $metadata['title'] ) ? (string) $metadata['title'] : '';
		if ( '' === $title ) {
			return new WP_Error( 'forwp_drive_no_title', __( 'Document is missing a Title.', '4wp-drive' ) );
		}

		$content = $this->build_post_content( $metadata );

		$postarr = array(
			'ID'           => $post_id,
			'post_title'   => $title,
			'post_content' => $content,
		);

		$post_date = Post_Date_Parser::to_post_date( isset( $metadata['date'] ) ? (string) $metadata['date'] : '' );
		if ( '' !== $post_date ) {
			$postarr['post_date']     = $post_date;
			$postarr['post_date_gmt'] = get_gmt_from_date( $post_date );
			$postarr['edit_date']     = true;
		}

		/**
		 * Filter post data before update.
		 *
		 * @param array<string, mixed> $postarr  Post array.
		 * @param array<string, mixed> $metadata Parsed metadata.
		 * @param int                  $post_id  Existing post id.
		 */
		$postarr = apply_filters( 'forwp_drive_import_update_postarr', $postarr, $metadata, $post_id );

		$updated = wp_update_post( $postarr, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->assign_taxonomies( $post_id, $metadata, $post_type );

		$meta_map = isset( $metadata['meta'] ) && is_array( $metadata['meta'] ) ? $metadata['meta'] : array();
		( new Seo_Meta_Applicator() )->apply( $post_id, $meta_map );

		return $post_id;
	}

	/**
	 * Create a new draft post from parsed metadata.
	 *
	 * @param array<string, mixed> $metadata Parsed template fields.
	 * @return int|WP_Error Post id.
	 */
	public function create_draft( array $metadata ) {
		$title = isset( $metadata['title'] ) ? (string) $metadata['title'] : '';
		if ( '' === $title ) {
			return new WP_Error( 'forwp_drive_no_title', __( 'Document is missing a Title.', '4wp-drive' ) );
		}

		$post_type = $this->config->get_import_post_type();

		$slug = isset( $metadata['slug'] ) ? (string) $metadata['slug'] : '';
		if ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}
		$slug = $this->unique_slug( $slug, $post_type );

		$content = $this->build_post_content( $metadata );

		$postarr = array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => $post_type,
			'post_author'  => $this->resolve_post_author( $metadata ),
		);

		$post_date = Post_Date_Parser::to_post_date( isset( $metadata['date'] ) ? (string) $metadata['date'] : '' );
		if ( '' !== $post_date ) {
			$postarr['post_date']     = $post_date;
			$postarr['post_date_gmt'] = get_gmt_from_date( $post_date );
			$postarr['edit_date']     = true;
		}

		/**
		 * Filter post data before insert.
		 *
		 * @param array<string, mixed> $postarr  Post array.
		 * @param array<string, mixed> $metadata Parsed metadata.
		 */
		$postarr = apply_filters( 'forwp_drive_import_postarr', $postarr, $metadata );

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->assign_taxonomies( (int) $post_id, $metadata, $post_type );

		$meta_map = isset( $metadata['meta'] ) && is_array( $metadata['meta'] ) ? $metadata['meta'] : array();
		( new Seo_Meta_Applicator() )->apply( (int) $post_id, $meta_map );

		return (int) $post_id;
	}

	/**
	 * Build sanitized post content from parsed metadata.
	 *
	 * @param array<string, mixed> $metadata Parsed metadata.
	 * @return string
	 */
	private function build_post_content( array $metadata ): string {
		$content = isset( $metadata['body_html'] ) ? (string) $metadata['body_html'] : '';
		$plain   = isset( $metadata['body'] ) ? trim( (string) $metadata['body'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $content ) ) && '' !== $plain ) {
			return wpautop( esc_html( $plain ) );
		}

		if ( $this->contains_block_markup( $content ) ) {
			return $content;
		}

		return wp_kses_post( $content );
	}

	/**
	 * Detect Gutenberg block comments in import content.
	 */
	private function contains_block_markup( string $content ): bool {
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			return true;
		}

		return false !== strpos( $content, '<!-- wp:' );
	}

	/**
	 * @param array<string, mixed> $metadata Parsed document metadata.
	 */
	private function resolve_post_author( array $metadata ): int {
		$author_value = isset( $metadata['author'] ) ? trim( (string) $metadata['author'] ) : '';
		if ( '' !== $author_value ) {
			$user_id = Post_Author_Resolver::resolve_user_id( $author_value );
			if ( $user_id > 0 ) {
				return $user_id;
			}
		}

		$current = get_current_user_id();

		return $current > 0 ? $current : 1;
	}

	/**
	 * @param int                  $post_id   Post id.
	 * @param array<string, mixed> $metadata  Metadata.
	 * @param string               $post_type Post type.
	 */
	private function assign_taxonomies( int $post_id, array $metadata, string $post_type ): void {
		$tax_data = array();

		if ( ! empty( $metadata['taxonomies'] ) && is_array( $metadata['taxonomies'] ) ) {
			$tax_data = $metadata['taxonomies'];
		}

		// Legacy keys from older scans.
		if ( ! empty( $metadata['category'] ) && ! isset( $tax_data['category'] ) ) {
			$tax_data['category'] = (string) $metadata['category'];
		}
		if ( ! empty( $metadata['tags'] ) && is_array( $metadata['tags'] ) && ! isset( $tax_data['post_tag'] ) ) {
			$tax_data['post_tag'] = $metadata['tags'];
		}

		$object_taxonomies = get_object_taxonomies( $post_type );

		foreach ( $tax_data as $taxonomy => $value ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' === $taxonomy || ! in_array( $taxonomy, $object_taxonomies, true ) ) {
				continue;
			}

			$term_ids = $this->resolve_term_ids( $taxonomy, $value );
			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}
	}

	/**
	 * @param string                    $taxonomy Taxonomy slug.
	 * @param string|array<int, string> $value    Term name(s).
	 * @return int[]
	 */
	private function resolve_term_ids( string $taxonomy, $value ): array {
		$names = is_array( $value ) ? $value : array( (string) $value );
		$ids   = array();

		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}

			$term = get_term_by( 'name', $name, $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
			}

			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
				continue;
			}

			$created = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
				$ids[] = (int) $created['term_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Ensure unique post slug.
	 *
	 * @param string $slug      Desired slug.
	 * @param string $post_type Post type.
	 */
	private function unique_slug( string $slug, string $post_type ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			$slug = 'imported-doc';
		}

		if ( ! get_page_by_path( $slug, OBJECT, $post_type ) ) {
			return $slug;
		}

		return wp_unique_post_slug( $slug, 0, 'draft', $post_type, 0 );
	}
}
