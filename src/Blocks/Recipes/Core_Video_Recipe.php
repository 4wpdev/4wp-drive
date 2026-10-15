<?php
/**
 * Core Video / Embed recipe: local [video:file.mp4] → core/video;
 * YouTube/Vimeo URLs → core/embed (Gutenberg save shape).
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Blocks\Recipes;

use ForWP\Drive\Blocks\Block_Markup_Builder;
use ForWP\Drive\Blocks\Block_Recipe_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Local package files become core/video (need attachments map).
 * Remote watch URLs become core/embed without sideload — same markup as
 * pasting a YouTube URL into the block editor.
 */
final class Core_Video_Recipe implements Block_Recipe_Interface {

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

		$body_html = self::normalize_loose_markers( $body_html );
		$builder   = new Block_Markup_Builder();

		$replace_token = static function ( array $matches ) use ( $attachments, $builder ): string {
			$block = self::block_for_token( (string) $matches[1], $attachments, $builder );
			return null !== $block ? $block : $matches[0];
		};

		// Gutenberg paragraph that is only a [video:…] marker.
		$body_html = (string) preg_replace_callback(
			'/<!--\s+wp:paragraph(?:\s+\{[^}]*\})?\s+-->\s*<p\b[^>]*>\s*(?:<span\b[^>]*>)?\s*\[video:\s*([^\]]+?)\]\s*(?:<\/span>)?\s*<\/p>\s*<!--\s+\/wp:paragraph\s+-->/iu',
			$replace_token,
			$body_html
		);

		$body_html = (string) preg_replace_callback(
			'/<p\b[^>]*>\s*(?:<span\b[^>]*>)?\s*\[video:\s*([^\]]+?)\]\s*(?:<\/span>)?\s*<\/p>/iu',
			$replace_token,
			$body_html
		);

		$body_html = (string) preg_replace_callback(
			'/\[video:\s*([^\]]+?)\]/iu',
			$replace_token,
			$body_html
		);

		// Bare YouTube / Vimeo URL as its own paragraph (as in the markdown file).
		$body_html = (string) preg_replace_callback(
			'/<!--\s+wp:paragraph(?:\s+\{[^}]*\})?\s+-->\s*<p\b[^>]*>\s*(?:<a\b[^>]*>)?\s*(https?:\/\/[^\s<]+)\s*(?:<\/a>)?\s*<\/p>\s*<!--\s+\/wp:paragraph\s+-->/iu',
			static function ( array $matches ) use ( $builder ): string {
				$block = self::embed_block_for_url( (string) $matches[1], '', $builder );
				return null !== $block ? $block : $matches[0];
			},
			$body_html
		);

		$body_html = (string) preg_replace_callback(
			'/<p\b[^>]*>\s*(?:<a\b[^>]*>)?\s*(https?:\/\/[^\s<]+)\s*(?:<\/a>)?\s*<\/p>/iu',
			static function ( array $matches ) use ( $builder ): string {
				$block = self::embed_block_for_url( (string) $matches[1], '', $builder );
				return null !== $block ? $block : $matches[0];
			},
			$body_html
		);

		$body_html = (string) preg_replace(
			'/<!--\s+wp:paragraph(?:\s+\{[^}]*\})?\s+-->\s*(<!--\s+wp:(?:video|embed)[\s\S]*?<!--\s+\/wp:(?:video|embed)\s+-->)\s*<!--\s+\/wp:paragraph\s+-->/iu',
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
	 * Local filenames only (for package sideload). Remote URLs are skipped.
	 *
	 * @return array<int, string>
	 */
	public static function extract_tokens( string $body_html ): array {
		if ( ! preg_match_all( '/\[video:\s*([^\]]+?)\]/iu', $body_html, $matches ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $matches[1] as $raw ) {
			$token = self::normalize_token( (string) $raw );
			if ( '' === $token || null !== self::detect_remote_provider( $token ) ) {
				continue;
			}
			$tokens[ $token ] = $token;
		}

		return array_values( $tokens );
	}

	/**
	 * Whether body has a YouTube/Vimeo URL or [video:https://…] marker.
	 */
	public static function body_has_remote_video( string $body_html ): bool {
		if ( preg_match( '/\[video:\s*https?:\/\/[^\]]+\]/iu', $body_html ) ) {
			return true;
		}

		return (bool) preg_match(
			'/https?:\/\/(?:(?:www|m)\.)?(?:youtube\.com\/|youtu\.be\/|vimeo\.com\/)/iu',
			$body_html
		);
	}

	/**
	 * `[file.mp4]` from preview mistakes → `[video:file.mp4]`.
	 */
	public static function normalize_loose_markers( string $html ): string {
		$out = (string) preg_replace_callback(
			'/\[(?!video:)(\s*[^\]\n]+\.(?:mp4|webm|ogg|ogv|m4v|mov))\s*\]/iu',
			static function ( array $matches ): string {
				$token = trim( (string) $matches[1] );
				return '[video:' . $token . ']';
			},
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * @param string                                           $token       Marker value.
	 * @param array<string, array{id: int, url: string}>       $attachments Normalized map.
	 * @param Block_Markup_Builder                             $builder     Markup helper.
	 */
	private static function block_for_token( string $token, array $attachments, Block_Markup_Builder $builder ): ?string {
		$parsed = self::parse_marker( $token );
		$key    = $parsed['file'];
		if ( '' === $key ) {
			return null;
		}

		$embed = self::embed_block_for_url( $key, $parsed['caption'], $builder );
		if ( null !== $embed ) {
			return $embed;
		}

		if ( ! isset( $attachments[ $key ] ) || ! is_array( $attachments[ $key ] ) ) {
			return null;
		}

		$attachment = $attachments[ $key ];
		$id         = (int) ( $attachment['id'] ?? 0 );
		$url        = (string) ( $attachment['url'] ?? '' );
		if ( $id <= 0 || '' === $url ) {
			return null;
		}

		return $builder->build_video_block( $id, $url, $parsed['caption'] );
	}

	/**
	 * @return string|null core/embed markup or null if not a known remote video URL.
	 */
	private static function embed_block_for_url( string $url, string $caption, Block_Markup_Builder $builder ): ?string {
		$remote = self::detect_remote_provider( $url );
		if ( null === $remote ) {
			return null;
		}

		return $builder->build_embed_block( $remote['url'], $remote['provider'], $caption );
	}

	/**
	 * Match core embed YouTube / Vimeo variation patterns.
	 *
	 * @return array{url: string, provider: string}|null
	 */
	public static function detect_remote_provider( string $url ): ?array {
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = trim( $url );
		$url = trim( $url, " \t\n\r\0\x0B\"'" );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		// Strip optional caption leftover if someone passed the whole marker body.
		if ( preg_match( '/^(https?:\/\/\S+)/iu', $url, $m ) ) {
			$url = $m[1];
		}

		if ( preg_match( '#^https?://((m|www)\.)?youtube\.com/.+#i', $url )
			|| preg_match( '#^https?://youtu\.be/.+#i', $url ) ) {
			return array(
				'url'      => $url,
				'provider' => 'youtube',
			);
		}

		if ( preg_match( '#^https?://(www\.)?vimeo\.com/.+#i', $url ) ) {
			return array(
				'url'      => $url,
				'provider' => 'vimeo',
			);
		}

		return null;
	}

	/**
	 * Split `[video:file.mp4 — Caption]` / `[video:https://… — Caption]`.
	 *
	 * @return array{file: string, caption: string}
	 */
	public static function parse_marker( string $token ): array {
		$token = html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$token = trim( $token );
		$token = trim( $token, " \t\n\r\0\x0B\"'" );

		$caption = '';
		if ( preg_match( '/^(https?:\/\/\S+)\s*[—–]\s+(.+)$/u', $token, $m )
			|| preg_match( '/^(https?:\/\/\S+)\s+-\s+(.+)$/u', $token, $m ) ) {
			$token   = trim( (string) $m[1] );
			$caption = trim( (string) $m[2] );
		} elseif ( preg_match( '/^(.*?)\s*[—–]\s+(.+)$/u', $token, $m )
			|| preg_match( '/^(.*?)\s+-\s+(.+)$/u', $token, $m ) ) {
			$token   = trim( (string) $m[1] );
			$caption = trim( (string) $m[2] );
		}

		// Keep remote URLs case-stable enough; local filenames lowercased for map keys.
		$file = preg_match( '#^https?://#i', $token ) ? $token : strtolower( $token );

		return array(
			'file'    => $file,
			'caption' => $caption,
		);
	}

	/**
	 * @param string $token Raw marker contents.
	 */
	public static function normalize_token( string $token ): string {
		return self::parse_marker( $token )['file'];
	}
}
