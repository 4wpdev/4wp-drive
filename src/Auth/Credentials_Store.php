<?php
/**
 * Google OAuth app credentials (admin settings).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Stores Client ID (option) and Client Secret (encrypted).
 */
final class Credentials_Store {

	public const CLIENT_ID_OPTION     = 'forwp_drive_google_client_id';
	public const CLIENT_SECRET_OPTION = 'forwp_drive_google_client_secret_enc';

	/**
	 * Whether credentials exist (wp-config or database).
	 */
	public function has_credentials(): bool {
		return '' !== $this->get_client_id() && '' !== $this->get_client_secret();
	}

	/**
	 * True when constants override database values.
	 */
	public function is_locked_by_wp_config(): bool {
		return defined( 'FORWP_DRIVE_GOOGLE_CLIENT_ID' ) && defined( 'FORWP_DRIVE_GOOGLE_CLIENT_SECRET' );
	}

	/**
	 * @return string
	 */
	public function get_client_id(): string {
		if ( defined( 'FORWP_DRIVE_GOOGLE_CLIENT_ID' ) ) {
			return (string) FORWP_DRIVE_GOOGLE_CLIENT_ID;
		}

		return (string) get_option( self::CLIENT_ID_OPTION, '' );
	}

	/**
	 * @return string
	 */
	public function get_client_secret(): string {
		if ( defined( 'FORWP_DRIVE_GOOGLE_CLIENT_SECRET' ) ) {
			return (string) FORWP_DRIVE_GOOGLE_CLIENT_SECRET;
		}

		$stored = get_option( self::CLIENT_SECRET_OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		$plain = Crypto::decrypt( $stored );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Whether a client secret is stored (without revealing it).
	 */
	public function has_stored_secret(): bool {
		if ( defined( 'FORWP_DRIVE_GOOGLE_CLIENT_SECRET' ) ) {
			return true;
		}

		$stored = get_option( self::CLIENT_SECRET_OPTION, '' );

		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * Save credentials from admin form.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret; empty keeps existing.
	 * @return true|\WP_Error
	 */
	public function save( string $client_id, string $client_secret ) {
		if ( $this->is_locked_by_wp_config() ) {
			return new \WP_Error(
				'forwp_drive_credentials_locked',
				__( 'Credentials are defined in wp-config.php and cannot be changed here.', '4wp-drive' )
			);
		}

		$client_id = sanitize_text_field( $client_id );
		if ( '' === $client_id ) {
			return new \WP_Error(
				'forwp_drive_missing_client_id',
				__( 'Client ID is required.', '4wp-drive' )
			);
		}

		update_option( self::CLIENT_ID_OPTION, $client_id, false );

		$client_secret = trim( $client_secret );
		if ( '' !== $client_secret ) {
			update_option( self::CLIENT_SECRET_OPTION, Crypto::encrypt( $client_secret ), false );
		} elseif ( ! $this->has_stored_secret() ) {
			return new \WP_Error(
				'forwp_drive_missing_client_secret',
				__( 'Client Secret is required on first save.', '4wp-drive' )
			);
		}

		return true;
	}

	/**
	 * Remove stored credentials (not wp-config).
	 */
	public function delete(): void {
		if ( $this->is_locked_by_wp_config() ) {
			return;
		}

		delete_option( self::CLIENT_ID_OPTION );
		delete_option( self::CLIENT_SECRET_OPTION );
	}
}
