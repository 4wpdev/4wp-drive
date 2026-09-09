<?php
/**
 * Core Image recipe: [image:filename] markers → core/image blocks.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks\Recipes;

use ForWP\Drive\Blocks\Block_Markup_Builder;
use ForWP\Drive\Blocks\Block_Recipe_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces editorial image placeholders with Gutenberg core/image markup.
 *
 * Markers: [image:filename.jpeg] (case-insensitive). Without an attachment map
 * the body is left unchanged (preview shows the marker text).
 */
final class Core_Image_Recipe implements Block_Recipe_Interface {

	/**
	 * @param array<string, mixed> $config Recipe config.
	 */
	public function requirements_met( array $config ): bool {
		return true;
	}

	/**
	 * @param string               $body_html Source HTML.
	 * @param array<string, mixed> $config    Recipe config (attachments map optional).
	 */
	public function transform( string $body_html, array $config ): string {
		$attachments = isset( $config['attachments'] ) && is_array( $config['attachments'] )
			? $config['attachments']
			: array();

		if ( empty( $attachments ) ) {
			return $body_html;
		}

		$builder = new Block_Markup_Builder();

		$replace_token = static function ( array $matches ) use ( $attachments, $builder ): string {
			$block = self::block_for_token( (string) $matches[1], $attachments, $builder );
			return null !== $block ? $block : $matches[0];
		};

		// Gutenberg paragraph that is only a pin marker — replace the whole block.
		$body_html = (string) preg_replace_callback(
			'/<!--\s+wp:paragraph(?:\s+\{[^}]*\})?\s+-->\s*<p\b[^>]*>\s*(?:<span\b[^>]*>)?\s*\[image:\s*([^\]]+?)\]\s*(?:<\/span>)?\s*<\/p>\s*<!--\s+\/wp:paragraph\s+-->/iu',
			$replace_token,
			$body_html
		);

		// Whole paragraph wrappers from Google Docs export / pin UI.
		$body_html = (string) preg_replace_callback(
			'/<p\b[^>]*>\s*(?:<span\b[^>]*>)?\s*\[image:\s*([^\]]+?)\]\s*(?:<\/span>)?\s*<\/p>/iu',
			$replace_token,
			$body_html
		);

		// Remaining bare markers.
		$body_html = (string) preg_replace_callback(
			'/\[image:\s*([^\]]+?)\]/iu',
			$replace_token,
			$body_html
		);

		// First pass used to leave <!-- wp:paragraph --> around the image.
		$body_html = (string) preg_replace(
			'/<!--\s+wp:paragraph(?:\s+\{[^}]*\})?\s+-->\s*(<!--\s+wp:image[\s\S]*?<!--\s+\/wp:image\s+-->)\s*<!--\s+\/wp:paragraph\s+-->/iu',
			'$1',
			$body_html
		);

		$body_html = (string) preg_replace(
			'/<!--\s+wp:paragraph(?:\s+\{[^}]*\})?\s+-->\s*<p(?:\s[^>]*)?>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>\s*<!--\s+\/wp:paragraph\s+-->/iu',
			'',
			$body_html
		);

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $body_html ) );
	}

	/**
	 * Collect unique filenames referenced by [image:…] markers.
	 *
	 * @return array<int, string>
	 */
	public static function extract_tokens( string $body_html ): array {
		if ( ! preg_match_all( '/\[image:\s*([^\]]+?)\]/iu', $body_html, $matches ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $matches[1] as $raw ) {
			$token = self::normalize_token( (string) $raw );
			if ( '' !== $token ) {
				$tokens[ $token ] = $token;
			}
		}

		return array_values( $tokens );
	}

	/**
	 * @param string                                           $token       Marker value.
	 * @param array<string, array{id: int, url: string, alt?: string}> $attachments Normalized map.
	 * @param Block_Markup_Builder                             $builder     Markup helper.
	 */
	private static function block_for_token( string $token, array $attachments, Block_Markup_Builder $builder ): ?string {
		$parsed = self::parse_marker( $token );
		$key    = $parsed['file'];
		if ( '' === $key || ! isset( $attachments[ $key ] ) || ! is_array( $attachments[ $key ] ) ) {
			return null;
		}

		$attachment = $attachments[ $key ];
		$id         = (int) ( $attachment['id'] ?? 0 );
		$url        = (string) ( $attachment['url'] ?? '' );
		if ( $id <= 0 || '' === $url ) {
			return null;
		}

		$alt = isset( $attachment['alt'] ) ? (string) $attachment['alt'] : '';

		return $builder->build_image_block( $id, $url, $alt, $parsed['align'] );
	}

	/**
	 * Split `[image:file.jpg left]` into filename + align.
	 *
	 * @return array{file: string, align: string}
	 */
	public static function parse_marker( string $token ): array {
		$token = html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$token = trim( $token );
		$token = trim( $token, " \t\n\r\0\x0B\"'" );

		$align = '';
		if ( preg_match( '/^(.*?)\s+(left|right|center)$/iu', $token, $m ) ) {
			$token = trim( $m[1] );
			$align = strtolower( $m[2] );
		}

		return array(
			'file'  => strtolower( $token ),
			'align' => $align,
		);
	}

	/**
	 * @param string $token Raw marker contents.
	 */
	public static function normalize_token( string $token ): string {
		return self::parse_marker( $token )['file'];
	}
}
