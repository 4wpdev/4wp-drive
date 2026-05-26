<?php
/**
 * Registered storage sources.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive;

use ForWP\Drive\Contracts\Storage_Source_Interface;
use ForWP\Drive\Sources\Google_Drive_Source;

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
			$rows[] = array(
				'slug'          => (string) $slug,
				'label'         => $source->get_label(),
				'implemented'   => true,
				'status'        => $ready
					? __( 'Live — Google Docs via Drive folders (incoming → published).', '4wp-drive' )
					: __( 'Add OAuth credentials, connect Google, set folder IDs.', '4wp-drive' ),
			);
		}

		$rows[] = array(
			'slug'        => 'github',
			'label'       => __( 'GitHub', '4wp-drive' ),
			'implemented' => false,
			'status'      => __( 'Roadmap — repo folders. Planned: Markdown (.md) and MDX.', '4wp-drive' ),
		);
		$rows[] = array(
			'slug'        => 'onedrive',
			'label'       => __( 'Microsoft OneDrive', '4wp-drive' ),
			'implemented' => false,
			'status'      => __( 'Roadmap — OneDrive folders. Planned: Word and shared libraries.', '4wp-drive' ),
		);
		$rows[] = array(
			'slug'        => 'dropbox',
			'label'       => __( 'Dropbox', '4wp-drive' ),
			'implemented' => false,
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
}
