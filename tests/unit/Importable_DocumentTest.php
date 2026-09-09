<?php
/**
 * Incoming file classification tests.
 *
 * @package ForWP\Drive\Tests
 */

namespace ForWP\Drive\Tests;

use ForWP\Drive\Import\Importable_Document;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ForWP\Drive\Import\Importable_Document
 */
class Importable_DocumentTest extends TestCase {

	public function test_kind_from_name_and_mime(): void {
		$this->assertSame(
			Importable_Document::KIND_MARKDOWN,
			Importable_Document::kind( 'text/plain', 'article.md' )
		);
		$this->assertSame(
			Importable_Document::KIND_GOOGLE_DOC,
			Importable_Document::kind( 'application/vnd.google-apps.document', 'Doc' )
		);
		$this->assertSame(
			Importable_Document::KIND_DOCX,
			Importable_Document::kind( '', 'notes.docx' )
		);
		$this->assertSame(
			Importable_Document::KIND_IMAGE,
			Importable_Document::kind( 'image/png', 'hero.png' )
		);
	}

	public function test_pick_default_prefers_markdown(): void {
		$picked = Importable_Document::pick_default(
			array(
				array(
					'id'       => 'doc-1',
					'name'     => 'Story',
					'mimeType' => 'application/vnd.google-apps.document',
				),
				array(
					'id'       => 'md-1',
					'name'     => 'story.md',
					'mimeType' => 'text/markdown',
				),
				array(
					'id'       => 'docx-1',
					'name'     => 'story.docx',
					'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				),
			)
		);

		$this->assertIsArray( $picked );
		$this->assertSame( 'md-1', $picked['id'] );
	}
}
