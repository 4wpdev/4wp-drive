<?php
/**
 * Resolve import language from REST/admin payload.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Multilingual\Language_Provider_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates language for create/update import.
 */
final class Import_Language_Resolver {

	/**
	 * Resolve language code for import.
	 *
	 * @param string $requested Language from client (may be empty).
	 * @return string|WP_Error
	 */
	public static function resolve( string $requested ) {
		$provider  = Language_Provider_Registry::get_active();
		$requested = sanitize_key( $requested );

		if ( ! $provider->requires_manual_selection() ) {
			$languages = $provider->get_languages();

			return isset( $languages[0]['code'] ) ? (string) $languages[0]['code'] : '';
		}

		if ( '' === $requested ) {
			return new WP_Error(
				'forwp_drive_language_required',
				__( 'Select a content language for this import.', '4wp-drive' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $provider->is_valid_language( $requested ) ) {
			return new WP_Error(
				'forwp_drive_invalid_language',
				__( 'Selected language is not available on this site.', '4wp-drive' ),
				array( 'status' => 400 )
			);
		}

		return $requested;
	}
}
