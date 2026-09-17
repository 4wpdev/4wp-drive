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
