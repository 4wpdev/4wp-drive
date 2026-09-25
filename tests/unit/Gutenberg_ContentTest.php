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

	public function test_from_html_maps_core_table(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<table class="forwp-drive-md-table"><thead><tr><th>Hook</th><th>Window open for</th></tr></thead>'
			. '<tbody><tr><td><code>init</code></td><td>Register post types</td></tr></tbody></table>';

		$out = Gutenberg_Content::from_html( $html );

		$this->assertStringContainsString( '<!-- wp:table -->', $out );
		$this->assertStringContainsString( '<figure class="wp-block-table">', $out );
		$this->assertStringContainsString( '<table class="has-fixed-layout">', $out );
		$this->assertStringContainsString( '<th>Hook</th>', $out );
		$this->assertStringContainsString( '<code>init</code>', $out );
		$this->assertStringNotContainsString( '<!-- wp:paragraph --><p><thead', $out );
	}

	public function test_from_html_maps_google_docs_classic_table(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<p>The draft framework contains five categories:</p>'
			. '<table><tr>'
			. '<td>Risk Zone</td><td>ATO Risk Classification</td>'
			. '</tr></table>'
			. '<table><tr><td>White</td><td>Further risk assessment not required</td></tr>'
			. '<tr><td>Green</td><td>Low risk</td></tr>'
			. '<tr><td>Yellow</td><td>Low to medium risk</td></tr>'
			. '<tr><td>Amber</td><td>Medium to high risk</td></tr>'
			. '<tr><td>Red</td><td>High risk</td></tr></table>';

		$out = Gutenberg_Content::from_html( $html );

		$this->assertStringContainsString( '<!-- wp:table -->', $out );
		$this->assertEquals( 2, substr_count( $out, '<!-- wp:table -->' ) );
		$this->assertStringContainsString( 'Risk Zone', $out );
		$this->assertStringContainsString( 'Amber', $out );
		$this->assertStringNotContainsString( '<!-- wp:paragraph --><p>Risk Zone</p>', $out );
		$this->assertStringNotContainsString( '<!-- wp:paragraph --><p>White</p>', $out );
	}

	public function test_from_mixed_hydrates_techarticle_wrapper_inner_blocks(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<!-- wp:forwp-seo/techarticle-issues -->'
			. '<section class="forwp-seo-techarticle-issues"><h2>Common Mistakes</h2><p>Do not skip auth.</p></section>'
			. '<!-- /wp:forwp-seo/techarticle-issues -->';

		$out = Gutenberg_Content::from_mixed( $html );

		$this->assertStringContainsString( '<!-- wp:forwp-seo/techarticle-issues -->', $out );
		$this->assertStringContainsString( '<!-- wp:heading', $out );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
		$this->assertStringContainsString( 'Common Mistakes', $out );
		$this->assertStringNotContainsString( '<section class="forwp-seo-techarticle-issues">', $out );
	}

	public function test_from_mixed_preserves_faq_accordion_markup(): void {
		$html = '<!-- wp:forwp/faq --><!-- wp:accordion -->'
			. '<div class="wp-block-accordion" role="group">'
			. '<!-- wp:accordion-item --><div class="wp-block-accordion-item">'
			. '<!-- wp:accordion-heading --><h3 class="wp-block-accordion-heading">Q</h3><!-- /wp:accordion-heading -->'
			. '<!-- wp:accordion-panel --><div class="wp-block-accordion-panel" role="region">'
			. '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:accordion-panel -->'
			. '</div><!-- /wp:accordion-item -->'
			. '</div><!-- /wp:accordion -->'
			. '<!-- /wp:forwp/faq -->';

		$out = Gutenberg_Content::from_mixed( $html );

		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $out );
		$this->assertStringContainsString( '<!-- wp:accordion -->', $out );
		$this->assertStringContainsString( 'role="region"', $out );
		$this->assertStringContainsString( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->', $out );
	}

	public function test_from_mixed_does_not_wrap_techarticle_in_paragraph(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<p>Intro</p>'
			. '<!-- wp:forwp-seo/techarticle-context -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Introduction</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->'
			. '<!-- /wp:forwp-seo/techarticle-context -->'
			. '<!-- wp:forwp/faq --><!-- wp:accordion -->'
			. '<div class="wp-block-accordion" role="group">'
			. '<!-- wp:accordion-item --><div class="wp-block-accordion-item">'
			. '<!-- wp:accordion-heading --><h3>Q</h3><!-- /wp:accordion-heading -->'
			. '<!-- wp:accordion-panel --><div class="wp-block-accordion-panel" role="region">'
			. '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:accordion-panel --></div><!-- /wp:accordion-item -->'
			. '</div><!-- /wp:accordion --><!-- /wp:forwp/faq -->';

		$out = Gutenberg_Content::from_mixed( $html );

		$this->assertStringContainsString( '<!-- wp:forwp-seo/techarticle-context -->', $out );
		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $out );
		$this->assertDoesNotMatchRegularExpression(
			'/<!--\s+wp:paragraph[^>]*-->\s*<!--\s+wp:forwp-seo\/techarticle-context/',
			$out
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<!--\s+wp:paragraph[^>]*-->\s*<!--\s+wp:forwp\/faq/',
			$out
		);
		$this->assertStringNotContainsString( '<!-- /wp:forwp-seo/techarticle-context --><!-- /wp:paragraph -->', $out );
	}

	public function test_sanitize_stored_html_keeps_block_comments(): void {
		$html = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$out  = Gutenberg_Content::sanitize_stored_html( $html );

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
		$this->assertStringContainsString( 'Hello', $out );
	}

	public function test_markdown_bootstrap_table_imports_as_core_table(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$md = "| Hook | Window open for | Too early (before this hook) | Too late (after this hook) |\n"
			. "|---|---|---|---|\n"
			. "| `muplugins_loaded` | Network-wide settings | No earlier hook exists | Plugins have already begun loading |\n"
			. "| `init` | `register_post_type()` | Taxonomies aren't ready | Permalinks may have gone around it |\n";

		$html = \ForWP\Drive\Import\Markdown_Content::to_html_document( $md );
		if ( preg_match( '/<body\b[^>]*>(.*)<\/body>/is', $html, $m ) ) {
			$html = $m[1];
		}
		$out = Gutenberg_Content::from_html( $html );

		$this->assertStringContainsString( '<!-- wp:table -->', $out );
		$this->assertStringContainsString( '<code>muplugins_loaded</code>', $out );
		$this->assertStringContainsString( '<code>register_post_type()</code>', $out );
		$this->assertStringContainsString( 'Too early (before this hook)', $out );
		$this->assertStringNotContainsString( '|---|', $out );
	}
}
