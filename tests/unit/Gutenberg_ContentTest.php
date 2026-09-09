<?php
/**
 * HTML → Gutenberg serialization tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Gutenberg_Content;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Gutenberg_Content
 */
class Gutenberg_ContentTest extends TestCase {

	public function test_from_html_maps_core_blocks(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<h1>Title</h1>'
			. '<h2>Section</h2>'
			. '<p>A <strong>bold</strong> <em>italic</em> <u>line</u>.</p>'
			. '<ul><li>One</li><li>Two</li></ul>'
			. '<ol><li>First</li></ol>'
			. '<blockquote><p>Quoted</p></blockquote>'
			. '<pre><code>echo hi;</code></pre>';

		$out = Gutenberg_Content::from_html( $html );

		$this->assertStringContainsString( '<!-- wp:heading', $out );
		$this->assertStringContainsString( '"level":1', $out );
		$this->assertStringContainsString( '<!-- wp:paragraph', $out );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
		$this->assertStringContainsString( '<em>italic</em>', $out );
		$this->assertStringContainsString( '<u>line</u>', $out );
		$this->assertStringContainsString( '<!-- wp:list', $out );
		$this->assertStringContainsString( '<!-- wp:list-item', $out );
		$this->assertStringContainsString( '"ordered":true', $out );
		$this->assertStringContainsString( '<!-- wp:quote', $out );
		$this->assertStringContainsString( '<!-- wp:code', $out );
		$this->assertStringContainsString( 'echo hi;', $out );
	}

	public function test_from_mixed_preserves_existing_blocks_and_converts_html(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<!-- wp:paragraph --><p>Keep me</p><!-- /wp:paragraph -->'
			. '<h2>After</h2><p>More</p>';

		$out = Gutenberg_Content::from_mixed( $html );

		$this->assertStringContainsString( 'Keep me', $out );
		$this->assertStringContainsString( '<!-- wp:heading', $out );
		$this->assertStringContainsString( 'After', $out );
		$this->assertStringContainsString( 'More', $out );
	}

	public function test_from_html_does_not_wrap_image_markers_in_paragraph_blocks(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$out = Gutenberg_Content::from_html(
			'<p>Before</p><p>[image:cover.jpeg left]</p><p>After</p>'
		);

		$this->assertStringContainsString( '[image:cover.jpeg left]', $out );
		$this->assertStringContainsString( 'Before', $out );
		$this->assertStringContainsString( 'After', $out );
		$this->assertDoesNotMatchRegularExpression(
			'/<!-- wp:paragraph -->\s*<p>\[image:cover.jpeg left\]<\/p>/',
			$out
		);
	}
}
