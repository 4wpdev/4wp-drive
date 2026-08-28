<?php
/**
 * Post creator block markup tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Post_Creator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \ForWP\Drive\Import\Post_Creator
 */
class Post_CreatorTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_preserves_block_markup_in_post_content(): void {
		$creator  = new Post_Creator();
		$method   = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$markup   = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$content  = $method->invoke(
			$creator,
			array(
				'body_html' => $markup,
			)
		);

		$this->assertSame( $markup, $content );
	}
}
