<?php
/**
 * Extract content from .docx (Word) files on Google Drive.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads word/document.xml from a DOCX zip archive and converts to HTML for the template parser.
 */
final class Docx_Content {

	public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * @param string $binary Raw .docx file bytes.
	 * @return string|WP_Error HTML document (`<html><body>…</body></html>`).
	 */
	public static function extract_html_document( string $binary ) {
		$opened = self::open_archive( $binary );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}

		$numbering = (string) ( $opened['numbering_xml'] ?? '' );
		$html      = self::document_xml_to_html( (string) $opened['document_xml'], $numbering );
		self::cleanup_temp( $opened['tmp'] );

		return '<html><body>' . $html . '</body></html>';
	}

	/**
	 * @param string $binary Raw .docx file bytes.
	 * @return string|WP_Error Plain text with paragraph line breaks.
	 */
	public static function extract_plain_text( string $binary ) {
		$opened = self::open_archive( $binary );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}

		$plain = self::document_xml_to_plain_text( (string) $opened['document_xml'] );
		self::cleanup_temp( $opened['tmp'] );

		return $plain;
	}

	/**
	 * @param string $binary Raw .docx file bytes.
	 * @return array{document_xml: string, numbering_xml: string|false, tmp: string}|WP_Error
	 */
	private static function open_archive( string $binary ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'forwp_drive_docx_zip',
				__( 'PHP ZipArchive is required to read .docx files.', '4wp-drive' )
			);
		}

		$tmp = wp_tempnam( 'forwp-drive-docx' );
		if ( ! $tmp ) {
			return new WP_Error( 'forwp_drive_docx_temp', __( 'Could not create a temp file for .docx.', '4wp-drive' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $binary ) ) {
			wp_delete_file( $tmp );

			return new WP_Error( 'forwp_drive_docx_write', __( 'Could not write .docx temp file.', '4wp-drive' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp ) ) {
			wp_delete_file( $tmp );

			return new WP_Error( 'forwp_drive_docx_open', __( 'Could not open .docx archive.', '4wp-drive' ) );
		}

		$document_xml = $zip->getFromName( 'word/document.xml' );
		$numbering    = $zip->getFromName( 'word/numbering.xml' );
		$zip->close();

		if ( false === $document_xml || '' === $document_xml ) {
			wp_delete_file( $tmp );

			return new WP_Error( 'forwp_drive_docx_xml', __( 'Could not read document.xml from .docx.', '4wp-drive' ) );
		}

		return array(
			'document_xml'   => (string) $document_xml,
			'numbering_xml'  => false !== $numbering ? (string) $numbering : '',
			'tmp'            => $tmp,
		);
	}

	private static function cleanup_temp( string $tmp ): void {
		if ( '' !== $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * @param string $xml            word/document.xml contents.
	 * @param string $numbering_xml  word/numbering.xml contents (optional).
	 */
	public static function document_xml_to_html( string $xml, string $numbering_xml = '' ): string {
		$dom = self::load_xml( $xml );
		if ( ! $dom ) {
			return '';
		}

		$xpath         = self::xpath( $dom );
		$num_formats   = self::parse_numbering_formats( $numbering_xml );
		$paragraphs    = $xpath->query( '//w:body/w:p' );
		$html_parts    = array();
		$open_lists    = array();

		if ( false === $paragraphs ) {
			return '';
		}

		foreach ( $paragraphs as $paragraph ) {
			if ( ! $paragraph instanceof \DOMElement ) {
				continue;
			}

			$num_id = self::paragraph_num_id( $paragraph, $xpath );
			$ilvl   = self::paragraph_ilvl( $paragraph, $xpath );

			if ( null !== $num_id ) {
				$list_type = $num_formats[ $num_id ] ?? 'bullet';
				$html_parts = array_merge( $html_parts, self::sync_list_stack( $open_lists, $ilvl, $list_type ) );
				$html_parts[] = '<li>' . self::runs_to_html( $paragraph, $xpath ) . '</li>';
				continue;
			}

			if ( ! empty( $open_lists ) ) {
				$html_parts   = array_merge( $html_parts, self::close_all_lists( $open_lists ) );
				$open_lists   = array();
			}

			$tag   = self::paragraph_tag( $paragraph, $xpath );
			$inner = self::runs_to_html( $paragraph, $xpath );
			if ( '' === trim( wp_strip_all_tags( $inner ) ) && '' === trim( $inner ) ) {
				$html_parts[] = '<p></p>';
				continue;
			}

			$html_parts[] = '<' . $tag . '>' . $inner . '</' . $tag . '>';
		}

		if ( ! empty( $open_lists ) ) {
			$html_parts = array_merge( $html_parts, self::close_all_lists( $open_lists ) );
		}

		return implode( '', $html_parts );
	}

	/**
	 * @param string $xml word/document.xml contents.
	 */
	public static function document_xml_to_plain_text( string $xml ): string {
		$dom = self::load_xml( $xml );
		if ( ! $dom ) {
			return '';
		}

		$xpath      = self::xpath( $dom );
		$paragraphs = $xpath->query( '//w:body/w:p' );
		if ( false === $paragraphs || 0 === $paragraphs->length ) {
			return '';
		}

		$lines = array();

		foreach ( $paragraphs as $paragraph ) {
			if ( ! $paragraph instanceof \DOMElement ) {
				continue;
			}

			$lines[] = wp_strip_all_tags( self::runs_to_html( $paragraph, $xpath ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return array<int, string> numId => bullet|decimal
	 */
	private static function parse_numbering_formats( string $numbering_xml ): array {
		if ( '' === trim( $numbering_xml ) ) {
			return array();
		}

		$dom = self::load_xml( $numbering_xml );
		if ( ! $dom ) {
			return array();
		}

		$xpath = self::xpath( $dom );
		$abstract_formats = array();

		$abstract_nums = $xpath->query( '//w:abstractNum' );
		if ( false !== $abstract_nums ) {
			foreach ( $abstract_nums as $abstract ) {
				if ( ! $abstract instanceof \DOMElement ) {
					continue;
				}

				$abstract_id = $abstract->getAttributeNS( self::W_NS, 'abstractNumId' );
				if ( '' === $abstract_id ) {
					$abstract_id = $abstract->getAttribute( 'w:abstractNumId' );
				}

				$lvl = $xpath->query( './/w:lvl[@w:ilvl="0"]/w:numFmt/@w:val', $abstract );
				if ( false === $lvl || 0 === $lvl->length ) {
					$lvl = $xpath->query( './/w:lvl/w:numFmt/@w:val', $abstract );
				}

				$fmt = ( false !== $lvl && $lvl->length ) ? (string) $lvl->item( 0 )->nodeValue : 'bullet';
				if ( '' !== $abstract_id ) {
					$abstract_formats[ $abstract_id ] = ( 'decimal' === $fmt || 'lowerLetter' === $fmt || 'upperLetter' === $fmt ) ? 'decimal' : 'bullet';
				}
			}
		}

		$map  = array();
		$nums = $xpath->query( '//w:num' );
		if ( false === $nums ) {
			return $map;
		}

		foreach ( $nums as $num ) {
			if ( ! $num instanceof \DOMElement ) {
				continue;
			}

			$num_id = $num->getAttributeNS( self::W_NS, 'numId' );
			if ( '' === $num_id ) {
				$num_id = $num->getAttribute( 'w:numId' );
			}

			$abstract = $xpath->query( './/w:abstractNumId/@w:val', $num );
			if ( false === $abstract || ! $abstract->length ) {
				continue;
			}

			$abstract_id = (string) $abstract->item( 0 )->nodeValue;
			if ( '' !== $num_id && isset( $abstract_formats[ $abstract_id ] ) ) {
				$map[ (int) $num_id ] = $abstract_formats[ $abstract_id ];
			}
		}

		return $map;
	}

	/**
	 * @return string[] HTML fragments to append (open/close list tags).
	 */
	private static function sync_list_stack( array &$open_lists, int $ilvl, string $list_type ): array {
		$out = array();

		while ( count( $open_lists ) > $ilvl + 1 ) {
			$closing = array_pop( $open_lists );
			$out[]   = '</' . $closing . '>';
		}

		if ( count( $open_lists ) === $ilvl + 1 ) {
			$current = $open_lists[ $ilvl ] ?? null;
			if ( $current !== $list_type ) {
				$out[] = '</' . array_pop( $open_lists ) . '>';
				$open_lists[ $ilvl ] = $list_type;
				$out[] = '<' . $list_type . '>';
			}

			return $out;
		}

		while ( count( $open_lists ) <= $ilvl ) {
			$open_lists[] = $list_type;
			$out[]        = '<' . $list_type . '>';
		}

		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function close_all_lists( array $open_lists ): array {
		$out = array();
		while ( ! empty( $open_lists ) ) {
			$out[] = '</' . array_pop( $open_lists ) . '>';
		}

		return $out;
	}

	private static function paragraph_num_id( \DOMElement $paragraph, \DOMXPath $xpath ): ?int {
		$nodes = $xpath->query( './/w:pPr/w:numPr/w:numId/@w:val', $paragraph );
		if ( false === $nodes || ! $nodes->length ) {
			return null;
		}

		return (int) $nodes->item( 0 )->nodeValue;
	}

	private static function paragraph_ilvl( \DOMElement $paragraph, \DOMXPath $xpath ): int {
		$nodes = $xpath->query( './/w:pPr/w:numPr/w:ilvl/@w:val', $paragraph );
		if ( false === $nodes || ! $nodes->length ) {
			return 0;
		}

		return (int) $nodes->item( 0 )->nodeValue;
	}

	private static function paragraph_tag( \DOMElement $paragraph, \DOMXPath $xpath ): string {
		$pstyle = $xpath->query( './/w:pPr/w:pStyle/@w:val', $paragraph );
		if ( false !== $pstyle && $pstyle->length ) {
			$style = (string) $pstyle->item( 0 )->nodeValue;
			if ( preg_match( '/heading\s*(\d)/i', $style, $m ) ) {
				return 'h' . max( 1, min( 6, (int) $m[1] ) );
			}
			if ( preg_match( '/heading(\d)/i', $style, $m ) ) {
				return 'h' . max( 1, min( 6, (int) $m[1] ) );
			}
			if ( stripos( $style, 'title' ) !== false ) {
				return 'h1';
			}
		}

		$outline = $xpath->query( './/w:pPr/w:outlineLvl/@w:val', $paragraph );
		if ( false !== $outline && $outline->length ) {
			$level = (int) $outline->item( 0 )->nodeValue;

			return 'h' . max( 1, min( 6, $level + 1 ) );
		}

		return 'p';
	}

	private static function runs_to_html( \DOMElement $paragraph, \DOMXPath $xpath ): string {
		$html = '';

		foreach ( $paragraph->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$name = $child->localName ?: $child->nodeName;
			if ( 'r' === $name ) {
				$html .= self::run_to_html( $child, $xpath );
			}
		}

		return $html;
	}

	private static function run_to_html( \DOMElement $run, \DOMXPath $xpath ): string {
		$text = '';

		foreach ( $run->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$name = $child->localName ?: $child->nodeName;
			if ( 't' === $name ) {
				$text .= htmlspecialchars( (string) $child->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			} elseif ( 'tab' === $name ) {
				$text .= ' ';
			} elseif ( 'br' === $name || 'cr' === $name ) {
				$text .= '<br />';
			}
		}

		if ( '' === $text ) {
			return '';
		}

		$bold      = $xpath->query( './/w:rPr/w:b', $run );
		$italic    = $xpath->query( './/w:rPr/w:i', $run );
		$underline = $xpath->query( './/w:rPr/w:u', $run );

		if ( false !== $bold && $bold->length ) {
			$text = '<strong>' . $text . '</strong>';
		}
		if ( false !== $italic && $italic->length ) {
			$text = '<em>' . $text . '</em>';
		}
		if ( false !== $underline && $underline->length ) {
			$val = $underline->item( 0 );
			if ( $val instanceof \DOMAttr && 'none' !== $val->value ) {
				$text = '<u>' . $text . '</u>';
			}
		}

		return $text;
	}

	private static function load_xml( string $xml ): ?\DOMDocument {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadXML( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	private static function xpath( \DOMDocument $dom ): \DOMXPath {
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::W_NS );

		return $xpath;
	}
}
