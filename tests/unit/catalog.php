<?php
/**
 * Catalog-adapter boundary tests with complete WooCommerce fixtures.
 *
 * Usage: php tests/unit/catalog.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/wc-shims.php';
require __DIR__ . '/../../ts-sound-guide.php';

use TSSoundGuide\CatalogAdapter;
use TSSoundGuide\CatalogHealth;
use TSSoundGuide\CapabilityRegistry;
use TSSoundGuide\Settings;
use TSSoundGuide\RecommendationEngine;

/* Settings fixture: earbuds term 11, headphones term 21. */
$GLOBALS['ts_test_option'] = [
	'ts_sound_guide_settings' => [ 'page_id' => 0, 'earbuds_term' => 125, 'headphones_term' => 116 ],
];

/* WP option shim override (wp-shims has no get_option). */
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['ts_test_option'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) {
		return null; // defaults resolved via settings fixture above.
	}
}

$pass = 0; $fail = 0;
function check( string $name, bool $cond ): void {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "ok  {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; }
}

$settings = new Settings();
$registry = new CapabilityRegistry();
$adapter  = new CatalogAdapter( $settings, $registry );
$engine   = new RecommendationEngine();

/* ---------- fixture store ---------- */
// A1: variable earbud, parent-managed stock, explicit capabilities.
ts_wc_product( 101, [
	'type' => 'variable', 'name' => 'Aurora ANC Buds', 'cats' => [ 125 ],
	'attributes' => [
		'pa_bluetooth' => 'بلوتوث دارد',
		'pa_connection' => 'ندارد',
		'pa_noise-cancellation' => 'ANC دارد',
		'pa_qip5asto9pe6c2dzxq' => 'دارد',
		'pa_inside-the-box' => 'سری سیلیکونی',
		'pa_headphones-type' => 'داخل گوش',
	],
	'children' => [ 1011, 1012 ],
], [
	1011 => [ 'type' => 'variation', 'price' => 8000000, 'managing_stock' => true, 'stock_managed_by_id' => 101, 'stock_quantity' => 5, 'held' => 1, 'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ], 'image_id' => 51 ],
	1012 => [ 'type' => 'variation', 'price' => 8500000, 'managing_stock' => false, 'stock_quantity' => null, 'variation_attributes' => [ 'pa_color' => 'white', 'pa_guarantee' => '12m' ], 'image_id' => 52 ],
] );

// A2: variable product with a backordered child and a missing-guarantee child.
ts_wc_product( 102, [
	'type' => 'variable', 'name' => 'Mixed Buds', 'cats' => [ 128 ], // ایرباد: child of هندزفری.
	'attributes' => [ 'pa_bluetooth' => 'دارد' ],
	'children' => [ 1021, 1022 ],
], [
	1021 => [ 'type' => 'variation', 'price' => 4000000, 'backorder' => true, 'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ] ],
	1022 => [ 'type' => 'variation', 'price' => 4200000, 'variation_attributes' => [ 'pa_color' => 'black' ] ], // no guarantee: rejected
] );

// A3: product-status=stop product.
ts_wc_product( 103, [
	'name' => 'Stopped Star', 'cats' => [ 125 ], 'meta' => [ 'product-status' => 'stop' ],
	'attributes' => [ 'pa_bluetooth' => 'دارد' ],
], [ 1031 => [ 'price' => 5000000, 'variation_attributes' => [ 'pa_color' => 'b', 'pa_guarantee' => '6m' ] ] ] );

// A4: hidden catalog visibility.
ts_wc_product( 104, [
	'name' => 'Hidden One', 'cats' => [ 125 ], 'catalog_visibility' => 'hidden',
	'attributes' => [ 'pa_bluetooth' => 'دارد' ],
], [ 1041 => [ 'price' => 5000000, 'variation_attributes' => [ 'pa_color' => 'b', 'pa_guarantee' => '6m' ] ] ] );

// A5: simple headphone in headphones category with managed stock fully held.
ts_wc_product( 105, [
	'type' => 'simple', 'name' => 'Held Headphone', 'cats' => [ 116 ],
	'attributes' => [ 'pa_bluetooth' => 'دارد' ],
	'price' => 9000000, 'managing_stock' => true, 'stock_quantity' => 2, 'held' => 2,
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

// A6: unknown-capability earbud product (no attribute data at all).
ts_wc_product( 106, [
	'name' => 'Mystery Buds', 'cats' => [ 125 ], 'price' => 4000000,
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

// A7: fully mapped but unpublished product; health must explain publication.
ts_wc_product( 107, [
	'status' => 'draft', 'name' => 'Draft Buds', 'cats' => [ 125 ], 'price' => 4500000,
	'attributes' => [
		'pa_bluetooth' => 'دارد', 'pa_connection' => 'ندارد',
		'pa_noise-cancellation' => 'ندارد', 'pa_qip5asto9pe6c2dzxq' => 'ندارد',
		'pa_inside-the-box' => 'سری سیلیکونی', 'pa_headphones-type' => 'داخل گوش',
	],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

// A8: ready headphone. USB-C/silicone are unknown but irrelevant to this flow.
ts_wc_product( 108, [
	'name' => 'Ready Headphone', 'cats' => [ 116 ], 'price' => 9500000,
	'attributes' => [
		'pa_bluetooth' => 'دارد', 'pa_aux' => 'دارد', 'pa_aux-microphone' => 'دارد',
		'pa_noise-cancellation' => 'ندارد', 'pa_qip5asto9pe6c2dzxq' => 'ندارد',
		'pa_headphones-type' => 'روی گوش',
	],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

/* A9-A11: category authority fixtures (rule 1 and rule 3). */
// A9: tagged «نویز کنسلینگ» (151) with NO ANC attribute -> anc must be true.
ts_wc_product( 109, [
	'name' => 'Category ANC Buds', 'cats' => [ 125, 151 ], 'price' => 5200000,
	'attributes' => [ 'pa_bluetooth' => 'دارد', 'pa_inside-the-box' => 'سری سیلیکونی' ],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

// A10: tagged with a sub-tag of «بی سیم | بلوتوث» (14401 under 144) and no
//      bluetooth attribute -> wireless must be true through the ancestor.
ts_wc_product( 110, [
	'name' => 'Ancestor Wireless Headphone', 'cats' => [ 116, 14401 ], 'price' => 11000000,
	'attributes' => [ 'pa_headphones-type' => 'روی گوش' ],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

// A11: tagged «با سیم | سیمی» (34745) with no bluetooth attribute ->
//      wireless false (rule 3).
ts_wc_product( 111, [
	'name' => 'Wired Headphone', 'cats' => [ 116, 34745 ], 'price' => 2200000,
	'attributes' => [ 'pa_headphones-type' => 'روی گوش' ],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

// A12: «نویز کنسلینگ» tag says ANC but the attribute explicitly says ندارد ->
//      category wins (true) and the disagreement is reported as a conflict.
ts_wc_product( 112, [
	'name' => 'Conflicted ANC Buds', 'cats' => [ 125, 151 ], 'price' => 4800000,
	'attributes' => [
		'pa_bluetooth' => 'دارد',
		'pa_noise-cancellation' => 'ندارد',
		'pa_inside-the-box' => 'سری سیلیکونی',
	],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
], [] );

/* ---------- adapter behavior ---------- */
$catalog = $adapter->catalog();
$queried_terms = (array) ( $GLOBALS['ts_wc_last_query']['product_category_id'] ?? [] );
sort( $queried_terms );
check( 'catalog queries the configured trees plus descendants', [ 116, 117, 119, 122, 125, 128 ] === $queried_terms );
$ids = array_keys( $catalog );
sort( $ids );
check( 'catalog includes only eligible products', [ 101, 106, 108, 109, 110, 111, 112 ] === $ids );
check( 'stop product excluded', ! isset( $catalog[103] ) );
check( 'hidden visibility excluded', ! isset( $catalog[104] ) );
check( 'backordered-only product excluded', ! isset( $catalog[102] ) );
check( 'fully-held stock excluded', ! isset( $catalog[105] ) );
check( 'child-category membership resolves earbuds flow', 'earbuds' === $catalog[101]['flow'] );

$v = $catalog[101]['variants'];
check( 'parent-managed net stock keeps variant', count( $v ) === 2 );
check( 'price carried in toman (IRT 1:1)', 8000000.0 === $v[0]['price'] );
check( 'variation query includes color+guarantee', isset( $v[0]['query']['attribute_pa_color'] ) && isset( $v[0]['query']['attribute_pa_guarantee'] ) );

check( 'explicit attributes map to confirmed capabilities', true === $catalog[101]['capabilities']['anc'] && true === $catalog[101]['capabilities']['wireless'] && true === $catalog[101]['capabilities']['multipoint'] && true === $catalog[101]['capabilities']['silicone'] );
check( 'absent attributes resolve to false (absence policy)', false === $catalog[106]['capabilities']['anc'] && false === $catalog[106]['capabilities']['usbc'] );
check( 'missing form factor is not replaced with an inferred label', null === $catalog[106]['form'] );

/* ---------- category authority (rule 1) and negation (rule 3) ---------- */
check( 'category names the capability -> true without any attribute', true === $catalog[109]['capabilities']['anc'] );
check( 'ancestor category implies capability for its children', true === $catalog[110]['capabilities']['wireless'] );
check( 'wireless stays false for an absent attribute without a category signal', false === $catalog[111]['capabilities']['wireless'] );
check( 'category negation forces false', false === $catalog[111]['capabilities']['wireless'] );
check( 'category authority beats an explicit negative attribute', true === $catalog[112]['capabilities']['anc'] );
check( 'category/attribute disagreement is reported as a conflict', 'category_conflict' === ( $catalog[112]['conflicts']['anc'] ?? null ) );
check( 'unconflicted product reports no conflicts', [] === $catalog[109]['conflicts'] );
check( 'silicone from a plain form-factor name is not a conflict', ! isset( $catalog[110]['conflicts']['silicone'] ) );

/* ---------- public budget span: cheapest to most expensive offer ---------- */
$span_source = [
	[ 'flow' => 'earbuds', 'variants' => [ [ 'price' => 1500000.0 ], [ 'price' => 2500000.0 ] ] ],
	[ 'flow' => 'headphones', 'variants' => [ [ 'price' => 7000000.0 ] ] ],
	[ 'flow' => 'earbuds', 'variants' => [ [ 'price' => 0.0 ] ] ],
	[ 'flow' => '', 'variants' => [ [ 'price' => 9000000.0 ] ] ],
];
$span = CatalogAdapter::price_range_of( $span_source );
check( 'price span starts at the cheapest eligible price', 1500000.0 === $span['min'] );
check( 'price span ends at the most expensive eligible offer', 9000000.0 === $span['max'] );
check( 'price span is reported per flow', [ 'min' => 1500000.0, 'max' => 2500000.0 ] === $span['byFlow']['earbuds'] && [ 'min' => 7000000.0, 'max' => 7000000.0 ] === $span['byFlow']['headphones'] );
check( 'unpriced variants never enter the span', 1500000.0 === $span['min'] );
check( 'a product outside a flow never creates a per-flow span', ! isset( $span['byFlow'][''] ) );
check( 'an empty catalog yields no span', [ 'min' => null, 'max' => null, 'byFlow' => [] ] === CatalogAdapter::price_range_of( [] ) );

$live_span = $adapter->price_range();
$live_prices = [];
foreach ( $catalog as $live_product ) {
	foreach ( $live_product['variants'] as $live_variant ) {
		$live_prices[] = (float) $live_variant['price'];
	}
}
check( 'live price span matches the eligible catalog', min( $live_prices ) === $live_span['min'] && max( $live_prices ) === $live_span['max'] );

/* ---------- catalog health sees products filtered out of recommendations ---------- */
$health = ( new CatalogHealth( $adapter ) )->report();
$unusable_ids = array_column( $health['groups']['unusable'], 'id' );
sort( $unusable_ids );
$issue_ids = array_unique( array_map( static fn( array $issue ): int => (int) $issue['product']['id'], $health['issues'] ) );
sort( $issue_ids );
check( 'health report includes every mapped or unusable relevant product', 12 === array_sum( $health['summary'] ) );
check( 'health report groups filtered commerce failures as unusable', [ 102, 103, 104, 105, 107 ] === $unusable_ids );
check( 'health report attaches actionable issues to unusable products', [] === array_diff( [ 102, 103, 104, 105, 107 ], $issue_ids ) );
$ready_ids = array_column( $health['groups']['ready'], 'id' );
sort( $ready_ids );
check( 'health applies only flow-relevant capabilities', [ 101, 108, 110, 111 ] === $ready_ids );
$mystery_capabilities = array_values( array_map(
	static fn( array $issue ): string => (string) $issue['capability'],
	array_filter( $health['issues'], static fn( array $issue ): bool => 106 === (int) $issue['product']['id'] )
) );
check( 'health flags missing form factor', in_array( 'form', $mystery_capabilities, true ) );
check( 'earbud health omits irrelevant AUX fields', ! in_array( 'aux', $mystery_capabilities, true ) && ! in_array( 'auxMic', $mystery_capabilities, true ) );

/* ---------- currency variants (each in its own subprocess) ---------- */
if ( $argc > 1 && 'irr' === $argv[1] ) {
	$GLOBALS['ts_wc_currency'] = 'IRR';
	$irr_adapter = new CatalogAdapter( $settings, $registry );
	$irr_catalog = $irr_adapter->catalog();
	check( 'IRR display price divided by ten', 800000.0 === $irr_catalog[101]['variants'][0]['price'] );
	exit( $fail ? 1 : 0 );
}
if ( $argc > 1 && 'usd' === $argv[1] ) {
	$GLOBALS['ts_wc_currency'] = 'USD';
	$usd_adapter = new CatalogAdapter( $settings, $registry );
	try {
		$usd_adapter->catalog();
		check( 'unsupported currency throws', false );
	} catch ( Throwable $e ) {
		check( 'unsupported currency throws controlled diagnostic', true );
	}
	exit( $fail ? 1 : 0 );
}

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
