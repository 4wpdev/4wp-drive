<?php
/**
 * Core Video recipe tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Recipes\Core_Video_Recipe;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Recipes\Core_Video_Recipe
 */
class Core_Video_RecipeTest extends TestCase {

	public function test_extract_tokens_from_markers(): void {
		$html   = '<p>[Video: 4wp-faq-overview.mp4]</p><p>[video:demo.webm — Overview]</p>';
		$tokens = Core_Video_Recipe::extract_tokens( $html );
		$this->assertSame( array( '4wp-faq-overview.mp4', 'demo.webm' ), $tokens );
	}

	public function test_transform_leaves_local_marker_without_attachments(): void {
		$recipe = new Core_Video_Recipe();
		$html   = '<p>[video:demo.mp4]</p>';
		$this->assertSame( $html, $recipe->transform( $html, array() ) );
	}

	public function test_transform_replaces_matched_markers_with_caption(): void {
		$recipe = new Core_Video_Recipe();
		$out    = $recipe->transform(
			'<p>[Video: 4wp-faq-overview.mp4 — See it in 60 seconds]</p>',
			array(
				'attachments' => array(
					'4wp-faq-overview.mp4' => array(
						'id'  => 99,
						'url' => 'https://example.com/4wp-faq-overview.mp4',
					),
				),
			)
		);

		$this->assertStringContainsString( '<!-- wp:video', $out );
		$this->assertStringContainsString( 'https://example.com/4wp-faq-overview.mp4', $out );
		$this->assertStringContainsString(
			'<figcaption class="wp-element-caption">See it in 60 seconds</figcaption>',
			$out
		);
		$this->assertStringNotContainsString( '[Video:', $out );
	}

	public function test_transform_youtube_url_becomes_core_embed(): void {
		$recipe = new Core_Video_Recipe();
		$out    = $recipe->transform(
			'<p>https://www.youtube.com/watch?v=SI3slJFYVbg</p>',
			array()
		);

		$this->assertStringContainsString( '<!-- wp:embed', $out );
		$this->assertStringContainsString( '"providerNameSlug":"youtube"', $out );
		$this->assertStringContainsString( 'is-provider-youtube', $out );
		$this->assertStringContainsString( 'wp-block-embed-youtube', $out );
		$this->assertStringContainsString( 'wp-block-embed__wrapper', $out );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=SI3slJFYVbg', $out );
		$this->assertStringNotContainsString( '<!-- wp:video', $out );
	}

	public function test_transform_youtube_marker_with_caption(): void {
		$recipe = new Core_Video_Recipe();
		$out    = $recipe->transform(
			'<p>[Video: https://youtu.be/SI3slJFYVbg — See it in 60 seconds]</p>',
			array()
		);

		$this->assertStringContainsString( '<!-- wp:embed', $out );
		$this->assertStringContainsString( '"providerNameSlug":"youtube"', $out );
		$this->assertStringContainsString(
			'<figcaption class="wp-element-caption">See it in 60 seconds</figcaption>',
			$out
		);
	}

	public function test_extract_tokens_skips_remote_urls(): void {
		$html   = '<p>[video:demo.mp4]</p><p>[video:https://www.youtube.com/watch?v=SI3slJFYVbg]</p>';
		$tokens = Core_Video_Recipe::extract_tokens( $html );
		$this->assertSame( array( 'demo.mp4' ), $tokens );
	}
}
