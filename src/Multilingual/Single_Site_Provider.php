<?php
/**
 * Single-language site fallback.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * No multilingual plugin — one implicit language, no import UI.
 */
final class Single_Site_Provider implements Language_Provider_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'single';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Single language', '4wp-drive' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function is_installed(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function get_languages(): array {
		$locale = determine_locale();
		$code   = sanitize_key( substr( $locale, 0, 2 ) );
		if ( '' === $code ) {
			$code = 'en';
		}

		return array(
			array(
				'code' => $code,
				'name' => $locale,
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function requires_manual_selection(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function is_valid_language( string $lang_code ): bool {
		$lang_code = sanitize_key( $lang_code );
		if ( '' === $lang_code ) {
			return true;
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
		unset( $post_id );

		$languages = $this->get_languages();

		return $languages[0]['code'] ?? 'en';
	}

	/**
	 * @inheritDoc
	 */
	public function assign_post_language( int $post_id, string $lang_code ): void {
		unset( $post_id, $lang_code );
	}

	/**
	 * @inheritDoc
	 */
	public function apply_language_to_query_args( array $query_args, string $lang_code ): array {
		unset( $lang_code );

		return $query_args;
	}
}
