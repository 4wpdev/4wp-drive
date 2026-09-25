<?php
/**
 * Section wrap recipe tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Blocks\Recipes\Section_Wrap_Recipe;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Blocks\Recipes\Section_Wrap_Recipe
 */
class Section_Wrap_RecipeTest extends TestCase {

	public function test_wraps_section_with_inner_gutenberg_blocks(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOMDocument not available.' );
		}

		$recipe = new Section_Wrap_Recipe();
		$out    = $recipe->transform(
			'<p>Intro</p><h2>Common Mistakes</h2><p>Do not skip auth.</p><h2>Next</h2><p>After</p>',
			array(
				'block'            => 'forwp-seo/techarticle-issues',
				'section_heading'  => array(
					'match' => 'Common Mistakes',
				),
			)
		);

		$this->assertStringContainsString( '<!-- wp:forwp-seo/techarticle-issues -->', $out );
		$this->assertStringContainsString( '<!-- wp:heading', $out );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
		$this->assertStringContainsString( 'Do not skip auth.', $out );
		$this->assertStringNotContainsString( '<section class="forwp-seo-techarticle-issues">', $out );
		$this->assertStringContainsString( '<h2>Next</h2>', $out );
	}
}
