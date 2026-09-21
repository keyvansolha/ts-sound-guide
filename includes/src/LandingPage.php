<?php
/**
 * Landing-page service: configured-page template, shortcode compatibility,
 * deterministic hero resolution from the live catalog.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * When the configured landing page is requested, a plugin template replaces
 * the page content between the theme's header and footer. The legacy
 * shortcode remains available; on the configured page the template wins and
 * the guide renders exactly once.
 */
final class LandingPage {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Catalog adapter (hero resolution).
	 *
	 * @var CatalogAdapter
	 */
	private CatalogAdapter $catalog;

	/**
	 * Whether render output has been emitted for this request.
	 *
	 * @var bool
	 */
	private bool $rendered = false;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param CatalogAdapter $catalog  Catalog adapter.
	 */
	public function __construct( Settings $settings, CatalogAdapter $catalog ) {
		$this->settings = $settings;
		$this->catalog  = $catalog;
	}

	/**
	 * The configured page ID when the current request is that page.
	 *
	 * @return int Page ID or 0.
	 */
	private function current_configured_page(): int {
		$page_id = $this->settings->landing_page_id();
		if ( $page_id < 1 || ! is_page( $page_id ) ) {
			return 0;
		}
		return $page_id;
	}

	/**
	 * template_include filter: use the plugin template on the configured page.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function template( string $template ): string {
		if ( $this->current_configured_page() > 0 ) {
			return TS_SOUND_GUIDE_DIR . 'templates/page.php';
		}
		// Compatibility shortcode pages remain inside their normal theme/page
		// template; only the administrator-selected page is fully replaced.
		return $template;
	}

	/**
	 * Shortcode handler: compatibility path. On the configured landing page
	 * the template already renders the guide; the shortcode returns an empty
	 * string there so the guide never appears twice.
	 *
	 * @param array<string, string>|string $attrs Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $attrs = [] ): string {
		$attrs = shortcode_atts( [ 'flow' => 'earbuds' ], $attrs, 'ts_sound_guide' );
		if ( $this->current_configured_page() > 0 ) {
			return '';
		}
		$flow = 'headphones' === $attrs['flow'] ? 'headphones' : 'earbuds';
		return $this->render( $flow );
	}

	/**
	 * Whether the guide is rendered for the current request.
	 *
	 * @return bool
	 */
	public function is_guide_request(): bool {
		return $this->current_configured_page() > 0
			|| ( is_page() && ( $page = get_queried_object() ) && has_shortcode( $page->post_content, 'ts_sound_guide' ) );
	}

	/**
	 * Render the complete guide markup for a flow.
	 *
	 * @param string $flow Initial flow.
	 * @return string HTML.
	 */
	public function render( string $flow = 'earbuds' ): string {
		if ( $this->rendered ) {
			return '';
		}
		$this->rendered = true;

		$heroes = $this->heroes();
		$config = [
			'mode'         => 'live',
			'flow'         => 'headphones' === $flow ? 'headphones' : 'earbuds',
			'endpoint'     => rest_url( TS_SOUND_GUIDE_REST_BASE ),
			'homeUrl'      => home_url( '/' ),
			'categoryUrls' => $this->category_urls(),
			'hero'         => $heroes,
		];

		$view = new GuideView( $config, $heroes );
		return $view->render();
	}

	/**
	 * Deterministic heroes for both flows from eligible WooCommerce products.
	 *
	 * An eligible administrator-selected product wins per flow. Automatic mode
	 * (or an invalid/stale selection) falls back to the cheapest eligible
	 * product with an image. A flow with no eligible hero renders a neutral
	 * fallback (no broken image).
	 *
	 * @return array<string, array{name:string,image:string}|null>
	 */
	private function heroes(): array {
		$heroes = [ 'earbuds' => null, 'headphones' => null ];
		try {
			$catalog = $this->catalog->catalog();
			$by_flow = [ 'earbuds' => [], 'headphones' => [] ];
			$selected = $this->settings->hero_products();
			foreach ( $catalog as $p ) {
				if ( ! empty( $p['image'] ) ) {
					$by_flow[ $p['flow'] ][] = $p;
				}
			}
			foreach ( $by_flow as $flow => $products ) {
				usort( $products, static fn( array $a, array $b ): int => ( (float) $a['variants'][0]['price'] ) <=> ( (float) $b['variants'][0]['price'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
				$selected_id = (int) ( $selected[ $flow ] ?? 0 );
				$product = $selected_id > 0 && isset( $catalog[ $selected_id ] )
					&& $flow === $catalog[ $selected_id ]['flow'] && ! empty( $catalog[ $selected_id ]['image'] )
					? $catalog[ $selected_id ]
					: ( $products[0] ?? null );
				if ( $product ) {
					$heroes[ $flow ] = [
						'name'  => (string) $product['name'],
						'image' => (string) $product['image'],
					];
				}
			}
		} catch ( \Throwable $e ) {
			// Catalog unavailable at render time: heroes fall back to neutral.
		}
		return $heroes;
	}

	/**
	 * Public archive URLs for the administrator-selected category terms.
	 *
	 * @return array{earbuds:string,headphones:string}
	 */
	private function category_urls(): array {
		$urls = [ 'earbuds' => home_url( '/' ), 'headphones' => home_url( '/' ) ];
		foreach ( $this->settings->category_terms() as $flow => $term_id ) {
			if ( $term_id < 1 ) {
				continue;
			}
			$url = get_term_link( $term_id, 'product_cat' );
			if ( is_string( $url ) && '' !== $url ) {
				$urls[ $flow ] = $url;
			}
		}
		return $urls;
	}
}
