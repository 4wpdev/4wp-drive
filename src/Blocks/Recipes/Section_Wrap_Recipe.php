<?php
/**
 * Wrap matched H2 sections using family Drive capabilities.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks\Recipes;

use ForWP\Drive\Blocks\Block_Markup_Builder;
use ForWP\Drive\Blocks\Block_Recipe_Interface;
use ForWP\Drive\Blocks\Gutenberg_Content;
use ForWP\Drive\Blocks\Html_Body_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Generic section wrap: H2 match → Gutenberg wrapper until the next H2.
 */
final class Section_Wrap_Recipe implements Block_Recipe_Interface {

	public function requirements_met( array $config ): bool {
		return true;
	}

	public function transform( string $body_html, array $config ): string {
		$block   = trim( (string) ( $config['block'] ?? '' ) );
		$pattern = (string) ( $config['section_heading']['match'] ?? '' );
		if ( '' === $block || '' === $pattern ) {
			return $body_html;
		}

		if ( false !== strpos( $body_html, 'wp:' . $block ) ) {
			return $body_html;
		}

		$held      = array();
		$body_html = $this->hold_other_wraps( $body_html, $held, $block );

		$nodes = Html_Body_Parser::nodes( $body_html );
		if ( empty( $nodes ) ) {
			return $this->restore_other_wraps( $body_html, $held );
		}

		$output = array();
		$count  = count( $nodes );
		$index  = 0;
		$did    = false;

		while ( $index < $count ) {
			$node = $nodes[ $index ];
			if ( ! $this->match_heading( $node, $pattern ) ) {
				$output[] = (string) $node['html'];
				++$index;
				continue;
			}

			$end = $index + 1;
			while ( $end < $count && ! $this->is_section_break( $nodes[ $end ] ) ) {
				++$end;
			}

			$inner = array();
			for ( $i = $index; $i < $end; $i++ ) {
				$inner[] = (string) $nodes[ $i ]['html'];
			}

			$output[] = $this->wrap_block( $block, implode( "\n", $inner ) );
			$did      = true;
			$index    = $end;
		}

		if ( ! $did ) {
			return $this->restore_other_wraps( $body_html, $held );
		}

		return $this->restore_other_wraps( implode( "\n\n", $output ), $held );
	}

	/**
	 * @param array<string, string> $held Placeholder map.
	 */
	private function hold_other_wraps( string $html, array &$held, string $current_block ): string {
		$names = array(
			'forwp/faq',
			'forwp/diagram',
			'forwp-seo/techarticle-goal',
			'forwp-seo/techarticle-context',
			'forwp-seo/techarticle-issues',
			'forwp-seo/techarticle-steps',
		);

		foreach ( $names as $name ) {
			if ( $name === $current_block ) {
				continue;
			}
			$quoted = preg_quote( $name, '/' );
			// Self-closing (diagram) — DOM drops HTML comments.
			$html = (string) preg_replace_callback(
				'/<!--\s+wp:' . $quoted . '\b[\s\S]*?\/-->/u',
				static function ( array $matches ) use ( &$held ): string {
					$key          = 'FORWPDRIVESECHOLD' . count( $held ) . 'Z';
					$held[ $key ] = $matches[0];

					return '<p data-forwp-drive-hold="1">' . $key . '</p>';
				},
				$html
			);
			$html   = (string) preg_replace_callback(
				'/<!--\s+wp:' . $quoted . '(?:\s+\{[\s\S]*?\})?\s+-->[\s\S]*?<!--\s+\/wp:' . $quoted . '\s+-->/u',
				static function ( array $matches ) use ( &$held ): string {
					$key          = 'FORWPDRIVESECHOLD' . count( $held ) . 'Z';
					$held[ $key ] = $matches[0];

					return '<p data-forwp-drive-hold="1">' . $key . '</p>';
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * @param array<string, string> $held Placeholder map.
	 */
	private function restore_other_wraps( string $html, array $held ): string {
		if ( empty( $held ) ) {
			return $html;
		}

		foreach ( $held as $key => $original ) {
			$html = str_replace( '<p data-forwp-drive-hold="1">' . $key . '</p>', $original, $html );
			$html = str_replace( '<p>' . $key . '</p>', $original, $html );
			$html = str_replace( $key, $original, $html );
		}

		return $html;
	}

	/**
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 */
	private function match_heading( array $node, string $pattern ): bool {
		if ( 2 !== (int) $node['level'] ) {
			return false;
		}

		if ( Html_Body_Parser::heading_skips_wrap( $node ) ) {
			return false;
		}

		$text = trim( (string) $node['text'] );
		if ( '' === $text ) {
			return false;
		}

		$regex = '/' . str_replace( '/', '\/', $pattern ) . '/iu';

		return 1 === preg_match( $regex, $text );
	}

	/**
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 */
	private function is_section_break( array $node ): bool {
		$level = (int) $node['level'];

		return $level > 0 && $level <= 2;
	}

	private function wrap_block( string $block, string $inner ): string {
		$inner = trim( $inner );
		$blocks = Gutenberg_Content::from_html( $inner );
		if ( '' === $blocks ) {
			$blocks = ( new Block_Markup_Builder() )->html_to_inner_blocks_markup( $inner );
		}

		return sprintf(
			"<!-- wp:%1\$s -->\n%2\$s\n<!-- /wp:%1\$s -->",
			$block,
			$blocks
		);
	}
}
