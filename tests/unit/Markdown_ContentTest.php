<?php
/**
 * Markdown → HTML tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Markdown_Content;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Markdown_Content
 */
class Markdown_ContentTest extends TestCase {

	public function test_converts_headings_lists_quotes_and_code(): void {
		$md = "Title: Hello\nSlug: hello\n---\n"
			. "# Heading one\n\n"
			. "A **bold** and *italic* line.\n\n"
			. "- One\n- Two\n\n"
			. "1. First\n2. Second\n\n"
			. "> Quoted\n\n"
			. "```\necho hi;\n```\n";

		$html = Markdown_Content::to_html_document( $md );

		$this->assertStringContainsString( 'data-forwp-md="1"', $html );
		$this->assertStringContainsString( '<p>Title: Hello</p>', $html );
		$this->assertStringContainsString( '<hr', $html );
		$this->assertStringContainsString( '<h1>Heading one</h1>', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<em>italic</em>', $html );
		$this->assertStringContainsString( '<ul>', $html );
		$this->assertStringContainsString( '<ol>', $html );
		$this->assertStringContainsString( '<blockquote>', $html );
		$this->assertStringContainsString( '<pre><code>', $html );
		$this->assertStringContainsString( 'echo hi;', $html );
	}

	public function test_local_markdown_image_becomes_marker(): void {
		$html = Markdown_Content::to_html_document( "Hello\n\n![Alt](hero.png)\n" );

		$this->assertStringContainsString( '[image:hero.png]', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_remote_markdown_image_stays_img(): void {
		$html = Markdown_Content::to_html_document( "![Alt](https://example.com/hero.png)\n" );

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'https://example.com/hero.png', $html );
		$this->assertStringNotContainsString( '[image:', $html );
	}

	public function test_pipe_tables_become_html_tables(): void {
		$md = "| Day | Path |\n"
			. "| --- | --- |\n"
			. "| Mon | /ai/mcp/ |\n"
			. "| Tue | /services/ |\n";

		$html = Markdown_Content::to_html_document( $md );

		$this->assertStringContainsString( '<table class="forwp-drive-md-table">', $html );
		$this->assertStringContainsString( '<th>Day</th>', $html );
		$this->assertStringContainsString( '<td>Mon</td>', $html );
		$this->assertStringContainsString( '/ai/mcp/', $html );
		$this->assertStringNotContainsString( '|---|', $html );
	}

	public function test_bootstrap_opportunity_table_keeps_code_cells(): void {
		$md = "| Hook | Window open for | Too early (before this hook) | Too late (after this hook) |\n"
			. "|---|---|---|---|\n"
			. "| `muplugins_loaded` | Network-wide/security settings that can't be disabled from the admin | No earlier hook exists — this is the start | Plugins have already begun loading — too late for \"non-toggleable\" code |\n"
			. "| `plugins_loaded` | Text domain loading, checking dependencies between plugins | No plugins exist yet — `class_exists()` is always false | The theme has already begun loading, some checks lose their point |\n"
			. "| `after_setup_theme` | `add_theme_support()`, `register_nav_menus()` | The theme isn't hooked up yet | WordPress has already passed the point of registering theme features — silently ignored |\n"
			. "| `init` | `register_post_type()`, `register_taxonomy()`, shortcodes | Taxonomies/dependencies aren't ready — fatal error | Still possible later, but some systems (permalinks) may have already gone around it |\n"
			. "| `wp_loaded` | Logic that needs a fully ready environment, before the request is parsed | Plugins or the theme haven't finished loading everything | The request is already being parsed — too late for environment setup |\n";

		$html = Markdown_Content::to_html_document( $md );

		$this->assertStringContainsString( '<table class="forwp-drive-md-table">', $html );
		$this->assertStringContainsString( '<th>Hook</th>', $html );
		$this->assertStringContainsString( '<th>Window open for</th>', $html );
		$this->assertStringContainsString( '<code>muplugins_loaded</code>', $html );
		$this->assertStringContainsString( '<code>add_theme_support()</code>', $html );
		$this->assertStringContainsString( '<code>class_exists()</code>', $html );
		$this->assertSame( 1, substr_count( $html, '<thead>' ) );
		$this->assertSame( 6, substr_count( $html, '<tr>' ) );
		$this->assertStringNotContainsString( '| Hook |', $html );
	}
}
