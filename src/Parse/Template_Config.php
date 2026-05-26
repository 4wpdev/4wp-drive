<?php
/**
 * Document template field map and import post type.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Parse;

use ForWP\Drive\Import\Seo_Meta_Applicator;

defined( 'ABSPATH' ) || exit;

/**
 * Stores which front-matter labels map to post fields and taxonomies.
 */
final class Template_Config {

	public const FIELDS_OPTION = 'forwp_drive_template_fields';

	public const POST_TYPE_OPTION = 'forwp_drive_import_post_type';

	public const OAUTH_REDIRECT_OPTION = 'forwp_drive_oauth_redirect_uri';

	/**
	 * Core field keys allowed in template mapping.
	 *
	 * @return string[]
	 */
	public static function core_field_keys(): array {
		return array( 'title', 'slug', 'date', 'author' );
	}

	/**
	 * Default field map for a post type (includes object taxonomies).
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function default_fields_for_post_type( string $post_type = 'post' ): array {
		$fields = array(
			array(
				'label'    => 'Title',
				'key'      => 'title',
				'type'     => 'core',
				'field'    => 'title',
				'required' => true,
			),
			array(
				'label'    => 'Slug',
				'key'      => 'slug',
				'type'     => 'core',
				'field'    => 'slug',
				'required' => false,
			),
			array(
				'label'    => 'Date',
				'key'      => 'date',
				'type'     => 'core',
				'field'    => 'date',
				'required' => false,
			),
			array(
				'label'    => 'Author',
				'key'      => 'author',
				'type'     => 'core',
				'field'    => 'author',
				'required' => false,
			),
		);

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		if ( ! is_array( $taxonomies ) ) {
			return $fields;
		}

		$has_category = isset( $taxonomies['category'] );
		$has_tags     = isset( $taxonomies['post_tag'] );

		if ( $has_category ) {
			$fields[] = array(
				'label'    => 'Category',
				'key'      => 'category',
				'type'     => 'taxonomy',
				'taxonomy' => 'category',
				'multi'    => false,
				'required' => false,
			);
		}

		if ( $has_tags ) {
			$fields[] = array(
				'label'    => 'Tags',
				'key'      => 'tags',
				'type'     => 'taxonomy',
				'taxonomy' => 'post_tag',
				'multi'    => true,
				'required' => false,
			);
		}

		$label_map = array(
			'region'  => 'Region',
			'country' => 'Country',
		);

		foreach ( $taxonomies as $taxonomy => $object ) {
			if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				continue;
			}
			if ( ! $object->public && ! $object->show_ui ) {
				continue;
			}

			$fields[] = array(
				'label'    => $label_map[ $taxonomy ] ?? $object->labels->singular_name,
				'key'      => sanitize_key( $taxonomy ),
				'type'     => 'taxonomy',
				'taxonomy' => $taxonomy,
				'multi'    => false,
				'required' => false,
			);
		}

		foreach ( Seo_Meta_Applicator::get_available_fields() as $meta_field ) {
			$fields[] = array(
				'label'    => (string) $meta_field['label'],
				'key'      => (string) $meta_field['slug'],
				'type'     => 'meta',
				'meta_key' => (string) $meta_field['meta_key'],
				'required' => false,
			);
		}

		return $fields;
	}

	/**
	 * Importable custom meta fields (Yoast SEO, etc.).
	 *
	 * @return array<int, array{slug: string, meta_key: string, label: string}>
	 */
	public static function get_available_meta_fields(): array {
		return Seo_Meta_Applicator::get_available_fields();
	}

	/**
	 * @return string
	 */
	public function get_import_post_type(): string {
		$type = get_option( self::POST_TYPE_OPTION, 'post' );

		return is_string( $type ) && post_type_exists( $type ) ? $type : 'post';
	}

	/**
	 * @param string $post_type Post type slug.
	 */
	public function set_import_post_type( string $post_type ): void {
		$post_type = sanitize_key( $post_type );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$previous = get_option( self::POST_TYPE_OPTION, 'post' );
		if ( $previous !== $post_type ) {
			delete_option( self::FIELDS_OPTION );
		}

		update_option( self::POST_TYPE_OPTION, $post_type, false );
	}

	/**
	 * Saved template fields or defaults for current import post type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_fields(): array {
		$stored = get_option( self::FIELDS_OPTION, null );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return $this->ensure_default_core_fields( $this->normalize_fields( $stored ) );
		}

		return self::default_fields_for_post_type( $this->get_import_post_type() );
	}

	/**
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 */
	public function set_fields( array $fields ): void {
		update_option( self::FIELDS_OPTION, $this->normalize_fields( $fields ), false );
	}

	/**
	 * Custom OAuth redirect or empty to use WordPress admin URL.
	 */
	public function get_oauth_redirect_uri(): string {
		$uri = get_option( self::OAUTH_REDIRECT_OPTION, '' );

		return is_string( $uri ) ? trim( $uri ) : '';
	}

	/**
	 * @param string $uri Redirect URI.
	 */
	public function set_oauth_redirect_uri( string $uri ): void {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			delete_option( self::OAUTH_REDIRECT_OPTION );
			return;
		}
		update_option( self::OAUTH_REDIRECT_OPTION, esc_url_raw( $uri ), false );
	}

	/**
	 * URI sent to Google (override or default admin callback).
	 */
	public function get_effective_redirect_uri(): string {
		$custom = $this->get_oauth_redirect_uri();
		if ( '' !== $custom ) {
			return $custom;
		}

		return admin_url( 'admin.php?page=forwp-drive-oauth' );
	}

	/**
	 * Whether the site host is unlikely to be accepted by Google OAuth (e.g. taxspoc.localhost).
	 */
	public function needs_localhost_redirect_help(): bool {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return false;
		}

		return (bool) preg_match( '/\.(local|localhost|test|invalid)$/i', $host );
	}

	/**
	 * Suggested redirect when the current host is not valid for Google (127.0.0.1 + Local port).
	 */
	public function get_suggested_oauth_redirect_uri(): string {
		if ( ! $this->needs_localhost_redirect_help() ) {
			return '';
		}

		$parts = wp_parse_url( admin_url( 'admin.php?page=forwp-drive-oauth' ) );
		if ( ! is_array( $parts ) ) {
			$parts = array(
				'path'  => '/wp-admin/admin.php',
				'query' => 'page=forwp-drive-oauth',
			);
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( $port <= 0 ) {
			$port = $this->detect_local_http_port();
		}

		$port_suffix = $port > 0 && ! in_array( $port, array( 80, 443 ), true ) ? ':' . $port : '';
		$path        = isset( $parts['path'] ) ? $parts['path'] : '/wp-admin/admin.php';
		$query       = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$scheme      = isset( $parts['scheme'] ) && 'https' === $parts['scheme'] ? 'https' : 'http';

		return $scheme . '://127.0.0.1' . $port_suffix . $path . $query;
	}

	/**
	 * Detect non-standard HTTP port (Local serves on 127.0.0.1:PORT).
	 */
	private function detect_local_http_port(): int {
		if ( ! empty( $_SERVER['SERVER_PORT'] ) ) {
			$port = (int) $_SERVER['SERVER_PORT'];
			if ( $port > 0 && ! in_array( $port, array( 80, 443 ), true ) ) {
				return $port;
			}
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		if ( false !== strpos( $host, ':' ) ) {
			$port = (int) substr( $host, (int) strrpos( $host, ':' ) + 1 );
			if ( $port > 0 ) {
				return $port;
			}
		}

		return 0;
	}

	/**
	 * Example document text for editors (Google Doc header).
	 */
	public function build_sample_document(): string {
		$lines = array();
		foreach ( $this->get_fields() as $field ) {
			$label = (string) ( $field['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}

			if ( 'core' === ( $field['type'] ?? '' ) && 'title' === ( $field['field'] ?? '' ) ) {
				$lines[] = $label . ': My Article';
			} elseif ( 'core' === ( $field['type'] ?? '' ) && 'slug' === ( $field['field'] ?? '' ) ) {
				$lines[] = $label . ': my-article';
			} elseif ( 'core' === ( $field['type'] ?? '' ) && 'date' === ( $field['field'] ?? '' ) ) {
				$lines[] = $label . ': ' . wp_date( 'Y-m-d' );
			} elseif ( 'core' === ( $field['type'] ?? '' ) && 'author' === ( $field['field'] ?? '' ) ) {
				$user    = wp_get_current_user();
				$example = ( $user && $user->exists() ) ? $user->display_name : 'Jane Editor';
				$lines[] = $label . ': ' . $example;
			} elseif ( 'taxonomy' === ( $field['type'] ?? '' ) ) {
				$example = ! empty( $field['multi'] ) ? 'one, two' : 'Example';
				$lines[] = $label . ': ' . $example;
			} elseif ( 'meta' === ( $field['type'] ?? '' ) ) {
				$lines[] = $label . ': Example value';
			}
		}

		$lines[] = '';
		$lines[] = Template_Separator::mark();
		$lines[] = '';
		$lines[] = 'Article body starts here…';

		return implode( "\n", $lines );
	}

	/**
	 * Post types available for import target.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function get_importable_post_types(): array {
		$types = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);

		$list = array();
		foreach ( $types as $object ) {
			if ( 'attachment' === $object->name ) {
				continue;
			}
			$list[] = array(
				'slug'  => $object->name,
				'label' => $object->labels->singular_name,
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $list;
	}

	/**
	 * Taxonomies attached to a post type (for mapping UI).
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array{slug: string, label: string, hierarchical: bool}>
	 */
	public static function get_taxonomies_for_post_type( string $post_type ): array {
		$objects = get_object_taxonomies( $post_type, 'objects' );
		if ( ! is_array( $objects ) ) {
			return array();
		}

		$list = array();
		foreach ( $objects as $taxonomy => $object ) {
			if ( ! $object->public && ! $object->show_ui ) {
				continue;
			}
			$list[] = array(
				'slug'          => $taxonomy,
				'label'         => $object->labels->singular_name,
				'hierarchical'  => (bool) $object->hierarchical,
			);
		}

		return $list;
	}

	/**
	 * @param array<int, array<string, mixed>> $fields Raw fields.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_fields( array $fields ): array {
		$normalized = array();
		$seen_keys  = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$type = (string) ( $field['type'] ?? 'taxonomy' );
			if ( 'core' === $type ) {
				$core = sanitize_key( (string) ( $field['field'] ?? '' ) );
				if ( ! in_array( $core, self::core_field_keys(), true ) ) {
					continue;
				}
				$key = $core;
			} elseif ( 'meta' === $type ) {
				$key      = sanitize_key( (string) ( $field['key'] ?? '' ) );
				$meta_key = self::resolve_meta_key( $key, $field );
				if ( '' === $meta_key ) {
					continue;
				}
			} elseif ( 'taxonomy' === $type ) {
				$taxonomy = sanitize_key( (string) ( $field['taxonomy'] ?? '' ) );
				if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$key = sanitize_key( (string) ( $field['key'] ?? $taxonomy ) );
			} else {
				continue;
			}

			if ( isset( $seen_keys[ $key ] ) ) {
				continue;
			}
			$seen_keys[ $key ] = true;

			$row = array(
				'label'    => $label,
				'key'      => $key,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
			);

			if ( 'core' === $type ) {
				$row['field'] = $core;
			} elseif ( 'meta' === $type ) {
				$row['meta_key'] = $meta_key;
			} else {
				$row['taxonomy'] = sanitize_key( (string) ( $field['taxonomy'] ?? $key ) );
				$row['multi']    = ! empty( $field['multi'] );
			}

			$normalized[] = $row;
		}

		$has_title = false;
		foreach ( $normalized as $row ) {
			if ( 'core' === $row['type'] && 'title' === $row['field'] ) {
				$has_title = true;
				$row['required'] = true;
				break;
			}
		}

		if ( ! $has_title ) {
			array_unshift(
				$normalized,
				array(
					'label'    => 'Title',
					'key'      => 'title',
					'type'     => 'core',
					'field'    => 'title',
					'required' => true,
				)
			);
		}

		return $normalized;
	}

	/**
	 * Ensure new core fields appear in saved maps (e.g. Date added after initial release).
	 *
	 * @param array<int, array<string, mixed>> $fields Normalized fields.
	 * @return array<int, array<string, mixed>>
	 */
	private function ensure_default_core_fields( array $fields ): array {
		$core_defaults = array(
			'date'   => array(
				'label'    => 'Date',
				'key'      => 'date',
				'type'     => 'core',
				'field'    => 'date',
				'required' => false,
			),
			'author' => array(
				'label'    => 'Author',
				'key'      => 'author',
				'type'     => 'core',
				'field'    => 'author',
				'required' => false,
			),
		);

		foreach ( $core_defaults as $core_key => $default_row ) {
			$has_field = false;
			foreach ( $fields as $field ) {
				if ( 'core' === ( $field['type'] ?? '' ) && $core_key === ( $field['field'] ?? '' ) ) {
					$has_field = true;
					break;
				}
			}

			if ( $has_field ) {
				continue;
			}

			$insert_at = 0;
			foreach ( $fields as $index => $field ) {
				if ( 'core' === ( $field['type'] ?? '' ) ) {
					$insert_at = $index + 1;
				}
			}

			array_splice( $fields, $insert_at, 0, array( $default_row ) );
		}

		return $fields;
	}

	/**
	 * @param string               $slug  Field slug.
	 * @param array<string, mixed> $field Raw field row.
	 */
	private static function resolve_meta_key( string $slug, array $field ): string {
		$requested = (string) ( $field['meta_key'] ?? '' );
		foreach ( self::get_available_meta_fields() as $available ) {
			if ( $available['slug'] === $slug || $available['meta_key'] === $requested ) {
				return (string) $available['meta_key'];
			}
		}

		return '';
	}
}
