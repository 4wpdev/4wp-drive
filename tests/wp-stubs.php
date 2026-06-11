<?php
/**
 * Minimal WordPress function stubs for unit tests (no WP test suite).
 *
 * @package ForWP\Drive\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

/**
 * Reset in-memory options between tests.
 */
function forwp_drive_tests_reset_options(): void {
	$GLOBALS['forwp_drive_test_options'] = array();
}

forwp_drive_tests_reset_options();

if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', 'test-stub' );
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		if ( array_key_exists( $option, $GLOBALS['forwp_drive_test_options'] ) ) {
			return $GLOBALS['forwp_drive_test_options'][ $option ];
		}

		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Unused in tests.
	 */
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['forwp_drive_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Option name.
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['forwp_drive_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * @param string $post_type Post type slug.
	 */
	function post_type_exists( $post_type ): bool {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	/**
	 * @param string $post_type Post type slug.
	 * @param string $output    names|objects.
	 * @return array<string, object>|string[]
	 */
	function get_object_taxonomies( $post_type, $output = 'names' ) {
		unset( $post_type );

		$objects = array();
		foreach ( array(
			'category' => 'Category',
			'post_tag' => 'Tag',
			'region'   => 'Region',
			'country'  => 'Country',
		) as $name => $singular ) {
			$obj            = new stdClass();
			$obj->name      = $name;
			$obj->public    = true;
			$obj->show_ui   = true;
			$obj->labels    = (object) array( 'singular_name' => $singular );
			$objects[ $name ] = $obj;
		}

		if ( 'objects' === $output ) {
			return $objects;
		}

		return array_keys( $objects );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $string        Text.
	 * @param bool   $remove_breaks   Strip line breaks when true.
	 */
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = strip_tags( (string) $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', '', $string );
		}

		return trim( $string );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * @param string $data HTML.
	 */
	function wp_kses_post( $data ) {
		return strip_tags(
			(string) $data,
			'<a><b><blockquote><br><cite><code><del><em><h1><h2><h3><h4><h5><h6><hr><i><li><ol><p><pre><s><span><strong><sub><sup><table><tbody><td><tfoot><th><thead><tr><u><ul>'
		);
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * @param string $filename Filename.
	 */
	function sanitize_file_name( $filename ) {
		$filename = (string) $filename;

		return preg_replace( '/[^a-zA-Z0-9._\-]/', '', $filename );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * @param string $title Title.
	 */
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );

		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );

		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param mixed $str Value.
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 */
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 */
	function __( $text, $domain = null ) {
		unset( $domain );

		return (string) $text;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	/**
	 * @param string $taxonomy Taxonomy slug.
	 */
	function taxonomy_exists( $taxonomy ): bool {
		return in_array( $taxonomy, array( 'category', 'post_tag', 'region', 'country' ), true );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	/**
	 * @param string $pee Text.
	 */
	function wpautop( $pee, $br = true ) {
		unset( $br );
		$pee = trim( (string) $pee );
		if ( '' === $pee ) {
			return '';
		}

		$parts = preg_split( "/\n\n+/", $pee ) ?: array( $pee );

		return '<p>' . implode( '</p><p>', array_map( 'esc_html', $parts ) ) . '</p>';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		unset( $hook );

		return $value;
	}
}
