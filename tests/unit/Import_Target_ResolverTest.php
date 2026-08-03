<?php
/**
 * Import target resolver tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Import_Target_Resolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Import_Target_Resolver
 */
class Import_Target_ResolverTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_resolve_for_import_rejects_missing_target(): void {
		$result = Import_Target_Resolver::resolve_for_import( 0, 'page' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forwp_drive_missing_target', $result->get_error_code() );
	}
}
