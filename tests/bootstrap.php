<?php
/**
 * Minimal WordPress compatibility layer for isolated repository tests.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = ''
		) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( string $text ): string {
		return 1 === preg_match( '//u', $text ) ? $text : '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['project_overrides_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		return (string) ( $GLOBALS['project_overrides_test_theme_dir'] ?? '' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( string $class ): string {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $class ) ?? '';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/src/Repository.php';
require_once dirname( __DIR__ ) . '/src/ClassNames.php';
require_once dirname( __DIR__ ) . '/src/Exporter.php';
