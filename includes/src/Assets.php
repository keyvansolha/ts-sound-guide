<?php
/**
 * Assets service: styles, script modules, and the public bootstrap config.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues assets only on the configured landing page or a page containing
 * the compatibility shortcode. Styles are theme-token-based with scoped
 * fallbacks; the browser script is split into ES modules registered with the
 * WordPress Script Modules API (no build step).
 */
final class Assets {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Landing page service.
	 *
	 * @var LandingPage
	 */
	private LandingPage $landing_page;

	/**
	 * Catalog adapter.
	 *
	 * @var CatalogAdapter
	 */
	private CatalogAdapter $catalog;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings     Settings.
	 * @param LandingPage    $landing_page Landing page service.
	 * @param CatalogAdapter $catalog      Catalog adapter.
	 */
	public function __construct( Settings $settings, LandingPage $landing_page, CatalogAdapter $catalog ) {
		$this->settings     = $settings;
		$this->landing_page = $landing_page;
		$this->catalog      = $catalog;
	}

	/**
	 * Enqueue guide assets when the guide is on this page.
	 */
	public function enqueue(): void {
		if ( ! $this->landing_page->is_guide_request() ) {
			return;
		}
		$base = TS_SOUND_GUIDE_URL . 'assets/';
		$ver  = TS_SOUND_GUIDE_VERSION;

		// Reuse the theme's semantic token layer; never a parallel palette.
		if ( ! wp_style_is( 'amazing-theme-system', 'registered' ) ) {
			wp_register_style( 'amazing-theme-system', get_template_directory_uri() . '/assets/css/theme-system.css', [], null );
		}

		wp_enqueue_style( 'ts-sound-tokens', $base . 'token-bridge.css', [ 'amazing-theme-system' ], $ver );
		wp_enqueue_style( 'ts-sound-guide', $base . 'style.css', [ 'ts-sound-tokens' ], $ver );

		$this->register_modules( $base, $ver );

		wp_enqueue_script_module( 'ts-sound-guide/entry' );
	}

	/**
	 * Register the split ES modules with the Script Modules API.
	 *
	 * The import map resolves bare specifiers (./questions.js etc. are
	 * relative, so only the entry needs registering per dependency graph;
	 * WordPress prints an import map for every registered module id).
	 *
	 * @param string $base Assets URL base.
	 * @param string $ver  Version.
	 */
	private function register_modules( string $base, string $ver ): void {
		$modules = [
			'ts-sound-guide/format'    => [ 'src' => 'js/format.js',    'deps' => [] ],
			'ts-sound-guide/questions' => [ 'src' => 'js/questions.js', 'deps' => [] ],
			'ts-sound-guide/analytics' => [ 'src' => 'js/analytics.js', 'deps' => [] ],
			'ts-sound-guide/rest'      => [ 'src' => 'js/rest.js',      'deps' => [] ],
			'ts-sound-guide/render'    => [ 'src' => 'js/render.js',    'deps' => [ 'ts-sound-guide/format', 'ts-sound-guide/questions' ] ],
			'ts-sound-guide/state'     => [ 'src' => 'js/state.js',     'deps' => [ 'ts-sound-guide/questions', 'ts-sound-guide/rest', 'ts-sound-guide/render', 'ts-sound-guide/analytics' ] ],
			'ts-sound-guide/entry'     => [ 'src' => 'js/entry.js',     'deps' => [ 'ts-sound-guide/state', 'ts-sound-guide/render', 'ts-sound-guide/analytics' ] ],
		];
		foreach ( $modules as $id => $module ) {
			wp_register_script_module(
				$id,
				$base . $module['src'],
				$module['deps'],
				$ver
			);
		}
	}
}
