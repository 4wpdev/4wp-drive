<?php
/**
 * Build Gutenberg block markup for import recipes.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes FAQ accordion trees using core block comments.
 */
final class Block_Markup_Builder {

	private const ACCORDION_ITEM_TEMPLATE = '<!-- wp:accordion-item -->
<div class="wp-block-accordion-item">
<!-- wp:accordion-heading -->
<h3 class="wp-block-accordion-heading">
<button type="button" aria-expanded="false" class="wp-block-accordion-heading__toggle">
<span class="wp-block-accordion-heading__toggle-title">%1$s</span>
<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>
</button>
</h3>
<!-- /wp:accordion-heading -->

<!-- wp:accordion-panel -->
<div class="wp-block-accordion-panel">
%2$s
</div>
<!-- /wp:accordion-panel -->
</div>
<!-- /wp:accordion-item -->';

	/**
	 * @param array<int, array{question: string, answer_html: string}> $items   Accordion items.
	 * @param string                                                  $template Block template id (4wp-faq, core-accordion).
	 * @param array<string, mixed>                                     $attrs    Optional forwp/faq attrs.
	 */
	public function build_accordion_section( array $items, string $template = '4wp-faq', array $attrs = array() ): string {
		if ( empty( $items ) ) {
			return '';
		}

		$items_markup = '';
		foreach ( $items as $item ) {
			$question = esc_html( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			if ( '' === $question ) {
				continue;
			}

			$answer_html = (string) ( $item['answer_html'] ?? '' );
			$panel       = $this->html_to_inner_blocks_markup( $answer_html );
			if ( '' === trim( wp_strip_all_tags( $panel ) ) ) {
				$panel = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
			}

			$items_markup .= sprintf( self::ACCORDION_ITEM_TEMPLATE, $question, $panel );
		}

		if ( '' === $items_markup ) {
			return '';
		}

		$accordion = sprintf(
			'<!-- wp:accordion -->
<div class="wp-block-accordion">%s</div>
<!-- /wp:accordion -->',
			$items_markup
		);

		if ( Block_Template_Registry::TEMPLATE_CORE_ACCORDION === sanitize_key( $template ) ) {
			return $accordion;
		}

		$attrs_part = '';
		if ( ! empty( $attrs ) ) {
			$encoded = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $encoded ) && '{}' !== $encoded ) {
				$attrs_part = ' ' . $encoded;
			}
		}

		return sprintf(
			'<!-- wp:forwp/faq%s -->%s<!-- /wp:forwp/faq -->',
			$attrs_part,
			$accordion
		);
	}

	/**
	 * Convert answer HTML into block comments for accordion panels.
	 */
	public function html_to_inner_blocks_markup( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$nodes = Html_Body_Parser::nodes( $html );
		if ( empty( $nodes ) ) {
			return sprintf(
				'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
				wp_kses_post( $html )
			);
		}

		$parts = array();
		foreach ( $nodes as $node ) {
			$tag = (string) $node['tag'];
			if ( 'p' === $tag ) {
				$parts[] = sprintf(
					'<!-- wp:paragraph -->%s<!-- /wp:paragraph -->',
					wp_kses_post( (string) $node['html'] )
				);
				continue;
			}

			if ( 'ul' === $tag || 'ol' === $tag ) {
				$parts[] = sprintf(
					'<!-- wp:list -->%s<!-- /wp:list -->',
					wp_kses_post( (string) $node['html'] )
				);
				continue;
			}

			if ( 'h' === substr( $tag, 0, 1 ) && strlen( $tag ) === 2 ) {
				$level   = (int) substr( $tag, 1 );
				$parts[] = sprintf(
					'<!-- wp:heading {"level":%1$d} -->%2$s<!-- /wp:heading -->',
					$level,
					wp_kses_post( (string) $node['html'] )
				);
				continue;
			}

			$text = trim( (string) $node['text'] );
			if ( '' === $text ) {
				continue;
			}

			$parts[] = sprintf(
				'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
				esc_html( $text )
			);
		}

		return implode( "\n\n", $parts );
	}
}
