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
		$this->assertStringContainsString( 'role="group"', $result );
		$this->assertStringContainsString( 'has-icon has-icon-right', $result );
		$this->assertStringContainsString( 'role="region"', $result );
		$this->assertStringNotContainsString( 'aria-expanded', $result );
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

	/**
	 * @return void
	 */
	public function test_transforms_bold_paragraph_questions_into_faq_blocks(): void {
		$html   = (string) file_get_contents( __DIR__ . '/../fixtures/faq-bold-section-body.html' );
		$config = $this->config;
		$config['template'] = Block_Template_Registry::TEMPLATE_4WP_FAQ;
		$result = $this->recipe->transform( $html, $config );

		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $result );
		$this->assertStringContainsString( 'Чи можна покластися на WP-Cron', $result );
		$this->assertStringContainsString( 'У чому різниця між вимкненням плагіна', $result );
		$this->assertStringContainsString( 'Ні. WP-Cron гарантує лише те', $result );
		$this->assertStringContainsString( '<h2>Підсумок</h2>', $result );
		$this->assertStringNotContainsString( '<p><strong>Чи можна покластися на WP-Cron', $result );
	}

	/**
	 * @return void
	 */
	public function test_keeps_faq_body_when_no_questions_detected(): void {
		$html   = '<h2>FAQ</h2><p>Still writing this section.</p><h2>Summary</h2><p>Done.</p>';
		$result = $this->recipe->transform( $html, $this->config );

		$this->assertStringContainsString( '<h2>FAQ</h2>', $result );
		$this->assertStringContainsString( '<p>Still writing this section.</p>', $result );
		$this->assertStringContainsString( '<h2>Summary</h2>', $result );
		$this->assertStringNotContainsString( '<!-- wp:forwp/faq -->', $result );
	}

	/**
	 * @return void
	 */
	public function test_skips_faq_heading_marked_to_leave_unwrapped(): void {
		$html   = '<h2 class="forwp-drive-skip-wrap">FAQ</h2><h3>Q?</h3><p>A.</p><h2>Summary</h2>';
		$result = $this->recipe->transform( $html, $this->config );

		$this->assertStringNotContainsString( '<!-- wp:forwp/faq -->', $result );
		$this->assertStringContainsString( '<h2 class="forwp-drive-skip-wrap">FAQ</h2>', $result );
		$this->assertStringContainsString( '<h3>Q?</h3>', $result );
	}

	public function test_keeps_existing_accordion_block_markup(): void {
		$html = '<h2>FAQ</h2><!-- wp:forwp/faq --><!-- wp:accordion -->'
			. '<div class="wp-block-accordion" role="group">'
			. '<!-- wp:accordion-item --><div class="wp-block-accordion-item">'
			. '<!-- wp:accordion-heading --><h3 class="wp-block-accordion-heading">'
			. '<button type="button" class="wp-block-accordion-heading__toggle">'
			. '<span class="wp-block-accordion-heading__toggle-title">Kept question</span></button></h3>'
			. '<!-- /wp:accordion-heading -->'
			. '<!-- wp:accordion-panel --><div class="wp-block-accordion-panel" role="region">'
			. '<!-- wp:paragraph --><p>Kept answer</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:accordion-panel --></div><!-- /wp:accordion-item -->'
			. '</div><!-- /wp:accordion --><!-- /wp:forwp/faq -->';

		$result = $this->recipe->transform( $html, $this->config );

		$this->assertSame( $html, $result );
	}

	public function test_rebuilds_faq_from_stamped_wrapper_around_qa_html(): void {
		$html = '<h2>FAQ</h2><!-- wp:forwp/faq -->'
			. '<h3>Should we use GraphQL?</h3><p>Yes when pages need several types.</p>'
			. '<!-- /wp:forwp/faq -->';

		$result = $this->recipe->transform( $html, $this->config );

		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $result );
		$this->assertStringContainsString( '<!-- wp:accordion -->', $result );
		$this->assertStringContainsString( 'Should we use GraphQL?', $result );
		$this->assertStringContainsString( 'Yes when pages need several types.', $result );
		$this->assertStringNotContainsString( '<h3>Should we use GraphQL?</h3>', $result );
	}

	public function test_rebuilds_faq_from_visual_accordion_dom(): void {
		$html = '<h2>FAQ</h2><div class="wp-block-accordion" role="group">'
			. '<div class="wp-block-accordion-item">'
			. '<h3 class="wp-block-accordion-heading"><button type="button">'
			. '<span class="wp-block-accordion-heading__toggle-title">Visual question</span></button></h3>'
			. '<div class="wp-block-accordion-panel" role="region"><p>Visual answer</p></div>'
			. '</div></div>';

		$result = $this->recipe->transform( $html, $this->config );

		$this->assertStringContainsString( '<!-- wp:accordion -->', $result );
		$this->assertStringContainsString( 'Visual question', $result );
		$this->assertStringContainsString( 'Visual answer', $result );
	}

	public function test_preserves_techarticle_wraps_when_rebuilding_faq(): void {
		$html = '<!-- wp:forwp-seo/techarticle-goal -->'
			. '<h2>Standards and Best Practices</h2><p>Use Application Passwords.</p>'
			. '<!-- /wp:forwp-seo/techarticle-goal -->'
			. '<!-- wp:forwp-seo/techarticle-issues -->'
			. '<h2>Common Mistakes</h2><p>Do not store tokens in localStorage.</p>'
			. '<!-- /wp:forwp-seo/techarticle-issues -->'
			. '<!-- wp:forwp/faq -->'
			. '<h2>FAQ</h2>'
			. '<div class="wp-block-accordion" role="group">'
			. '<div class="wp-block-accordion-item">'
			. '<h3 class="wp-block-accordion-heading"><button type="button">'
			. '<span class="wp-block-accordion-heading__toggle-title">Can I use cookies?</span></button></h3>'
			. '<div class="wp-block-accordion-panel" role="region"><p>Only same origin.</p></div>'
			. '</div></div>'
			. '<!-- /wp:forwp/faq -->';

		$config = $this->config;
		$config['template'] = Block_Template_Registry::TEMPLATE_4WP_FAQ;
		$result = $this->recipe->transform( $html, $config );

		$this->assertStringContainsString( '<!-- wp:forwp-seo/techarticle-goal -->', $result );
		$this->assertStringContainsString( '<!-- wp:forwp-seo/techarticle-issues -->', $result );
		$this->assertStringContainsString( 'Use Application Passwords.', $result );
		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $result );
		$this->assertStringContainsString( '<!-- wp:accordion -->', $result );
		$this->assertStringContainsString( 'Can I use cookies?', $result );
	}
}
