<?php
/**
 * Read and write meta.json of a cross-posting package.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Package;

use ForWP\Drive\Api\GitHub_Client;
use ForWP\Drive\Import\Import_Runner;
use ForWP\Drive\Sources\GitHub_Source;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Drive is the only writer of meta.json. Other plugins go through the filters:
 *
 *     $package = apply_filters( 'forwp_drive_package', null, $post_id, $fresh );   // array|WP_Error|null
 *     $result  = apply_filters( 'forwp_drive_update_package', null, $post_id, $patch ); // true|WP_Error|null
 *     $files   = apply_filters( 'forwp_drive_package_files', null, $post_id );        // array<name, size>|WP_Error|null
 *     $text    = apply_filters( 'forwp_drive_package_file', null, $post_id, $name );  // string|WP_Error|null
 *
 * null means the post has no meta.json package (or Drive is not active).
 */
final class Package_Store {

	/**
	 * Post meta holding the last meta.json sync error.
	 */
	public const ERROR_POST_META = '_forwp_drive_package_error';

	private const CACHE_PREFIX = 'forwp_drive_pkg_';

	private const CACHE_TTL = 60;

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_filter( 'forwp_drive_package', array( self::class, 'filter_get' ), 10, 3 );
		add_filter( 'forwp_drive_update_package', array( self::class, 'filter_update' ), 10, 3 );
		add_filter( 'forwp_drive_package_files', array( self::class, 'filter_files' ), 10, 2 );
		add_filter( 'forwp_drive_package_file', array( self::class, 'filter_file' ), 10, 3 );
		add_action( 'transition_post_status', array( self::class, 'on_status_change' ), 10, 3 );
	}

	/**
	 * Filter callback: meta.json data for a post.
	 *
	 * @param mixed $value   Value from earlier callbacks.
	 * @param int   $post_id Post id.
	 * @param bool  $fresh   Skip the cache and read GitHub.
	 * @return array<string, mixed>|WP_Error|null
	 */
	public static function filter_get( $value, $post_id, $fresh = false ) {
		return null !== $value ? $value : self::get( (int) $post_id, (bool) $fresh );
	}

	/**
	 * Filter callback: files in the package folder.
	 *
	 * @param mixed $value   Value from earlier callbacks.
	 * @param int   $post_id Post id.
	 * @return array<string, int>|WP_Error|null File name => size in bytes.
	 */
	public static function filter_files( $value, $post_id ) {
		return null !== $value ? $value : self::files( (int) $post_id );
	}

	/**
	 * Filter callback: contents of one package file (read on demand, never stored).
	 *
	 * @param mixed  $value   Value from earlier callbacks.
	 * @param int    $post_id Post id.
	 * @param string $name    File name inside the package folder.
	 * @return string|WP_Error|null
	 */
	public static function filter_file( $value, $post_id, $name ) {
		if ( null !== $value ) {
			return $value;
		}

		$path = self::manifest_path( (int) $post_id );
		$name = (string) $name;
		if ( ! $path || '' === $name || basename( $name ) !== $name ) {
			return null;
		}

		return ( new GitHub_Client() )->get_file_contents( dirname( $path ) . '/' . $name );
	}

	/**
	 * Files in the package folder (name => size), so callers can tell missing and empty variants apart.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, int>|WP_Error|null
	 */
	public static function files( int $post_id ) {
		$path = self::manifest_path( $post_id );
		if ( ! $path ) {
			return null;
		}

		$listing = ( new GitHub_Client() )->list_path( dirname( $path ) );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$files = array();
		foreach ( $listing as $entry ) {
			if ( is_array( $entry ) && 'file' === ( $entry['type'] ?? '' ) ) {
				$files[ (string) $entry['name'] ] = (int) ( $entry['size'] ?? 0 );
			}
		}

		return $files;
	}

	/**
	 * Filter callback: merge a patch into meta.json and commit it.
	 *
	 * @param mixed                $value   Value from earlier callbacks.
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Changes.
	 * @return true|WP_Error|null
	 */
	public static function filter_update( $value, $post_id, $patch ) {
		if ( null !== $value ) {
			return $value;
		}
		if ( ! self::manifest_path( (int) $post_id ) ) {
			return null;
		}

		return self::update( (int) $post_id, is_array( $patch ) ? $patch : array() );
	}

	/**
	 * Fill in URL, slug and publish date when the imported original goes live.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_status_change( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! self::manifest_path( (int) $post->ID ) ) {
			return;
		}

		$result = self::update(
			(int) $post->ID,
			array(
				'original' => array(
					'status'       => 'published',
					'post_id'      => (int) $post->ID,
					'slug'         => (string) $post->post_name,
					'url'          => (string) get_permalink( $post ),
					'published_at' => (string) get_post_time( 'c', true, $post ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			update_post_meta( (int) $post->ID, self::ERROR_POST_META, $result->get_error_message() );
		}
	}

	/**
	 * Package meta.json data for a post.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $fresh   Skip the cache and read GitHub.
	 * @return array<string, mixed>|WP_Error|null Null when the post has no package.
	 */
	public static function get( int $post_id, bool $fresh = false ) {
		$path = self::manifest_path( $post_id );
		if ( ! $path ) {
			return null;
		}

		$cached = $fresh ? false : get_transient( self::cache_key( $path ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$file = ( new GitHub_Client() )->get_file( $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$data = self::decode( $file['content'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		set_transient( self::cache_key( $path ), $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Merge a patch into meta.json and commit. Retries once when the file changed in between.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Changes.
	 * @return true|WP_Error
	 */
	public static function update( int $post_id, array $patch ) {
		$path = self::manifest_path( $post_id );
		if ( ! $path ) {
			return new WP_Error( 'forwp_drive_no_package', __( 'This post has no meta.json package.', '4wp-drive' ) );
		}

		$client = new GitHub_Client();
		$result = new WP_Error( 'forwp_drive_package_write', __( 'Could not update meta.json.', '4wp-drive' ) );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$file = $client->get_file( $path );
			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$data = self::decode( $file['content'] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$merged = Package_Manifest::merge( $data, $patch );
			if ( $merged === $data ) {
				return true;
			}

			$result = $client->put_file(
				$path,
				Package_Manifest::encode( $merged ),
				$file['sha'],
				'4WP Drive: update ' . $path
			);

			// 409: meta.json changed since we read it — re-read and merge again.
			$status = is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 0 ) : 0;
			if ( 409 !== $status ) {
				break;
			}
		}

		delete_transient( self::cache_key( $path ) );
		if ( true === $result ) {
			delete_post_meta( $post_id, self::ERROR_POST_META );
		}

		return $result;
	}

	/**
	 * Repo path of meta.json for a post imported from a GitHub package.
	 *
	 * @param int $post_id Post id.
	 */
	private static function manifest_path( int $post_id ): string {
		$link = get_post_meta( $post_id, Import_Runner::PACKAGE_POST_META, true );
		if ( ! is_array( $link ) || GitHub_Source::SLUG !== ( $link['source'] ?? '' ) ) {
			return '';
		}

		$folder = trim( str_replace( '\\', '/', (string) ( $link['path'] ?? '' ) ), '/' );

		return '' !== $folder ? $folder . '/' . Package_Manifest::FILE_NAME : '';
	}

	/**
	 * Decode meta.json.
	 *
	 * @param string $json Raw meta.json.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode( string $json ) {
		$data = json_decode( $json, true );

		return is_array( $data )
			? $data
			: new WP_Error( 'forwp_drive_package_json', __( 'meta.json is not valid JSON.', '4wp-drive' ) );
	}

	/**
	 * Transient key for a meta.json path.
	 *
	 * @param string $path Repo path.
	 */
	private static function cache_key( string $path ): string {
		return self::CACHE_PREFIX . md5( $path );
	}
}
