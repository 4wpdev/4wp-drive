<?php
/**
 * Catalog of import wrap capabilities (core + family allow-list + later custom).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Drive does not list sibling Gutenberg blocks. Family plugins push an explicit allow-list.
 */
final class Wrap_Capability_Registry {

	public const ORIGIN_CORE   = 'core';
	public const ORIGIN_FAMILY = 'family';
	public const ORIGIN_CUSTOM = 'custom';

	public const WRAP_SECTION = 'section';
	public const WRAP_FAQ_QA  = 'faq-qa';
	public const WRAP_MARKER  = 'marker';

	public const MAP_ARTICLE      = 'article';
	public const MAP_TECH_ARTICLE = 'tech-article';
	public const MAP_RECIPE       = 'recipe';

	/**
	 * Built-in Map values (not Schema.org). Family may add more via capability `maps`.
	 *
	 * @return string[]
	 */
	public static function default_maps(): array {
		return array(
			self::MAP_ARTICLE,
			self::MAP_TECH_ARTICLE,
			self::MAP_RECIPE,
		);
	}

	/**
	 * Normalize a document Map: header value.
	 *
	 * @param string $raw Raw header value.
	 */
	public static function normalize_map( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		$key = str_replace( '_', '-', $key );
		$key = sanitize_key( $key );

		if ( 'techarticle' === $key ) {
			$key = self::MAP_TECH_ARTICLE;
		}

		if ( '' === $key ) {
			return self::MAP_ARTICLE;
		}

		$known = self::known_maps();
		if ( in_array( $key, $known, true ) ) {
			return $key;
		}

		return self::MAP_ARTICLE;
	}

	/**
	 * @return string[]
	 */
	public static function known_maps(): array {
		$maps = self::default_maps();

		foreach ( self::all() as $capability ) {
			foreach ( $capability['maps'] as $map ) {
				$maps[] = $map;
			}
		}

		$maps = array_values( array_unique( array_filter( $maps ) ) );
		sort( $maps );

		return $maps;
	}

	/**
	 * Active capabilities (core + filter from family/custom).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$items = self::core();

		/**
		 * Explicit Drive wrap allow-list. Do not dump every registered Gutenberg block.
		 *
		 * @param array<int, array<string, mixed>> $items Capability rows.
		 */
		$filtered = apply_filters( 'forwp_drive_wrap_capabilities', $items );

		if ( ! is_array( $filtered ) ) {
			$filtered = $items;
		}

		$out  = array();
		$seen = array();

		foreach ( $filtered as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$normalized = self::normalize( $row );
			if ( null === $normalized ) {
				continue;
			}

			$id = $normalized['id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $normalized;
		}

		return $out;
	}

	/**
	 * One capability by id.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return null;
		}

		foreach ( self::all() as $capability ) {
			if ( $id === (string) ( $capability['id'] ?? '' ) ) {
				return $capability;
			}
		}

		return null;
	}

	/**
	 * Capabilities that apply to a document Map value.
	 *
	 * Empty `maps` on a row means every Map.
	 *
	 * @param string $map Normalized map slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_map( string $map ): array {
		$map = self::normalize_map( $map );
		$out = array();

		foreach ( self::all() as $capability ) {
			if ( empty( $capability['maps'] ) || in_array( $map, $capability['maps'], true ) ) {
				$out[] = $capability;
			}
		}

		return $out;
	}

	/**
	 * Let family plugins upgrade Map when the document signals their profile.
	 *
	 * Explicit Map: recipe stays. Default article may become tech-article.
	 *
	 * @param string $map    Parsed Map value.
	 * @param string $header Document header text.
	 * @param string $body   Body HTML or plain text.
	 */
	public static function detect_map( string $map, string $header, string $body ): string {
		$map = self::normalize_map( $map );

		/**
		 * Detect document Map from header/body (family plugins).
		 *
		 * @param string $map    Current map slug.
		 * @param string $header Header text.
		 * @param string $body   Body HTML.
		 */
		$detected = apply_filters( 'forwp_drive_detect_map', $map, $header, $body );
		if ( ! is_string( $detected ) || '' === $detected ) {
			return $map;
		}

		$detected = self::normalize_map( $detected );
		if ( self::MAP_ARTICLE === $map && self::MAP_ARTICLE !== $detected ) {
			return $detected;
		}

		return $map;
	}

	/**
	 * Family plugins grouped for the Patterns dashboard.
	 *
	 * Starts from {@see Family_Plugin_Catalog} so cards exist before activate,
	 * then attaches wrap rows from `forwp_drive_wrap_capabilities`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function family_groups(): array {
		$groups = array();

		foreach ( Family_Plugin_Catalog::all() as $slug => $meta ) {
			$groups[ $slug ] = self::empty_family_group( $slug, $meta );
		}

		foreach ( self::all() as $capability ) {
			if ( self::ORIGIN_FAMILY !== ( $capability['origin'] ?? '' ) ) {
				continue;
			}

			$plugin = (string) ( $capability['plugin'] ?? '' );
			if ( '' === $plugin ) {
				$plugin = 'family';
			}

			if ( ! isset( $groups[ $plugin ] ) ) {
				$catalog = Family_Plugin_Catalog::get( $plugin );
				$groups[ $plugin ] = self::empty_family_group(
					$plugin,
					is_array( $catalog ) ? $catalog : array( 'label' => self::plugin_label( $plugin ) )
				);
			}

			$groups[ $plugin ]['items'][] = $capability;
		}

		return array_values( $groups );
	}

	public static function plugin_label( string $plugin ): string {
		$catalog = Family_Plugin_Catalog::get( $plugin );
		if ( $catalog ) {
			return (string) $catalog['label'];
		}

		$labels = array(
			'4wp-faq'        => '4WP FAQ',
			'4wp-seo-helper' => '4WP SEO Helper',
			'4wp-drive'      => '4WP Drive',
		);

		return $labels[ $plugin ] ?? $plugin;
	}

	public static function is_family_plugin_active( string $plugin ): bool {
		return Family_Plugin_Catalog::is_active( $plugin );
	}

	/**
	 * @param array<string, mixed> $meta Catalog row.
	 * @return array<string, mixed>
	 */
	private static function empty_family_group( string $slug, array $meta ): array {
		$action = Family_Plugin_Catalog::action( $slug );
		$active = Family_Plugin_Catalog::is_active( $slug );
		$installed = Family_Plugin_Catalog::is_installed( $slug );

		$status = __( 'Not installed', '4wp-drive' );
		if ( $active ) {
			$status = __( 'Active', '4wp-drive' );
		} elseif ( $installed ) {
			$status = __( 'Inactive', '4wp-drive' );
		}

		return array(
			'plugin'       => $slug,
			'label'        => (string) ( $meta['label'] ?? self::plugin_label( $slug ) ),
			'description'  => (string) ( $meta['description'] ?? '' ),
			'uri'          => (string) ( $meta['uri'] ?? '' ),
			'wrap_labels'  => isset( $meta['wrap_labels'] ) && is_array( $meta['wrap_labels'] ) ? $meta['wrap_labels'] : array(),
			'active'       => $active,
			'installed'    => $installed,
			'status'       => $status,
			'action_url'   => (string) ( $action['url'] ?? '' ),
			'action_label' => (string) ( $action['label'] ?? '' ),
			'action_kind'  => (string) ( $action['kind'] ?? '' ),
			'items'        => array(),
		);
	}

	/**
	 * Core wraps owned by Drive (not family Gutenberg catalogs).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function core(): array {
		return array(
			array(
				'id'     => 'core-image',
				'origin' => self::ORIGIN_CORE,
				'plugin' => '4wp-drive',
				'block'  => 'core/image',
				'maps'   => array(),
				'wrap'   => self::WRAP_MARKER,
				'match'  => array(
					'type'    => 'marker',
					'pattern' => '\[image:[^\]]+\]',
				),
				'prompt' => __( 'Same folder as the article. Google Doc: [image:exact-filename.jpg] (optional left/center/right). Markdown: ![alt](file.png) or the same marker.', '4wp-drive' ),
				'auto'   => true,
				'manual' => true,
			),
		);
	}

	/**
	 * @param array<string, mixed> $row Raw capability.
	 * @return array<string, mixed>|null
	 */
	public static function normalize( array $row ): ?array {
		$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
		if ( '' === $id ) {
			return null;
		}

		$origin = sanitize_key( (string) ( $row['origin'] ?? self::ORIGIN_FAMILY ) );
		if ( ! in_array( $origin, array( self::ORIGIN_CORE, self::ORIGIN_FAMILY, self::ORIGIN_CUSTOM ), true ) ) {
			$origin = self::ORIGIN_FAMILY;
		}

		$wrap = sanitize_key( (string) ( $row['wrap'] ?? self::WRAP_SECTION ) );
		if ( ! in_array( $wrap, array( self::WRAP_SECTION, self::WRAP_FAQ_QA, self::WRAP_MARKER ), true ) ) {
			return null;
		}

		$block = trim( (string) ( $row['block'] ?? '' ) );
		if ( '' === $block || ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block ) ) {
			return null;
		}

		$maps = array();
		if ( isset( $row['maps'] ) && is_array( $row['maps'] ) ) {
			foreach ( $row['maps'] as $map ) {
				$map = sanitize_key( str_replace( '_', '-', (string) $map ) );
				if ( 'techarticle' === $map ) {
					$map = self::MAP_TECH_ARTICLE;
				}
				if ( '' !== $map ) {
					$maps[] = $map;
				}
			}
		}
		$maps = array_values( array_unique( $maps ) );

		$match = isset( $row['match'] ) && is_array( $row['match'] ) ? $row['match'] : array();
		$match = array(
			'type'    => sanitize_key( (string) ( $match['type'] ?? 'heading' ) ),
			'level'   => isset( $match['level'] ) ? max( 0, (int) $match['level'] ) : 2,
			'pattern' => (string) ( $match['pattern'] ?? '' ),
		);

		$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $id;
		}

		return array(
			'id'            => $id,
			'origin'        => $origin,
			'plugin'        => sanitize_key( (string) ( $row['plugin'] ?? '' ) ),
			'block'         => $block,
			'label'         => $label,
			'maps'          => $maps,
			'wrap'          => $wrap,
			'match'         => $match,
			'heading_seeds' => sanitize_text_field( (string) ( $row['heading_seeds'] ?? '' ) ),
			'prompt'        => sanitize_text_field( (string) ( $row['prompt'] ?? '' ) ),
			'auto'          => ! empty( $row['auto'] ),
			'manual'        => ! isset( $row['manual'] ) || ! empty( $row['manual'] ),
		);
	}
}
