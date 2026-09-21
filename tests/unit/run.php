<?php
/**
 * PHP unit test suite (no framework): services and boundary behaviors.
 *
 * Usage: php tests/unit/run.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/../../ts-sound-guide.php';

use TSSoundGuide\CapabilityRegistry;
use TSSoundGuide\PublicDto;
use TSSoundGuide\RecommendationEngine;

$pass = 0;
$fail = 0;

/**
 * Assert helper.
 *
 * @param string $name  Test name.
 * @param bool   $cond  Condition.
 */
function check( string $name, bool $cond ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "ok  {$name}\n";
	} else {
		$fail++;
		echo "FAIL {$name}\n";
	}
}

/* ---------------- normalization ---------------- */
$engine = new RecommendationEngine();

$a = $engine->normalize( [ 'flow' => 'headphones', 'use' => 'music', 'pain' => 'noise', 'connection' => 'wireless', 'fit' => 'open', 'budget' => '1' ] );
check( 'normalize clamps low budget to 500000', 500000.0 === $a['budget'] );
check( 'normalize drops earbud fit for headphones', ! array_key_exists( 'fit', $a ) );

$a = $engine->normalize( [ 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'fit' => 'any', 'budget' => 'abc' ] );
check( 'normalize defaults invalid budget to 6000000', 6000000.0 === $a['budget'] );

$a = $engine->normalize( [ 'use' => 'commute', 'pain' => 'noise', 'connection' => 'aux', 'device' => 'lightning', 'fit' => 'any', 'budget' => 6000000 ] );
check( 'normalize drops device when connection is not usbc', ! array_key_exists( 'device', $a ) );

$a = $engine->normalize( [ 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'fit' => 'any', 'budget' => 6000000000 ] );
check( 'normalize clamps high budget to 500000000', 500000000.0 === $a['budget'] );

$a = $engine->normalize( [ 'use' => 'work', 'pain' => 'balanced', 'connection' => 'aux', 'calls' => 'quiet', 'fit' => 'any', 'budget' => 6000000 ] );
check( 'normalize keeps calls for work', 'quiet' === ( $a['calls'] ?? null ) );

$a = $engine->normalize( [ 'use' => 'music', 'pain' => 'balanced', 'connection' => 'aux', 'calls' => 'quiet', 'fit' => 'any', 'budget' => 6000000 ] );
check( 'normalize drops calls without work/calls pain', ! array_key_exists( 'calls', $a ) );

/* ---------------- capability registry ---------------- */
$registry = new CapabilityRegistry();

check( 'ANC yes from explicit ANC value', true === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ANC دارد' ] ) );
check( 'ANC stays unknown from ENC-only value', null === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ENC دارد' ] ) );
check( 'ANC no from explicit ندارد', false === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ندارد' ] ) );
check( 'ANC unknown when absent', null === $registry->resolve( 'anc', [] ) );
check( 'ANC unknown on contradictory value', null === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ANC دارد ولی ندارد' ] ) );
check( 'wireless no from explicit ندارد', false === $registry->resolve( 'wireless', [ 'pa_bluetooth' => 'ندارد' ] ) );
check( 'wireless contradictory yes/no stays unknown', null === $registry->resolve( 'wireless', [ 'pa_bluetooth' => 'دارد ولی ندارد' ] ) );
check( 'USB-C yes only from audio-capable value', true === $registry->resolve( 'usbc', [ 'pa_connection' => 'USB-C audio' ] ) );
check( 'USB-C unknown from generic charging port text', null === $registry->resolve( 'usbc', [ 'pa_connection' => 'درگاه شارژ' ] ) );
check( 'AUX mic yes from explicit AUX-mic value', true === $registry->resolve( 'auxMic', [ 'pa_aux-microphone' => 'میکروفون دارد' ] ) );
check( 'AUX mic unknown when attribute absent', null === $registry->resolve( 'auxMic', [ 'pa_bluetooth' => 'دارد' ] ) );
check( 'fit yes from box contents', true === $registry->resolve( 'silicone', [ 'pa_inside-the-box' => 'سری سیلیکونی' ] ) );
check( 'fit no from form factor fallback', false === $registry->resolve( 'silicone', [ 'pa_headphones-type' => 'open-ear' ] ) );

/* ---------------- public DTO allow-list ---------------- */
$dto = new PublicDto();
$product = [
	'id' => 5, 'wcId' => 5, 'name' => 'X', 'flow' => 'earbuds', 'url' => 'https://store.example/p/5',
	'image' => null, 'price' => 100.0, 'form' => 'فرم', 'cautions' => [ 'c' ], 'sources' => [],
	'reasons' => [ 'r' ], 'role' => 'پیشنهاد اصلی', 'overBudget' => false,
	'capabilities' => [ 'wireless' => true, 'usbc' => null, 'aux' => null, 'auxMic' => null, 'anc' => true, 'multipoint' => null, 'silicone' => null ],
	'variants' => [ [ 'id' => 51, 'price' => 100.0, 'attributes' => [], 'label' => 'مشکی', 'image' => null, 'qty' => 9, 'held' => 2, 'stockOwnerId' => 5 ] ],
	'fitScore' => 87, 'qty' => 7, 'status' => 'publish', 'lifecycle' => 'active', 'inStock' => true, 'purchasable' => true,
];
$public = $dto->product( $product );
$encoded = json_encode( $public );
check( 'DTO omits fitScore', false === strpos( $encoded, 'fitScore' ) );
check( 'DTO omits qty', false === strpos( $encoded, '"qty"' ) );
check( 'DTO omits held', false === strpos( $encoded, '"held"' ) );
check( 'DTO omits stockOwnerId', false === strpos( $encoded, 'stockOwnerId' ) );
check( 'DTO omits status/lifecycle', false === strpos( $encoded, 'lifecycle' ) );
check( 'DTO carries flat capabilities', true === $public['wireless'] && true === $public['anc'] );
check( 'DTO carries variants allow-list', isset( $public['variants'][0]['id'] ) && ! isset( $public['variants'][0]['qty'] ) );

/* ---------------- availability edge cases ---------------- */
$dead = [ 'status' => 'publish', 'lifecycle' => 'stop', 'inStock' => true, 'purchasable' => true, 'variants' => [ [ 'id' => 1, 'price' => 10.0, 'qty' => 5.0, 'held' => 0.0 ] ] ];
check( 'stopped lifecycle yields no variants', [] === $engine->available_variants( $dead ) );

$held_out = [ 'status' => 'publish', 'lifecycle' => 'active', 'inStock' => true, 'purchasable' => true, 'variants' => [ [ 'id' => 1, 'price' => 10.0, 'qty' => 3.0, 'held' => 3.0 ] ] ];
check( 'managed stock fully held yields no variants', [] === $engine->available_variants( $held_out ) );

$unmanaged = [ 'status' => 'publish', 'lifecycle' => 'active', 'inStock' => true, 'purchasable' => true, 'variants' => [ [ 'id' => 1, 'price' => 10.0, 'qty' => null, 'held' => 0.0 ] ] ];
check( 'unmanaged stock stays eligible (not coerced to zero)', 1 === count( $engine->available_variants( $unmanaged ) ) );

/* ---------------- unknown-capability path semantics ---------------- */
require __DIR__ . '/../characterization/fixtures.php';
$fixture_product = ts_sound_fixtures_catalog()[0]; // Aurora ANC Buds
$caps = [];
foreach ( [ 'wireless', 'usbc', 'aux', 'auxMic', 'anc', 'multipoint', 'silicone' ] as $key ) {
	$caps[ $key ] = $fixture_product[ $key ] ?? null;
}
$fixture_product['capabilities'] = $caps;

// ANC required: unknown anc must exclude. Fixture P1 is 8M; use a 20M budget
// so price never blocks, isolating the capability rule.
$unknown_anc = $fixture_product;
$unknown_anc['capabilities']['anc'] = null;
$result = $engine->select( [ 'flow' => 'earbuds', 'use' => 'commute', 'pain' => 'noise', 'connection' => 'wireless', 'fit' => 'silicone', 'budget' => 20000000 ], [ $unknown_anc ] );
check( 'unknown required ANC excludes from that path', 0 === $result['total'] && 'anc' === $result['rejected'][0]['reason'] );

// Same product on a path that does not need ANC stays eligible.
$result = $engine->select( [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'fit' => 'silicone', 'budget' => 20000000 ], [ $unknown_anc ] );
check( 'unknown irrelevant capability still recommendable', 1 === $result['total'] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
