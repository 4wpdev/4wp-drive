<?php
/**
 * Convert semantic HTML into Gutenberg block markup.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Maps headings, lists, quotes, code, and paragraphs to core blocks.
 */
final class Gutenberg_Content {

	/**
	 * Convert body HTML to serialized blocks, preserving existing `<!-- wp: -->` regions.
	 *
	 * Do not run wp_kses_post() on the return value — it strips block comments.
	 *
	 * @param string $html Body HTML (may already contain recipe block markup).
	 */
	public static function from_mixed( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		if ( function_exists( 'has_blocks' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_block' ) && has_blocks( $html ) ) {
			$parsed = parse_blocks( $html );
			$out    = array();

			foreach ( $parsed as $block ) {
				$name = $block['blockName'] ?? null;
				if ( empty( $name ) ) {
					$inner = trim( (string) ( $block['innerHTML'] ?? '' ) );
					if ( '' === $inner ) {
						continue;
					}
					$converted = self::from_html( $inner );
					if ( '' !== $converted ) {
						$out[] = $converted;
					}
					continue;
				}

				$out[] = serialize_block( $block );
			}

			return implode( "\n\n", $out );
		}

		return self::from_html( $html );
	}

	/**
	 * Convert an HTML fragment (no block comments) to serialized Gutenberg blocks.
	 *
	 * @param string $html Sanitized HTML fragment.
	 */
	public static function from_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) || ! function_exists( 'serialize_blocks' ) ) {
			return '';
		}

		$dom = self::load_html_dom( $html );
		if ( ! $dom ) {
			return '';
		}

		$root = self::resolve_root( $dom );
		if ( ! $root ) {
			return '';
		}

		$blocks = self::blocks_from_children( $root );
		if ( empty( $blocks ) ) {
			return '';
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function blocks_from_children( \DOMNode $parent ): array {
		$blocks = array();

		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$text = trim( (string) $child->textContent );
				if ( '' !== $text ) {
					$blocks[] = self::paragraph_block( esc_html( $text ) );
				}
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$mapped = self::blocks_from_element( $child );
			if ( ! empty( $mapped ) ) {
				$blocks = array_merge( $blocks, $mapped );
			}
		}

		return $blocks;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function blocks_from_element( \DOMElement $element ): array {
		$tag = strtolower( $element->tagName );

		if ( in_array( $tag, array( 'div', 'span', 'section', 'article', 'main', 'body' ), true ) ) {
			$inner = self::blocks_from_children( $element );
			if ( ! empty( $inner ) ) {
				return $inner;
			}

			$text = trim( (string) $element->textContent );
			if ( '' === $text ) {
				return array();
			}

			return array( self::paragraph_block( self::inner_html( $element ) ) );
		}

		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			return array( self::heading_block( (int) $m[1], self::inner_html( $element ) ) );
		}

		if ( 'p' === $tag ) {
			if ( self::is_empty_element( $element ) ) {
				return array();
			}

			if ( self::element_is_image_marker_only( $element ) ) {
				return array( self::passthrough_html( '<p>' . self::inner_html( $element ) . '</p>' ) );
			}

			if ( self::element_is_code_only( $element ) ) {
				return array( self::code_block( (string) $element->textContent ) );
			}

			return array( self::paragraph_block( self::inner_html( $element ) ) );
		}

		if ( 'pre' === $tag ) {
			return array( self::code_block( self::code_text_from_pre( $element ) ) );
		}

		if ( 'ul' === $tag || 'ol' === $tag ) {
			return array( self::list_block( $element ) );
		}

		if ( 'blockquote' === $tag ) {
			return array( self::quote_block( $element ) );
		}

		if ( 'hr' === $tag ) {
			return array( self::separator_block() );
		}

		if ( self::is_empty_element( $element ) ) {
			return array();
		}

		return array( self::paragraph_block( self::inner_html( $element ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function heading_block( int $level, string $inner ): array {
		$level = max( 1, min( 6, $level ) );
		$inner = wp_kses_post( $inner );
		$html  = '<h' . $level . ' class="wp-block-heading">' . $inner . '</h' . $level . '>';
		$attrs = 2 === $level ? array() : array( 'level' => $level );

		return self::make_block( 'core/heading', $attrs, $html );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function paragraph_block( string $inner ): array {
		$inner = wp_kses_post( trim( $inner ) );

		return self::make_block( 'core/paragraph', array(), '<p>' . $inner . '</p>' );
	}

	/**
	 * Leave raw HTML for a later recipe (e.g. [image:] → core/image).
	 *
	 * @return array<string, mixed>
	 */
	private static function passthrough_html( string $html ): array {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function code_block( string $text ): array {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\r\n", "\n", $text );
		$text = rtrim( str_replace( "\r", "\n", $text ), "\n" );
		$html = '<pre class="wp-block-code"><code>' . esc_html( $text ) . '</code></pre>';

		return self::make_block( 'core/code', array(), $html );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function list_block( \DOMElement $list ): array {
		$ordered = 'ol' === strtolower( $list->tagName );
		$tag     = $ordered ? 'ol' : 'ul';
		$attrs   = $ordered ? array( 'ordered' => true ) : array();
		$items   = array();

		foreach ( $list->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$child_tag = strtolower( $child->tagName );
			if ( 'li' === $child_tag ) {
				$items[] = self::list_item_block( $child );
			}
		}

		$open          = '<' . $tag . ' class="wp-block-list">';
		$close         = '</' . $tag . '>';
		$inner_content = array( $open );
		$inner_html    = $open;
		foreach ( $items as $index => $item ) {
			if ( $index > 0 ) {
				$inner_content[] = '';
			}
			$inner_content[] = null;
			$inner_html     .= $item['innerHTML'];
		}
		$inner_content[] = $close;
		$inner_html     .= $close;

		return self::make_block( 'core/list', $attrs, $inner_html, $items, $inner_content );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function list_item_block( \DOMElement $li ): array {
		$nested = array();
		$parts  = array();

		foreach ( $li->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->tagName ), array( 'ul', 'ol' ), true ) ) {
				$nested[] = self::list_block( $child );
				continue;
			}

			$parts[] = $li->ownerDocument ? $li->ownerDocument->saveHTML( $child ) : '';
		}

		$inner = wp_kses_post( implode( '', $parts ) );

		if ( empty( $nested ) ) {
			return self::make_block( 'core/list-item', array(), '<li>' . $inner . '</li>' );
		}

		$inner_content = array( '<li>' . $inner );
		foreach ( $nested as $index => $block ) {
			if ( $index > 0 ) {
				$inner_content[] = '';
			}
			$inner_content[] = null;
		}
		$inner_content[] = '</li>';

		return self::make_block( 'core/list-item', array(), '<li>' . $inner . '</li>', $nested, $inner_content );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function quote_block( \DOMElement $quote ): array {
		$inner_blocks = self::blocks_from_children( $quote );
		if ( empty( $inner_blocks ) ) {
			$text = trim( (string) $quote->textContent );
			if ( '' !== $text ) {
				$inner_blocks[] = self::paragraph_block( esc_html( $text ) );
			}
		}

		$open          = '<blockquote class="wp-block-quote">';
		$close         = '</blockquote>';
		$inner_content = array( $open );
		$inner_html    = $open;
		foreach ( $inner_blocks as $index => $block ) {
			if ( $index > 0 ) {
				$inner_content[] = '';
			}
			$inner_content[] = null;
			$inner_html     .= $block['innerHTML'];
		}
		$inner_content[] = $close;
		$inner_html     .= $close;

		return self::make_block( 'core/quote', array(), $inner_html, $inner_blocks, $inner_content );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function separator_block(): array {
		return self::make_block(
			'core/separator',
			array(),
			'<hr class="wp-block-separator has-alpha-channel-opacity"/>'
		);
	}

	/**
	 * @param array<string, mixed>             $attrs         Attributes.
	 * @param array<int, array<string, mixed>> $inner_blocks  Nested blocks.
	 * @param array<int, string|null>|null     $inner_content Chunks.
	 * @return array<string, mixed>
	 */
	private static function make_block( string $name, array $attrs, string $inner_html, array $inner_blocks = array(), $inner_content = null ): array {
		if ( null === $inner_content ) {
			$inner_content = array( $inner_html );
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		);
	}

	private static function inner_html( \DOMElement $element ): string {
		$dom = $element->ownerDocument;
		if ( ! $dom ) {
			return '';
		}

		$html = '';
		foreach ( $element->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		return trim( $html );
	}

	private static function code_text_from_pre( \DOMElement $pre ): string {
		$code = $pre->getElementsByTagName( 'code' )->item( 0 );
		if ( $code instanceof \DOMElement ) {
			return (string) $code->textContent;
		}

		return (string) $pre->textContent;
	}

	private static function element_is_image_marker_only( \DOMElement $element ): bool {
		$text = trim( (string) $element->textContent );

		return (bool) preg_match( '/^\[image:\s*[^\]]+\]$/iu', $text );
	}

	private static function element_is_code_only( \DOMElement $element ): bool {
		$code_nodes = 0;
		$other_text = '';

		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$other_text .= $child->textContent;
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			if ( 'code' === strtolower( $child->tagName ) ) {
				++$code_nodes;
				continue;
			}

			$other_text .= $child->textContent;
		}

		return $code_nodes > 0 && '' === trim( $other_text );
	}

	private static function is_empty_element( \DOMElement $element ): bool {
		$text = trim( (string) $element->textContent );
		if ( '' !== $text ) {
			return false;
		}

		return 0 === $element->getElementsByTagName( 'img' )->length;
	}

	/**
	 * @return \DOMDocument|null
	 */
	private static function load_html_dom( string $html ) {
		$wrapped = '<div data-forwp-drive="blocks">' . $html . '</div>';
		$dom     = new \DOMDocument();
		$prev    = libxml_use_internal_errors( true );
		$loaded  = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $wrapped,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $loaded ? $dom : null;
	}

	/**
	 * @return \DOMElement|null
	 */
	private static function resolve_root( \DOMDocument $dom ) {
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//*[@data-forwp-drive="blocks"]' );
		if ( $nodes && $nodes->length > 0 && $nodes->item( 0 ) instanceof \DOMElement ) {
			return $nodes->item( 0 );
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		return $body instanceof \DOMElement ? $body : null;
	}
}
