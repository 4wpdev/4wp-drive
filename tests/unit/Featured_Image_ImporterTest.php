<?php
/**
 * Featured image importer tests.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Featured_Image_Importer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Featured_Image_Importer
 */
final class Featured_Image_ImporterTest extends TestCase {

	public function test_build_filename_uses_slug_and_extension(): void {
		$this->assertSame(
			'image-kpmg-ai-tax-strategy.jpg',
			Featured_Image_Importer::build_filename( 'kpmg-ai-tax-strategy', 'hero-photo.JPG' )
		);
	}

	public function test_build_filename_defaults_extension(): void {
		$this->assertSame(
			'image-my-post.jpg',
			Featured_Image_Importer::build_filename( 'my-post', 'photo' )
		);
	}
}
