<?php
/**
 * Settings service: storage, defaults, Settings API registration.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one plugin option with the landing page, product-category terms,
 * and optional hero-product selections. Registered through the WordPress
 * Settings API.
 */
final class Settings {

	/**
	 * In-memory cache of the merged option value.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $cache = null;

	/**
	 * Default settings keyed by field.
	 *
	 * The earbud and headphone category terms resolve the store's existing
	 * handsfree/headphone categories when present; 0 means unresolved.
	 *
	 * @return array<string, int> Defaults.
	 */
	public function defaults(): array {
		$defaults = [
			'page_id'                 => 0,
			'earbuds_term'            => 0,
			'headphones_term'         => 0,
			'hero_earbuds_product'    => 0,
			'hero_headphones_product' => 0,
		];
		if ( ! function_exists( 'get_term_by' ) ) {
			return $defaults;
		}
		foreach ( [ 'earbuds_term' => 'handsfree', 'headphones_term' => 'headphone' ] as $key => $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) && (int) $term->term_id > 0 ) {
				$defaults[ $key ] = (int) $term->term_id;
			}
		}
		return $defaults;
	}

	/**
	 * Read the plugin option merged with defaults.
	 *
	 * @return array<string, int> Current settings.
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( TS_SOUND_GUIDE_OPTION, [] );
			$stored      = is_array( $stored ) ? $stored : [];
			$this->cache = array_map( 'intval', array_merge( $this->defaults(), $stored ) );
		}
		return $this->cache;
	}

	/**
	 * Raw stored option (no default merge) for the settings form.
	 *
	 * @return array<string, int>
	 */
	public function stored(): array {
		$stored = get_option( TS_SOUND_GUIDE_OPTION, [] );
		return is_array( $stored ) ? array_map( 'intval', $stored ) : [];
	}

	/**
	 * Value for one field.
	 *
	 * @param string $key Field key.
	 * @return int
	 */
	public function get( string $key ): int {
		$all = $this->all();
		return $all[ $key ] ?? 0;
	}

	/**
	 * Sanitize callback for the Settings API. Numeric fields only; a
	 * non-numeric or missing value keeps the current setting.
	 *
	 * @param mixed $input Raw submitted settings.
	 * @return array<string, int> Sanitized settings.
	 */
	public function sanitize( $input ): array {
		$clean = [];
		$all   = $this->all();
		foreach ( [ 'page_id', 'earbuds_term', 'headphones_term', 'hero_earbuds_product', 'hero_headphones_product' ] as $key ) {
			$value = is_array( $input ) ? ( $input[ $key ] ?? null ) : null;
			if ( is_numeric( $value ) ) {
				$clean[ $key ] = max( 0, (int) $value );
			} else {
				$clean[ $key ] = (int) ( $all[ $key ] ?? 0 );
			}
		}
		return $clean;
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			'ts_sound_guide',
			TS_SOUND_GUIDE_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => $this->defaults(),
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Register cache-invalidation hooks after option writes.
	 */
	public function register_hooks(): void {
		add_action( 'update_option_' . TS_SOUND_GUIDE_OPTION, [ $this, 'invalidate' ], 10, 0 );
		add_action( 'add_option_' . TS_SOUND_GUIDE_OPTION, [ $this, 'invalidate' ], 10, 0 );
	}

	/**
	 * Drop the in-memory cache after a write.
	 */
	public function invalidate(): void {
		$this->cache = null;
	}

	/**
	 * The selected landing page ID when it refers to a public page.
	 *
	 * A deleted or non-public page is ignored (flagged in settings instead).
	 *
	 * @return int Page ID or 0 when unconfigured/invalid.
	 */
	public function landing_page_id(): int {
		$page_id = $this->get( 'page_id' );
		if ( $page_id < 1 ) {
			return 0;
		}
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			return 0;
		}
		return $page_id;
	}

	/**
	 * Whether the settings page should warn about a broken page selection.
	 *
	 * @return bool True when a page is selected but not usable.
	 */
	public function has_broken_page(): bool {
		return $this->get( 'page_id' ) > 0 && $this->landing_page_id() === 0;
	}

	/**
	 * Category term IDs as [flow => term_id].
	 *
	 * @return array<string, int>
	 */
	public function category_terms(): array {
		return [
			'earbuds'    => $this->get( 'earbuds_term' ),
			'headphones' => $this->get( 'headphones_term' ),
		];
	}

	/**
	 * Optional administrator-selected hero product IDs by flow.
	 *
	 * Zero keeps the deterministic automatic selection.
	 *
	 * @return array<string, int>
	 */
	public function hero_products(): array {
		return [
			'earbuds'    => $this->get( 'hero_earbuds_product' ),
			'headphones' => $this->get( 'hero_headphones_product' ),
		];
	}
}
