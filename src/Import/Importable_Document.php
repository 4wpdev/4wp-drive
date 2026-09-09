<?php
/**
 * Classify incoming files as article vs image.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Shared mime/name rules for Drive and GitHub packages.
 */
final class Importable_Document {

	public const KIND_GOOGLE_DOC = 'google_doc';

	public const KIND_DOCX = 'docx';

	public const KIND_MARKDOWN = 'markdown';

	public const KIND_IMAGE = 'image';

	public const KIND_OTHER = 'other';

	/**
	 * @param string $mime File mime type.
	 * @param string $name Filename.
	 */
	public static function kind( string $mime, string $name ): string {
		$mime = strtolower( trim( $mime ) );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 0 === strpos( $mime, 'image/' ) || in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif' ), true ) ) {
			return self::KIND_IMAGE;
		}

		if ( 'application/vnd.google-apps.document' === $mime ) {
			return self::KIND_GOOGLE_DOC;
		}

		if ( Docx_Content::MIME === $mime || 'docx' === $ext ) {
			return self::KIND_DOCX;
		}

		if (
			in_array( $ext, array( 'md', 'markdown', 'mdx' ), true )
			|| in_array( $mime, array( 'text/markdown', 'text/x-markdown' ), true )
			|| ( 'text/plain' === $mime && in_array( $ext, array( 'md', 'markdown', 'mdx' ), true ) )
		) {
			return self::KIND_MARKDOWN;
		}

		if ( 'text/plain' === $mime && self::name_looks_markdown( $name ) ) {
			return self::KIND_MARKDOWN;
		}

		return self::KIND_OTHER;
	}

	/**
	 * Whether this file can be imported as the article body.
	 */
	public static function is_article( string $kind ): bool {
		return in_array( $kind, array( self::KIND_GOOGLE_DOC, self::KIND_DOCX, self::KIND_MARKDOWN ), true );
	}

	/**
	 * Default pick when a folder has several article files: Markdown, then Google Doc, then Word.
	 *
	 * @param array<int, array<string, mixed>> $files Rows with id, name, mimeType.
	 * @return array<string, mixed>|null
	 */
	public static function pick_default( array $files ): ?array {
		$ranked = array(
			self::KIND_MARKDOWN   => null,
			self::KIND_GOOGLE_DOC => null,
			self::KIND_DOCX       => null,
		);

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$kind = self::kind( (string) ( $file['mimeType'] ?? '' ), (string) ( $file['name'] ?? '' ) );
			if ( ! self::is_article( $kind ) ) {
				continue;
			}

			if ( null === $ranked[ $kind ] ) {
				$ranked[ $kind ] = $file;
			}
		}

		foreach ( $ranked as $file ) {
			if ( is_array( $file ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $files Candidate files.
	 * @return array<int, array{id: string, name: string, kind: string, mime: string}>
	 */
	public static function to_source_files( array $files ): array {
		$out = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$id   = (string) ( $file['id'] ?? '' );
			$name = (string) ( $file['name'] ?? '' );
			$mime = (string) ( $file['mimeType'] ?? '' );
			if ( '' === $id || '' === $name ) {
				continue;
			}

			$kind = self::kind( $mime, $name );
			if ( ! self::is_article( $kind ) ) {
				continue;
			}

			$out[] = array(
				'id'   => $id,
				'name' => $name,
				'kind' => 'document',
				'mime' => $mime,
			);
		}

		return $out;
	}

	/**
	 * Pick a row by id from a file list.
	 *
	 * @param array<int, array<string, mixed>> $files Files.
	 * @param string                           $id    File id.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_id( array $files, string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		foreach ( $files as $file ) {
			if ( is_array( $file ) && (string) ( $file['id'] ?? '' ) === $id ) {
				return $file;
			}
		}

		return null;
	}

	private static function name_looks_markdown( string $name ): bool {
		return (bool) preg_match( '/\.(md|markdown|mdx)$/i', $name );
	}
}
