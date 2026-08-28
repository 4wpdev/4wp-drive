<?php
/**
 * FAQ accordion recipe tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Recipes\Faq_Accordion_Recipe;
use ForWP\Drive\Blocks\Block_Template_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Recipes\Faq_Accordion_Recipe
 */
class Faq_Accordion_RecipeTest extends TestCase {

	/**
	 * @var Faq_Accordion_Recipe
	 */
	private $recipe;

	/**
	 * @var array<string, mixed>
	 */
	private $config;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		forwp_drive_tests_reset_options();

		if ( ! defined( 'FORWP_FAQ_VERSION' ) ) {
			define( 'FORWP_FAQ_VERSION', 'test-stub' );
		}

		$this->recipe = new Faq_Accordion_Recipe();
		$this->config = array(
			'type'               => 'faq-accordion',
			'requires_plugins'   => array(),
			'section_heading'    => array(
				'level' => 2,
				'match' => '^(FAQ|Frequently Asked Questions)$',
			),
			'item_heading_level' => 3,
			'keep_section_heading' => true,
		);
	}

	/**
	 * @return void
	 */
	public function test_transforms_faq_section_into_forwp_faq_blocks(): void {
		$html   = (string) file_get_contents( __DIR__ . '/../fixtures/faq-section-body.html' );
		$config = $this->config;
		$config['template'] = Block_Template_Registry::TEMPLATE_4WP_FAQ;
		$result = $this->recipe->transform( $html, $config );

		$this->assertStringContainsString( '<h2>Frequently Asked Questions</h2>', $result );
		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $result );
		$this->assertStringContainsString( '<!-- wp:accordion -->', $result );
		$this->assertStringContainsString( 'What is the 20-year tax exemption?', $result );
		$this->assertStringContainsString( 'Who can apply for investment incentives?', $result );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
		$this->assertStringContainsString( '<!-- wp:list -->', $result );
		$this->assertStringContainsString( '<h2>Next section</h2>', $result );
		$this->assertStringNotContainsString( '<h3>What is the 20-year tax exemption?</h3>', $result );
	}

	/**
	 * @return void
	 */
	public function test_leaves_html_unchanged_without_faq_heading(): void {
		$html   = '<p>Only intro.</p><h2>Overview</h2><p>Body.</p>';
		$result = $this->recipe->transform( $html, $this->config );

		$this->assertSame( $html, $result );
	}
}
