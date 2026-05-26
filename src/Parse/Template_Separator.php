<?php
/**
 * Front-matter / body separator markers.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Parse;

defined( 'ABSPATH' ) || exit;

/**
 * Document body starts after this line (own paragraph in Google Docs).
 *
 * A row of equals signs (3 or more) is accepted so `=====`, `======`, etc. all work.
 */
final class Template_Separator {

	public const MARK = '======';

	/** Minimum length of an equals-only separator line (e.g. ===== or ======). */
	public const MIN_EQUALS_RUN = 3;

	/**
	 * Primary separator for new documents.
	 */
	public static function mark(): string {
		return self::MARK;
	}

	/**
	 * Exact legacy / canonical strings still matched in HTML regex fallbacks.
	 *
	 * @return string[]
	 */
	public static function all_marks(): array {
		return array(
			self::MARK,
			'---',
		);
	}

	/**
	 * Whether a compact line (whitespace stripped) is only equals signs.
	 */
	public static function is_equals_only_compact( string $compact ): bool {
		return (bool) preg_match( '/^={' . self::MIN_EQUALS_RUN . ',}$/', $compact );
	}

	/**
	 * Whether a plain-text line is only a separator marker.
	 */
	public static function is_mark_line( string $line ): bool {
		$compact = preg_replace( '/\s+/u', '', trim( $line ) );

		if ( self::is_equals_only_compact( $compact ) ) {
			return true;
		}

		foreach ( self::all_marks() as $mark ) {
			if ( $compact === $mark ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Regex to split header/body on its own line (multiline).
	 */
	public static function multiline_split_pattern(): string {
		return '/\r?\n\s*(?:---|={' . self::MIN_EQUALS_RUN . ',})\s*\r?\n/';
	}

	/**
	 * Regex to split collapsed "headerMARKbody" exports.
	 */
	public static function collapsed_split_pattern(): string {
		return '/^(.*?)((?:---|={' . self::MIN_EQUALS_RUN . ',}))\s*(.+)$/s';
	}

	/**
	 * Replace Google horizontal rules with the primary separator in plain text.
	 */
	public static function html_hr_to_mark(): string {
		return "\n" . self::MARK . "\n";
	}
}
