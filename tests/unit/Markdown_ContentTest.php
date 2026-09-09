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
}
