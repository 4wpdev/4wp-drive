<?php
/**
 * Block recipe engine tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Block_Mapping_Settings;
use ForWP\Drive\Blocks\Block_Recipe_Engine;
use ForWP\Drive\Blocks\Block_Template_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Block_Recipe_Engine
 */
class Block_Recipe_EngineTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		forwp_drive_tests_reset_options();

		if ( ! defined( 'FORWP_FAQ_VERSION' ) ) {
			define( 'FORWP_FAQ_VERSION', 'test-stub' );
		}
	}

	/**
	 * @return void
	 */
	public function test_applies_4wp_faq_template_rule(): void {
		( new Block_Mapping_Settings() )->save(
			array(
				'rules' => array(
					array(
						'id'                   => 'rule_faq',
						'enabled'              => true,
						'template'             => Block_Template_Registry::TEMPLATE_4WP_FAQ,
						'section_headings'     => 'FAQ, Frequently Asked Questions',
						'keep_section_heading' => true,
					),
				),
			)
		);

		$html   = (string) file_get_contents( __DIR__ . '/../fixtures/faq-section-body.html' );
		$result = ( new Block_Recipe_Engine() )->apply( $html );

		$this->assertStringContainsString( '<!-- wp:forwp/faq -->', $result );
	}

	/**
	 * @return void
	 */
	public function test_applies_core_accordion_template_without_faq_wrapper(): void {
		( new Block_Mapping_Settings() )->save(
			array(
				'rules' => array(
					array(
						'id'                   => 'rule_accordion',
						'enabled'              => true,
						'template'             => Block_Template_Registry::TEMPLATE_CORE_ACCORDION,
						'section_headings'     => 'Frequently Asked Questions',
						'keep_section_heading' => true,
					),
				),
			)
		);

		$html   = (string) file_get_contents( __DIR__ . '/../fixtures/faq-section-body.html' );
		$result = ( new Block_Recipe_Engine() )->apply( $html );

		$this->assertStringContainsString( '<!-- wp:accordion -->', $result );
		$this->assertStringNotContainsString( '<!-- wp:forwp/faq -->', $result );
	}

	/**
	 * @return void
	 */
	public function test_returns_html_when_no_rules_enabled(): void {
		$html   = '<p>Plain body.</p>';
		$result = ( new Block_Recipe_Engine() )->apply( $html );

		$this->assertSame( $html, $result );
	}
}
