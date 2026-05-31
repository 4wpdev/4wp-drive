<?php
/**
 * Template parser tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Parse\Template_Config;
use ForWP\Drive\Parse\Template_Parser;
use ForWP\Drive\Parse\Template_Separator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Parse\Template_Parser
 */
class Template_ParserTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_parses_front_matter_and_body(): void {
		$mark = Template_Separator::mark();
		$raw  = "Title: Hello World\nSlug: hello-world\nCategory: News\nTags: one, two\n\n{$mark}\n\nFirst paragraph.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'Hello World', $result['title'] );
		$this->assertSame( 'hello-world', $result['slug'] );
		$this->assertSame( 'News', $result['category'] );
		$this->assertSame( array( 'one', 'two' ), $result['tags'] );
		$this->assertStringContainsString( 'First paragraph', $result['body'] );
	}

	/**
	 * @return void
	 */
	public function test_title_required_from_header(): void {
		$mark = Template_Separator::mark();
		$raw  = "Title: Only Title\n\n{$mark}\n\nBody text.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'Only Title', $result['title'] );
		$this->assertSame( 'Body text.', trim( $result['body'] ) );
	}

	/**
	 * @return void
	 */
	public function test_custom_field_labels(): void {
		$config = $this->createMock( Template_Config::class );
		$config->method( 'get_fields' )->willReturn(
			array(
				array(
					'label'    => 'Headline',
					'key'      => 'title',
					'type'     => 'core',
					'field'    => 'title',
					'required' => true,
				),
				array(
					'label'    => 'Market',
					'key'      => 'region',
					'type'     => 'taxonomy',
					'taxonomy' => 'region',
					'multi'    => false,
				),
			)
		);

		$mark   = Template_Separator::mark();
		$parser = new Template_Parser( $config );
		$result = $parser->parse( "Headline: LATAM story\nMarket: LATAM\n\n{$mark}\n\nBody." );

		$this->assertSame( 'LATAM story', $result['title'] );
		$this->assertSame( 'LATAM', $result['taxonomies']['region'] );
	}

	/**
	 * @return void
	 */
	public function test_parses_google_docs_html_paragraphs(): void {
		$mark = Template_Separator::mark();
		$html = '<html><body>'
			. '<p>Title: My Article</p>'
			. '<p>Slug: my-article</p>'
			. '<p>Category: Example</p>'
			. '<p>Tags: one, two</p>'
			. '<p>Region: Example</p>'
			. '<p>Country: Example</p>'
			. '<p>' . $mark . '</p>'
			. '<p>Article body starts here.</p>'
			. '</body></html>';

		$parser = new Template_Parser();
		$result = $parser->parse( $html );

		$this->assertSame( 'My Article', $result['title'] );
		$this->assertSame( 'my-article', $result['slug'] );
		$this->assertSame( 'Example', $result['category'] );
		$this->assertSame( array( 'one', 'two' ), $result['tags'] );
		$this->assertStringContainsString( 'Article body starts here', $result['body'] );
		$this->assertStringNotContainsString( 'Slug:', $result['title'] );
	}

	/**
	 * @return void
	 */
	public function test_parses_collapsed_single_line_export(): void {
		$mark = Template_Separator::mark();
		$raw  = 'Title: Some ArticleSlug: my-articleCategory: MexicoTags: one, twoRegion: NACountry: USA' . $mark . 'Article body starts here.';

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'Some Article', $result['title'] );
		$this->assertSame( 'my-article', $result['slug'] );
		$this->assertStringContainsString( 'Article body starts here', $result['body'] );
	}

	/**
	 * @return void
	 */
	public function test_parses_meta_fields_and_html_body(): void {
		$config = $this->createMock( Template_Config::class );
		$config->method( 'get_fields' )->willReturn(
			array(
				array(
					'label'    => 'Title',
					'key'      => 'title',
					'type'     => 'core',
					'field'    => 'title',
					'required' => true,
				),
				array(
					'label'    => 'SEO Title',
					'key'      => 'yoast_seo_title',
					'type'     => 'meta',
					'meta_key' => '_yoast_wpseo_title',
				),
				array(
					'label'    => 'SEO Description',
					'key'      => 'yoast_seo_description',
					'type'     => 'meta',
					'meta_key' => '_yoast_wpseo_metadesc',
				),
			)
		);

		$mark = Template_Separator::mark();
		$html = '<html><body>'
			. '<p>Title: My Article</p>'
			. '<p>SEO Title: Custom SEO headline</p>'
			. '<p>SEO Description: Meta description text.</p>'
			. '<p>' . $mark . '</p>'
			. '<p>Body with <strong>bold</strong> text.</p>'
			. '</body></html>';

		$parser = new Template_Parser( $config );
		$result = $parser->parse( $html );

		$this->assertSame( 'My Article', $result['title'] );
		$this->assertSame( 'Custom SEO headline', $result['meta']['_yoast_wpseo_title'] );
		$this->assertSame( 'Meta description text.', $result['meta']['_yoast_wpseo_metadesc'] );
		$this->assertStringContainsString( '<strong>bold</strong>', $result['body_html'] );
	}

	/**
	 * @return void
	 */
	public function test_preserves_headings_and_lists_from_google_docs_styles(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$mark = Template_Separator::mark();
		$html = '<html><head><style>'
			. 'p{margin:0;font-size:11pt}'
			. '.c1{font-size:20pt;font-weight:700}'
			. '.c2{font-size:16pt;font-weight:700}'
			. '.c3{font-size:11pt}'
			. '</style></head><body>'
			. '<p class="c3">Title: Some Article</p>'
			. '<p class="c3">Slug: my-article</p>'
			. '<p class="c3">' . $mark . '</p>'
			. '<p class="c1"><span class="c1">Main Heading</span></p>'
			. '<p class="c2"><span class="c2">Section Heading</span></p>'
			. '<p class="c3"><span class="c3">Body paragraph.</span></p>'
			. '<ul><li><span class="c3">First item</span></li><li><span class="c3">Second item</span></li></ul>'
			. '</body></html>';

		$parser = new Template_Parser();
		$result = $parser->parse( $html );

		$this->assertSame( 'Some Article', $result['title'] );
		$this->assertStringContainsString( '<h1', $result['body_html'] );
		$this->assertStringContainsString( 'Main Heading', $result['body_html'] );
		$this->assertStringContainsString( '<h2', $result['body_html'] );
		$this->assertStringContainsString( 'Section Heading', $result['body_html'] );
		$this->assertStringContainsString( '<ul', $result['body_html'] );
		$this->assertStringContainsString( '<li', $result['body_html'] );
	}

	/**
	 * @return void
	 */
	public function test_parses_publication_date(): void {
		$mark = Template_Separator::mark();
		$raw  = "Title: Dated Article\nSlug: dated-article\nDate: 2026-05-26\n\n{$mark}\n\nBody.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( '2026-05-26', $result['date'] );
	}

	/**
	 * Shorter equals row (common in Google Docs) must split header/body like ======.
	 *
	 * @return void
	 */
	public function test_parses_five_equals_separator(): void {
		$raw = "Title: Five Equals\nSlug: five-equals\n\n=====\n\nBody after.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'Five Equals', $result['title'] );
		$this->assertSame( 'five-equals', $result['slug'] );
		$this->assertStringContainsString( 'Body after', $result['body'] );
	}

	/**
	 * Legacy --- separator still parses for older documents.
	 *
	 * @return void
	 */
	public function test_parses_legacy_dash_separator(): void {
		$raw = "Title: Legacy Doc\nSlug: legacy-doc\n\n---\n\nOld body.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'Legacy Doc', $result['title'] );
		$this->assertSame( 'legacy-doc', $result['slug'] );
		$this->assertStringContainsString( 'Old body', $result['body'] );
	}

	/**
	 * @return void
	 */
	public function test_slug_falls_back_to_sanitized_title_when_omitted(): void {
		$mark = Template_Separator::mark();
		$raw  = "Title: KPMG AI Tax Strategy\n\n{$mark}\n\nBody.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'kpmg-ai-tax-strategy', $result['slug'] );
	}

	/**
	 * @return void
	 */
	public function test_alias_label_maps_to_slug(): void {
		$mark = Template_Separator::mark();
		$raw  = "Title: Aliased Article\nAlias: custom-alias\n\n{$mark}\n\nBody.";

		$parser = new Template_Parser();
		$result = $parser->parse( $raw );

		$this->assertSame( 'custom-alias', $result['slug'] );
	}

	/**
	 * Header before ====== is read as plain text for mapping (inline bold in label line must not break values).
	 *
	 * @return void
	 */
	public function test_html_header_uses_plain_text_for_mapping(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$mark = Template_Separator::mark();
		$html = '<html><body>'
			. '<p><strong>Title:</strong> Hello</p>'
			. '<p>Slug: hello</p>'
			. '<p>' . $mark . '</p>'
			. '<p>Only body.</p>'
			. '</body></html>';

		$parser = new Template_Parser();
		$result = $parser->parse( $html );

		$this->assertSame( 'Hello', $result['title'] );
		$this->assertSame( 'hello', $result['slug'] );
		$this->assertStringContainsString( 'Only body', $result['body'] );
	}

	/**
	 * Full HTML parse with Google-style wrapper div and five equals separator.
	 *
	 * @return void
	 */
	public function test_parses_metadata_from_nested_google_doc_html(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$config = $this->createMock( Template_Config::class );
		$config->method( 'get_fields' )->willReturn(
			array(
				array(
					'label'    => 'Title',
					'type'     => 'core',
					'field'    => 'title',
					'required' => true,
				),
				array(
					'label'    => 'Slug',
					'type'     => 'core',
					'field'    => 'slug',
				),
				array(
					'label'    => 'Date',
					'type'     => 'core',
					'field'    => 'date',
				),
				array(
					'label'    => 'Category',
					'type'     => 'taxonomy',
					'taxonomy' => 'category',
					'multi'    => false,
				),
			)
		);

		$html = '<html><body><div>'
			. '<p>Title: DOJ Agreement</p>'
			. '<p>Slug: trump-irs-audit</p>'
			. '<p>Date: 2026-05-26</p>'
			. '<p>Category: Personal Income Tax</p>'
			. '<p>=====</p>'
			. '<p>Main Heading</p>'
			. '<p>Body paragraph.</p>'
			. '</div></body></html>';

		$parser = new Template_Parser( $config );
		$result = $parser->parse( $html );

		$this->assertSame( 'DOJ Agreement', $result['title'] );
		$this->assertSame( 'trump-irs-audit', $result['slug'] );
		$this->assertSame( '2026-05-26', $result['date'] );
		$this->assertSame( 'Personal Income Tax', $result['category'] );
		$this->assertStringContainsString( 'Body paragraph', $result['body'] );
		$this->assertStringNotContainsString( 'Title: DOJ', $result['body_html'] );
	}

	/**
	 * @return void
	 */
	public function test_parses_author_field(): void {
		$config = $this->createMock( Template_Config::class );
		$config->method( 'get_fields' )->willReturn(
			array(
				array(
					'label'    => 'Title',
					'type'     => 'core',
					'field'    => 'title',
					'required' => true,
				),
				array(
					'label' => 'Author',
					'type'  => 'core',
					'field' => 'author',
				),
			)
		);

		$mark   = Template_Separator::mark();
		$parser = new Template_Parser( $config );
		$result = $parser->parse( "Title: Story\nAuthor: Jane Editor\n\n{$mark}\n\nBody." );

		$this->assertSame( 'Jane Editor', $result['author'] );
	}
}
