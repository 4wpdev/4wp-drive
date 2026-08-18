<?php
/**
 * Polylang multilingual provider.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Polylang language detection and post assignment.
 */
final class Polylang_Provider implements Language_Provider_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'polylang';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return 'Polylang';
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		return function_exists( 'pll_get_post_language' )
			&& function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_set_post_language' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_installed(): bool {
		return Installed_Plugin_Detector::is_plugin_present(
			array(
				'polylang/polylang.php',
				'polylang-pro/polylang.php',
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

		$codes     = pll_languages_list( array( 'fields' => 'slug' ) );
		$names     = pll_languages_list( array( 'fields' => 'name' ) );
		$languages = array();

		if ( ! is_array( $codes ) ) {
			return array();
		}

		foreach ( $codes as $index => $code ) {
			$languages[] = array(
				'code' => (string) $code,
				'name' => (string) ( is_array( $names ) ? ( $names[ $index ] ?? $code ) : $code ),
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

		$lang = pll_get_post_language( $post_id, 'slug' );

		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * @inheritDoc
	 */
	public function assign_post_language( int $post_id, string $lang_code ): void {
		if ( ! $this->is_available() || ! $this->is_valid_language( $lang_code ) ) {
			return;
		}

		pll_set_post_language( $post_id, $lang_code );
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
