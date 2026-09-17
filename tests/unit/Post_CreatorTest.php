<?php
/**
 * Post creator block markup tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Google_Doc_Content;
use ForWP\Drive\Import\Post_Creator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \ForWP\Drive\Import\Post_Creator
 */
class Post_CreatorTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_preserves_block_markup_in_post_content(): void {
		$creator  = new Post_Creator();
		$method   = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$markup   = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$content  = $method->invoke(
			$creator,
			array(
				'body_html' => $markup,
			)
		);

		$this->assertStringContainsString( '<!-- wp:paragraph', $content );
		$this->assertStringContainsString( 'Hello', $content );
	}

	public function test_image_marker_paragraphs_stay_raw_for_recipe(): void {
		$creator = new Post_Creator();
		$method  = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$content = $method->invoke(
			$creator,
			array(
				'body_html' => '<p>Intro</p><p>[image:cover.jpeg left]</p><p>Outro</p>',
			)
		);

		$this->assertStringContainsString( '[image:cover.jpeg left]', $content );
		$this->assertDoesNotMatchRegularExpression(
			'/<!-- wp:paragraph -->\s*<p>\[image:cover.jpeg left\]<\/p>/',
			$content
		);
	}

	public function test_html_table_becomes_core_table_block(): void {
		$creator = new Post_Creator();
		$method  = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$content = $method->invoke(
			$creator,
			array(
				'body_html' => '<p>Before</p><table><thead><tr><th>Hook</th></tr></thead><tbody><tr><td><code>init</code></td></tr></tbody></table><p>After</p>',
			)
		);

		$this->assertStringContainsString( '<!-- wp:table -->', $content );
		$this->assertStringContainsString( '<code>init</code>', $content );
		$this->assertStringContainsString( 'Before', $content );
		$this->assertStringContainsString( 'After', $content );
	}

	public function test_google_docs_table_with_cell_paragraphs_becomes_core_table(): void {
		$creator = new Post_Creator();
		$method  = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$body    = Google_Doc_Content::prepare(
			'<p>The draft framework contains five categories:</p>'
			. '<table><tr>'
			. '<td><p>Risk Zone</p></td>'
			. '<td><p>ATO Risk Classification</p></td>'
			. '</tr></table>'
		);
		$content = $method->invoke(
			$creator,
			array(
				'body_html' => $body,
			)
		);

		$this->assertStringContainsString( '<!-- wp:table -->', $content );
		$this->assertStringContainsString( 'Risk Zone', $content );
		$this->assertStringNotContainsString( '<!-- wp:paragraph --><p>Risk Zone</p>', $content );
	}

	public function test_strips_document_fonts_by_default(): void {
		$creator = new Post_Creator();
		$method  = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$content = $method->invoke(
			$creator,
			array(
				'body_html' => '<p><span style="font-family:&quot;Arial&quot;;font-size:11pt">Hello</span> <strong>world</strong>.</p>',
			)
		);

		$this->assertStringNotContainsString( 'font-family', $content );
		$this->assertStringNotContainsString( '<span', $content );
		$this->assertStringContainsString( 'Hello', $content );
		$this->assertStringContainsString( '<strong>world</strong>', $content );
	}

	public function test_keeps_document_fonts_when_enabled(): void {
		$creator = new Post_Creator( null, true );
		$method  = ( new ReflectionClass( $creator ) )->getMethod( 'build_post_content' );
		$method->setAccessible( true );
		$content = $method->invoke(
			$creator,
			array(
				'body_html' => '<p><span style="font-family:Arial">Hello</span></p>',
			)
		);

		$this->assertStringContainsString( 'font-family', $content );
	}
}
