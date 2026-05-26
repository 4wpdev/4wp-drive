<?php
/**
 * Parse publication dates from document front matter.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Converts common date strings to WordPress post_date format (local time).
 */
final class Post_Date_Parser {

	/**
	 * @param string $raw Value from front matter.
	 */
	public static function to_post_date( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m ) ) {
			return self::format(
				(int) $m[1],
				(int) $m[2],
				(int) $m[3],
				isset( $m[4] ) ? (int) $m[4] : 9,
				isset( $m[5] ) ? (int) $m[5] : 0,
				isset( $m[6] ) ? (int) $m[6] : 0
			);
		}

		if ( preg_match( '/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m ) ) {
			return self::format(
				(int) $m[3],
				(int) $m[2],
				(int) $m[1],
				isset( $m[4] ) ? (int) $m[4] : 9,
				isset( $m[5] ) ? (int) $m[5] : 0,
				isset( $m[6] ) ? (int) $m[6] : 0
			);
		}

		$timestamp = strtotime( $raw );
		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * @param int $year   Year.
	 * @param int $month  Month.
	 * @param int $day    Day.
	 * @param int $hour   Hour.
	 * @param int $minute Minute.
	 * @param int $second Second.
	 */
	private static function format( int $year, int $month, int $day, int $hour, int $minute, int $second ): string {
		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}

		return sprintf(
			'%04d-%02d-%02d %02d:%02d:%02d',
			$year,
			$month,
			$day,
			max( 0, min( 23, $hour ) ),
			max( 0, min( 59, $minute ) ),
			max( 0, min( 59, $second ) )
		);
	}
}
