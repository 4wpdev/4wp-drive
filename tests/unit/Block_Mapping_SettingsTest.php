<?php
/**
 * Block mapping / Pattern library tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Block_Mapping_Settings;
use ForWP\Drive\Blocks\Block_Template_Registry;
use ForWP\Drive\Patterns\Pattern_Library;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Block_Mapping_Settings
 * @covers \ForWP\Drive\Patterns\Pattern_Library
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
	public function test_builds_recipe_config_from_enabled_preset(): void {
		Pattern_Library::save_preset_enabled_overrides(
			array(
				'core-image' => true,
				'4wp-faq'    => true,
			)
		);

		$configs = ( new Block_Mapping_Settings() )->active_recipe_configs();
		$this->assertGreaterThanOrEqual( 1, count( $configs ) );
		$faq = null;
		foreach ( $configs as $config ) {
			if ( 'faq-accordion' === ( $config['type'] ?? '' ) && Block_Template_Registry::TEMPLATE_4WP_FAQ === ( $config['template'] ?? '' ) ) {
				$faq = $config;
				break;
			}
		}
		$this->assertIsArray( $faq );
		$this->assertSame(
			'^(FAQ|Frequently Asked Questions)$',
			$faq['section_heading']['match']
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
	public function test_empty_collection_still_includes_default_core_image(): void {
		$configs = ( new Block_Mapping_Settings() )->active_recipe_configs();
		$this->assertNotEmpty( $configs );
		$this->assertSame( 'core-image', $configs[0]['type'] );
		$this->assertSame( Block_Template_Registry::TEMPLATE_CORE_IMAGE, $configs[0]['template'] );
	}

	/**
	 * @return void
	 */
	public function test_explicit_disabled_core_image_preset_removes_default(): void {
		Pattern_Library::save_preset_enabled_overrides(
			array(
				'core-image'     => false,
				'4wp-faq'        => false,
				'core-accordion' => false,
			)
		);

		$configs = ( new Block_Mapping_Settings() )->active_recipe_configs();
		$this->assertSame( array(), $configs );
	}

	/**
	 * @return void
	 */
	public function test_patterns_not_customized_by_default(): void {
		$this->assertFalse( Pattern_Library::is_customized() );
		$rules = Pattern_Library::get_rules();
		$this->assertNotEmpty( $rules );
		$this->assertSame( 'preset', $rules[0]['origin'] ?? '' );
	}
}
