<?php
/**
 * Apply SEO plugin meta from parsed document fields.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Writes post meta for supported SEO plugins (Yoast first).
 */
final class Seo_Meta_Applicator {

	/**
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $meta    meta_key => value from parser.
	 */
	public function apply( int $post_id, array $meta ): void {
		if ( empty( $meta ) ) {
			return;
		}

		/**
		 * Filter meta map before it is written (extend for Rank Math, AIOSEO, etc.).
		 *
		 * @param array<string, string> $meta    Meta keys and values.
		 * @param int                   $post_id Post id.
		 */
		$meta = apply_filters( 'forwp_drive_import_seo_meta', $this->sanitize_meta_map( $meta ), $post_id );

		if ( $this->is_yoast_active() ) {
			$this->apply_yoast( $post_id, $meta );
		}

		/**
		 * Fires after built-in SEO handlers run.
		 *
		 * @param int                   $post_id Post id.
		 * @param array<string, string> $meta    Meta keys and values.
		 */
		do_action( 'forwp_drive_import_seo_meta_applied', $post_id, $meta );
	}

	/**
	 * @return array<int, array{slug: string, meta_key: string, label: string}>
	 */
	public static function get_available_fields(): array {
		$fields = array();

		if ( self::is_yoast_active() ) {
			$fields[] = array(
				'slug'     => 'yoast_seo_title',
				'meta_key' => '_yoast_wpseo_title',
				'label'    => __( 'SEO Title (Yoast)', '4wp-drive' ),
			);
			$fields[] = array(
				'slug'     => 'yoast_seo_description',
				'meta_key' => '_yoast_wpseo_metadesc',
				'label'    => __( 'SEO Description (Yoast)', '4wp-drive' ),
			);
		}

		/**
		 * Register importable SEO/meta fields for the document template UI.
		 *
		 * @param array<int, array{slug: string, meta_key: string, label: string}> $fields
		 */
		return apply_filters( 'forwp_drive_import_meta_fields', $fields );
	}

	/**
	 * @param array<string, mixed> $meta Raw meta from parser.
	 * @return array<string, string>
	 */
	private function sanitize_meta_map( array $meta ): array {
		$clean = array();
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = sanitize_text_field( (string) $value );
		}

		return $clean;
	}

	/**
	 * @param int                   $post_id Post id.
	 * @param array<string, string> $meta   Meta map.
	 */
	private function apply_yoast( int $post_id, array $meta ): void {
		$map = array(
			'_yoast_wpseo_title'    => '_yoast_wpseo_title',
			'_yoast_wpseo_metadesc' => '_yoast_wpseo_metadesc',
		);

		foreach ( $map as $meta_key => $target ) {
			if ( empty( $meta[ $meta_key ] ) ) {
				continue;
			}
			update_post_meta( $post_id, $target, $meta[ $meta_key ] );
		}
	}

	private static function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}
}
