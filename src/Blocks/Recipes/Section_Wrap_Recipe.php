<?php
/**
 * Wrap matched H2 sections using family Drive capabilities.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks\Recipes;

use ForWP\Drive\Blocks\Block_Recipe_Interface;
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

		$nodes = Html_Body_Parser::nodes( $body_html );
		if ( empty( $nodes ) ) {
			return $body_html;
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
			return $body_html;
		}

		return implode( "\n\n", $output );
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
		$class = str_replace( '/', '-', $block );
		$inner = trim( $inner );

		return sprintf(
			"<!-- wp:%1\$s -->\n<section class=\"%2\$s\">\n%3\$s\n</section>\n<!-- /wp:%1\$s -->",
			$block,
			esc_attr( $class ),
			$inner
		);
	}
}
