<?php
/**
 * WordPress/WooCommerce function shims for pure-PHP tests.
 *
 * Unit tests run without WordPress; these shims provide the small API surface
 * the plugin's pure classes and the plugin bootstrap reference. They are
 * never loaded by the shipped plugin.
 *
 * Usage in a test entry point:
 *   define( 'ABSPATH', ... ); require tests/unit/wp-shims.php;
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/**
 * Plugin directory path (real filesystem).
 */
function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

/**
 * Plugin directory URL (unused in tests; present for bootstrap parity).
 */
function plugin_dir_url( string $file ): string {
	return 'https://store.example/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

/**
 * Register the activation hook (no-op outside WordPress).
 */
function register_activation_hook( string $file, callable $callback ): void {
}

/**
 * Stub the SPL autoloader registration of the plugin bootstrap.
 */
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['ts_test_actions'][ $hook ][] = [ $callback, $priority ];
}

/**
 * add_filter alias in the shim environment.
 */
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	add_action( $hook, $callback, $priority, $args );
}

/**
 * Strip all HTML tags (mirrors wp_strip_all_tags behavior).
 */
function wp_strip_all_tags( string $text ): string {
	return trim( strip_tags( $text ) );
}

/**
 * JSON encode with WordPress defaults for tests.
 */
function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Translation passthrough: the plugin ships Persian copy.
 */
function __( string $text, string $domain = 'ts-sound-guide' ): string {
	return $text;
}

/**
 * Escaping passthrough for tests (values are trusted fixtures).
 */
function esc_html( string $text ): string {
	return $text;
}

/**
 * Attribute escaping passthrough for tests.
 */
function esc_attr( string $text ): string {
	return $text;
}

/**
 * URL escaping passthrough for tests.
 */
function esc_url( string $url ): string {
	return $url;
}
