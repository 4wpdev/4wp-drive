<?php
/**
 * Parse front-matter template from Google Doc export text.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Parse;

use ForWP\Drive\Import\Google_Doc_Content;

defined( 'ABSPATH' ) || exit;

/**
 * Line-based metadata + body separator (======).
 */
final class Template_Parser {

	/**
	 * @var Template_Config
	 */
	private $config;

	/**
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $fields = null;

	public function __construct( ?Template_Config $config = null ) {
		$this->config = $config ?? new Template_Config();
	}

	/**
	 * Parse exported plain text or HTML stripped to text for header lines.
	 *
	 * Before `======`: plain text only, matched to the template map (no Doc styling in values).
	 * After `======`: post body; HTML exports keep headings/lists/bold via {@see Google_Doc_Content::prepare()}.
	 *
	 * @param string $raw Export body (plain text preferred; HTML stripped when needed).
	 * @return array<string, mixed> Parsed title, slug, body, taxonomies, legacy category/tags.
	 */
	public function parse( string $raw ): array {
		if ( $this->is_html( $raw ) ) {
			$html_split = Google_Doc_Content::split_export_at_separator( $raw );
			if ( null !== $html_split ) {
				$header_plain = $this->html_to_plain_text(
					'<html><body>' . $html_split['header_html'] . '</body></html>'
				);
				$body_plain = $this->html_to_plain_text(
					'<html><body>' . $html_split['body_html'] . '</body></html>'
				);

				$meta = $this->parse_header( $header_plain );
				$body = $body_plain;
				list( $meta, $body ) = $this->apply_first_line_title_fallback( $meta, $body );

				$body_html = trim(
					Google_Doc_Content::prepare(
						$html_split['body_html'],
						Google_Doc_Content::get_embedded_style_css( $raw )
					)
				);
				if ( '' === $this->visible_text( $body_html ) && '' !== trim( $body ) ) {
					$body_html = wpautop( esc_html( $body ) );
				}

				return $this->assemble_parse_result( $meta, $body, $body_html );
			}
		}

		$text   = $this->normalize_text( $raw );
		$split  = $this->split_header_body( $text );
		$header = $split['header'];
		$body   = $split['body'];

		$meta = $this->parse_header( $header );
		list( $meta, $body ) = $this->apply_first_line_title_fallback( $meta, $body );

		$body_html = $this->is_html( $raw ) ? $this->extract_body_html( $raw ) : wpautop( esc_html( $body ) );
		if ( '' === $this->visible_text( $body_html ) && '' !== trim( $body ) ) {
			$body_html = wpautop( esc_html( $body ) );
		}

		return $this->assemble_parse_result( $meta, $body, $body_html );
	}

	/**
	 * @param array{title: string, slug: string, date: string, author: string, taxonomies: array<string, string|array<int, string>>, meta: array<string, string>} $meta Parsed header.
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	private function apply_first_line_title_fallback( array $meta, string $body ): array {
		if ( '' === $meta['title'] && '' !== $body ) {
			$lines      = explode( "\n", $body, 2 );
			$first_line = isset( $lines[0] ) ? trim( $lines[0] ) : '';
			if ( '' !== $first_line && false === strpos( $first_line, ':' ) ) {
				$meta['title'] = $first_line;
				$body          = isset( $lines[1] ) ? trim( $lines[1] ) : '';
			}
		}

		return array( $meta, $body );
	}

	/**
	 * @param array{title: string, slug: string, date: string, taxonomies: array<string, string|array<int, string>>, meta: array<string, string>} $meta Parsed header.
	 * @return array<string, mixed>
	 */
	private function assemble_parse_result( array $meta, string $body, string $body_html ): array {
		$category = '';
		$tags     = array();
		if ( isset( $meta['taxonomies']['category'] ) ) {
			$category = is_array( $meta['taxonomies']['category'] )
				? (string) ( $meta['taxonomies']['category'][0] ?? '' )
				: (string) $meta['taxonomies']['category'];
		}
		if ( isset( $meta['taxonomies']['post_tag'] ) && is_array( $meta['taxonomies']['post_tag'] ) ) {
			$tags = $meta['taxonomies']['post_tag'];
		}

		$slug = $this->resolve_slug( (string) $meta['slug'], (string) $meta['title'] );

		return array(
			'title'      => $meta['title'],
			'slug'       => $slug,
			'date'       => $meta['date'],
			'author'     => $meta['author'],
			'category'   => $category,
			'tags'       => $tags,
			'taxonomies' => $meta['taxonomies'],
			'meta'       => $meta['meta'],
			'body'       => $body,
			'body_html'  => $body_html,
		);
	}

	/**
	 * Use post slug from title when Slug/Alias is omitted in the document.
	 */
	private function resolve_slug( string $slug, string $title ): string {
		$slug = sanitize_title( $slug );
		if ( '' !== $slug ) {
			return $slug;
		}

		return '' !== trim( $title ) ? sanitize_title( $title ) : '';
	}

	/**
	 * @return array{header: string, body: string}
	 */
	private function split_header_body( string $text ): array {
		$pattern = Template_Separator::multiline_split_pattern();
		$parts   = preg_split( $pattern, $text, 2 );
		$header  = trim( $parts[0] ?? '' );
		$body    = trim( $parts[1] ?? '' );

		if ( '' !== $body || preg_match( $pattern, $text ) ) {
			return array(
				'header' => $header,
				'body'   => $body,
			);
		}

		$collapsed = $this->split_collapsed_header_body( $text );
		if ( null !== $collapsed ) {
			return $collapsed;
		}

		return array(
			'header' => '',
			'body'   => $text,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_fields(): array {
		if ( null === $this->fields ) {
			$this->fields = $this->config->get_fields();
		}

		return $this->fields;
	}

	/**
	 * @return array{title: string, slug: string, date: string, author: string, taxonomies: array<string, string|array<int, string>>, meta: array<string, string>}
	 */
	private function parse_header( string $header ): array {
		$title       = '';
		$slug        = '';
		$date        = '';
		$author      = '';
		$taxonomies  = array();
		$meta        = array();
		$label_index = $this->build_label_index();

		foreach ( $this->header_lines( $header ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || Template_Separator::is_mark_line( $line ) || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $raw_key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$key = strtolower( $raw_key );

			if ( ! isset( $label_index[ $key ] ) ) {
				continue;
			}

			$field = $label_index[ $key ];

			if ( 'core' === ( $field['type'] ?? '' ) ) {
				if ( 'title' === ( $field['field'] ?? '' ) ) {
					$title = $value;
				} elseif ( 'slug' === ( $field['field'] ?? '' ) ) {
					$slug = sanitize_title( $value );
				} elseif ( 'date' === ( $field['field'] ?? '' ) ) {
					$date = $value;
				} elseif ( 'author' === ( $field['field'] ?? '' ) ) {
					$author = $value;
				}
				continue;
			}

			if ( 'meta' === ( $field['type'] ?? '' ) ) {
				$meta_key = (string) ( $field['meta_key'] ?? '' );
				if ( '' !== $meta_key ) {
					$meta[ $meta_key ] = $value;
				}
				continue;
			}

			$taxonomy = (string) ( $field['taxonomy'] ?? '' );
			if ( '' === $taxonomy ) {
				continue;
			}

			if ( ! empty( $field['multi'] ) ) {
				$taxonomies[ $taxonomy ] = array_values(
					array_filter( array_map( 'trim', explode( ',', $value ) ) )
				);
			} else {
				$taxonomies[ $taxonomy ] = $value;
			}
		}

		return array(
			'title'      => $title,
			'slug'       => $slug,
			'date'       => $date,
			'author'     => $author,
			'taxonomies' => $taxonomies,
			'meta'       => $meta,
		);
	}

	/**
	 * Split header into lines (supports single-line HTML collapse).
	 *
	 * @return array<int, string>
	 */
	private function header_lines( string $header ): array {
		if ( false !== strpos( $header, "\n" ) ) {
			return preg_split( '/\r?\n/', $header ) ?: array();
		}

		return $this->split_collapsed_header_lines( $header );
	}

	/**
	 * When Google Docs HTML strips to one line: Title: xSlug: y...
	 *
	 * @return array<int, string>
	 */
	private function split_collapsed_header_lines( string $header ): array {
		$labels = array();
		foreach ( $this->get_fields() as $field ) {
			$label = trim( (string) ( $field['label'] ?? '' ) );
			if ( '' !== $label ) {
				$labels[] = preg_quote( $label, '/' );
			}
		}

		if ( empty( $labels ) ) {
			return array( $header );
		}

		usort(
			$labels,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		$pattern = '/(?=(?:' . implode( '|', $labels ) . '):)/i';
		$chunks  = preg_split( $pattern, $header, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $chunks ) ? $chunks : array( $header );
	}

	/**
	 * Split "metaMARKbody" when the separator has no surrounding newlines.
	 *
	 * @return array{header: string, body: string}|null
	 */
	private function split_collapsed_header_body( string $text ): ?array {
		if ( ! preg_match( Template_Separator::collapsed_split_pattern(), $text, $m ) ) {
			return null;
		}

		$header = trim( $m[1] );
		$body   = trim( $m[3] );

		if ( '' === $header || '' === $body ) {
			return null;
		}

		return array(
			'header' => $header,
			'body'   => $body,
		);
	}

	/**
	 * Map lowercase label => field definition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function build_label_index(): array {
		$index = array();
		foreach ( $this->get_fields() as $field ) {
			$label = strtolower( trim( (string) ( $field['label'] ?? '' ) ) );
			if ( '' !== $label ) {
				$index[ $label ] = $field;
			}
		}

		if ( isset( $index['slug'] ) ) {
			$index['alias'] = $index['slug'];
		}

		return $index;
	}

	/**
	 * Convert Google Docs HTML export to plain text with line breaks.
	 */
	private function normalize_text( string $raw ): string {
		if ( $this->is_html( $raw ) ) {
			$raw = $this->html_to_plain_text( $raw );
		}

		return trim( preg_replace( "/\r\n|\r/", "\n", $raw ) );
	}

	/**
	 * Preserve paragraph boundaries from Google Docs HTML.
	 */
	private function html_to_plain_text( string $html ): string {
		$html = preg_replace( '/\r\n|\r/', "\n", $html );
		$html = preg_replace( '/<hr\b[^>]*\/?>/i', Template_Separator::html_hr_to_mark(), $html );
		$html = preg_replace( '/<\/(p|div|h[1-6]|li|tr|table)>/i', "\n", $html );
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( preg_replace( '/[ \t]+/', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param string $raw Raw export.
	 */
	private function is_html( string $raw ): bool {
		return (bool) preg_match( '/<html|<body|<p[\s>]/i', $raw );
	}

	/**
	 * Keep HTML body after front-matter separator when export is HTML.
	 */
	private function extract_body_html( string $raw ): string {
		$converted = Google_Doc_Content::prepare_from_export( $raw );
		if ( '' !== $this->visible_text( $converted ) ) {
			return $converted;
		}

		$plain = $this->html_to_plain_text( $raw );
		$split = $this->split_header_body( $plain );
		if ( '' !== trim( $split['body'] ) ) {
			return wpautop( esc_html( $split['body'] ) );
		}

		return wpautop( esc_html( $plain ) );
	}

	/**
	 * Visible text length after stripping tags (detect empty HTML).
	 */
	private function visible_text( string $html ): string {
		return trim( wp_strip_all_tags( $html ) );
	}
}
