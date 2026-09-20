<?php
/**
 * FAQ section recipe: H2 FAQ heading + H3 or bold-paragraph Q/A → forwp/faq accordion.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks\Recipes;

use ForWP\Drive\Blocks\Block_Markup_Builder;
use ForWP\Drive\Blocks\Block_Recipe_Interface;
use ForWP\Drive\Blocks\Block_Template_Registry;
use ForWP\Drive\Blocks\Html_Body_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Converts editorial FAQ HTML patterns into 4WP FAQ blocks.
 */
final class Faq_Accordion_Recipe implements Block_Recipe_Interface {

	/**
	 * @param array<string, mixed> $config Recipe config.
	 */
	public function requirements_met( array $config ): bool {
		$required = isset( $config['requires_plugins'] ) && is_array( $config['requires_plugins'] )
			? $config['requires_plugins']
			: array( '4wp-faq/4wp-faq.php' );

		foreach ( $required as $plugin ) {
			$plugin = (string) $plugin;
			if ( '' === $plugin ) {
				continue;
			}

			if ( function_exists( 'is_plugin_active' ) && ! is_plugin_active( $plugin ) ) {
				return false;
			}

			if ( ! function_exists( 'is_plugin_active' ) && ! defined( 'FORWP_FAQ_VERSION' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string               $body_html Source HTML.
	 * @param array<string, mixed> $config    Recipe config.
	 */
	public function transform( string $body_html, array $config ): string {
		$nodes = Html_Body_Parser::nodes( $body_html );
		if ( empty( $nodes ) ) {
			return $body_html;
		}

		$section_level   = (int) ( $config['section_heading']['level'] ?? 2 );
		$item_level      = (int) ( $config['item_heading_level'] ?? 3 );
		$keep_heading    = ! empty( $config['keep_section_heading'] );
		$section_pattern = (string) ( $config['section_heading']['match'] ?? '^(FAQ|Frequently Asked Questions)$' );

		$output      = array();
		$count       = count( $nodes );
		$index       = 0;
		$transformed = false;

		while ( $index < $count ) {
			$node = $nodes[ $index ];
			if ( $this->is_section_start( $node, $section_level, $section_pattern ) ) {
				$transformed = true;
				if ( $keep_heading ) {
					$output[] = (string) $node['html'];
				}

				++$index;
				$lead = array();
				while ( $index < $count ) {
					$peek = $nodes[ $index ];
					if ( $this->is_section_end( $peek, $section_level, $section_pattern ) ) {
						break;
					}
					if ( $this->is_item_heading( $peek, $item_level ) ) {
						break;
					}
					$lead[] = (string) $peek['html'];
					++$index;
				}

				$items = array();
				if ( $index < $count && ! $this->is_section_end( $nodes[ $index ], $section_level, $section_pattern ) ) {
					$items = $this->collect_items( $nodes, $index, $count, $item_level, $section_level, $section_pattern );
				}

				if ( ! empty( $items ) ) {
					foreach ( $lead as $lead_html ) {
						$output[] = $lead_html;
					}
					$builder  = new Block_Markup_Builder();
					$template = sanitize_key( (string) ( $config['template'] ?? Block_Template_Registry::TEMPLATE_4WP_FAQ ) );
					$faq_html = $builder->build_accordion_section( $items, $template );
					if ( '' !== $faq_html ) {
						$output[] = $faq_html;
					}
				} else {
					foreach ( $lead as $lead_html ) {
						$output[] = $lead_html;
					}
				}

				continue;
			}

			$output[] = (string) $node['html'];
			++$index;
		}

		if ( ! $transformed ) {
			return $body_html;
		}

		return implode( "\n\n", array_filter( $output ) );
	}

	/**
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 * @param int                                                        $section_level Section heading level.
	 * @param string                                                     $pattern       Regex (without delimiters).
	 */
	private function is_section_start( array $node, int $section_level, string $pattern ): bool {
		if ( (int) $node['level'] !== $section_level ) {
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
	 * @param array<int, array{tag: string, level: int, html: string, text: string}> $nodes Nodes.
	 * @param int                                                                    $index Current index (by ref).
	 * @param int                                                                    $count Node count.
	 * @param int                                                                    $item_level Item heading level.
	 * @param int                                                                    $section_level Section heading level.
	 * @param string                                                                 $section_pattern Section match regex.
	 * @return array<int, array{question: string, answer_html: string}>
	 */
	private function collect_items(
		array $nodes,
		int &$index,
		int $count,
		int $item_level,
		int $section_level,
		string $section_pattern
	): array {
		$items = array();

		while ( $index < $count ) {
			$node = $nodes[ $index ];
			if ( $this->is_section_end( $node, $section_level, $section_pattern ) ) {
				break;
			}

			if ( ! $this->is_item_heading( $node, $item_level ) ) {
				++$index;
				continue;
			}

			$extracted = $this->extract_item_question( $node );
			$question  = $extracted['question'];
			++$index;

			$answer_parts = array();
			if ( '' !== $extracted['answer_html'] ) {
				$answer_parts[] = $extracted['answer_html'];
			}
			while ( $index < $count ) {
				$next = $nodes[ $index ];
				if ( $this->is_section_end( $next, $section_level, $section_pattern ) ) {
					break;
				}
				if ( $this->is_item_heading( $next, $item_level ) ) {
					break;
				}

				$answer_parts[] = (string) $next['html'];
				++$index;
			}

			if ( '' === $question ) {
				continue;
			}

			$items[] = array(
				'question'    => $question,
				'answer_html' => implode( '', $answer_parts ),
			);
		}

		return $items;
	}

	/**
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 * @param int                                                        $section_level Section heading level.
	 * @param string                                                     $section_pattern Section match regex.
	 */
	private function is_section_end( array $node, int $section_level, string $section_pattern ): bool {
		if ( (int) $node['level'] >= $section_level && (int) $node['level'] > 0 ) {
			$text = trim( (string) $node['text'] );
			if ( '' === $text ) {
				return true;
			}

			$regex = '/' . str_replace( '/', '\/', $section_pattern ) . '/iu';
			if ( (int) $node['level'] === $section_level && 1 !== preg_match( $regex, $text ) ) {
				return true;
			}

			if ( (int) $node['level'] < $section_level ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 * @param int                                                        $item_level Expected heading level.
	 */
	private function is_item_heading( array $node, int $item_level ): bool {
		if ( (int) $node['level'] === $item_level ) {
			return true;
		}

		return $this->is_bold_question_paragraph( $node );
	}

	/**
	 * Markdown FAQ often uses **question** as a paragraph, not H3.
	 *
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 */
	private function is_bold_question_paragraph( array $node ): bool {
		if ( 'p' !== (string) $node['tag'] ) {
			return false;
		}

		$html = trim( (string) $node['html'] );
		if ( '' === $html ) {
			return false;
		}

		return 1 === preg_match(
			'/^<p(?:\s[^>]*)?>\s*<(strong|b)(?:\s[^>]*)?>[\s\S]+?<\/\1>/iu',
			$html
		);
	}

	/**
	 * @param array{tag: string, level: int, html: string, text: string} $node Node.
	 * @return array{question: string, answer_html: string}
	 */
	private function extract_item_question( array $node ): array {
		if ( (int) $node['level'] > 0 ) {
			return array(
				'question'    => trim( (string) $node['text'] ),
				'answer_html' => '',
			);
		}

		$html = trim( (string) $node['html'] );
		if ( preg_match(
			'/^<p(?:\s[^>]*)?>\s*<(strong|b)(?:\s[^>]*)?>([\s\S]*?)<\/\1>\s*([\s\S]*?)<\/p>$/iu',
			$html,
			$matches
		) ) {
			$question = trim( wp_strip_all_tags( (string) $matches[2] ) );
			$rest     = trim( (string) $matches[3] );
			$rest     = preg_replace( '/^<(?:br\s*\/?|br)>/i', '', $rest );
			$rest     = is_string( $rest ) ? trim( $rest ) : '';
			$answer   = '' === $rest ? '' : '<p>' . $rest . '</p>';

			return array(
				'question'    => $question,
				'answer_html' => $answer,
			);
		}

		return array(
			'question'    => trim( (string) $node['text'] ),
			'answer_html' => '',
		);
	}
}
