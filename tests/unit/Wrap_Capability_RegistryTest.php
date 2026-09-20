<?php
/**
 * Wrap capability registry tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Wrap_Capability_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Wrap_Capability_Registry
 */
class Wrap_Capability_RegistryTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( 'forwp_drive_wrap_capabilities' );
			remove_all_filters( 'forwp_drive_detect_map' );
		}
	}

	/**
	 * @return void
	 */
	public function test_core_includes_image_marker(): void {
		$ids = array_column( Wrap_Capability_Registry::all(), 'id' );

		$this->assertContains( 'core-image', $ids );
	}

	/**
	 * @return void
	 */
	public function test_family_filter_adds_explicit_row_only(): void {
		add_filter(
			'forwp_drive_wrap_capabilities',
			static function ( array $items ): array {
				$items[] = array(
					'id'     => 'techarticle-goal',
					'origin' => Wrap_Capability_Registry::ORIGIN_FAMILY,
					'plugin' => '4wp-seo-helper',
					'block'  => 'forwp-seo/techarticle-goal',
					'maps'   => array( 'tech-article' ),
					'wrap'   => Wrap_Capability_Registry::WRAP_SECTION,
					'match'  => array(
						'type'    => 'heading',
						'level'   => 2,
						'pattern' => '^(Goal|Мета)$',
					),
					'prompt' => 'H2 Goal',
					'auto'   => true,
				);

				return $items;
			}
		);

		$for_tech = Wrap_Capability_Registry::for_map( 'tech-article' );
		$ids      = array_column( $for_tech, 'id' );

		$this->assertContains( 'techarticle-goal', $ids );
		$this->assertContains( 'core-image', $ids );

		$for_article = array_column( Wrap_Capability_Registry::for_map( 'article' ), 'id' );
		$this->assertNotContains( 'techarticle-goal', $for_article );
	}

	/**
	 * @return void
	 */
	public function test_rejects_row_without_block(): void {
		add_filter(
			'forwp_drive_wrap_capabilities',
			static function ( array $items ): array {
				$items[] = array(
					'id'   => 'no-block',
					'wrap' => Wrap_Capability_Registry::WRAP_SECTION,
				);

				return $items;
			}
		);

		$ids = array_column( Wrap_Capability_Registry::all(), 'id' );
		$this->assertNotContains( 'no-block', $ids );
	}

	/**
	 * @return void
	 */
	public function test_family_groups_collects_plugin_rows(): void {
		add_filter(
			'forwp_drive_wrap_capabilities',
			static function ( array $items ): array {
				$items[] = array(
					'id'            => '4wp-faq',
					'origin'        => Wrap_Capability_Registry::ORIGIN_FAMILY,
					'plugin'        => '4wp-faq',
					'block'         => 'forwp/faq',
					'wrap'          => Wrap_Capability_Registry::WRAP_FAQ_QA,
					'heading_seeds' => 'FAQ',
				);
				$items[] = array(
					'id'            => 'techarticle-goal',
					'origin'        => Wrap_Capability_Registry::ORIGIN_FAMILY,
					'plugin'        => '4wp-seo-helper',
					'block'         => 'forwp-seo/techarticle-goal',
					'wrap'          => Wrap_Capability_Registry::WRAP_SECTION,
					'heading_seeds' => 'Goal, Мета',
				);

				return $items;
			}
		);

		$groups = Wrap_Capability_Registry::family_groups();
		$by_plugin = array_column( $groups, null, 'plugin' );

		$this->assertArrayHasKey( '4wp-faq', $by_plugin );
		$this->assertArrayHasKey( '4wp-seo-helper', $by_plugin );
		$this->assertSame( '4WP FAQ', $by_plugin['4wp-faq']['label'] );
		$this->assertSame( '4wp-faq', $by_plugin['4wp-faq']['items'][0]['id'] );
		$this->assertSame( 'techarticle-goal', $by_plugin['4wp-seo-helper']['items'][0]['id'] );
	}

	/**
	 * @return void
	 */
	public function test_family_groups_includes_catalog_without_filter(): void {
		$groups    = Wrap_Capability_Registry::family_groups();
		$by_plugin = array_column( $groups, null, 'plugin' );

		$this->assertArrayHasKey( '4wp-faq', $by_plugin );
		$this->assertArrayHasKey( '4wp-seo-helper', $by_plugin );
		$this->assertNotEmpty( $by_plugin['4wp-faq']['action_url'] );
		$this->assertNotEmpty( $by_plugin['4wp-seo-helper']['action_url'] );
	}

	/**
	 * @return void
	 */
	public function test_normalize_map_aliases_and_unknown(): void {
		$this->assertSame( 'article', Wrap_Capability_Registry::normalize_map( '' ) );
		$this->assertSame( 'tech-article', Wrap_Capability_Registry::normalize_map( 'TechArticle' ) );
		$this->assertSame( 'recipe', Wrap_Capability_Registry::normalize_map( 'recipe' ) );
		$this->assertSame( 'article', Wrap_Capability_Registry::normalize_map( 'not-a-map' ) );
	}

	/**
	 * @return void
	 */
	public function test_detect_map_upgrades_article_from_family(): void {
		add_filter(
			'forwp_drive_detect_map',
			static function ( string $map, string $header, string $body ): string {
				unset( $map );
				if ( false !== strpos( $header . $body, 'wp:forwp-seo/techarticle-' ) ) {
					return Wrap_Capability_Registry::MAP_TECH_ARTICLE;
				}

				return Wrap_Capability_Registry::MAP_ARTICLE;
			},
			10,
			3
		);

		$this->assertSame(
			'tech-article',
			Wrap_Capability_Registry::detect_map(
				'article',
				'',
				'<!-- wp:forwp-seo/techarticle-goal --><p>Goal</p><!-- /wp:forwp-seo/techarticle-goal -->'
			)
		);
	}

	/**
	 * @return void
	 */
	public function test_detect_map_does_not_override_recipe(): void {
		add_filter(
			'forwp_drive_detect_map',
			static function (): string {
				return Wrap_Capability_Registry::MAP_TECH_ARTICLE;
			}
		);

		$this->assertSame(
			'recipe',
			Wrap_Capability_Registry::detect_map( 'recipe', 'TechArticle', '' )
		);
	}
}
