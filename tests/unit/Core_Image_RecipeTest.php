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
