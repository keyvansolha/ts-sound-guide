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
/* Absence policy (WP-45 §2): an unlisted capability is never turned into a
 * false claim about the product. The store saying nothing keeps the third
 * state; only an explicit value, a negating category, or an unreadable value
 * answers for the store. */
check( 'ANC stays unknown when the attribute is not listed', null === $registry->resolve( 'anc', [] ) );
check( 'unlisted ANC is not counted as a data-quality defect', null === $registry->conflict( 'anc', [] ) );
check( 'ANC unknown on contradictory value', null === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ANC دارد ولی ندارد' ] ) );
check( 'wireless no from explicit ندارد', false === $registry->resolve( 'wireless', [ 'pa_bluetooth' => 'ندارد' ] ) );
check( 'wireless contradictory yes/no stays unknown', null === $registry->resolve( 'wireless', [ 'pa_bluetooth' => 'دارد ولی ندارد' ] ) );
check( 'USB-C yes only from audio-capable value', true === $registry->resolve( 'usbc', [ 'pa_connection' => 'USB-C audio' ] ) );
check( 'USB-C unknown from generic charging port text', null === $registry->resolve( 'usbc', [ 'pa_connection' => 'درگاه شارژ' ] ) );
check( 'AUX mic yes from explicit AUX-mic value', true === $registry->resolve( 'auxMic', [ 'pa_aux-microphone' => 'میکروفون دارد' ] ) );
check( 'AUX mic stays unknown when the attribute is not listed', null === $registry->resolve( 'auxMic', [ 'pa_bluetooth' => 'دارد' ] ) );
check( 'fit yes from box contents', true === $registry->resolve( 'silicone', [ 'pa_inside-the-box' => 'سری سیلیکونی' ] ) );
check( 'fit no from form factor fallback', false === $registry->resolve( 'silicone', [ 'pa_headphones-type' => 'open-ear' ] ) );

/* ---------------- category authority (rule 1) and negation (rule 3) ----------------
 * Signals are the store's real «ویژگی ها» feature categories:
 * 144 «بی سیم | بلوتوث», 34745 «با سیم | سیمی», 151 «نویز کنسلینگ». */
$anc_category    = [ [ 'id' => '151', 'name' => 'نویز کنسلینگ', 'slug' => 'noise-cancelling' ] ];
$wireless_category = [ [ 'id' => '144', 'name' => 'بی سیم | بلوتوث', 'slug' => 'wireless-bluetooth' ] ];
$wired_category  = [ [ 'id' => '34745', 'name' => 'با سیم | سیمی', 'slug' => 'wired' ] ];

check( 'feature category grants capability with no attribute', true === $registry->resolve( 'anc', [], $anc_category ) );
check( 'feature category grants wireless with no attribute', true === $registry->resolve( 'wireless', [], $wireless_category ) );
check( 'category authority beats an explicit negative attribute', true === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ندارد' ], $anc_category ) );
check( 'category/attribute disagreement reported', 'category_conflict' === $registry->conflict( 'anc', [ 'pa_noise-cancellation' => 'ندارد' ], $anc_category ) );
check( 'category negation forces false for an absent attribute', false === $registry->resolve( 'wireless', [], $wired_category ) );
check( 'explicit positive attribute beats category negation', true === $registry->resolve( 'wireless', [ 'pa_bluetooth' => 'دارد' ], $wired_category ) );

// Term ID matching is primary; the name fragment is what keeps a re-created
// category working. Both are exercised independently.
check( 'term ID matches regardless of a renamed category', true === $registry->resolve( 'anc', [], [ [ 'id' => '151', 'name' => 'دسته‌بندی تغییرنام‌یافته', 'slug' => 'renamed' ] ] ) );
check( 'name fragment matches a category created with a new ID', true === $registry->resolve( 'anc', [], [ [ 'id' => '99001', 'name' => 'نویز کنسلینگ', 'slug' => 'anc-new' ] ] ) );
check( 'a form category that names the capability grants it (هدفون بی سیم)', true === $registry->resolve( 'wireless', [], [ [ 'id' => '119', 'name' => 'هدفون بی سیم', 'slug' => 'headphone-wireless' ] ] ) );
check( 'form categories that state no capability grant nothing (ایرباد)', null === $registry->resolve( 'silicone', [], [ [ 'id' => '128', 'name' => 'ایرباد', 'slug' => 'earbud' ] ] ) );
check( 'unrelated category implies nothing', null === $registry->resolve( 'anc', [], [ [ 'id' => '106', 'name' => 'لوازم جانبی', 'slug' => 'accessories' ] ] ) );

// Category membership never grants capabilities the store tree does not claim.
check( 'no USB-C category exists, so USB-C stays attribute-only', null === $registry->resolve( 'usbc', [], [ [ 'id' => '116', 'name' => 'هدفون', 'slug' => 'headphone' ] ] ) );
check( 'no multipoint category exists in the tree', null === $registry->resolve( 'multipoint', [], [ [ 'id' => '144', 'name' => 'بی سیم | بلوتوث', 'slug' => 'wireless-bluetooth' ] ] ) );
check( 'no AUX category exists in the tree', null === $registry->resolve( 'aux', [], [ [ 'id' => '34745', 'name' => 'با سیم | سیمی', 'slug' => 'wired' ] ] ) );

check( 'absence policy keeps every capability unknown until the store lists it', null === $registry->resolve( 'multipoint', [] ) && null === $registry->resolve( 'silicone', [] ) && null === $registry->resolve( 'usbc', [] ) );
check( 'the three states stay independent', null === $registry->resolve( 'anc', [] ) && true === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ANC دارد' ] ) && false === $registry->resolve( 'anc', [ 'pa_noise-cancellation' => 'ندارد' ] ) );

/* WP-45 §2: USB-C charging is never read as USB-C audio. */
check( 'USB-C charging-only text is an explicit no for USB-C audio', false === $registry->resolve( 'usbc', [ 'pa_connection' => 'بی‌سیم از طریق بلوتوث؛ USB-C کیس فقط برای شارژ' ] ) );
check( 'USB-C charging-only text with audio wording still counts', true === $registry->resolve( 'usbc', [ 'pa_connection' => 'بی‌سیم و با کابل صدای USB-C؛ شارژ کیس هم دارد' ] ) );
check( 'a USB-C audio connector is confirmed', true === $registry->resolve( 'usbc', [ 'pa_connection' => 'باسیم با کانکتور USB-C؛ سازگاری پخش وابسته به دستگاه میزبان' ] ) );
check( 'a charging port is never used as a USB-C audio source', null === $registry->resolve( 'usbc', [ 'pa_charging-port' => 'USB-C' ] ) );
check( 'R50i reading: no USB-C audio, but silicone tips are confirmed', false === $registry->resolve( 'usbc', [ 'pa_connection' => 'بی‌سیم از طریق بلوتوث؛ USB-C کیس فقط برای شارژ' ] ) && true === $registry->resolve( 'silicone', [ 'pa_inside-the-box' => '2 ایرباد، کیس شارژ، 3 جفت ایرتیپ، کابل USB-A به USB-C' ] ) );
check( 'the alternate source answers when the primary carries no answer', true === $registry->resolve( 'silicone', [ 'pa_inside-the-box' => 'کابل USB-C', 'pa_headphones-type' => 'داخل گوش (In-Ear) با سری سیلیکونی' ] ) );
check( 'a form-factor name alone is not a tip claim', null === $registry->resolve( 'silicone', [ 'pa_headphones-type' => 'روی گوش ( on-ear )' ] ) );
check( 'open fit is reported when the store says so', false === $registry->resolve( 'silicone', [ 'pa_inside-the-box' => 'بدون سری سیلیکونی اضافه' ] ) );
check( 'category negation against an affirmative attribute is reported', 'category_negation_conflict' === $registry->conflict( 'wireless', [ 'pa_bluetooth' => 'دارد' ], $wired_category ) );
check( 'absent attribute is not a conflict', null === $registry->conflict( 'anc', [], $anc_category ) );
check( 'unreadable primary attribute stays unknown', null === $registry->resolve( 'usbc', [ 'pa_connection' => 'درگاه شارژ' ] ) );

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
check( 'unknown required ANC excludes from that path', 0 === $result['total'] && 'anc_not_listed' === $result['rejected'][0]['reason'] );

// Same product on a path that does not need ANC stays eligible.
$result = $engine->select( [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'fit' => 'silicone', 'budget' => 20000000 ], [ $unknown_anc ] );
check( 'unknown irrelevant capability still recommendable', 1 === $result['total'] );

/* ---------------- ANC only exists while the product runs wirelessly ---------------- */
// Hybrid headphones: ANC plus an AUX jack. Plug the cable in and the
// noise-cancelling circuit switches off, so a wired path can never satisfy an
// ANC priority - the engine must reject it instead of promising it.
$hybrid = [
	'id' => 900, 'wcId' => 900, 'name' => 'Hybrid ANC Headphones', 'flow' => 'headphones',
	'status' => 'publish', 'lifecycle' => 'active', 'inStock' => true, 'purchasable' => true,
	'capabilities' => [ 'wireless' => true, 'usbc' => false, 'aux' => true, 'auxMic' => true, 'anc' => true, 'multipoint' => true, 'silicone' => false ],
	'variants' => [ [ 'id' => 9001, 'price' => 5000000.0 ] ],
];

$result = $engine->select( [ 'flow' => 'headphones', 'use' => 'music', 'pain' => 'noise', 'connection' => 'aux', 'budget' => 6000000 ], [ $hybrid ] );
check( 'ANC priority on a wired path rejects the model', 0 === $result['total'] && 'anc_wired' === ( $result['rejected'][0]['reason'] ?? '' ) );
check( 'ANC priority on a wired path explains why', false !== mb_strpos( implode( ' ', $result['notices'] ), 'ANC' ) );

$result = $engine->select( [ 'flow' => 'headphones', 'use' => 'music', 'pain' => 'noise', 'connection' => 'wireless', 'budget' => 6000000 ], [ $hybrid ] );
check( 'ANC priority on a wireless path keeps the model', 1 === $result['total'] );
check( 'ANC is credited as a reason only on a wireless path', in_array( 'کاهش صدای محیط با ANC', $result['picks'][0]['reasons'], true ) );

// use=commute nudges towards ANC: that nudge must not appear on a wired path.
$result = $engine->select( [ 'flow' => 'headphones', 'use' => 'commute', 'pain' => 'balanced', 'connection' => 'aux', 'budget' => 6000000 ], [ $hybrid ] );
check( 'commute never credits ANC on a wired path', 1 === $result['total'] && ! in_array( 'کاهش صدای محیط با ANC', $result['picks'][0]['reasons'], true ) );

// An ANC claim without a wireless mode cannot be offered as an ANC pick.
$anc_only = $hybrid;
$anc_only['capabilities']['wireless'] = false;
$result = $engine->select( [ 'flow' => 'headphones', 'use' => 'music', 'pain' => 'noise', 'connection' => 'wireless', 'budget' => 6000000 ], [ $anc_only ] );
check( 'ANC without a wireless mode is never offered for an ANC priority', 0 === $result['total'] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
