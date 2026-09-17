<?php
/**
 * Prepare Google Docs HTML export for WordPress post_content.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Parse\Template_Separator;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Google Docs export HTML to semantic WordPress-friendly HTML.
 */
final class Google_Doc_Content {

	/**
	 * CSS blocks from `<style>` tags in a full export (for passing into `prepare()` on a body fragment).
	 */
	public static function get_embedded_style_css( string $html ): string {
		return self::extract_styles_from_html_string( $html );
	}

	/**
	 * Split a full Google Docs HTML export at the front-matter separator (paragraph with ====== / ---, or hr).
	 *
	 * When present, only `body_html` should receive heading/list formatting; header is plain text for template mapping.
	 *
	 * @return array{header_html: string, body_html: string}|null null if no separator node found in the DOM.
	 */
	public static function split_export_at_separator( string $html ): ?array {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		$html = trim( $html );
		if ( '' === $html ) {
			return null;
		}

		$dom = self::load_html_dom( $html );
		if ( ! $dom ) {
			return null;
		}

		$blocks = self::export_content_blocks( $dom );
		if ( empty( $blocks ) ) {
			return null;
		}

		$header_parts = array();
		$body_parts   = array();
		$past_sep     = false;

		foreach ( $blocks as $block ) {
			if ( ! $past_sep ) {
				if ( self::node_is_separator( $block ) ) {
					$past_sep = true;
					continue;
				}
				$header_parts[] = $dom->saveHTML( $block );
				continue;
			}

			$body_parts[] = $dom->saveHTML( $block );
		}

		if ( ! $past_sep ) {
			return null;
		}

		return array(
			'header_html' => implode( '', $header_parts ),
			'body_html'   => implode( '', $body_parts ),
		);
	}

	/**
	 * Process a full Google Docs HTML export (preferred entry point).
	 *
	 * @param string $html Full export HTML.
	 */
	public static function prepare_from_export( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		if ( class_exists( 'DOMDocument' ) ) {
			$converted = self::prepare_export_with_dom( $html );
			if ( '' !== $converted ) {
				return $converted;
			}
		}

		$fragment = self::extract_body_fragment_regex( $html );
		if ( null !== $fragment ) {
			return self::prepare( $fragment, self::extract_styles_from_html_string( $html ) );
		}

		return self::prepare( $html, self::extract_styles_from_html_string( $html ) );
	}

	/**
	 * Sanitize HTML body for post_content.
	 *
	 * @param string $html      HTML fragment or full export document.
	 * @param string $style_css Optional CSS from document <head> when $html is body-only.
	 */
	public static function prepare( string $html, string $style_css = '' ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		if ( class_exists( 'DOMDocument' ) ) {
			$converted = self::prepare_with_dom( $html, $style_css );
			if ( '' !== $converted ) {
				return $converted;
			}
		}

		return trim( wp_kses_post( self::normalize_google_markup( $html ) ) );
	}

	/**
	 * Load full export, strip front matter, convert headings.
	 */
	private static function prepare_export_with_dom( string $html ): string {
		$dom = self::load_html_dom( $html );
		if ( ! $dom ) {
			return '';
		}

		$style_map = self::parse_style_map( $dom );
		$root      = self::resolve_content_root( $dom );
		if ( ! $root ) {
			return '';
		}

		self::remove_front_matter( $root );
		self::transform_content( $root, $style_map );

		return self::serialize_root_children( $dom, $root );
	}

	/**
	 * Parse fragment or document with optional injected CSS.
	 */
	private static function prepare_with_dom( string $html, string $style_css = '' ): string {
		$wrapped = ! preg_match( '/<\s*(?:html|body)\b/i', $html );
		if ( $wrapped ) {
			$html = '<div data-forwp-drive="fragment">' . $html . '</div>';
		}

		$dom = self::load_html_dom( $html );
		if ( ! $dom ) {
			return '';
		}

		$style_map = self::parse_style_map( $dom );
		if ( empty( $style_map ) && '' !== trim( $style_css ) ) {
			$style_map = self::parse_style_map_from_css( $style_css );
		}

		$root = self::resolve_content_root( $dom );
		if ( ! $root ) {
			return '';
		}

		if ( $wrapped ) {
			$xpath = new \DOMXPath( $dom );
			$nodes = $xpath->query( '//*[@data-forwp-drive="fragment"]' );
			if ( $nodes && $nodes->length > 0 && $nodes->item( 0 ) instanceof \DOMElement ) {
				$root = $nodes->item( 0 );
			}
		}

		self::transform_content( $root, $style_map );

		return self::serialize_root_children( $dom, $root );
	}

	/**
	 * @param \DOMElement                             $root      Content root.
	 * @param array<string, array<string, int|float>> $style_map Class styles.
	 */
	private static function transform_content( \DOMElement $root, array $style_map ): void {
		self::normalize_existing_headings( $root );
		self::apply_inline_formatting( $root, $style_map );
		self::convert_code_blocks( $root, $style_map );
		self::convert_paragraph_headings( $root, $style_map );
		self::unwrap_table_cell_paragraphs( $root );
	}

	/**
	 * Ensure h1–h6 from Google export keep sensible levels (class heading-1, etc.).
	 */
	private static function normalize_existing_headings( \DOMElement $root ): void {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$nodes = array();
			foreach ( $root->getElementsByTagName( $tag ) as $node ) {
				if ( $node instanceof \DOMElement ) {
					$nodes[] = $node;
				}
			}
			foreach ( $nodes as $heading ) {
				$level = self::heading_level_from_class( $heading->getAttribute( 'class' ) );
				if ( null !== $level && $level !== (int) substr( $tag, 1 ) ) {
					$replacement = $heading->ownerDocument->createElement( 'h' . $level );
					while ( $heading->firstChild ) {
						$replacement->appendChild( $heading->firstChild );
					}
					$heading->parentNode->replaceChild( $replacement, $heading );
				}
			}
		}
	}

	/**
	 * Convert styled spans to strong/em before wp_kses strips inline styles.
	 *
	 * @param \DOMElement                             $root      Content root.
	 * @param array<string, array<string, int|float>> $style_map Class styles.
	 */
	private static function apply_inline_formatting( \DOMElement $root, array $style_map ): void {
		$spans = array();
		foreach ( $root->getElementsByTagName( 'span' ) as $span ) {
			if ( $span instanceof \DOMElement ) {
				$spans[] = $span;
			}
		}

		foreach ( $spans as $span ) {
			$styles = self::resolve_element_styles( $span, $style_map );
			$bold      = (int) $styles['font_weight'] >= 700;
			$italic    = ! empty( $styles['font_style_italic'] );
			$underline = ! empty( $styles['font_underline'] );

			if ( ! $bold && ! $italic && ! $underline ) {
				continue;
			}

			$doc = $span->ownerDocument;
			if ( ! $doc || ! $span->parentNode ) {
				continue;
			}

			$inner = $doc->createDocumentFragment();
			while ( $span->firstChild ) {
				$inner->appendChild( $span->firstChild );
			}

			$wrapped = $inner;
			if ( $underline ) {
				$u = $doc->createElement( 'u' );
				$u->appendChild( $wrapped );
				$wrapped = $u;
			}
			if ( $italic ) {
				$em = $doc->createElement( 'em' );
				$em->appendChild( $wrapped );
				$wrapped = $em;
			}
			if ( $bold ) {
				$strong = $doc->createElement( 'strong' );
				$strong->appendChild( $wrapped );
				$wrapped = $strong;
			}

			$span->parentNode->replaceChild( $wrapped, $span );
		}
	}

	/**
	 * @return \DOMDocument|null
	 */
	private static function load_html_dom( string $html ) {
		$dom    = new \DOMDocument();
		$prev   = libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $loaded ? $dom : null;
	}

	/**
	 * @return \DOMElement|null
	 */
	private static function resolve_content_root( \DOMDocument $dom ) {
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( $body instanceof \DOMElement ) {
			return $body;
		}

		$root = $dom->documentElement;
		return $root instanceof \DOMElement ? $root : null;
	}

	/**
	 * Block-level nodes from a Google Docs HTML export in document order (includes nested wrappers).
	 *
	 * @return \DOMElement[]
	 */
	private static function export_content_blocks( \DOMDocument $dom ): array {
		$xpath = new \DOMXPath( $dom );
		$query = '//body//*[self::p or self::hr or self::ul or self::ol or self::table or self::blockquote or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]'
			. '[not(ancestor::table)][not(ancestor::ul)][not(ancestor::ol)][not(ancestor::blockquote)]';
		$nodes = $xpath->query( $query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return array();
		}

		$blocks = array();
		foreach ( $nodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$blocks[] = $node;
			}
		}

		return $blocks;
	}

	/**
	 * Remove metadata block and separator from the content root.
	 */
	private static function remove_front_matter( \DOMElement $root ): void {
		$dom = $root->ownerDocument;
		if ( ! $dom ) {
			return;
		}

		foreach ( self::export_content_blocks( $dom ) as $block ) {
			if ( self::node_is_separator( $block ) ) {
				if ( $block->parentNode ) {
					$block->parentNode->removeChild( $block );
				}
				break;
			}

			if ( $block->parentNode ) {
				$block->parentNode->removeChild( $block );
			}
		}
	}

	/**
	 * @param \DOMNode $node DOM node.
	 */
	private static function node_is_separator( \DOMNode $node ): bool {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		$tag = strtolower( $node->tagName );
		if ( 'hr' === $tag ) {
			return true;
		}

		if ( 'p' !== $tag && 'div' !== $tag ) {
			return false;
		}

		return Template_Separator::is_mark_line( (string) $node->textContent );
	}

	/**
	 * @return string|null HTML fragment after separator.
	 */
	private static function extract_body_fragment_regex( string $raw ): ?string {
		$patterns   = array( '/<hr\b[^>]*\/?>\s*(.*)$/is' );
		$min_equals = Template_Separator::MIN_EQUALS_RUN;
		$patterns[] = '/<p[^>]*>.*?={' . $min_equals . ',}\s*(.+?)<\/p>/is';
		$patterns[] = '/<p[^>]*>.*?={' . $min_equals . ',}.*?<\/p>\s*(.*)$/is';
		$patterns[] = '/={' . $min_equals . ',}\s*<\/p>\s*(.*)$/is';

		foreach ( Template_Separator::all_marks() as $mark ) {
			$quoted     = preg_quote( $mark, '/' );
			$patterns[] = '/<p[^>]*>.*?' . $quoted . '\s*(.+?)<\/p>/is';
			$patterns[] = '/<p[^>]*>.*?' . $quoted . '.*?<\/p>\s*(.*)$/is';
			$patterns[] = '/' . $quoted . '\s*<\/p>\s*(.*)$/is';
		}

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $raw, $match ) && '' !== trim( $match[1] ) ) {
				return trim( $match[1] );
			}
		}

		return null;
	}

	/**
	 * @return string
	 */
	private static function extract_styles_from_html_string( string $raw ): string {
		if ( ! preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $raw, $matches ) ) {
			return '';
		}

		return implode( "\n", $matches[1] );
	}

	/**
	 * @return string
	 */
	private static function serialize_root_children( \DOMDocument $dom, \DOMElement $root ): string {
		$inner = '';
		foreach ( $root->childNodes as $child ) {
			$inner .= $dom->saveHTML( $child );
		}

		$inner = self::normalize_google_markup( $inner );

		return trim( wp_kses_post( $inner ) );
	}

	/**
	 * @return array<string, array{font_size: float, font_weight: int, font_style_italic?: bool}>
	 */
	private static function parse_style_map( \DOMDocument $dom ): array {
		$css = '';
		foreach ( $dom->getElementsByTagName( 'style' ) as $style_node ) {
			$css .= $style_node->textContent;
		}

		return self::parse_style_map_from_css( $css );
	}

	/**
	 * @return array<string, array{font_size: float, font_weight: int, font_style_italic?: bool}>
	 */
	private static function parse_style_map_from_css( string $css ): array {
		$map = array();
		if ( '' === trim( $css ) ) {
			return $map;
		}

		if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)\s*\{([^}]+)\}/', $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$class         = (string) $match[1];
				$rules         = (string) $match[2];
				$map[ $class ] = self::parse_css_rules( $rules, $map[ $class ] ?? array() );
			}
		}

		return $map;
	}

	/**
	 * @param string               $rules CSS declarations.
	 * @param array<string, mixed> $base  Existing values.
	 * @return array{font_size: float, font_weight: int, font_style_italic?: bool}
	 */
	private static function parse_css_rules( string $rules, array $base = array() ): array {
		$font_size   = isset( $base['font_size'] ) ? (float) $base['font_size'] : 11.0;
		$font_weight = isset( $base['font_weight'] ) ? (int) $base['font_weight'] : 400;
		$italic      = ! empty( $base['font_style_italic'] );
		$underline   = ! empty( $base['font_underline'] );
		$font_family = isset( $base['font_family'] ) ? (string) $base['font_family'] : '';

		if ( preg_match( '/font-size\s*:\s*([\d.]+)\s*pt/i', $rules, $m ) ) {
			$font_size = (float) $m[1];
		} elseif ( preg_match( '/font-size\s*:\s*([\d.]+)\s*px/i', $rules, $m ) ) {
			$font_size = (float) $m[1] * 0.75;
		}
		if ( preg_match( '/font-weight\s*:\s*(bold|[6-9]00)/i', $rules, $m ) ) {
			$font_weight = 700;
		}
		if ( preg_match( '/font-style\s*:\s*italic/i', $rules, $m ) ) {
			$italic = true;
		}
		if ( preg_match( '/text-decoration\s*:[^;]*underline/i', $rules ) ) {
			$underline = true;
		}
		if ( preg_match( '/font-family\s*:\s*([^;]+)/i', $rules, $m ) ) {
			$font_family = trim( $m[1] );
		}

		$parsed = array(
			'font_size'         => $font_size,
			'font_weight'       => $font_weight,
			'font_style_italic' => $italic,
			'font_underline'    => $underline,
		);
		if ( '' !== $font_family ) {
			$parsed['font_family'] = $font_family;
		}

		return $parsed;
	}

	/**
	 * Courier / Consolas / <pre> paragraphs → <pre><code> (Docs exports one line per <p>).
	 *
	 * @param \DOMElement                         $root      Root.
	 * @param array<string, array<string, mixed>> $style_map Styles.
	 */
	private static function convert_code_blocks( \DOMElement $root, array $style_map ): void {
		self::convert_code_in_parent( $root, $style_map );
		$divs = array();
		foreach ( $root->getElementsByTagName( 'div' ) as $div ) {
			if ( $div instanceof \DOMElement ) {
				$divs[] = $div;
			}
		}
		foreach ( $divs as $div ) {
			self::convert_code_in_parent( $div, $style_map );
		}
	}

	/**
	 * @param \DOMElement                         $parent    Parent.
	 * @param array<string, array<string, mixed>> $style_map Styles.
	 */
	private static function convert_code_in_parent( \DOMElement $parent, array $style_map ): void {
		$tag = strtolower( $parent->tagName );
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), true ) ) {
			return;
		}

		$doc = $parent->ownerDocument;
		if ( ! $doc ) {
			return;
		}

		$children = array();
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$children[] = $child;
			}
		}

		$run   = array();
		$flush = static function () use ( &$run, $parent, $doc ): void {
			if ( empty( $run ) ) {
				return;
			}
			$lines = array();
			foreach ( $run as $el ) {
				$lines[] = rtrim( str_replace( "\xc2\xa0", ' ', (string) $el->textContent ) );
			}
			$pre  = $doc->createElement( 'pre' );
			$code = $doc->createElement( 'code' );
			$code->appendChild( $doc->createTextNode( implode( "\n", $lines ) ) );
			$pre->appendChild( $code );
			$parent->replaceChild( $pre, $run[0] );
			$count = count( $run );
			for ( $i = 1; $i < $count; $i++ ) {
				if ( $run[ $i ]->parentNode ) {
					$run[ $i ]->parentNode->removeChild( $run[ $i ] );
				}
			}
			$run = array();
		};

		foreach ( $children as $el ) {
			if ( self::element_is_code_block( $el, $style_map ) ) {
				$run[] = $el;
				continue;
			}
			$flush();
		}
		$flush();
	}

	/**
	 * @param \DOMElement                         $element   Element.
	 * @param array<string, array<string, mixed>> $style_map Styles.
	 */
	private static function element_is_code_block( \DOMElement $element, array $style_map ): bool {
		$tag = strtolower( $element->tagName );
		if ( 'pre' === $tag ) {
			return true;
		}
		if ( ! in_array( $tag, array( 'p', 'div' ), true ) ) {
			return false;
		}
		if ( '' === trim( (string) $element->textContent ) ) {
			return false;
		}

		$family = '';
		$parsed = self::parse_css_rules( $element->getAttribute( 'style' ), array() );
		if ( ! empty( $parsed['font_family'] ) ) {
			$family = (string) $parsed['font_family'];
		}
		if ( '' === $family ) {
			$class = $element->getAttribute( 'class' );
			if ( '' !== $class ) {
				foreach ( preg_split( '/\s+/', $class ) as $class_name ) {
					if ( ! empty( $style_map[ $class_name ]['font_family'] ) ) {
						$family = (string) $style_map[ $class_name ]['font_family'];
						break;
					}
				}
			}
		}

		if ( '' === $family ) {
			foreach ( $element->getElementsByTagName( 'span' ) as $span ) {
				if ( ! $span instanceof \DOMElement ) {
					continue;
				}
				$span_parsed = self::parse_css_rules( $span->getAttribute( 'style' ), array() );
				if ( ! empty( $span_parsed['font_family'] ) ) {
					$family = (string) $span_parsed['font_family'];
					break;
				}
				$span_class = $span->getAttribute( 'class' );
				if ( '' !== $span_class ) {
					foreach ( preg_split( '/\s+/', $span_class ) as $class_name ) {
						if ( ! empty( $style_map[ $class_name ]['font_family'] ) ) {
							$family = (string) $style_map[ $class_name ]['font_family'];
							break 2;
						}
					}
				}
			}
		}

		return self::is_monospace_family( $family );
	}

	private static function is_monospace_family( string $family ): bool {
		$family = strtolower( str_replace( array( '"', "'", '&quot;' ), '', $family ) );
		if ( '' === $family ) {
			return false;
		}

		$needles = array( 'courier', 'consolas', 'menlo', 'monaco', 'inconsolata', 'source code', 'roboto mono', 'lucida console', 'monospace' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $family, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param \DOMElement                             $root      Body element.
	 * @param array<string, array<string, int|float>> $style_map Class styles.
	 */
	private static function convert_paragraph_headings( \DOMElement $root, array $style_map ): void {
		$paragraphs = array();
		foreach ( $root->getElementsByTagName( 'p' ) as $p ) {
			if ( $p instanceof \DOMElement ) {
				$paragraphs[] = $p;
			}
		}

		foreach ( $paragraphs as $p ) {
			if ( self::element_is_inside_table( $p ) ) {
				continue;
			}
			$styles = self::resolve_element_styles( $p, $style_map );
			if ( self::is_monospace_family( (string) ( $styles['font_family'] ?? '' ) ) ) {
				continue;
			}
			$class_level = self::heading_level_from_class( $p->getAttribute( 'class' ) );
			$tag         = null !== $class_level ? 'h' . $class_level : self::heading_tag_for_styles( $styles, $p );

			if ( null === $tag ) {
				continue;
			}

			$heading = $p->ownerDocument->createElement( $tag );
			while ( $p->firstChild ) {
				$heading->appendChild( $p->firstChild );
			}
			$p->parentNode->replaceChild( $heading, $p );
		}
	}

	private static function element_is_inside_table( \DOMElement $element ): bool {
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			$tag = strtolower( $parent->tagName );
			if ( in_array( $tag, array( 'table', 'td', 'th' ), true ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}

		return false;
	}

	/**
	 * Google Docs wraps every cell in <p>; core/table cells prefer inline content.
	 */
	private static function unwrap_table_cell_paragraphs( \DOMElement $root ): void {
		$cells = array();
		foreach ( array( 'td', 'th' ) as $tag ) {
			foreach ( $root->getElementsByTagName( $tag ) as $cell ) {
				if ( $cell instanceof \DOMElement ) {
					$cells[] = $cell;
				}
			}
		}

		foreach ( $cells as $cell ) {
			$paras = array();
			foreach ( $cell->childNodes as $child ) {
				if ( $child instanceof \DOMText && '' === trim( (string) $child->textContent ) ) {
					continue;
				}
				if ( $child instanceof \DOMElement && 'p' === strtolower( $child->tagName ) ) {
					$paras[] = $child;
					continue;
				}
				$paras = array();
				break;
			}
			if ( count( $paras ) !== 1 ) {
				continue;
			}

			$p = $paras[0];
			while ( $p->firstChild ) {
				$cell->insertBefore( $p->firstChild, $p );
			}
			$cell->removeChild( $p );
		}
	}

	/**
	 * @param \DOMElement                             $element   Element.
	 * @param array<string, array<string, int|float>> $style_map Class styles.
	 * @return array{font_size: float, font_weight: int, font_style_italic?: bool}
	 */
	private static function resolve_element_styles( \DOMElement $element, array $style_map ): array {
		$styles = array(
			'font_size'         => 11.0,
			'font_weight'       => 400,
			'font_style_italic' => false,
			'font_underline'    => false,
			'font_family'       => '',
		);

		$class = $element->getAttribute( 'class' );
		if ( '' !== $class ) {
			foreach ( preg_split( '/\s+/', $class ) as $class_name ) {
				if ( isset( $style_map[ $class_name ] ) ) {
					$styles = self::merge_styles( $styles, $style_map[ $class_name ] );
				}
			}
		}

		$styles = self::merge_styles( $styles, self::parse_css_rules( $element->getAttribute( 'style' ), $styles ) );

		foreach ( $element->getElementsByTagName( 'span' ) as $span ) {
			if ( ! $span instanceof \DOMElement ) {
				continue;
			}

			$span_class = $span->getAttribute( 'class' );
			if ( '' !== $span_class ) {
				foreach ( preg_split( '/\s+/', $span_class ) as $class_name ) {
					if ( isset( $style_map[ $class_name ] ) ) {
						$styles = self::merge_styles( $styles, $style_map[ $class_name ] );
					}
				}
			}

			$span_styles = self::parse_css_rules( $span->getAttribute( 'style' ), array() );
			$styles      = self::merge_styles( $styles, $span_styles );
		}

		return $styles;
	}

	/**
	 * @param array<string, mixed> $base Incoming styles.
	 * @param array<string, mixed> $add  Additional styles.
	 * @return array<string, mixed>
	 */
	private static function merge_styles( array $base, array $add ): array {
		if ( isset( $add['font_size'] ) && (float) $add['font_size'] > (float) $base['font_size'] ) {
			$base['font_size'] = (float) $add['font_size'];
		}
		if ( isset( $add['font_weight'] ) && (int) $add['font_weight'] >= 700 ) {
			$base['font_weight'] = 700;
		}
		if ( ! empty( $add['font_style_italic'] ) ) {
			$base['font_style_italic'] = true;
		}
		if ( ! empty( $add['font_underline'] ) ) {
			$base['font_underline'] = true;
		}
		if ( ! empty( $add['font_family'] ) ) {
			$base['font_family'] = (string) $add['font_family'];
		}

		return $base;
	}

	/**
	 * @param string $class Class attribute value.
	 */
	private static function heading_level_from_class( string $class ): ?int {
		if ( '' === $class ) {
			return null;
		}

		if ( preg_match( '/\b(?:heading|headings)[-_]?([1-6])\b/i', $class, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/\btitle\b/i', $class ) && ! preg_match( '/\bsubtitle\b/i', $class ) ) {
			return 1;
		}
		if ( preg_match( '/\bsubtitle\b/i', $class ) ) {
			return 2;
		}

		return null;
	}

	/**
	 * @param array{font_size: float, font_weight: int, font_style_italic?: bool} $styles Resolved styles.
	 * @param \DOMElement                                                         $element Paragraph.
	 */
	private static function heading_tag_for_styles( array $styles, \DOMElement $element ): ?string {
		$size   = (float) $styles['font_size'];
		$weight = (int) $styles['font_weight'];
		$text   = trim( preg_replace( '/\s+/u', ' ', (string) $element->textContent ) );
		$len    = strlen( $text );

		if ( $size >= 20 ) {
			return 'h1';
		}
		if ( $size >= 16 ) {
			return 'h2';
		}
		if ( $size >= 14 ) {
			return 'h3';
		}
		if ( $size >= 12 && $weight >= 700 ) {
			return 'h4';
		}

		// Bold subheadings at body size (common in editorial Google Docs).
		if ( $weight >= 700 && $len > 0 && $len <= 200 && ! preg_match( '/[.!?]\\s*$/u', $text ) ) {
			return 'h3';
		}

		return null;
	}

	/**
	 * Light cleanup before wp_kses_post (styles/ids stripped by kses).
	 */
	private static function normalize_google_markup( string $html ): string {
		$html = preg_replace( '/\s+id="[^"]*"/i', '', $html );
		$html = preg_replace( '/<b\b/i', '<strong', $html );
		$html = preg_replace( '/<\/b>/i', '</strong>', $html );
		$html = preg_replace( '/<i\b/i', '<em', $html );
		$html = preg_replace( '/<\/i>/i', '</em>', $html );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Strip Google Docs typography (Arial, 11pt, color) and unwrap empty spans.
	 *
	 * Headings, lists, strong/em stay. Theme fonts apply. Safe on Gutenberg markup.
	 *
	 * @param string $html Body HTML or block markup.
	 */
	public static function strip_presentational_markup( string $html ): string {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( function_exists( 'has_blocks' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_block' ) && has_blocks( $html ) ) {
			$out = array();
			foreach ( parse_blocks( $html ) as $block ) {
				$out[] = serialize_block( self::strip_presentational_block( $block ) );
			}

			return implode( "\n\n", $out );
		}

		return self::strip_html_fragment( $html );
	}

	/**
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private static function strip_presentational_block( array $block ): array {
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) && '' !== $block['innerHTML'] ) {
			$block['innerHTML'] = self::strip_html_fragment( $block['innerHTML'] );
		}

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $i => $chunk ) {
				if ( is_string( $chunk ) && '' !== $chunk ) {
					$block['innerContent'][ $i ] = self::strip_html_fragment( $chunk );
				}
			}
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $i => $inner ) {
				if ( is_array( $inner ) ) {
					$block['innerBlocks'][ $i ] = self::strip_presentational_block( $inner );
				}
			}
		}

		return $block;
	}

	/**
	 * @param string $html HTML fragment (no block comments required).
	 */
	private static function strip_html_fragment( string $html ): string {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		if ( ! preg_match( '/<(?:span|p|h[1-6]|li|td|th|div|table)\b/i', $html ) ) {
			return $html;
		}

		if ( ! preg_match( '/\s(?:style|class|id)\s*=/i', $html ) && ! preg_match( '/<span\b/i', $html ) ) {
			return $html;
		}

		$lead  = '';
		$trail = '';
		if ( preg_match( '/^(\s*)(.*?)(\s*)$/s', $html, $m ) ) {
			$lead  = $m[1];
			$html  = $m[2];
			$trail = $m[3];
		}

		$comments = array();
		$html     = preg_replace_callback(
			'/<!--.*?-->/s',
			static function ( $match ) use ( &$comments ) {
				$token              = 'FORWPDRIVECMT' . count( $comments ) . 'Z';
				$comments[ $token ] = $match[0];

				return $token;
			},
			$html
		);
		if ( ! is_string( $html ) ) {
			return $lead . $trail;
		}

		$wrapped = '<div data-forwp-drive-strip="1">' . $html . '</div>';
		$dom     = self::load_html_dom( $wrapped );
		if ( ! $dom ) {
			if ( ! empty( $comments ) ) {
				$html = str_replace( array_keys( $comments ), array_values( $comments ), $html );
			}

			return $lead . $html . $trail;
		}

		$xpath = new \DOMXPath( $dom );
		$root  = $xpath->query( '//*[@data-forwp-drive-strip="1"]' );
		$node  = ( $root && $root->length > 0 ) ? $root->item( 0 ) : null;
		if ( ! $node instanceof \DOMElement ) {
			if ( ! empty( $comments ) ) {
				$html = str_replace( array_keys( $comments ), array_values( $comments ), $html );
			}

			return $lead . $html . $trail;
		}

		$elements = array();
		foreach ( $xpath->query( './/*', $node ) as $el ) {
			if ( $el instanceof \DOMElement ) {
				$elements[] = $el;
			}
		}

		foreach ( $elements as $el ) {
			if ( $el->hasAttribute( 'style' ) ) {
				$el->removeAttribute( 'style' );
			}
			if ( $el->hasAttribute( 'id' ) ) {
				$el->removeAttribute( 'id' );
			}
			self::scrub_google_class( $el );
		}

		$spans = array();
		foreach ( $node->getElementsByTagName( 'span' ) as $span ) {
			if ( $span instanceof \DOMElement ) {
				$spans[] = $span;
			}
		}
		$spans = array_reverse( $spans );

		foreach ( $spans as $span ) {
			if ( ! $span->parentNode ) {
				continue;
			}
			if ( $span->hasAttributes() ) {
				continue;
			}
			while ( $span->firstChild ) {
				$span->parentNode->insertBefore( $span->firstChild, $span );
			}
			$span->parentNode->removeChild( $span );
		}

		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $dom->saveHTML( $child );
		}

		if ( ! empty( $comments ) ) {
			$inner = str_replace( array_keys( $comments ), array_values( $comments ), $inner );
		}

		return $lead . $inner . $trail;
	}

	/**
	 * Drop Google Docs classes; keep WordPress block classes.
	 */
	private static function scrub_google_class( \DOMElement $el ): void {
		$class = trim( $el->getAttribute( 'class' ) );
		if ( '' === $class ) {
			return;
		}

		$kept = array();
		foreach ( preg_split( '/\s+/', $class ) as $name ) {
			if ( 0 === strpos( $name, 'wp-' ) || 0 === strpos( $name, 'has-' ) ) {
				$kept[] = $name;
			}
		}

		if ( empty( $kept ) ) {
			$el->removeAttribute( 'class' );
			return;
		}

		$el->setAttribute( 'class', implode( ' ', $kept ) );
	}

	/**
	 * Whether stored HTML already has structural markup worth keeping.
	 */
	public static function has_structural_markup( string $html ): bool {
		return (bool) preg_match( '/<(h[1-6]|ul|ol|li|strong|em)\b/i', $html );
	}
}
