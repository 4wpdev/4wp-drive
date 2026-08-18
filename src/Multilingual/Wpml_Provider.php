<?php
/**
 * WPML multilingual provider.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * WPML language provider (planned — class kept for a future release).
 */
final class Wpml_Provider implements Language_Provider_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'wpml';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return 'WPML';
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'apply_filters' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_installed(): bool {
		return Installed_Plugin_Detector::is_plugin_present(
			array(
				'sitepress-multilingual-cms/sitepress.php',
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_languages(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML third-party filter.
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $active ) ) {
			return array();
		}

		$languages = array();

		foreach ( $active as $code => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$languages[] = array(
				'code' => (string) $code,
				'name' => (string) ( $data['native_name'] ?? $code ),
			);
		}

		return $languages;
	}

	/**
	 * @inheritDoc
	 */
	public function requires_manual_selection(): bool {
		return count( $this->get_languages() ) > 1;
	}

	/**
	 * @inheritDoc
	 */
	public function is_valid_language( string $lang_code ): bool {
		$lang_code = sanitize_key( $lang_code );
		if ( '' === $lang_code ) {
			return false;
		}

		foreach ( $this->get_languages() as $language ) {
			if ( $language['code'] === $lang_code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function get_post_language( int $post_id ): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML third-party filter.
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}

		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function assign_post_language( int $post_id, string $lang_code ): void {
		if ( ! $this->is_available() || ! $this->is_valid_language( $lang_code ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML third-party filter.
		$element_type = apply_filters( 'wpml_element_type', 'post_' . $post_type );

		/**
		 * WPML sets element language after import.
		 *
		 * @param array<string, mixed> $args Language details.
		 */
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $post_id,
				'element_type'         => $element_type,
				'language_code'        => $lang_code,
				'source_language_code' => null,
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function apply_language_to_query_args( array $query_args, string $lang_code ): array {
		if ( ! $this->is_available() || ! $this->is_valid_language( $lang_code ) ) {
			return $query_args;
		}

		$query_args['lang'] = $lang_code;

		return $query_args;
	}
}
