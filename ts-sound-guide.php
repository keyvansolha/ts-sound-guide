<?php
/**
 * Plugin bootstrap and composition root.
 *
 * Only environment checks, class loading, service construction, and hook
 * registration happen here. Business logic lives in the services.
 *
 * @package TSSoundGuide
 */

defined( 'ABSPATH' ) || exit;

define( 'TS_SOUND_GUIDE_VERSION', '3.0.0' );
define( 'TS_SOUND_GUIDE_FILE', __FILE__ );
define( 'TS_SOUND_GUIDE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TS_SOUND_GUIDE_URL', plugin_dir_url( __FILE__ ) );
define( 'TS_SOUND_GUIDE_OPTION', 'ts_sound_guide_settings' );
define( 'TS_SOUND_GUIDE_REST_BASE', 'ts-sound/v1' );
define( 'TS_SOUND_GUIDE_TRANSIENT_EXPIRY', 30 * MINUTE_IN_SECONDS );

/**
 * Minimal PSR-4 class autoloader for the TSSoundGuide namespace.
 *
 * @param string $class Fully qualified class name.
 */
function ts_sound_guide_autoload( string $class ): void {
	if ( ! str_starts_with( $class, 'TSSoundGuide\\' ) ) {
		return;
	}
	$relative = substr( $class, strlen( 'TSSoundGuide\\' ) );
	$file     = __DIR__ . '/includes/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'ts_sound_guide_autoload' );

register_activation_hook( __FILE__, static function (): void {
	// Activation writes nothing: no product edits, no pages, no options rows
	// beyond defaults written on first read (get_option default path).
	ts_sound_guide_environment_ready();
} );

/**
 * Whether the runtime supports the plugin (PHP and WooCommerce presence).
 *
 * @return bool True when WooCommerce is active with a compatible runtime.
 */
function ts_sound_guide_environment_ready(): bool {
	$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
	$wc_ok  = class_exists( 'WooCommerce' ) || ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce/woocommerce.php' ) );
	return $php_ok && $wc_ok;
}

/**
 * Compose and register services.
 *
 * @return TSSoundGuide\App The application container.
 */
function ts_sound_guide(): TSSoundGuide\App {
	static $app = null;
	if ( null === $app ) {
		$app = new TSSoundGuide\App();
		$app->register( $GLOBALS['wp_filter'] ?? [] );
	}
	return $app;
}

add_action( 'plugins_loaded', 'ts_sound_guide', 5 );
