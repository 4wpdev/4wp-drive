<?php
/**
 * Block mapping settings tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Block_Mapping_Settings;
use ForWP\Drive\Blocks\Block_Template_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Block_Mapping_Settings
 */
class Block_Mapping_SettingsTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		forwp_drive_tests_reset_options();
	}

	/**
	 * @return void
	 */
	public function test_builds_recipe_config_from_template_rule(): void {
		$settings = new Block_Mapping_Settings();
		$settings->save(
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

		$configs = $settings->active_recipe_configs();
		$this->assertCount( 1, $configs );
		$this->assertSame( 'faq-accordion', $configs[0]['type'] );
		$this->assertSame( Block_Template_Registry::TEMPLATE_4WP_FAQ, $configs[0]['template'] );
		$this->assertSame(
			'^(FAQ|Frequently Asked Questions)$',
			$configs[0]['section_heading']['match']
		);
	}

	/**
	 * @return void
	 */
	public function test_core_accordion_template_rule_has_no_faq_wrapper_requirement(): void {
		$config = Block_Mapping_Settings::rule_to_recipe_config(
			array(
				'enabled'              => true,
				'template'             => Block_Template_Registry::TEMPLATE_CORE_ACCORDION,
				'section_headings'     => 'FAQ',
				'keep_section_heading' => false,
			)
		);

		$this->assertIsArray( $config );
		$this->assertSame( Block_Template_Registry::TEMPLATE_CORE_ACCORDION, $config['template'] );
		$this->assertSame( array(), $config['requires_plugins'] );
	}

	/**
	 * @return void
	 */
	public function test_returns_no_configs_when_collection_empty(): void {
		$this->assertSame( array(), ( new Block_Mapping_Settings() )->active_recipe_configs() );
	}
}
