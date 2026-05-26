<?php
/**
 * Google Doc content formatter tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Google_Doc_Content;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Google_Doc_Content
 */
class Google_Doc_ContentTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_converts_styled_paragraphs_to_headings_and_keeps_lists(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<html><head><style>'
			. 'p{margin:0;font-size:11pt}'
			. '.title{font-size:20pt;font-weight:700}'
			. '.subtitle{font-size:16pt;font-weight:700}'
			. '.normal{font-size:11pt}'
			. '</style></head><body>'
			. '<p class="title"><span class="title">Main Heading</span></p>'
			. '<p class="subtitle"><span class="subtitle">Section Heading</span></p>'
			. '<p class="normal"><span class="normal">Body paragraph.</span></p>'
			. '<ul><li><span class="normal">First item</span></li><li><span class="normal">Second item</span></li></ul>'
			. '</body></html>';

		$result = Google_Doc_Content::prepare( $html );

		$this->assertStringContainsString( '<h1', $result );
		$this->assertStringContainsString( 'Main Heading', $result );
		$this->assertStringContainsString( '<h2', $result );
		$this->assertStringContainsString( 'Section Heading', $result );
		$this->assertStringContainsString( '<ul', $result );
		$this->assertStringContainsString( '<li', $result );
		$this->assertStringContainsString( 'First item', $result );
		$this->assertStringContainsString( 'Body paragraph', $result );
	}

	/**
	 * @return void
	 */
	public function test_uses_head_styles_when_body_is_split_off(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$css = 'p{margin:0;font-size:11pt}.heading{font-size:16pt;font-weight:700}';
		$body = '<p class="heading"><span class="heading">Section Heading</span></p>'
			. '<p class="normal"><span class="normal">Body paragraph.</span></p>';

		$result = Google_Doc_Content::prepare( $body, $css );

		$this->assertStringContainsString( '<h2', $result );
		$this->assertStringContainsString( 'Section Heading', $result );
		$this->assertStringContainsString( 'Body paragraph', $result );
	}

	/**
	 * @return void
	 */
	public function test_prepare_from_export_keeps_all_body_nodes(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<html><head><style>.c1{font-size:20pt;font-weight:700}</style></head><body>'
			. '<p>Title: Article</p>'
			. '<p>======</p>'
			. '<p class="c1"><span class="c1">Heading</span></p>'
			. '<p>Body paragraph.</p>'
			. '<ul><li>First item</li></ul>'
			. '</body></html>';

		$result = Google_Doc_Content::prepare_from_export( $html );

		$this->assertStringContainsString( 'Heading', $result );
		$this->assertStringContainsString( 'Body paragraph', $result );
		$this->assertStringContainsString( '<ul', $result );
		$this->assertStringContainsString( 'First item', $result );
		$this->assertStringNotContainsString( 'Title: Article', $result );
	}

	/**
	 * @return void
	 */
	public function test_converts_bold_spans_to_strong(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<html><body><p>Intro text.</p>'
			. '<p><span style="font-weight:700">Bold subheading</span></p>'
			. '<p>More <span style="font-weight:700">bold words</span> here.</p>'
			. '</body></html>';

		$result = Google_Doc_Content::prepare_from_export( $html );

		$this->assertStringContainsString( '<strong', $result );
		$this->assertStringContainsString( 'Bold subheading', $result );
		$this->assertStringContainsString( 'bold words', $result );
	}

	/**
	 * @return void
	 */
	public function test_split_export_at_separator_splits_header_and_body(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<html><body>'
			. '<p>Title: A</p>'
			. '<p>Slug: a</p>'
			. '<p>=====</p>'
			. '<p>Body line.</p>'
			. '</body></html>';

		$split = Google_Doc_Content::split_export_at_separator( $html );

		$this->assertIsArray( $split );
		$this->assertStringContainsString( 'Title: A', $split['header_html'] );
		$this->assertStringNotContainsString( 'Body line', $split['header_html'] );
		$this->assertStringContainsString( 'Body line', $split['body_html'] );
		$this->assertStringNotContainsString( 'Title: A', $split['body_html'] );
	}

	/**
	 * Google Docs wraps paragraphs in a body div — split must still find the separator.
	 *
	 * @return void
	 */
	public function test_split_export_at_separator_works_inside_body_wrapper_div(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<html><body><div class="doc-content">'
			. '<p>Title: A</p>'
			. '<p>Slug: a</p>'
			. '<p>=====</p>'
			. '<p>Body line.</p>'
			. '</div></body></html>';

		$split = Google_Doc_Content::split_export_at_separator( $html );

		$this->assertIsArray( $split );
		$this->assertStringContainsString( 'Title: A', $split['header_html'] );
		$this->assertStringContainsString( 'Body line', $split['body_html'] );
	}

	/**
	 * @return void
	 */
	public function test_prepare_from_export_strips_header_inside_wrapper_div(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$html = '<html><head><style>.c1{font-size:20pt;font-weight:700}</style></head><body><div>'
			. '<p>Title: Article</p>'
			. '<p>Slug: article</p>'
			. '<p>=====</p>'
			. '<p class="c1"><span class="c1">Heading</span></p>'
			. '<p>Body paragraph.</p>'
			. '</div></body></html>';

		$result = Google_Doc_Content::prepare_from_export( $html );

		$this->assertStringContainsString( 'Heading', $result );
		$this->assertStringContainsString( 'Body paragraph', $result );
		$this->assertStringNotContainsString( 'Title: Article', $result );
		$this->assertStringNotContainsString( 'Slug: article', $result );
	}
}
