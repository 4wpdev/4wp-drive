<?php
/**
 * Featured image chooser tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Featured_Image_Chooser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Featured_Image_Chooser
 */
class Featured_Image_ChooserTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_prefers_cover_filename(): void {
		$pick = Featured_Image_Chooser::suggest(
			array(
				array(
					'id'   => '1',
					'name' => 'article-diagram.jpeg',
				),
				array(
					'id'   => '2',
					'name' => 'hooks-cover.jpeg',
				),
				array(
					'id'   => '3',
					'name' => 'footer-code.jpeg',
				),
			)
		);

		$this->assertIsArray( $pick );
		$this->assertSame( '2', $pick['id'] );
	}

	/**
	 * @return void
	 */
	public function test_falls_back_to_first_image(): void {
		$pick = Featured_Image_Chooser::suggest(
			array(
				array(
					'id'   => 'a',
					'name' => 'one.jpeg',
				),
				array(
					'id'   => 'b',
					'name' => 'two.jpeg',
				),
			)
		);

		$this->assertSame( 'a', $pick['id'] );
	}

	/**
	 * @return void
	 */
	public function test_apply_override_updates_metadata(): void {
		$meta = Featured_Image_Chooser::apply_override(
			array(
				'image_file_id'   => 'old',
				'image_file_name' => 'old.jpeg',
				'package_files'   => array(
					array(
						'id'   => 'new',
						'name' => 'hero.jpeg',
						'kind' => 'image',
					),
				),
			),
			'new'
		);

		$this->assertSame( 'new', $meta['image_file_id'] );
		$this->assertSame( 'hero.jpeg', $meta['image_file_name'] );
	}
}
