<?php
/**
 * Core Image recipe tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Recipes\Core_Image_Recipe;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Recipes\Core_Image_Recipe
 */
class Core_Image_RecipeTest extends TestCase {

	public function test_extract_tokens_from_google_docs_markup(): void {
		$html   = '<p><span>[image:wp-head-cover.jpeg]</span></p><p>Text</p><p>[image:Other.PNG]</p>';
		$tokens = Core_Image_Recipe::extract_tokens( $html );
		$this->assertSame( array( 'wp-head-cover.jpeg', 'other.png' ), $tokens );
	}

	public function test_extract_tokens_strips_align(): void {
		$html   = '<p>[image:cover.jpeg left]</p><p>[image:hero.png center]</p>';
		$tokens = Core_Image_Recipe::extract_tokens( $html );
		$this->assertSame( array( 'cover.jpeg', 'hero.png' ), $tokens );
	}

	public function test_extract_tokens_strips_caption_after_em_dash(): void {
		$html   = '<p>[Image: 01-convert.png — Convert an Accordion to FAQ]</p>';
		$tokens = Core_Image_Recipe::extract_tokens( $html );
		$this->assertSame( array( '01-convert.png' ), $tokens );
	}

	public function test_parse_marker_caption_and_align(): void {
		$parsed = Core_Image_Recipe::parse_marker( '01-convert.png left — Convert an Accordion to FAQ' );
		$this->assertSame( '01-convert.png', $parsed['file'] );
		$this->assertSame( 'left', $parsed['align'] );
		$this->assertSame( 'Convert an Accordion to FAQ', $parsed['caption'] );
	}

	public function test_transform_leaves_body_without_attachments(): void {
		$recipe = new Core_Image_Recipe();
		$html   = '<p>[image:cover.jpeg]</p>';
		$this->assertSame( $html, $recipe->transform( $html, array() ) );
	}

	public function test_transform_replaces_matched_markers(): void {
		$recipe = new Core_Image_Recipe();
		$html   = '<p><span>[image:cover.jpeg]</span></p><p>Hello</p>';
		$out    = $recipe->transform(
			$html,
			array(
				'attachments' => array(
					'cover.jpeg' => array(
						'id'  => 42,
						'url' => 'https://example.com/cover.jpeg',
						'alt' => 'cover',
					),
				),
			)
		);

		$this->assertStringContainsString( '<!-- wp:image', $out );
		$this->assertStringContainsString( 'wp-image-42', $out );
		$this->assertStringContainsString( 'https://example.com/cover.jpeg', $out );
		$this->assertStringNotContainsString( '[image:cover.jpeg]', $out );
		$this->assertStringContainsString( 'Hello', $out );
	}

	public function test_transform_applies_left_align(): void {
		$recipe = new Core_Image_Recipe();
		$out    = $recipe->transform(
			'<p>[image:cover.jpeg left]</p>',
			array(
				'attachments' => array(
					'cover.jpeg' => array(
						'id'  => 42,
						'url' => 'https://example.com/cover.jpeg',
						'alt' => 'cover',
					),
				),
			)
		);

		$this->assertStringContainsString( 'alignleft', $out );
		$this->assertStringContainsString( '"align":"left"', $out );
		$this->assertStringNotContainsString( '[image:cover.jpeg left]', $out );
	}

	public function test_transform_uses_caption_as_figcaption(): void {
		$recipe = new Core_Image_Recipe();
		$out    = $recipe->transform(
			'<p>[Image: 01-convert.png — Convert an Accordion to FAQ]</p>',
			array(
				'attachments' => array(
					'01-convert.png' => array(
						'id'  => 7,
						'url' => 'https://example.com/01-convert.png',
						'alt' => '01-convert',
					),
				),
			)
		);

		$this->assertStringContainsString( '<!-- wp:image', $out );
		$this->assertStringContainsString( 'wp-image-7', $out );
		$this->assertStringContainsString(
			'<figcaption class="wp-element-caption">Convert an Accordion to FAQ</figcaption>',
			$out
		);
		$this->assertStringContainsString( 'alt="Convert an Accordion to FAQ"', $out );
		$this->assertStringNotContainsString( '[Image:', $out );
	}

	public function test_transform_replaces_gutenberg_paragraph_without_empty_leftover(): void {
		$recipe = new Core_Image_Recipe();
		$html   = '<!-- wp:paragraph --><p>[image:cover.jpeg left]</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$out    = $recipe->transform(
			$html,
			array(
				'attachments' => array(
					'cover.jpeg' => array(
						'id'  => 42,
						'url' => 'https://example.com/cover.jpeg',
						'alt' => 'cover',
					),
				),
			)
		);

		$this->assertStringContainsString( '<!-- wp:image', $out );
		$this->assertStringContainsString( 'alignleft', $out );
		$this->assertStringContainsString( 'Hello', $out );
		$this->assertStringNotContainsString( '[image:', $out );
		$this->assertDoesNotMatchRegularExpression( '/<!-- wp:paragraph -->\s*<!-- wp:image/', $out );
		$this->assertDoesNotMatchRegularExpression( '/<!-- wp:paragraph -->\s*<p>(?:\s|&nbsp;)*<\/p>/', $out );
	}
}
