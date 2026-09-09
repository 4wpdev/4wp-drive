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
 * Lightweight Markdown → HTML (headings, lists, emphasis, links, code, quotes).
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

		return '<html><body>' . implode( "\n", array_filter( $parts ) ) . '</body></html>';
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

		foreach ( $lines as $line ) {
			if ( $in_code ) {
				if ( preg_match( '/^```/', $line ) ) {
					$html[]  = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
					$in_code = false;
					$code    = array();
					continue;
				}
				$code[] = $line;
				continue;
			}

			if ( preg_match( '/^```/', $line ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$in_code = true;
				$code    = array();
				continue;
			}

			$trim = rtrim( $line );

			if ( Template_Separator::is_mark_line( $trim ) || preg_match( '/^(\*\s*){3,}$|^(-\s*){3,}$|^(_\s*){3,}$/', $trim ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$html[] = '<hr />';
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trim, $m ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$level  = strlen( $m[1] );
				$html[] = '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>';
				continue;
			}

			if ( preg_match( '/^>\s?(.*)$/', $trim, $m ) ) {
				$flush_para();
				$flush_list();
				$quote[] = $m[1];
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
				continue;
			}

			if ( '' === trim( $trim ) ) {
				$flush_para();
				$flush_list();
				continue;
			}

			$flush_list();
			$para[] = $trim;
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
