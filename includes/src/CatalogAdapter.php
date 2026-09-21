<?php
/**
 * WooCommerce catalog adapter: queries products and maps them to the
 * internal immutable catalog representation.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce is the sole source of product facts. The adapter reads products,
 * variations, prices, images, availability, and capabilities at request time
 * and never writes to WooCommerce data.
 */
final class CatalogAdapter {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $capabilities;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings     Settings service.
	 * @param CapabilityRegistry $capabilities Capability registry.
	 */
	public function __construct( Settings $settings, CapabilityRegistry $capabilities ) {
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
	}

	/**
	 * Whether WooCommerce is available in this runtime.
	 *
	 * @return bool
	 */
	public function woo_available(): bool {
		return function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Build the full catalog for recommendations.
	 *
	 * @return array<int, array<string, mixed>> Internal products keyed by WooCommerce product ID.
	 * @throws RuntimeException When WooCommerce is unavailable, the query fails, or the currency is unsupported.
	 */
	public function catalog(): array {
		if ( ! $this->woo_available() ) {
			throw new RuntimeException( 'WooCommerce unavailable' );
		}

		$currency = $this->currency();
		if ( null === $currency ) {
			throw new RuntimeException( 'Unsupported currency' );
		}

		$products = $this->query_products();
		$catalog  = [];
		foreach ( $products as $product ) {
			$mapped = $this->map_product( $product, $currency );
			if ( null !== $mapped ) {
				$catalog[ $mapped['id'] ] = $mapped;
			}
		}
		return $catalog;
	}

	/**
	 * Build the admin health snapshot, including relevant products that are
	 * deliberately excluded from the recommendation catalog.
	 *
	 * @return array<int, array{product:array<string,mixed>,issues:array<int,array<string,string>>}>
	 * @throws RuntimeException When WooCommerce is unavailable, the query fails, or the currency is unsupported.
	 */
	public function health_catalog(): array {
		if ( ! $this->woo_available() ) {
			throw new RuntimeException( 'WooCommerce unavailable' );
		}
		$currency = $this->currency();
		if ( null === $currency ) {
			throw new RuntimeException( 'Unsupported currency' );
		}

		$rows = [];
		foreach ( $this->query_products( false ) as $product ) {
			$issues = $this->commerce_issues( $product, $currency );
			$mapped = $issues ? null : $this->map_product( $product, $currency );
			$rows[] = [
				'product' => $mapped ?? [
					'id'   => (int) $product->get_id(),
					'wcId' => (int) $product->get_id(),
					'name' => wp_strip_all_tags( (string) $product->get_name() ),
					'flow' => $this->product_flow( $product ) ?? 'unknown',
				],
				'issues'  => $issues,
			];
		}
		return $rows;
	}

	/**
	 * Current store currency resolved to the internal toman representation or
	 * null when unsupported.
	 *
	 * IRR is divided by ten; IRT and TOMAN are accepted directly.
	 *
	 * @return array{code:string,factor:float}|null
	 */
	public function currency(): ?array {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return null;
		}
		$code = get_woocommerce_currency();
		if ( 'IRR' === $code ) {
			return [ 'code' => $code, 'factor' => 0.1 ];
		}
		if ( 'IRT' === $code || 'TOMAN' === $code ) {
			return [ 'code' => $code, 'factor' => 1.0 ];
		}
		return null;
	}

	/**
	 * Query published, visible, in-stock products from the configured category
	 * terms (including child terms). Paged with a hard page ceiling.
	 *
	 * @return array<int, \WC_Product> Products keyed by ID.
	 * @throws RuntimeException On query failure.
	 */
	private function query_products( bool $recommendation_only = true ): array {
		$term_ids = array_values( array_filter( $this->settings->category_terms() ) );
		if ( ! $term_ids ) {
			return [];
		}
		$all_terms = [];
		foreach ( $term_ids as $term_id ) {
			$all_terms[] = (int) $term_id;
			$children    = get_term_children( (int) $term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					$all_terms[] = (int) $child;
				}
			}
		}
		$all_terms = array_values( array_unique( $all_terms ) );

		$products = [];
		$page     = 1;
		do {
			$args = [
				'status'       => $recommendation_only ? 'publish' : [ 'publish', 'private', 'draft', 'pending', 'future' ],
				'type'         => [ 'simple', 'variable' ],
				'product_category_id' => $all_terms,
				'limit'        => 100,
				'page'         => $page,
				'paginate'     => true,
			];
			if ( $recommendation_only ) {
				$args['stock_status'] = 'instock';
			}
			$result = wc_get_products( $args );
			if ( ! is_object( $result ) || ! isset( $result->products, $result->total_pages ) ) {
				throw new RuntimeException( 'Catalog query failed' );
			}
			foreach ( $result->products as $product ) {
				$products[ $product->get_id() ] = $product;
			}
			$page++;
			if ( $page > 50 && $page <= (int) $result->total_pages ) {
				throw new RuntimeException( 'Catalog limit exceeded' );
			}
		} while ( $page <= (int) $result->total_pages );

		return $products;
	}

	/**
	 * Explain why a relevant product cannot enter the recommendation catalog.
	 *
	 * @param \WC_Product                   $product Product object.
	 * @param array{code:string,factor:float} $currency Currency metadata.
	 * @return array<int, array<string, string>> Blocking issues.
	 */
	private function commerce_issues( $product, array $currency ): array {
		$id     = (int) $product->get_id();
		$issues = [];
		$add    = static function ( string $capability, string $field, string $help ) use ( &$issues ): void {
			$issues[] = [ 'severity' => 'error', 'capability' => $capability, 'field' => $field, 'help' => $help ];
		};

		if ( 'publish' !== $product->get_status() ) {
			$add( 'publication', 'وضعیت انتشار محصول', 'محصول باید منتشرشده باشد.' );
		}
		if ( 'hidden' === $product->get_catalog_visibility() ) {
			$add( 'visibility', 'نمایش در کاتالوگ WooCommerce', 'محصول مخفی از راهنمای عمومی کنار گذاشته می‌شود.' );
		}
		if ( 'stop' === (string) get_post_meta( $id, 'product-status', true ) ) {
			$add( 'lifecycle', 'فیلد product-status', 'محصول با وضعیت stop قابل پیشنهاد نیست.' );
		}
		if ( ! $product->is_in_stock() ) {
			$add( 'stock', 'وضعیت موجودی WooCommerce', 'محصول باید در WooCommerce موجود باشد.' );
		}
		if ( ! $product->is_purchasable() ) {
			$add( 'purchasable', 'قابلیت خرید محصول', 'محصول باید توسط WooCommerce قابل خرید باشد.' );
		}
		if ( null === $this->product_flow( $product ) ) {
			$add( 'category', 'دسته‌بندی محصول', 'محصول باید در یکی از دو دسته‌بندی تنظیم‌شده باشد.' );
		}
		if ( $issues ) {
			return $issues;
		}

		$variation_ids = $product->is_type( 'variable' ) ? $product->get_children() : [ $id ];
		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation && null !== $this->map_variation( $variation, $product, $currency ) ) {
				return [];
			}
		}
		$add(
			'availability',
			'واریانت‌ها: وضعیت، قیمت، موجودی، بک‌اوردر، رنگ و گارانتی',
			'هیچ واریانت قابل خریدی وجود ندارد؛ قیمت، موجودی، فعال‌بودن، بک‌اوردر و ویژگی‌های رنگ و گارانتی را بررسی کنید.'
		);
		return $issues;
	}

	/**
	 * Map one WooCommerce product to the internal representation, or null
	 * when the product is not eligible (unpublished, hidden, stopped,
	 * out-of-stock, or without eligible variations).
	 *
	 * @param \WC_Product              $product  Product object.
	 * @param array{code:string,factor:float} $currency Currency.
	 * @return array<string, mixed>|null
	 */
	private function map_product( $product, array $currency ): ?array {
		$id = (int) $product->get_id();

		if ( 'publish' !== $product->get_status()
			|| 'hidden' === $product->get_catalog_visibility()
			|| ! $product->is_in_stock()
			|| 'stop' === (string) get_post_meta( $id, 'product-status', true )
			|| ! $product->is_purchasable() ) {
			return null;
		}

		$flow = $this->product_flow( $product );
		if ( null === $flow ) {
			return null; // Not in a configured category.
		}

		$variation_ids = $product->is_type( 'variable' ) ? $product->get_children() : [ $id ];
		$variants      = [];
		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( null === $variation ) {
				continue;
			}
			$mapped = $this->map_variation( $variation, $product, $currency );
			if ( null !== $mapped ) {
				$variants[] = $mapped;
			}
		}
		if ( ! $variants ) {
			return null; // No eligible variation: cannot be safely offered.
		}

		$raw_attributes = $this->raw_attributes( $product );
		$caps = [
			'wireless'   => $this->capabilities->resolve( 'wireless', $raw_attributes ),
			'usbc'       => $this->capabilities->resolve( 'usbc', $raw_attributes ),
			'aux'        => $this->capabilities->resolve( 'aux', $raw_attributes ),
			'auxMic'     => $this->capabilities->resolve( 'auxMic', $raw_attributes ),
			'anc'        => $this->capabilities->resolve( 'anc', $raw_attributes ),
			'multipoint' => $this->capabilities->resolve( 'multipoint', $raw_attributes ),
			'silicone'   => $this->capabilities->resolve( 'silicone', $raw_attributes ),
		];

		return [
			'id'          => $id,
			'wcId'        => $id,
			'name'        => wp_strip_all_tags( (string) $product->get_name() ),
			'flow'        => $flow,
			'url'         => (string) get_permalink( $id ),
			'image'       => wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_single' ) ?: null,
			'form'        => $this->form_label( $product ),
			'capabilities' => $caps,
			'variants'    => $variants,
			'status'      => 'publish',
			'lifecycle'   => 'stop' === (string) get_post_meta( $id, 'product-status', true ) ? 'stop' : 'active',
			'inStock'     => true,
			'purchasable' => true,
			'cautions'    => $this->cautions( $caps ),
			'sources'     => [ [
				'label' => 'مشخصات ثبت‌شده در فروشگاه',
				'url'   => (string) get_permalink( $id ),
			] ],
		];
	}

	/**
	 * Map one variation (or a simple product as its own only "variation") when
	 * it is eligible; otherwise null.
	 *
	 * Eligibility: published, enabled, visible, purchasable, in stock, priced,
	 * not on backorder; managed stock minus held reservations at least one;
	 * unmanaged stock keeps WooCommerce stock state (never coerced to zero);
	 * variable variations must expose both pa_color and pa_guarantee.
	 *
	 * @param \WC_Product              $variation Variation or simple product.
	 * @param \WC_Product              $parent    Parent product.
	 * @param array{code:string,factor:float} $currency Currency.
	 * @return array<string, mixed>|null
	 */
	private function map_variation( $variation, $parent, array $currency ): ?array {
		if ( 'publish' !== $variation->get_status()
			|| ! $variation->is_purchasable()
			|| ! $variation->is_in_stock()
			|| 'instock' !== $variation->get_stock_status()
			|| $variation->is_on_backorder( 1 ) ) {
			return null;
		}
		if ( $variation->is_type( 'variation' ) && ! $variation->variation_is_visible() ) {
			return null;
		}

		$price = (float) wc_get_price_to_display( $variation ) * $currency['factor'];
		if ( $price <= 0 ) {
			return null;
		}

		$stock_owner = $variation->get_stock_managed_by_id() === $parent->get_id() ? $parent : $variation;
		$qty         = $stock_owner->managing_stock() ? $stock_owner->get_stock_quantity() : null;
		$held        = function_exists( 'wc_get_held_stock_quantity' ) ? (float) wc_get_held_stock_quantity( $stock_owner ) : 0.0;
		if ( $stock_owner->managing_stock() ) {
			if ( null === $qty || (float) $qty - $held < 1 ) {
				return null;
			}
		}

		$attributes = [];
		$labels     = [];
		$query      = [];
		foreach ( $variation->get_attributes() as $key => $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$label = $slug;
			if ( taxonomy_exists( $key ) ) {
				$term = get_term_by( 'slug', $slug, $key );
				if ( $term && ! is_wp_error( $term ) ) {
					$label = $term->name;
				}
			}
			$attributes[] = [ 'name' => wc_attribute_label( $key ), 'slug' => $key, 'option' => $label ];
			$labels[]     = $label;
			$query[ 'attribute_' . $key ] = $slug;
		}

		if ( $variation->is_type( 'variation' )
			&& ( ! isset( $query['attribute_pa_color'] ) || ! isset( $query['attribute_pa_guarantee'] ) ) ) {
			return null; // The amazing purchase panel requires color and guarantee.
		}

		return [
			'id'           => (int) $variation->get_id(),
			'image'        => wp_get_attachment_image_url( (int) $variation->get_image_id( 'edit' ), 'woocommerce_single' ) ?: null,
			'stockOwnerId' => (int) $stock_owner->get_id(),
			'price'        => $price,
			'qty'          => null === $qty ? null : (float) $qty,
			'held'         => $held,
			'inStock'      => true,
			'status'       => 'publish',
			'stockStatus'  => 'instock',
			'enabled'      => true,
			'backorder'    => false,
			'attributes'   => $attributes,
			'label'        => '' !== implode( '', $labels ) ? implode( ' · ', $labels ) : 'مدل موجود',
			'query'        => $query,
		];
	}

	/**
	 * Which configured flow a product belongs to, or null when it is in
	 * neither configured category tree.
	 *
	 * @param \WC_Product $product Product.
	 * @return string|null
	 */
	private function product_flow( $product ): ?string {
		$terms = $this->settings->category_terms();
		$id    = (int) $product->get_id();
		foreach ( $terms as $flow => $term_id ) {
			$ids = [ (int) $term_id ];
			$children = get_term_children( (int) $term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $children ) );
			}
			if ( has_term( $ids, 'product_cat', $id ) ) {
				return (string) $flow;
			}
		}
		return null;
	}

	/**
	 * Raw attribute values keyed by taxonomy for capability mapping.
	 *
	 * For taxonomy-backed attributes get_attribute() returns the term names as
	 * a comma-separated string, which is exactly what the matcher consumes.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, string>
	 */
	private function raw_attributes( $product ): array {
		$raw = [];
		foreach ( $this->capability_taxonomies() as $taxonomy ) {
			$text = trim( wp_strip_all_tags( (string) $product->get_attribute( $taxonomy ) ) );
			if ( '' !== $text ) {
				$raw[ $taxonomy ] = $text;
			}
		}
		return $raw;
	}

	/**
	 * Taxonomies the capability registry reads.
	 *
	 * @return array<int, string>
	 */
	private function capability_taxonomies(): array {
		$taxonomies = [];
		foreach ( $this->capabilities->capabilities() as $def ) {
			$taxonomies[] = $def['attribute'];
			if ( ! empty( $def['attribute_alt'] ) ) {
				$taxonomies[] = $def['attribute_alt'];
			}
		}
		return array_values( array_unique( $taxonomies ) );
	}

	/**
	 * Human-readable form-factor label.
	 *
	 * @param \WC_Product $product Product.
	 * @return string|null
	 */
	private function form_label( $product ): ?string {
		$form = trim( wp_strip_all_tags( (string) $product->get_attribute( 'pa_headphones-type' ) ) );
		if ( '' !== $form ) {
			return $form;
		}
		return null;
	}

	/**
	 * Shopper cautions derived from capabilities: unknown capabilities never
	 * become affirmative claims; missing facts are surfaced as advice to check.
	 *
	 * @param array<string, bool|null> $caps Tri-state capabilities.
	 * @return array<int, string>
	 */
	private function cautions( array $caps ): array {
		$cautions = [ 'مشخصات این مدل از فهرست فروشگاه خوانده شده است؛ سازگاری دستگاه و راحتی را پیش از خرید بررسی کن.' ];
		if ( null === $caps['anc'] ) {
			$cautions[] = 'پشتیبانی ANC این مدل در مشخصات فروشگاه ثبت نشده است.';
		} elseif ( false === $caps['anc'] ) {
			$cautions[] = 'ANC برای شنیدن ندارد؛ کاهش نویز تماس را با آن اشتباه نگیرید.';
		}
		return $cautions;
	}
}
