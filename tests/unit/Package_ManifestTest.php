<?php
/**
 * Package manifest (meta.json) tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Package\Package_Manifest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Package\Package_Manifest
 */
class Package_ManifestTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_reads_original_and_platforms(): void {
		$manifest = Package_Manifest::from_json(
			(string) json_encode(
				array(
					'schema'    => 1,
					'original'  => array( 'file' => 'article.md' ),
					'platforms' => array(
						'dev.to' => array( 'file' => 'dev.to.md' ),
						'x.com'  => array( 'file' => 'x.com.md' ),
					),
				)
			)
		);

		$this->assertSame( '', $manifest->error() );
		$this->assertSame(
			array(
				'schema'    => 1,
				'original'  => 'article.md',
				'platforms' => array( 'dev.to', 'x.com' ),
			),
			$manifest->to_metadata()
		);
	}

	/**
	 * @return void
	 */
	public function test_defaults_original_file(): void {
		$manifest = Package_Manifest::from_json( '{"schema":1}' );

		$this->assertSame( Package_Manifest::DEFAULT_ORIGINAL, $manifest->original_file() );
	}

	/**
	 * @return void
	 */
	public function test_original_file_cannot_escape_package_folder(): void {
		$manifest = Package_Manifest::from_json( '{"schema":1,"original":{"file":"../other/secret.md"}}' );

		$this->assertSame( 'secret.md', $manifest->original_file() );
	}

	/**
	 * @return void
	 */
	public function test_invalid_json_reports_error_with_defaults(): void {
		$manifest = Package_Manifest::from_json( '{not json' );

		$this->assertNotSame( '', $manifest->error() );
		$this->assertSame( Package_Manifest::DEFAULT_ORIGINAL, $manifest->original_file() );
	}

	/**
	 * @return void
	 */
	public function test_missing_schema_reports_error(): void {
		$this->assertNotSame( '', Package_Manifest::from_json( '{"original":{"file":"original.md"}}' )->error() );
	}

	/**
	 * @return void
	 */
	public function test_in_progress_state(): void {
		$metadata = array( Package_Manifest::META_KEY => array( 'schema' => 1 ) );

		$this->assertTrue( Package_Manifest::is_package( $metadata ) );
		$this->assertFalse( Package_Manifest::is_in_progress( $metadata ) );

		$marked = Package_Manifest::mark_in_progress( $metadata, 42 );

		$this->assertTrue( Package_Manifest::is_in_progress( $marked ) );
		$this->assertSame( 42, $marked[ Package_Manifest::META_KEY ]['post_id'] );
	}

	/**
	 * @return void
	 */
	public function test_plain_metadata_is_not_a_package(): void {
		$this->assertFalse( Package_Manifest::is_package( array( 'title' => 'Post' ) ) );
		$this->assertSame( array( 'title' => 'Post' ), Package_Manifest::mark_in_progress( array( 'title' => 'Post' ), 1 ) );
	}

	/**
	 * @return void
	 */
	public function test_only_original_is_importable(): void {
		$metadata = array( Package_Manifest::META_KEY => array( 'original' => 'original.md' ) );

		$this->assertFalse( Package_Manifest::is_blocked_file( $metadata, 'development/Headless/pkg/original.md' ) );
		$this->assertTrue( Package_Manifest::is_blocked_file( $metadata, 'development/Headless/pkg/facebook.com.md' ) );
		$this->assertTrue( Package_Manifest::is_blocked_file( $metadata, 'meta.json' ) );
		$this->assertFalse( Package_Manifest::is_blocked_file( array( 'title' => 'Post' ), 'facebook.com.md' ) );
	}

	/**
	 * @return void
	 */
	public function test_merge_patches_objects_and_replaces_lists(): void {
		$data = array(
			'schema'    => 1,
			'original'  => array( 'file' => 'original.md', 'status' => 'pending', 'url' => null ),
			'media'     => array( array( 'id' => 'cover' ), array( 'id' => 'video' ) ),
			'platforms' => array( 'dev.to' => array( 'status' => 'ready', 'mode' => 'api' ) ),
		);

		$merged = Package_Manifest::merge(
			$data,
			array(
				'original'  => array( 'status' => 'published', 'url' => 'https://4wp.dev/x/' ),
				'media'     => array( array( 'id' => 'cover' ) ),
				'platforms' => array( 'dev.to' => array( 'status' => 'posted' ) ),
			)
		);

		$this->assertSame( 'original.md', $merged['original']['file'] );
		$this->assertSame( 'published', $merged['original']['status'] );
		$this->assertSame( 'https://4wp.dev/x/', $merged['original']['url'] );
		$this->assertSame( array( array( 'id' => 'cover' ) ), $merged['media'] );
		$this->assertSame( array( 'status' => 'posted', 'mode' => 'api' ), $merged['platforms']['dev.to'] );
		$this->assertSame( 1, $merged['schema'] );
	}

	/**
	 * @return void
	 */
	public function test_encode_uses_two_space_indent(): void {
		$json = Package_Manifest::encode( array( 'original' => array( 'url' => 'https://4wp.dev/a/' ) ) );

		$this->assertSame( "{\n  \"original\": {\n    \"url\": \"https://4wp.dev/a/\"\n  }\n}\n", $json );
	}

	/**
	 * @return void
	 */
	public function test_manifest_name_is_case_insensitive(): void {
		$this->assertTrue( Package_Manifest::is_manifest_name( 'META.json' ) );
		$this->assertFalse( Package_Manifest::is_manifest_name( 'meta.json.bak' ) );
	}
}
