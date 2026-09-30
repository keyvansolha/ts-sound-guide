<?php
declare(strict_types=1);
/**
 * Offline probe: runs the plugin's real CapabilityRegistry + RecommendationEngine
 * against a snapshot of the live public catalog (Store API), so the reported
 * scenarios can be reproduced and explained without the site's database.
 *
 * Usage:
 *   php probe.php <catalog.json> [--json] [scenario-filter]
 *
 * The catalog snapshot is built by live_snapshot.py from the public Store API;
 * it mirrors CatalogAdapter's mapping but can only see public fields (managed
 * stock depth and held reservations are not public, and do not affect
 * eligibility in this probe).
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'TS_SOUND_GUIDE_VERSION', 'probe' );
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $t ): string { return trim( strip_tags( $t ) ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $h, $v ) { return $v; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ): bool { return false; }
}

$src = getenv( 'TSG_SRC' ) ?: '/mnt/K1/git/Site/public_html/wp-content/plugins/ts-sound-guide/includes/src/';
$src = rtrim( $src, '/' ) . '/';
require $src . 'CapabilityRegistry.php';
require $src . 'RecommendationEngine.php';

use TSSoundGuide\CapabilityRegistry;
use TSSoundGuide\RecommendationEngine;

$json_mode = in_array( '--json', $argv, true );
$args      = array_values( array_filter( $argv, static fn( $a ): bool => '--json' !== $a ) );
$path      = $args[1] ?? ( __DIR__ . '/live_catalog.json' );
$filter    = $args[2] ?? null;

$products = json_decode( (string) file_get_contents( $path ), true );
if ( ! is_array( $products ) ) {
	fwrite( STDERR, "cannot read catalog {$path}\n" );
	exit( 1 );
}

$registry = new CapabilityRegistry();
$engine   = new RecommendationEngine();

$catalog = [];
foreach ( $products as $p ) {
	$raw       = $p['_raw'] ?? [];
	$cats      = $p['categories'] ?? [];
	$caps     = [];
	$unlisted = [];
	$has_interpretation = method_exists( $registry, 'interpretation' );
	foreach ( array_keys( $registry->capabilities() ) as $cap ) {
		if ( $has_interpretation ) {
			$reading      = $registry->interpretation( $cap, $raw, $cats );
			$caps[ $cap ] = $reading['value'];
			if ( 'absent' === $reading['source'] ) {
				$unlisted[] = $cap;
			}
			continue;
		}
		// Pre-WP-45 registry (used to reproduce the reported behaviour).
		$caps[ $cap ] = $registry->resolve( $cap, $raw, $cats );
	}
	$p['capabilities'] = $caps;
	$p['unlisted']     = $unlisted;
	unset( $p['_raw'] );
	$catalog[ $p['id'] ] = $p;
}

$fmt = static function ( $v ): string {
	return true === $v ? 'yes' : ( false === $v ? 'no' : '?' );
};

$scenarios = [
	'S1 earbuds/music/balanced/wireless/silicone/30M/flex-off' => [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'fit' => 'silicone', 'budget' => 30000000, 'flex' => false ],
	'S2 earbuds/music/balanced/usbc+device/usbc/any/30M'        => [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'usbc', 'device' => 'usbc', 'fit' => 'any', 'budget' => 30000000, 'flex' => false ],
	'S3a work/balanced/wireless/calls-quiet/any/30M'            => [ 'flow' => 'earbuds', 'use' => 'work', 'pain' => 'balanced', 'connection' => 'wireless', 'calls' => 'quiet', 'fit' => 'any', 'budget' => 30000000, 'flex' => false ],
	'S3b work/balanced/wireless/calls-noisy/any/30M'            => [ 'flow' => 'earbuds', 'use' => 'work', 'pain' => 'balanced', 'connection' => 'wireless', 'calls' => 'noisy', 'fit' => 'any', 'budget' => 30000000, 'flex' => false ],
	'S3c earbuds/music/charge/wireless/any/30M'                 => [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'charge', 'connection' => 'wireless', 'fit' => 'any', 'budget' => 30000000, 'flex' => false ],
	'S4 headphones/music/balanced/aux/40M'                      => [ 'flow' => 'headphones', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'aux', 'budget' => 40000000, 'flex' => false ],
	'S5 earbuds/commute/noise/wireless/any/30M'                 => [ 'flow' => 'earbuds', 'use' => 'commute', 'pain' => 'noise', 'connection' => 'wireless', 'fit' => 'any', 'budget' => 30000000, 'flex' => false ],
	'S6 earbuds/music/switch/wireless/any/30M'                  => [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'switch', 'connection' => 'wireless', 'fit' => 'any', 'budget' => 30000000, 'flex' => false ],
	'S8 headphones/music/balanced/wireless/40M'                 => [ 'flow' => 'headphones', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'budget' => 40000000, 'flex' => false ],
	'S9 headphones/commute/noise/wireless/40M'                  => [ 'flow' => 'headphones', 'use' => 'commute', 'pain' => 'noise', 'connection' => 'wireless', 'budget' => 40000000, 'flex' => false ],
	'S7 earbuds/music/balanced/wireless/silicone/30M/flex-ON'   => [ 'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced', 'connection' => 'wireless', 'fit' => 'silicone', 'budget' => 30000000, 'flex' => true ],
];

$out = [];
foreach ( $scenarios as $name => $answers ) {
	if ( $filter && false === strpos( $name, $filter ) ) {
		continue;
	}
	$r = $engine->select( $answers, $catalog );
	$shown_ids = array_column( $r['picks'], 'id' );
	$option_ids = array_column( $r['options'] ?? [], 'id' );

	$rows = [];
	foreach ( $r['matched'] as $index => $m ) {
		$rows[] = [
			'id'        => (int) $m['id'],
			'name'      => $m['name'],
			'price'     => (float) $m['price'],
			'score'     => (int) $m['fitScore'],
			'rank'      => $index + 1,
			'exposure'  => in_array( (int) $m['id'], $shown_ids, true ) ? 'primary' : ( in_array( (int) $m['id'], $option_ids, true ) ? 'more' : 'unreachable' ),
			'role'      => $m['role'] ?? null,
			'capabilities' => array_map( $fmt, $m['capabilities'] ),
			'unlisted'  => $m['unlisted'] ?? [],
			'reasons'   => $m['reasons'],
		];
	}

	$out[ $name ] = [
		'answers'      => $r['answers'],
		'total'        => $r['total'],
		'picks'        => $shown_ids,
		'options'      => $option_ids,
		'notices'      => $r['notices'],
		'matched'      => $rows,
		'rejected'     => $r['rejected'],
		'catalog_size' => count( $catalog ),
	];
}

if ( $json_mode ) {
	echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ), "\n";
	exit( 0 );
}

foreach ( $out as $name => $s ) {
	echo "\n== {$name}\n   total matched: {$s['total']}   primary: " . count( $s['picks'] ) . '   more: ' . count( $s['options'] ) . '   rejected: ' . count( $s['rejected'] ) . "\n";
	foreach ( $s['matched'] as $m ) {
		printf(
			"   #%d %-7d %-38s %12s score=%-3d exposure=%-6s anc=%s mp=%s sil=%s wl=%s usbc=%s aux=%s\n",
			$m['rank'],
			$m['id'],
			mb_substr( $m['name'], 0, 38 ),
			number_format( $m['price'] ),
			$m['score'],
			$m['exposure'],
			$m['capabilities']['anc'],
			$m['capabilities']['multipoint'],
			$m['capabilities']['silicone'],
			$m['capabilities']['wireless'],
			$m['capabilities']['usbc'],
			$m['capabilities']['aux']
		);
	}
	$by_reason = [];
	foreach ( $s['rejected'] as $rej ) {
		$by_reason[ $rej['reason'] ][] = $rej['id'];
	}
	foreach ( $by_reason as $reason => $ids ) {
		echo '   rejected[' . $reason . ']: ' . implode( ',', $ids ) . "\n";
	}
	if ( $s['notices'] ) {
		echo '   notices: ' . implode( ' | ', $s['notices'] ) . "\n";
	}
}
