<?php
/**
 * Import history path + alias derivation.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Admin\GitHub_Settings;
use ForWP\Drive\Import\Import_History_Recorder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Import_History_Recorder
 */
class Import_History_RecorderTest extends TestCase {

	protected function setUp(): void {
		if ( function_exists( 'forwp_drive_tests_reset_options' ) ) {
			forwp_drive_tests_reset_options();
		}
	}

	public function test_github_package_keeps_tree_and_aliases(): void {
		GitHub_Settings::save(
			array(
				'owner'     => '4wpdev',
				'repo'      => 'sandbox',
				'incoming'  => 'incoming',
				'published' => 'published',
			)
		);

		$row = Import_History_Recorder::build(
			array(
				'source'      => 'github',
				'post_id'     => 2287,
				'post_type'   => 'post',
				'site_alias'  => 'what-can-4wp-drive-plugin-do',
				'document_id' => 12,
				'file_id'     => 'gh:4wpdev/sandbox:incoming/4wp-drive-plugin-overview/article.md',
				'mode'        => 'create',
				'metadata'    => array(
					'package_folder_id'   => 'incoming/4wp-drive-plugin-overview',
					'package_folder_name' => '4wp-drive-plugin-overview',
					'github_path'         => 'incoming/4wp-drive-plugin-overview/article.md',
				),
			)
		);

		$this->assertSame( 'github', $row['source'] );
		$this->assertSame( 2287, $row['post_id'] );
		$this->assertSame( 'post', $row['post_type'] );
		$this->assertSame( '4wp-drive-plugin-overview', $row['folder_alias'] );
		$this->assertSame( 'what-can-4wp-drive-plugin-do', $row['site_alias'] );
		$this->assertSame( 'incoming/4wp-drive-plugin-overview', $row['incoming_path'] );
		$this->assertSame( 'published/4wp-drive-plugin-overview', $row['published_path'] );
		$this->assertSame( 'incoming/4wp-drive-plugin-overview', $row['package_ref'] );
	}

	public function test_drive_package_uses_incoming_and_published_labels(): void {
		$row = Import_History_Recorder::build(
			array(
				'source'     => 'google_drive',
				'post_id'    => 10,
				'post_type'  => 'page',
				'site_alias' => 'hello-world',
				'metadata'   => array(
					'package_folder_id'   => '1AbCfolder',
					'package_folder_name' => '4wp-drive-plugin-overview',
				),
			)
		);

		$this->assertSame( 'google_drive', $row['source'] );
		$this->assertSame( 'page', $row['post_type'] );
		$this->assertSame( '4wp-drive-plugin-overview', $row['folder_alias'] );
		$this->assertSame( 'hello-world', $row['site_alias'] );
		$this->assertSame( 'incoming/4wp-drive-plugin-overview', $row['incoming_path'] );
		$this->assertSame( 'published/4wp-drive-plugin-overview', $row['published_path'] );
		$this->assertSame( '1AbCfolder', $row['package_ref'] );
	}
}
