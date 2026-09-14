<?php
/**
 * GitHub Contents API client (personal access token).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Api;

use ForWP\Drive\Admin\GitHub_Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and moves files in a GitHub repo via the Contents API.
 */
final class GitHub_Client {

	private const API = 'https://api.github.com';

	/**
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_path( string $path ) {
		$path = trim( $path, '/' );
		$res  = $this->request( 'GET', $this->contents_path( $path ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( isset( $res['type'] ) && 'file' === $res['type'] ) {
			return array( $res );
		}

		return is_array( $res ) ? $res : array();
	}

	/**
	 * @return string|WP_Error File contents (decoded).
	 */
	public function get_file_contents( string $path ) {
		$res = $this->request( 'GET', $this->contents_path( $path ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( ! is_array( $res ) || ( $res['type'] ?? '' ) !== 'file' ) {
			return new WP_Error( 'forwp_drive_github_file', __( 'GitHub path is not a file.', '4wp-drive' ) );
		}

		$encoding = (string) ( $res['encoding'] ?? '' );
		$content  = (string) ( $res['content'] ?? '' );
		if ( 'base64' === $encoding ) {
			$decoded = base64_decode( str_replace( "\n", '', $content ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $decoded ) {
				return new WP_Error( 'forwp_drive_github_decode', __( 'Could not decode GitHub file.', '4wp-drive' ) );
			}

			return $decoded;
		}

		$url = (string) ( $res['download_url'] ?? '' );
		if ( '' !== $url ) {
			$raw = wp_remote_get( $url, array( 'timeout' => 30 ) );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}

			return (string) wp_remote_retrieve_body( $raw );
		}

		return $content;
	}

	/**
	 * Move a file from one repo path to another (create + delete).
	 *
	 * @return true|WP_Error
	 */
	public function move_file( string $from_path, string $to_path ) {
		$from = $this->request( 'GET', $this->contents_path( $from_path ) );
		if ( is_wp_error( $from ) ) {
			return $from;
		}

		$content = (string) ( $from['content'] ?? '' );
		$sha     = (string) ( $from['sha'] ?? '' );
		if ( '' === $content || '' === $sha ) {
			$raw = $this->get_file_contents( $from_path );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			$content = base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$put = $this->request(
			'PUT',
			$this->contents_path( $to_path, false ),
			array(
				'message' => '4WP Drive: move to ' . $to_path,
				'content' => str_replace( "\n", '', $content ),
				'branch'  => GitHub_Settings::get_public()['branch'],
			)
		);
		if ( is_wp_error( $put ) ) {
			return $put;
		}

		$delete = $this->request(
			'DELETE',
			$this->contents_path( $from_path, false ),
			array(
				'message' => '4WP Drive: remove from incoming',
				'sha'     => $sha,
				'branch'  => GitHub_Settings::get_public()['branch'],
			)
		);

		return is_wp_error( $delete ) ? $delete : true;
	}

	private function contents_path( string $path, bool $with_ref = true ): string {
		$cfg   = GitHub_Settings::get_public();
		$owner = rawurlencode( $cfg['owner'] );
		$repo  = rawurlencode( $cfg['repo'] );
		$path  = trim( str_replace( '\\', '/', $path ), '/' );
		$base  = '/repos/' . $owner . '/' . $repo . '/contents';
		if ( '' !== $path ) {
			$enc   = str_replace( '%2F', '/', rawurlencode( $path ) );
			$base .= '/' . $enc;
		}

		if ( ! $with_ref ) {
			return $base;
		}

		return $base . '?ref=' . rawurlencode( $cfg['branch'] );
	}

	/**
	 * @param array<string, mixed> $body JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, array $body = array() ) {
		$token = GitHub_Settings::get_token();
		if ( '' === $token ) {
			return new WP_Error( 'forwp_drive_github_token', __( 'GitHub token is missing.', '4wp-drive' ) );
		}

		$url  = self::API . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/vnd.github+json',
				'User-Agent'    => '4wp-drive',
			),
		);

		if ( ! empty( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? (string) $data['message']
				: __( 'GitHub API error.', '4wp-drive' );

			if ( 404 === (int) $code ) {
				$cfg = GitHub_Settings::get_public();
				$message = sprintf(
					/* translators: 1: owner/repo, 2: branch */
					__( 'GitHub repository not found or this token cannot access it: %1$s (branch %2$s). Check Owner/Repository in Settings → Storage sources → GitHub.', '4wp-drive' ),
					$cfg['owner'] . '/' . $cfg['repo'],
					$cfg['branch']
				);
			}

			return new WP_Error( 'forwp_drive_github_api', $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
