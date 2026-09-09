<?php
/**
 * Registered storage sources.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive;

use ForWP\Drive\Admin\GitHub_Settings;
use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Auth\Google_OAuth;
use ForWP\Drive\Contracts\Storage_Source_Interface;
use ForWP\Drive\Sources\Google_Drive_Source;
use ForWP\Drive\Sources\GitHub_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry for Drive sources.
 */
final class Source_Registry {

	/**
	 * @var array<string, Storage_Source_Interface>|null
	 */
	private static ?array $sources = null;

	/**
	 * @return array<string, Storage_Source_Interface>
	 */
	public static function all(): array {
		if ( null !== self::$sources ) {
			return self::$sources;
		}

		$map = array(
			Google_Drive_Source::SLUG => new Google_Drive_Source(),
			GitHub_Source::SLUG      => new GitHub_Source(),
		);

		/**
		 * Filter storage sources.
		 *
		 * @param array<string, Storage_Source_Interface> $map Sources.
		 */
		$filtered      = apply_filters( 'forwp_drive_sources', $map );
		self::$sources = is_array( $filtered ) ? $filtered : $map;

		return self::$sources;
	}

	/**
	 * @param string $slug Source slug.
	 */
	public static function get( string $slug ): ?Storage_Source_Interface {
		$all = self::all();

		return $all[ $slug ] ?? null;
	}

	/**
	 * Default active source for MVP.
	 */
	public static function get_default(): ?Storage_Source_Interface {
		return self::get( Google_Drive_Source::SLUG );
	}

	/**
	 * Rows for admin UI (implemented + roadmap stubs), same shape as 4WP Weather providers.
	 *
	 * @return array<int, array{slug: string, label: string, implemented: bool, status: string}>
	 */
	public static function get_admin_status_rows(): array {
		$rows = array();

		foreach ( self::all() as $slug => $source ) {
			$ready = $source->is_ready();
			if ( Google_Drive_Source::SLUG === $slug ) {
				$status = $ready
					? __( 'Live — Google Docs, Markdown, and Word via Drive folders (incoming → published).', '4wp-drive' )
					: __( 'Add OAuth credentials, connect Google, set folder IDs.', '4wp-drive' );
			} elseif ( GitHub_Source::SLUG === $slug ) {
				$status = $ready
					? __( 'Live — Markdown from a GitHub repo incoming/ folder.', '4wp-drive' )
					: __( 'Add a PAT, owner, and repo under this card.', '4wp-drive' );
			} else {
				$status = $ready
					? __( 'Live.', '4wp-drive' )
					: __( 'Not configured.', '4wp-drive' );
			}

			$rows[] = array(
				'slug'        => (string) $slug,
				'label'       => $source->get_label(),
				'implemented' => true,
				'ready'       => $ready,
				'status'      => $status,
			);
		}

		$known = array_map( 'strval', array_keys( self::all() ) );
		if ( ! in_array( 'github', $known, true ) ) {
			$rows[] = array(
				'slug'        => 'github',
				'label'       => __( 'GitHub', '4wp-drive' ),
				'implemented' => false,
				'ready'       => false,
				'status'      => __( 'Roadmap — repo folders. Planned: Markdown (.md) and MDX.', '4wp-drive' ),
			);
		}
		$rows[] = array(
			'slug'        => 'onedrive',
			'label'       => __( 'Microsoft OneDrive', '4wp-drive' ),
			'implemented' => false,
			'ready'       => false,
			'status'      => __( 'Roadmap — OneDrive folders. Planned: Word and shared libraries.', '4wp-drive' ),
		);
		$rows[] = array(
			'slug'        => 'dropbox',
			'label'       => __( 'Dropbox', '4wp-drive' ),
			'implemented' => false,
			'ready'       => false,
			'status'      => __( 'Roadmap — Dropbox folders. Planned: shared team workspaces.', '4wp-drive' ),
		);

		/**
		 * Filter admin source registry rows (add stubs or reorder).
		 *
		 * @param array<int, array{slug: string, label: string, implemented: bool, status: string}> $rows Rows.
		 */
		$filtered = apply_filters( 'forwp_drive_source_admin_rows', $rows );

		return is_array( $filtered ) ? $filtered : $rows;
	}

	/**
	 * Per-source connection + last sync for the Incoming status bar.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_inbox_status(): array {
		$last = Settings::instance()->get_last_sync();
		$by   = is_array( $last ) && isset( $last['by_source'] ) && is_array( $last['by_source'] )
			? $last['by_source']
			: array();

		$out = array();

		foreach ( self::get_admin_status_rows() as $row ) {
			$slug        = (string) ( $row['slug'] ?? '' );
			$implemented = ! empty( $row['implemented'] );
			$source      = '' !== $slug ? self::get( $slug ) : null;
			$ready       = $source ? $source->is_ready() : false;

			if ( Google_Drive_Source::SLUG === $slug ) {
				$folders = Settings::instance()->get_folder_ids();
				$out[ $slug ] = array(
					'ready'        => $ready,
					'implemented'  => $implemented,
					'connection'   => Google_OAuth::instance()->get_connection_payload(),
					'last_sync'    => isset( $by[ $slug ] ) && is_array( $by[ $slug ] ) ? $by[ $slug ] : null,
					'incoming_id'  => isset( $folders['incoming'] ) ? (string) $folders['incoming'] : '',
					'incoming_url' => '',
				);
				continue;
			}

			if ( GitHub_Source::SLUG === $slug ) {
				$out[ $slug ] = array(
					'ready'        => $ready,
					'implemented'  => $implemented,
					'connection'   => array(
						'state'           => $ready ? 'ok' : 'not_configured',
						'message'         => $ready
							? ''
							: __( 'GitHub is not connected. Add a PAT, owner, and repo in Settings.', '4wp-drive' ),
						'needs_reconnect' => false,
						'settings_url'    => admin_url( 'admin.php?page=forwp-drive-settings' ),
					),
					'last_sync'    => isset( $by[ $slug ] ) && is_array( $by[ $slug ] ) ? $by[ $slug ] : null,
					'incoming_id'  => '',
					'incoming_url' => GitHub_Settings::incoming_web_url(),
				);
				continue;
			}

			$out[ $slug ] = array(
				'ready'        => false,
				'implemented'  => $implemented,
				'connection'   => array(
					'state'           => 'not_configured',
					'message'         => (string) ( $row['status'] ?? '' ),
					'needs_reconnect' => false,
					'settings_url'    => admin_url( 'admin.php?page=forwp-drive-settings' ),
				),
				'last_sync'    => null,
				'incoming_id'  => '',
				'incoming_url' => '',
			);
		}

		return $out;
	}
}
