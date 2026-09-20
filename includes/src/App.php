<?php
/**
 * Application container and composition root.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Constructs services and wires them into WordPress hooks.
 *
 * Business logic never lives in hook callbacks; each callback delegates to a
 * focused service.
 */
final class App {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * Landing-page routing service.
	 *
	 * @var LandingPage
	 */
	public LandingPage $landing_page;

	/**
	 * Capability registry (immutable).
	 *
	 * @var CapabilityRegistry
	 */
	public CapabilityRegistry $capabilities;

	/**
	 * WooCommerce catalog adapter.
	 *
	 * @var CatalogAdapter
	 */
	public CatalogAdapter $catalog;

	/**
	 * Catalog-health diagnostics service.
	 *
	 * @var CatalogHealth
	 */
	public CatalogHealth $health;

	/**
	 * REST controllers.
	 *
	 * @var Rest\RecommendController
	 */
	public Rest\RecommendController $recommend;

	/**
	 * @var Rest\ValidateController
	 */
	public Rest\ValidateController $validate;

	/**
	 * Attribution service.
	 *
	 * @var Attribution
	 */
	public Attribution $attribution;

	/**
	 * Admin screens (settings + health).
	 *
	 * @var AdminScreens
	 */
	public AdminScreens $admin;

	/**
	 * Assets service (styles, script modules, bootstrap config).
	 *
	 * @var Assets
	 */
	public Assets $assets;

	/**
	 * Construct services. Dependencies are explicit and narrow.
	 */
	public function __construct() {
		$this->settings     = new Settings();
		$this->capabilities = new CapabilityRegistry();
		$this->catalog      = new CatalogAdapter( $this->settings, $this->capabilities );
		$this->health       = new CatalogHealth( $this->catalog );
		$this->landing_page = new LandingPage( $this->settings, $this->catalog );
		$this->attribution  = new Attribution();
		$this->recommend    = new Rest\RecommendController( $this->settings, $this->catalog );
		$this->validate     = new Rest\ValidateController( $this->settings, $this->catalog, $this->attribution );
		$this->admin        = new AdminScreens( $this->settings, $this->catalog, $this->health );
		$this->assets       = new Assets( $this->settings, $this->landing_page, $this->catalog );
	}

	/**
	 * Register WordPress hooks. Admin notice on missing WooCommerce.
	 *
	 * @param array<int|string, mixed>|null $wp_filter Unused filter registry (kept for signature stability).
	 */
	public function register( ?array $wp_filter = null ): void {
		add_action( 'rest_api_init', [ $this->recommend, 'register_routes' ] );
		add_action( 'rest_api_init', [ $this->validate, 'register_routes' ] );

		add_action( 'admin_menu', [ $this->admin, 'register_menu' ] );
		add_action( 'admin_init', [ $this->settings, 'register_settings' ] );
		$this->settings->register_hooks();

		add_action( 'admin_notices', [ $this, 'render_environment_notice' ] );

		add_action( 'wp_enqueue_scripts', [ $this->assets, 'enqueue' ], 1001 );

		add_filter( 'template_include', [ $this->landing_page, 'template' ], 99 );
		add_action( 'template_redirect', [ $this->attribution, 'capture_token' ] );
		add_shortcode( 'ts_sound_guide', [ $this->landing_page, 'shortcode' ] );

		add_action( 'woocommerce_checkout_create_order', [ $this->attribution, 'attach_order_attribution' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this->attribution, 'attach_order_attribution' ], 20, 1 );
	}
}
