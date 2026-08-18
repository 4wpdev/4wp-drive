<?php
/**
 * Import language resolver tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Import_Language_Resolver;
use ForWP\Drive\Multilingual\Language_Provider_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Import_Language_Resolver
 */
class Import_Language_ResolverTest extends TestCase {

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Language_Provider_Registry::reset();
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	public function test_resolve_returns_implicit_language_on_single_site(): void {
		$result = Import_Language_Resolver::resolve( '' );

		$this->assertIsString( $result );
		$this->assertNotSame( '', $result );
	}

	/**
	 * @return void
	 */
	public function test_resolve_accepts_valid_requested_language(): void {
		$provider = Language_Provider_Registry::get_active();
		$languages = $provider->get_languages();
		if ( empty( $languages ) ) {
			$this->markTestSkipped( 'No languages configured.' );
		}

		$code   = (string) $languages[0]['code'];
		$result = Import_Language_Resolver::resolve( $code );

		$this->assertSame( $code, $result );
	}
}
