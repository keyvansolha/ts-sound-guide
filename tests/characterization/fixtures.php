<?php
/**
 * Characterization fixtures for the legacy ts_sound_select() engine.
 *
 * The catalog below is synthetic but shaped exactly like the internal product
 * arrays the live engine consumed (see includes/inventory.php mapping and
 * includes/profiles.json). Every rule the design spec requires to survive the
 * refactor is exercised by at least one product/scenario pair:
 *
 *   - both product flows (earbuds / headphones);
 *   - wireless / USB-C / AUX connection constraints;
 *   - required ANC and multipoint paths;
 *   - open and silicone fit;
 *   - AUX microphone support for work/calls;
 *   - gaming over wireless (always rejected);
 *   - budget clamp bounds and the 20-percent flexibility cap;
 *   - unavailable, backordered, and zero-net-stock variants;
 *   - equal-score / equal-price tie-breaking with parent-managed stock dedup;
 *   - unknown (null) capabilities on required and non-required paths.
 *
 * phpcs:disable Generic.Files.LineLength -- data tables read best unwrapped.
 */

declare(strict_types=1);

/**
 * @return array<int, array<string, mixed>> synthetic internal catalog
 */
function ts_sound_fixtures_catalog(): array {
	$v = static function ( int $id, float $price, ?float $qty, float $held = 0.0, ?int $owner = null, array $extra = [] ): array {
		return array_merge( [
			'id'         => $id,
			'price'      => $price,
			'qty'        => $qty,
			'held'       => $held,
			'stockOwnerId' => $owner ?? $id,
			'inStock'    => true,
			'status'     => 'publish',
			'stockStatus' => 'instock',
			'enabled'    => true,
			'backorder'  => false,
			'attributes' => [ [ 'name' => 'رنگ', 'slug' => 'pa_color', 'option' => 'مشکی' ] ],
			'label'      => 'مشکی · ۶ ماه',
			'image'      => null,
			'query'      => [ 'attribute_pa_color' => 'black', 'attribute_pa_guarantee' => '6m' ],
		], $extra );
	};

	$p = static function ( int $id, string $name, string $flow, array $caps, array $variants, array $extra = [] ): array {
		return array_merge( [
			'id'          => $id,
			'wcId'        => $id + 1000,
			'name'        => $name,
			'flow'        => $flow,
			'wireless'    => $caps['wireless'] ?? null,
			'usbc'        => $caps['usbc'] ?? null,
			'aux'         => $caps['aux'] ?? null,
			'auxMic'      => $caps['auxMic'] ?? null,
			'anc'         => $caps['anc'] ?? null,
			'multipoint'  => $caps['multipoint'] ?? null,
			'silicone'    => $caps['silicone'] ?? null,
			'form'        => $caps['form'] ?? 'فرم تست',
			'cautions'    => [ 'مشخصات این مدل از فهرست فروشگاه خوانده شده است؛ سازگاری دستگاه و راحتی را پیش از خرید بررسی کن.' ],
			'sources'     => [ [ 'label' => 'مشخصات ثبت‌شده در فروشگاه', 'url' => 'https://store.example/product/' . $id ] ],
			'url'         => 'https://store.example/product/' . $id,
			'image'       => null,
			'variants'    => $variants,
			'status'      => 'publish',
			'lifecycle'   => 'active',
			'inStock'     => true,
			'purchasable' => true,
		], $extra );
	};

	return [
		// P1: flagship earbuds, everything confirmed, multi-variant.
		$p( 10, 'Aurora ANC Buds', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => true, 'silicone' => true,
			'form' => 'سری سیلیکونی قابل تعویض',
		], [
			$v( 101, 8000000.0, 5.0, 1.0, 10 ),   // parent-managed (owner = product id 10)
			$v( 102, 8500000.0, 3.0 ),
			$v( 103, 9500000.0, null ),           // unmanaged stock
		] ),
		// P2: budget earbuds, no ANC/multipoint, one dead variant.
		$p( 20, 'Nova Bass Buds', 'earbuds', [
			'wireless' => true, 'anc' => false, 'multipoint' => false, 'silicone' => true,
		], [
			$v( 201, 5000000.0, 10.0 ),
			$v( 202, 5500000.0, 4.0, 0.0, 20, [ 'inStock' => false, 'stockStatus' => 'outofstock' ] ),
		] ),
		// P3: open-fit earbuds with ANC.
		$p( 30, 'Open Air One', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => false, 'silicone' => false,
			'form' => 'بدون سری سیلیکونی',
		], [ $v( 301, 12000000.0, 2.0 ), $v( 302, 13000000.0, 1.0 ) ] ),
		// P4: wired USB-C earbuds.
		$p( 40, 'Wire Studio USB-C', 'earbuds', [
			'wireless' => false, 'usbc' => true, 'anc' => false, 'silicone' => false,
		], [ $v( 401, 2500000.0, 8.0 ) ] ),
		// P5: AUX headphones with confirmed AUX microphone.
		$p( 50, 'Stage AUX Pro', 'headphones', [
			'aux' => true, 'auxMic' => true, 'wireless' => false, 'multipoint' => false,
		], [ $v( 501, 3000000.0, 4.0 ) ] ),
		// P6: flagship wireless headphones.
		$p( 60, 'Metro ANC Headphone', 'headphones', [
			'wireless' => true, 'anc' => true, 'multipoint' => true,
		], [
			$v( 601, 15000000.0, 6.0, 2.0, 60 ),
			$v( 602, 16500000.0, 1.0 ),
		] ),
		// P7: sellable earbuds with all recommendation capabilities unknown.
		$p( 70, 'Mystery Buds', 'earbuds', [
			'wireless' => true,
		], [ $v( 701, 4000000.0, 2.0 ) ] ),
		// P8/P9: identical twins for the inventory tie-break; P8 carries two
		// parent-managed variants (dedup: 10 - 3 counted once = 7), P9 has 20.
		$p( 80, 'Tie Alpha', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => true, 'silicone' => true,
		], [ $v( 801, 6000000.0, 10.0, 3.0, 80 ), $v( 802, 6200000.0, 10.0, 3.0, 80 ) ] ),
		$p( 90, 'Tie Beta', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => true, 'silicone' => true,
		], [ $v( 901, 6000000.0, 20.0 ) ] ),
		// P10: flexibility boundary product (1.19x / 1.233x of a 6M budget).
		$p( 100, 'Budget Stretch', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => true, 'silicone' => true,
		], [ $v( 1001, 7140000.0, 3.0 ), $v( 1002, 7400000.0, 3.0 ) ] ),
		// P11: lifecycle stop — ineligible regardless of stock.
		$p( 110, 'Stopped Star', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => true, 'silicone' => true,
		], [ $v( 1101, 6000000.0, 9.0 ) ], [ 'lifecycle' => 'stop' ] ),
		// P12: unpublished product.
		$p( 120, 'Draft Dud', 'earbuds', [
			'wireless' => true, 'anc' => true,
		], [ $v( 1201, 6000000.0, 9.0 ) ], [ 'status' => 'draft' ] ),
		// P13: every variant fails eligibility (backorder flag, on-backorder
		// status, and managed stock with zero net quantity).
		$p( 130, 'Backorder Bud', 'earbuds', [
			'wireless' => true, 'anc' => true,
		], [
			$v( 1301, 6000000.0, 9.0, 0.0, 130, [ 'backorder' => true ] ),
			$v( 1302, 6500000.0, 9.0, 0.0, 130, [ 'stockStatus' => 'onbackorder' ] ),
			$v( 1303, 7000000.0, 0.0, 0.0, 130 ),
		] ),
		// P14: only affordable far above the default budget.
		$p( 140, 'Pricey Pro', 'earbuds', [
			'wireless' => true, 'anc' => true, 'multipoint' => true, 'silicone' => true,
		], [ $v( 1401, 50000000.0, 5.0 ) ] ),
		// P15: AUX earbuds with a confirmed AUX microphone.
		$p( 150, 'AUX Talk Nano', 'earbuds', [
			'aux' => true, 'auxMic' => true, 'wireless' => false,
		], [ $v( 1501, 1800000.0, 5.0 ) ] ),
		// P16: multipoint confirmed, ANC unknown (matches P1 score on work/switch).
		$p( 160, 'Silence Unknown ANC', 'earbuds', [
			'wireless' => true, 'multipoint' => true, 'silicone' => true,
		], [ $v( 1601, 5000000.0, 6.0 ) ] ),
		// P17: AUX earbuds with unknown AUX microphone (pins wired_microphone rejection).
		$p( 170, 'AUX Silent Nano', 'earbuds', [
			'aux' => true, 'auxMic' => null, 'wireless' => false,
		], [ $v( 1701, 1600000.0, 5.0 ) ] ),
	];
}

/**
 * @return array<string, array<string, mixed>> answer scenarios by name
 */
function ts_sound_fixtures_scenarios(): array {
	return [
		'earbuds-commute-noise-wireless-silicone' => [
			'flow' => 'earbuds', 'use' => 'commute', 'pain' => 'noise',
			'connection' => 'wireless', 'fit' => 'silicone', 'budget' => 6000000,
		],
		'earbuds-work-switch-wireless-any' => [
			'flow' => 'earbuds', 'use' => 'work', 'pain' => 'switch',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 6000000,
		],
		'earbuds-music-balanced-usbc-device' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'usbc', 'device' => 'usbc', 'fit' => 'silicone', 'budget' => 6000000,
		],
		'headphones-work-calls-aux-quiet' => [
			'flow' => 'headphones', 'use' => 'work', 'pain' => 'calls',
			'connection' => 'aux', 'calls' => 'quiet', 'budget' => 6000000,
		],
		'headphones-work-calls-aux-noisy' => [
			'flow' => 'headphones', 'use' => 'work', 'pain' => 'calls',
			'connection' => 'aux', 'calls' => 'noisy', 'budget' => 6000000,
		],
		'earbuds-gaming-wireless' => [
			'flow' => 'earbuds', 'use' => 'gaming', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 6000000,
		],
		'headphones-music-noise-wireless' => [
			'flow' => 'headphones', 'use' => 'music', 'pain' => 'noise',
			'connection' => 'wireless', 'budget' => 6000000,
		],
		'earbuds-fit-open-noise' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'noise',
			'connection' => 'wireless', 'fit' => 'open', 'budget' => 6000000,
		],
		'earbuds-music-balanced-wireless-any-unavailable-paths' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 6000000,
		],
		'earbuds-aux-calls-quiet' => [
			'flow' => 'earbuds', 'use' => 'work', 'pain' => 'calls',
			'connection' => 'aux', 'calls' => 'quiet', 'fit' => 'any', 'budget' => 6000000,
		],
		'earbuds-aux-calls-unknown-mic' => [
			'flow' => 'earbuds', 'use' => 'work', 'pain' => 'calls',
			'connection' => 'aux', 'calls' => 'quiet', 'fit' => 'any', 'budget' => 3000000,
		],
		'headphones-music-noise-wireless-bigbudget' => [
			'flow' => 'headphones', 'use' => 'music', 'pain' => 'noise',
			'connection' => 'wireless', 'budget' => 20000000,
		],
		'earbuds-commute-balanced-wireless-any-bigbudget' => [
			'flow' => 'earbuds', 'use' => 'commute', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 20000000,
		],
		'earbuds-music-balanced-usbc-any' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'usbc', 'device' => 'usbc', 'fit' => 'any', 'budget' => 6000000,
		],
		'budget-min-exact' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 500000,
		],
		'budget-below-clamped' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 450000,
		],
		'budget-above-clamped' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 600000000,
		],
		'budget-invalid-default' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 'abc',
		],
		'flex-cap-inside' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 6000000, 'flex' => true,
		],
		'flex-cap-outside' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'wireless', 'fit' => 'any', 'budget' => 5900000, 'flex' => true,
		],
		'usbc-device-lightning' => [
			'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
			'connection' => 'usbc', 'device' => 'lightning', 'fit' => 'any', 'budget' => 6000000,
		],
	];
}
