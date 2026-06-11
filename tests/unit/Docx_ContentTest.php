<?php
/**
 * DOCX content extraction tests.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Docx_Content;
use ForWP\Drive\Parse\Template_Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Docx_Content
 */
final class Docx_ContentTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		forwp_drive_tests_reset_options();
	}

	public function test_document_xml_to_plain_text_preserves_paragraphs(): void {
		$xml = self::sample_document_xml();

		$plain = Docx_Content::document_xml_to_plain_text( $xml );

		$this->assertStringContainsString( "Title: Hello\nSlug: hello-world", $plain );
		$this->assertStringContainsString( "======\nBody paragraph.", $plain );

		$parsed = ( new Template_Parser() )->parse( $plain );
		$this->assertSame( 'Hello', $parsed['title'] );
		$this->assertSame( 'hello-world', $parsed['slug'] );
		$this->assertStringContainsString( 'Body paragraph.', $parsed['body'] );
	}

	public function test_document_xml_to_html_keeps_headings_and_bold(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body>'
			. '<w:p><w:r><w:t>Title: Hello</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>Slug: hello-world</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>======</w:t></w:r></w:p>'
			. '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Section</w:t></w:r></w:p>'
			. '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold text</w:t></w:r></w:p>'
			. '</w:body></w:document>';

		$html   = Docx_Content::document_xml_to_html( $xml );
		$parsed = ( new Template_Parser() )->parse( '<html><body>' . $html . '</body></html>' );

		$this->assertSame( 'Hello', $parsed['title'] );
		$this->assertStringContainsString( '<h1>Section</h1>', $parsed['body_html'] );
		$this->assertStringContainsString( '<strong>Bold text</strong>', $parsed['body_html'] );
	}

	private static function sample_document_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body>'
			. '<w:p><w:r><w:t>Title: Hello</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>Slug: hello-world</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>======</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>Body paragraph.</w:t></w:r></w:p>'
			. '</w:body></w:document>';
	}
}
