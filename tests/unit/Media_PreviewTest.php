<?php
/**
 * Inbox media preview tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Rest\Media_Preview;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Rest\Media_Preview
 */
class Media_PreviewTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_detects_png_bytes(): void {
		$png = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
			true
		);

		$mime = Media_Preview::detect_mime( 'software.png', $png );

		$this->assertSame( 'image/png', $mime );
	}

	/**
	 * @return void
	 */
	public function test_rejects_non_image_bytes(): void {
		$result = Media_Preview::detect_mime( 'software.jpg', '<html>not an image</html>' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
