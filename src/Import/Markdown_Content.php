<?php
/**
 * Convert Markdown files into HTML for the template parser.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Import;

use ForWP\Drive\Parse\Template_Separator;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight Markdown → HTML (headings, lists, tables, emphasis, links, code, quotes).
 */
final class Markdown_Content {

	/**
	 * Wrap Markdown as an HTML document the template parser can split.
	 *
	 * @param string $markdown Raw file contents.
	 */
	public static function to_html_document( string $markdown ): string {
		$text = self::normalize( $markdown );
		if ( '' === $text ) {
			return '<html><body></body></html>';
		}

		$split  = self::split_front_matter( $text );
		$header = $split['header'];
		$body   = $split['body'];

		$parts = array();

		if ( '' !== $header ) {
			foreach ( preg_split( '/\r?\n/', $header ) ?: array() as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts[] = '<p>' . esc_html( $line ) . '</p>';
			}
			$parts[] = '<hr />';
		}

		$parts[] = self::convert_body( '' !== $body ? $body : ( '' === $header ? $text : '' ) );

		return '<html><body data-forwp-md="1">' . implode( "\n", array_filter( $parts ) ) . '</body></html>';
	}

	/**
	 * @return array{header: string, body: string}
	 */
	private static function split_front_matter( string $text ): array {
		$pattern = Template_Separator::multiline_split_pattern();
		$parts   = preg_split( $pattern, $text, 2 );

		if ( is_array( $parts ) && isset( $parts[1] ) ) {
			return array(
				'header' => trim( (string) $parts[0] ),
				'body'   => trim( (string) $parts[1] ),
			);
		}

		return array(
			'header' => '',
			'body'   => $text,
		);
	}

	/**
	 * @param string $body Markdown body.
	 */
	private static function convert_body( string $body ): string {
		$body = trim( $body );
		if ( '' === $body ) {
			return '';
		}

		$lines   = preg_split( '/\r?\n/', $body ) ?: array();
		$html    = array();
		$para    = array();
		$list    = null;
		$in_code = false;
		$code    = array();
		$quote   = array();
		$i       = 0;
		$count   = count( $lines );

		$flush_para = static function () use ( &$para, &$html ): void {
			if ( empty( $para ) ) {
				return;
			}
			$html[] = '<p>' . self::inline( implode( ' ', $para ) ) . '</p>';
			$para   = array();
		};

		$flush_list = static function () use ( &$list, &$html ): void {
			if ( ! is_array( $list ) ) {
				return;
			}
			$tag    = $list['tag'];
			$html[] = '<' . $tag . '>' . implode( '', $list['items'] ) . '</' . $tag . '>';
			$list   = null;
		};

		$flush_quote = static function () use ( &$quote, &$html ): void {
			if ( empty( $quote ) ) {
				return;
			}
			$html[] = '<blockquote><p>' . self::inline( implode( ' ', $quote ) ) . '</p></blockquote>';
			$quote  = array();
		};

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( $in_code ) {
				if ( preg_match( '/^```/', $line ) ) {
					$html[]  = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
					$in_code = false;
					$code    = array();
					++$i;
					continue;
				}
				$code[] = $line;
				++$i;
				continue;
			}

			if ( preg_match( '/^```/', $line ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$in_code = true;
				$code    = array();
				++$i;
				continue;
			}

			$trim = rtrim( $line );

			// GFM pipe tables (must run before generic paragraph / hr handling).
			if ( self::is_table_row_line( $trim ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$table_lines = array( $trim );
				++$i;
				while ( $i < $count && self::is_table_row_line( rtrim( $lines[ $i ] ) ) ) {
					$table_lines[] = rtrim( $lines[ $i ] );
					++$i;
				}
				$built = self::build_table_html( $table_lines );
				if ( '' !== $built ) {
					$html[] = $built;
				} else {
					foreach ( $table_lines as $table_line ) {
						$html[] = '<p>' . self::inline( $table_line ) . '</p>';
					}
				}
				continue;
			}

			if ( Template_Separator::is_mark_line( $trim ) || preg_match( '/^(\*\s*){3,}$|^(-\s*){3,}$|^(_\s*){3,}$/', $trim ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$html[] = '<hr />';
				++$i;
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$level  = strlen( $m[1] );
				$html[] = '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>';
				++$i;
				continue;
			}

			if ( preg_match( '/^>\s?(.*)$/', $trim, $m ) ) {
				$flush_para();
				$flush_list();
				$quote[] = $m[1];
				++$i;
				continue;
			}

			$flush_quote();

			if ( preg_match( '/^\s*[-*+]\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				if ( ! is_array( $list ) || 'ul' !== $list['tag'] ) {
					$flush_list();
					$list = array(
						'tag'   => 'ul',
						'items' => array(),
					);
				}
				$list['items'][] = '<li>' . self::inline( $m[1] ) . '</li>';
				++$i;
				continue;
			}

			if ( preg_match( '/^\s*\d+\.\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				if ( ! is_array( $list ) || 'ol' !== $list['tag'] ) {
					$flush_list();
					$list = array(
						'tag'   => 'ol',
						'items' => array(),
					);
				}
				$list['items'][] = '<li>' . self::inline( $m[1] ) . '</li>';
				++$i;
				continue;
			}

			if ( '' === trim( $trim ) ) {
				$flush_para();
				$flush_list();
				++$i;
				continue;
			}

			$flush_list();
			$para[] = $trim;
			++$i;
		}

		if ( $in_code ) {
			$html[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
		}

		$flush_quote();
		$flush_para();
		$flush_list();

		return implode( "\n", $html );
	}

	/**
	 * Whether a line looks like a Markdown pipe-table row.
	 */
	private static function is_table_row_line( string $line ): bool {
		$line = trim( $line );
		if ( '' === $line ) {
			return false;
		}

		if ( self::is_table_separator_line( $line ) ) {
			return true;
		}

		// Require pipe-bounded rows — avoid treating prose with a lone "|" as a table.
		return (bool) preg_match( '/^\|.+\|\s*$/', $line );
	}

	/**
	 * GFM table separator: | --- | :---: | ---: |
	 */
	private static function is_table_separator_line( string $line ): bool {
		$line = trim( $line );

		return (bool) preg_match( '/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/', $line );
	}

	/**
	 * @param array<int, string> $lines Consecutive table lines.
	 */
	private static function build_table_html( array $lines ): string {
		$rows = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( self::is_table_separator_line( $line ) ) {
				$rows[] = array(
					'type'  => 'sep',
					'cells' => array(),
				);
				continue;
			}
			$cells = self::split_table_cells( $line );
			if ( empty( $cells ) ) {
				continue;
			}
			$rows[] = array(
				'type'  => 'row',
				'cells' => $cells,
			);
		}

		if ( count( $rows ) < 2 ) {
			return '';
		}

		$header = null;
		$body   = array();
		$saw_sep = false;

		foreach ( $rows as $row ) {
			if ( 'sep' === $row['type'] ) {
				$saw_sep = true;
				continue;
			}
			if ( ! $saw_sep && null === $header ) {
				$header = $row['cells'];
				continue;
			}
			$body[] = $row['cells'];
		}

		if ( null === $header ) {
			return '';
		}

		if ( ! $saw_sep && empty( $body ) ) {
			// Single header-looking row without separator — not a table.
			return '';
		}

		$html = '<table class="forwp-drive-md-table"><thead><tr>';
		foreach ( $header as $cell ) {
			$html .= '<th>' . self::inline( $cell ) . '</th>';
		}
		$html .= '</tr></thead>';

		if ( ! empty( $body ) ) {
			$html .= '<tbody>';
			foreach ( $body as $cells ) {
				$html .= '<tr>';
				foreach ( $cells as $cell ) {
					$html .= '<td>' . self::inline( $cell ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody>';
		}

		$html .= '</table>';

		return $html;
	}

	/**
	 * @return array<int, string>
	 */
	private static function split_table_cells( string $line ): array {
		$line = trim( $line );
		if ( '' !== $line && '|' === $line[0] ) {
			$line = substr( $line, 1 );
		}
		if ( '' !== $line && '|' === substr( $line, -1 ) ) {
			$line = substr( $line, 0, -1 );
		}

		$parts = explode( '|', $line );
		$cells = array();
		foreach ( $parts as $part ) {
			$cells[] = trim( $part );
		}

		return $cells;
	}

	/**
	 * Inline Markdown (code, images, links, bold, italic).
	 *
	 * @param string $text Line or paragraph.
	 */
	private static function inline( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		$pattern = '/(`[^`]+`|!\[[^\]]*\]\([^)]+\)|\[[^\]]+\]\([^)]+\)|\*\*[^*]+\*\*|__[^_]+__|\*[^*\s][^*]*\*|_[^_\s][^_]*_)/u';
		$parts   = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return esc_html( $text );
		}

		$out = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '/^`([^`]+)`$/u', $part, $m ) ) {
				$out .= '<code>' . esc_html( $m[1] ) . '</code>';
				continue;
			}

			if ( preg_match( '/^!\[([^\]]*)\]\(([^)]+)\)$/u', $part, $m ) ) {
				$src = trim( $m[2] );
				$src = (string) preg_replace( '/\s+".*"$/', '', $src );
				if ( self::is_local_image_src( $src ) ) {
					$out .= '[image:' . basename( $src ) . ']';
					continue;
				}

				$out .= '<img alt="' . esc_attr( $m[1] ) . '" src="' . esc_url( $src ) . '" />';
				continue;
			}

			if ( preg_match( '/^\[([^\]]+)\]\(([^)]+)\)$/u', $part, $m ) ) {
				$out .= '<a href="' . esc_url( $m[2] ) . '">' . esc_html( $m[1] ) . '</a>';
				continue;
			}

			if ( preg_match( '/^\*\*([^*]+)\*\*$|^__([^_]+)__$/u', $part, $m ) ) {
				$out .= '<strong>' . esc_html( $m[1] !== '' ? $m[1] : ( $m[2] ?? '' ) ) . '</strong>';
				continue;
			}

			if ( preg_match( '/^\*([^*]+)\*$|^_([^_]+)_$/u', $part, $m ) ) {
				$out .= '<em>' . esc_html( $m[1] !== '' ? $m[1] : ( $m[2] ?? '' ) ) . '</em>';
				continue;
			}

			$out .= esc_html( $part );
		}

		return $out;
	}

	/**
	 * @param string $markdown Raw markdown.
	 */
	private static function normalize( string $markdown ): string {
		$markdown = preg_replace( '/^\xEF\xBB\xBF/', '', $markdown );
		$markdown = str_replace( "\r\n", "\n", (string) $markdown );
		$markdown = str_replace( "\r", "\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * Package-relative image paths become [image:] markers for Core Image import.
	 */
	private static function is_local_image_src( string $src ): bool {
		$src = trim( $src );
		if ( '' === $src || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $src ) ) {
			return false;
		}

		$ext = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );

		return in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif' ), true );
	}
}
