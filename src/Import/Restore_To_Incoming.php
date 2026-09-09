<?php
/**
 * Move a published package back to incoming.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Api\GitHub_Client;
use ForWP\Drive\Api\Google_Drive_Client;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Database\Import_History_Repository;
use ForWP\Drive\Sources\GitHub_Source;
use ForWP\Drive\Sources\Google_Drive_Source;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reverse of move_after_import for one history row.
 */
final class Restore_To_Incoming {

	/**
	 * @var Import_History_Repository
	 */
	private $history;

	public function __construct( ?Import_History_Repository $history = null ) {
		$this->history = $history ?? new Import_History_Repository();
	}

	/**
	 * @return true|WP_Error
	 */
	public function restore( int $history_id ) {
		$row = $this->history->find( $history_id );
		if ( ! $row ) {
			return new WP_Error( 'forwp_drive_history_missing', __( 'History row was not found.', '4wp-drive' ) );
		}

		if ( ! empty( $row->restored_at ) ) {
			return new WP_Error( 'forwp_drive_already_restored', __( 'This package was already restored to incoming.', '4wp-drive' ) );
		}

		$source = (string) ( $row->source ?? '' );
		if ( GitHub_Source::SLUG === $source ) {
			$moved = $this->restore_github( $row );
		} elseif ( Google_Drive_Source::SLUG === $source ) {
			$moved = $this->restore_drive( $row );
		} else {
			return new WP_Error( 'forwp_drive_restore_source', __( 'Restore is not available for this source.', '4wp-drive' ) );
		}

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$this->history->mark_restored( $history_id );

		return true;
	}

	/**
	 * @param object $row History row.
	 * @return true|WP_Error
	 */
	private function restore_github( $row ) {
		$from_dir = trim( str_replace( '\\', '/', (string) ( $row->published_path ?? '' ) ), '/' );
		$to_dir   = trim( str_replace( '\\', '/', (string) ( $row->incoming_path ?? '' ) ), '/' );
		if ( '' === $from_dir || '' === $to_dir ) {
			return new WP_Error( 'forwp_drive_restore_path', __( 'History row is missing incoming or published path.', '4wp-drive' ) );
		}

		$client  = new GitHub_Client();
		$listing = $client->list_path( $from_dir );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$moved_any = false;
		$last      = true;
		foreach ( $listing as $entry ) {
			if ( ! is_array( $entry ) || 'file' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$from = trim( (string) ( $entry['path'] ?? '' ), '/' );
			if ( '' === $from ) {
				continue;
			}
			$to = basename( $to_dir ) === basename( $from )
				? $to_dir
				: trim( $to_dir . '/' . basename( $from ), '/' );
			$last = $client->move_file( $from, $to );
			if ( is_wp_error( $last ) ) {
				return $last;
			}
			$moved_any = true;
		}

		if ( ! $moved_any ) {
			return new WP_Error( 'forwp_drive_restore_empty', __( 'No files found in the published path.', '4wp-drive' ) );
		}

		return true === $last ? true : $last;
	}

	/**
	 * @param object $row History row.
	 * @return true|WP_Error
	 */
	private function restore_drive( $row ) {
		$folders   = Settings::instance()->get_folder_ids();
		$incoming  = (string) ( $folders['incoming'] ?? '' );
		$published = (string) ( $folders['published'] ?? '' );
		if ( '' === $incoming || '' === $published ) {
			return new WP_Error( 'forwp_drive_folders', __( 'Folder mapping is incomplete.', '4wp-drive' ) );
		}

		$ref = (string) ( $row->package_ref ?? '' );
		if ( '' === $ref ) {
			$ref = (string) ( $row->file_id ?? '' );
		}
		if ( '' === $ref ) {
			return new WP_Error( 'forwp_drive_restore_ref', __( 'History row is missing the Drive folder id.', '4wp-drive' ) );
		}

		$client = new Google_Drive_Client( Google_OAuth::instance() );

		return $client->move_file( $ref, $incoming, $published );
	}
}
