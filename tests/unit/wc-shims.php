<?php
/**
 * WooCommerce shims + product fixtures for catalog-adapter boundary tests.
 *
 * Fakes the narrow WC API surface the adapter reads: wc_get_products,
 * wc_get_product, wc_get_price_to_display, wc_get_held_stock_quantity,
 * get_woocommerce_currency, plus WP term/permalink helpers. Product objects
 * are stdClass with the getters the adapter calls.
 */

declare(strict_types=1);

$GLOBALS['ts_wc_products'] = [];

/**
 * Complete defaults used by every fake WooCommerce product.
 *
 * @return array<string, mixed>
 */
function ts_wc_defaults(): array {
	return [
		'id'                    => 0,
		'status'                => 'publish',
		'type'                  => 'simple',
		'name'                  => '',
		'catalog_visibility'    => 'visible',
		'in_stock'              => true,
		'purchasable'           => true,
		'image_id'              => 0,
		'attributes'            => [],
		'price'                 => 0.0,
		'managing_stock'        => false,
		'stock_quantity'        => null,
		'stock_managed_by_id'   => 0,
		'stock_status'          => 'instock',
		'backorder'             => false,
		'variation_visible'     => true,
		'children'              => [],
		'variation_attributes'  => [],
	];
}

/**
 * Narrow WC_Product test double implementing only the adapter's boundary.
 */
final class TS_WC_Product_Stub {
	/** @var array<string, mixed> */
	public array $props;

	/** @param array<string, mixed> $props Product properties. */
	public function __construct( array $props ) {
		$this->props = $props;
	}

	public function get_id(): int { return (int) $this->props['id']; }
	public function get_status(): string { return (string) $this->props['status']; }
	public function get_type(): string { return (string) $this->props['type']; }
	public function get_name(): string { return (string) $this->props['name']; }
	public function get_catalog_visibility(): string { return (string) $this->props['catalog_visibility']; }
	public function is_in_stock(): bool { return (bool) $this->props['in_stock']; }
	public function is_purchasable(): bool { return (bool) $this->props['purchasable']; }
	public function get_image_id( string $context = '' ): int { return (int) $this->props['image_id']; }
	public function get_attribute( string $key ): string { return (string) ( $this->props['attributes'][ $key ] ?? '' ); }
	public function is_type( string $type ): bool { return $type === (string) $this->props['type']; }
	/** @return array<int, int> */
	public function get_children(): array { return (array) $this->props['children']; }
	/** @return array<string, string> */
	public function get_attributes(): array { return (array) $this->props['variation_attributes']; }
	public function managing_stock(): bool { return (bool) $this->props['managing_stock']; }
	public function get_stock_quantity(): ?float {
		return null === $this->props['stock_quantity'] ? null : (float) $this->props['stock_quantity'];
	}
	public function get_stock_managed_by_id(): int { return (int) $this->props['stock_managed_by_id']; }
	public function get_stock_status(): string { return (string) $this->props['stock_status']; }
	public function is_on_backorder( int $quantity = 1 ): bool { return (bool) $this->props['backorder']; }
	public function variation_is_visible(): bool { return (bool) $this->props['variation_visible']; }
}

/**
 * Register a product in the fake store.
 *
 * @param int                                  $id     Product ID.
 * @param array<string, mixed>                 $props  Product properties.
 * @param array<int, array<string, mixed>>     $children Variation properties keyed by variation ID.
 */
function ts_wc_product( int $id, array $props, array $children = [] ): void {
	$GLOBALS['ts_wc_products'][ $id ] = [ 'props' => $props, 'children' => $children ];
}

/**
 * Build a fake WC_Product-like object.
 */
function ts_wc_make( int $id, array $props ): object {
	$defaults = array_merge(
		ts_wc_defaults(),
		[ 'id' => $id, 'name' => "Product {$id}", 'stock_managed_by_id' => $id ]
	);
	$complete       = array_merge( $defaults, $props );
	$complete['id'] = $id;
	if ( empty( $complete['stock_managed_by_id'] ) ) {
		$complete['stock_managed_by_id'] = $id;
	}
	return new TS_WC_Product_Stub( $complete );
}

/* ----- WC function shims ----- */
function wc_get_products( array $args ) {
	$GLOBALS['ts_wc_last_query'] = $args;
	$out = [];
	foreach ( $GLOBALS['ts_wc_products'] as $id => $entry ) {
		$props = array_merge( ts_wc_defaults(), $entry['props'] );
		$statuses = (array) ( $args['status'] ?? 'publish' );
		if ( ! in_array( $props['status'], $statuses, true ) ) { continue; }
		if ( ! in_array( (string) $props['type'], (array) $args['type'], true ) ) { continue; }
		if ( isset( $args['product_category_id'] ) && ! array_intersect( (array) ( $props['cats'] ?? [] ), (array) $args['product_category_id'] ) ) { continue; }
		if ( isset( $args['category'] ) ) { continue; } // WooCommerce interprets this field as slugs, never fixture IDs.
		$out[] = ts_wc_make( $id, $props );
	}
	return $args['paginate'] ?? false ? (object) [
		'products'      => $out,
		'total'         => count( $out ),
		'max_num_pages' => 1,
	] : $out;
}
function wc_get_product( $id ) {
	$id = (int) $id;
	if ( isset( $GLOBALS['ts_wc_products'][ $id ] ) ) {
		return ts_wc_make( $id, $GLOBALS['ts_wc_products'][ $id ]['props'] );
	}
	// Variations live in their parent's children list.
	foreach ( $GLOBALS['ts_wc_products'] as $pid => $entry ) {
		foreach ( $entry['children'] as $vid => $child ) {
			if ( (int) $vid === $id ) {
				return ts_wc_make( $id, array_merge( $entry['props'], [ 'type' => 'variation' ], $child ) );
			}
		}
	}
	return false;
}
function wc_get_price_to_display( $product, $qty = 1 ) {
	return (float) $product->props['price'];
}
function wc_get_held_stock_quantity( $product ) {
	return (float) ( $product->props['held'] ?? 0 );
}
function get_woocommerce_currency() {
	return (string) ( $GLOBALS['ts_wc_currency'] ?? 'IRT' );
}
function wc_attribute_label( $key ) {
	return (string) $key;
}
function taxonomy_exists( $taxonomy ) {
	return false; // fixtures store slugs directly; no term lookups.
}
function get_term_by( $field, $value, $taxonomy = '' ) {
	return false;
}

/* ----- WP shims for the adapter ----- */
/**
 * Term registry for category-authority tests: id => [name, slug, parent].
 *
 * @return array<int, array{name:string,slug:string,parent:int}>
 */
function ts_wc_terms(): array {
	return $GLOBALS['ts_wc_term_map'] ?? [
		// Product tree (ids and names as they exist in the store).
		116 => [ 'name' => 'هدفون', 'slug' => 'headphone', 'parent' => 0 ],
		117 => [ 'name' => 'ایرفون', 'slug' => 'earphone', 'parent' => 125 ],
		119 => [ 'name' => 'هدفون بی سیم', 'slug' => 'headphone-wireless', 'parent' => 116 ],
		122 => [ 'name' => 'هدفون گیمینگ', 'slug' => 'headphone-gaming', 'parent' => 116 ],
		125 => [ 'name' => 'هندزفری', 'slug' => 'handsfree', 'parent' => 116 ],
		128 => [ 'name' => 'ایرباد', 'slug' => 'earbud', 'parent' => 125 ],
		// Feature tree «ویژگی ها»: explicit feature claims.
		139 => [ 'name' => 'ویژگی ها', 'slug' => 'features', 'parent' => 0 ],
		144 => [ 'name' => 'بی سیم | بلوتوث', 'slug' => 'wireless-bluetooth', 'parent' => 139 ],
		151 => [ 'name' => 'نویز کنسلینگ', 'slug' => 'noise-cancelling', 'parent' => 139 ],
		34745 => [ 'name' => 'با سیم | سیمی', 'slug' => 'wired', 'parent' => 139 ],
		// A store-created sub-tag under the wireless feature, used to prove
		// that an ancestor category grants its children the capability.
		14401 => [ 'name' => 'بی سیم | بلوتوث نسل جدید', 'slug' => 'wireless-bluetooth-next', 'parent' => 144 ],
	];
}

/**
 * All descendants of a term (any depth), mirroring get_term_children().
 */
function get_term_children( $term_id, $taxonomy = 'product_cat' ) {
	$terms  = ts_wc_terms();
	$parent = (int) $term_id;
	$out    = [];
	$queue  = [ $parent ];
	$guard  = 0;
	while ( $queue && $guard < 50 ) {
		$current = (int) array_shift( $queue );
		foreach ( $terms as $id => $term ) {
			if ( (int) $term['parent'] === $current && ! in_array( $id, $out, true ) ) {
				$out[]   = $id;
				$queue[] = $id;
			}
		}
		$guard++;
	}
	sort( $out );
	return $out;
}

/**
 * Assigned product categories as term objects.
 */
function wp_get_post_terms( $post_id, $taxonomy = 'product_cat', $args = [] ) {
	$cats = [];
	foreach ( $GLOBALS['ts_wc_products'] as $pid => $entry ) {
		if ( (int) $pid === (int) $post_id ) {
			$cats = (array) ( $entry['props']['cats'] ?? [] );
			break;
		}
	}
	$terms = ts_wc_terms();
	$out   = [];
	foreach ( $cats as $cat_id ) {
		$cat_id = (int) $cat_id;
		if ( isset( $terms[ $cat_id ] ) ) {
			$out[] = (object) [
				'term_id' => $cat_id,
				'name'    => $terms[ $cat_id ]['name'],
				'slug'    => $terms[ $cat_id ]['slug'],
			];
		}
	}
	return $out;
}

/**
 * Ancestor chain (nearest first) for a term.
 */
function get_ancestors( $term_id, $taxonomy = 'product_cat', $resource = '' ) {
	$terms   = ts_wc_terms();
	$current = (int) $term_id;
	$out     = [];
	$guard   = 0;
	while ( isset( $terms[ $current ] ) && $terms[ $current ]['parent'] > 0 && $guard < 10 ) {
		$parent = (int) $terms[ $current ]['parent'];
		$out[]  = $parent;
		$current = $parent;
		$guard++;
	}
	return $out;
}

/**
 * Fetch a term object by ID.
 */
function get_term( $term_id, $taxonomy = 'product_cat' ) {
	$terms  = ts_wc_terms();
	$id     = (int) $term_id;
	if ( ! isset( $terms[ $id ] ) ) {
		return null;
	}
	return (object) [
		'term_id' => $id,
		'name'    => $terms[ $id ]['name'],
		'slug'    => $terms[ $id ]['slug'],
		'parent'  => $terms[ $id ]['parent'],
	];
}

/**
 * WP_Error test shim (fixtures never return errors).
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error_Shim;
}

/**
 * Minimal WP_Error stand-in so is_wp_error() can be exercised.
 */
class WP_Error_Shim {}

function get_post_meta( $id, $key, $single = false ) {
	foreach ( $GLOBALS['ts_wc_products'] as $pid => $entry ) {
		if ( (int) $pid === (int) $id ) {
			return (string) ( $entry['props']['meta'][ $key ] ?? '' );
		}
	}
	return '';
}
function wp_get_attachment_image_url( $id, $size = '' ) {
	return $id > 0 ? "https://store.example/img/{$id}.webp" : false;
}
function get_permalink( $id ) {
	$base = $GLOBALS['ts_wc_permalink_base'] ?? 'https://store.example/product/';
	return $base . $id . '/';
}
function get_edit_post_link( $id, $context = 'display' ) {
	return "https://store.example/wp-admin/post.php?post={$id}&action=edit";
}
function has_term( $terms, $taxonomy, $id ) {
	foreach ( $GLOBALS['ts_wc_products'] as $pid => $entry ) {
		if ( (int) $pid === (int) $id ) {
			return (bool) array_intersect( (array) $terms, (array) ( $entry['props']['cats'] ?? [] ) );
		}
	}
	return false;
}
