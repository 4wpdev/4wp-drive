<?php
/**
 * Import a Google Drive image as the post featured image.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Api\Google_Drive_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads Drive image bytes and sideloads into the media library.
 */
final class Featured_Image_Importer {

	/**
	 * Google Drive API client.
	 *
	 * @var Google_Drive_Client
	 */
	private $client;

	/**
	 * Store the Drive client used for downloads.
	 *
	 * @param Google_Drive_Client $client Drive client.
	 */
	public function __construct( Google_Drive_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Attach Drive image as featured image using image-{slug}.{ext} filename.
	 *
	 * @param string $file_id   Drive file id.
	 * @param string $file_name Original Drive filename.
	 * @param int    $post_id   WordPress post id.
	 * @param string $slug      Post slug.
	 * @return int|WP_Error Attachment id.
	 */
	public function attach_from_drive( string $file_id, string $file_name, int $post_id, string $slug ) {
		if ( '' === $file_id || $post_id <= 0 ) {
			return new WP_Error( 'forwp_drive_no_image', __( 'Featured image file is missing.', '4wp-drive' ) );
		}

		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			$slug = 'imported';
		}

		$binary = $this->client->download_file( $file_id );
		if ( is_wp_error( $binary ) ) {
			return $binary;
		}

		$filename = self::build_filename( $slug, $file_name );

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'forwp_drive_upload_failed', (string) $upload['error'] );
		}

		$filetype   = wp_check_filetype( $filename, null );
		$mime_type  = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/jpeg';
		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return is_wp_error( $attachment_id )
				? $attachment_id
				: new WP_Error( 'forwp_drive_attachment_failed', __( 'Could not create media attachment.', '4wp-drive' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( (int) $attachment_id, $metadata );
		}

		set_post_thumbnail( $post_id, (int) $attachment_id );

		return (int) $attachment_id;
	}

	/**
	 * Build media filename: image-{slug}.{ext}
	 *
	 * @param string $slug      Post slug.
	 * @param string $file_name Original Drive filename.
	 */
	public static function build_filename( string $slug, string $file_name ): string {
		$slug = sanitize_file_name( $slug );
		if ( '' === $slug ) {
			$slug = 'imported';
		}

		$ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( '' === $ext || ! preg_match( '/^(jpe?g|png|gif|webp|avif)$/i', $ext ) ) {
			$ext = 'jpg';
		}

		return 'image-' . $slug . '.' . $ext;
	}
}
