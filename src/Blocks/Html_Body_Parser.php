<?php
/**
 * Split HTML body fragments into top-level nodes.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight DOM helper for recipe matching.
 */
final class Html_Body_Parser {

	/**
	 * @return array<int, array{tag: string, level: int, html: string, text: string}>
	 */
	public static function nodes( string $html ): array {
		$html = trim( $html );
		if ( '' === $html ) {
			return array();
		}

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array();
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return array();
		}

		$nodes = array();
		foreach ( $body->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag   = strtolower( $child->tagName );
			$level = 0;
			if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
				$level = (int) $matches[1];
			}

			$nodes[] = array(
				'tag'   => $tag,
				'level' => $level,
				'html'  => (string) $document->saveHTML( $child ),
				'text'  => trim( (string) $child->textContent ),
			);
		}

		return $nodes;
	}
}
