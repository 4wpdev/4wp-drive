<?php
/**
 * Block recipe engine tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Block_Recipe_Engine;
use ForWP\Drive\Patterns\Pattern_Library;
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
		Pattern_Library::save_preset_enabled_overrides(
			array(
				'core-image'     => false,
				'4wp-faq'        => true,
				'core-accordion' => false,
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
		Pattern_Library::save_preset_enabled_overrides(
			array(
				'core-image'     => false,
				'4wp-faq'        => false,
				'core-accordion' => true,
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
	public function test_returns_html_when_no_heading_rules_enabled(): void {
		Pattern_Library::save_preset_enabled_overrides(
			array(
				'core-image'     => false,
				'4wp-faq'        => false,
				'core-accordion' => false,
			)
		);

		$html   = '<p>Plain body.</p>';
		$result = ( new Block_Recipe_Engine() )->apply( $html );

		$this->assertSame( $html, $result );
	}
}
