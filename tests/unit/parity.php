<?php
/**
 * Parity harness: the refactored engine must reproduce the legacy baseline.
 *
 * Converts the legacy-shaped characterization fixtures into the new internal
 * shape (capabilities sub-array), runs TSSoundGuide\RecommendationEngine over
 * every scenario, and diffs against tests/characterization/baseline.json.
 *
 * Usage: php tests/unit/parity.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' ); // satisfy the plugin's ABSPATH guard
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/../characterization/fixtures.php';
require __DIR__ . '/../../ts-sound-guide.php'; // constants + autoloader

use TSSoundGuide\RecommendationEngine;

/**
 * Convert a legacy fixture product to the new internal shape.
 *
 * @param array<string, mixed> $p Legacy product.
 * @return array<string, mixed>
 */
function parity_product( array $p ): array {
	$caps = [];
	foreach ( [ 'wireless', 'usbc', 'aux', 'auxMic', 'anc', 'multipoint', 'silicone' ] as $key ) {
		$caps[ $key ] = $p[ $key ] ?? null;
		unset( $p[ $key ] );
	}
	$p['capabilities'] = $caps;
	return $p;
}

$engine    = new RecommendationEngine();
$catalog   = array_map( 'parity_product', ts_sound_fixtures_catalog() );
$scenarios = ts_sound_fixtures_scenarios();
$baseline  = json_decode( (string) file_get_contents( __DIR__ . '/../characterization/baseline.json' ), true );
if ( ! is_array( $baseline ) ) {
	fwrite( STDERR, "baseline.json missing or invalid\n" );
	exit( 1 );
}

/**
 * Public-DTO strip identical to the shipped legacy allow-list.
 *
 * @param array<string, mixed> $p Matched product.
 * @return array<string, mixed>
 */
function parity_public( array $p ): array {
	$keys = [ 'id', 'wcId', 'name', 'flow', 'url', 'image', 'price', 'wireless', 'usbc', 'aux', 'auxMic', 'silicone', 'anc', 'multipoint', 'form', 'cautions', 'sources', 'reasons', 'role', 'overBudget', 'upgradeReason' ];
	$dto  = array_intersect_key( $p, array_flip( $keys ) );
	$dto['variants'] = array_map(
		static fn( array $v ): array => array_intersect_key( $v, array_flip( [ 'id', 'price', 'attributes', 'label', 'image' ] ) ),
		$p['variants']
	);
	return $dto;
}

// The new engine carries capabilities in a sub-array; the public DTO exposes
// them flat for browser compatibility. parity_public expects flat keys, so
// flatten before stripping.
$flatten = static function ( array $p ): array {
	$p += $p['capabilities'];
	unset( $p['capabilities'] );
	return $p;
};

$failures = 0;
/**
 * Canonicalize any value for comparison: recursively sort object keys (JSON
 * objects are unordered per RFC 8259; the baseline key order was an artifact
 * of the removed profiles.json), then serialize. PHP encodes 6000000.0 and
 * 6000000 identically, so int/float storage differences never mismatch.
 *
 * @param mixed $value Any value.
 * @return string Canonical JSON.
 */
$canonical = static function ( $value ) use ( &$canonical ): string {
	$sort = static function ( $v ) use ( &$sort ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		// Preserve integer-keyed lists (order matters); sort string keys.
		$is_list = array_is_list( $v );
		if ( $is_list ) {
			return array_map( static fn( $x ) => $sort( $x ), $v );
		}
		$keys = array_keys( $v );
		usort( $keys, 'strnatcasecmp' );
		$sorted = [];
		foreach ( $keys as $key ) {
			$sorted[ $key ] = $sort( $v[ $key ] );
		}
		return $sorted;
	};
	return (string) json_encode( $sort( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
};

foreach ( $scenarios as $name => $answers ) {
	$result = $engine->select( $answers, $catalog );
	$public = array_map(
		static function ( array $pick ) use ( $flatten ): array {
			$pick = $flatten( $pick );
			return parity_public( $pick );
		},
		$result['picks']
	);
	$result['picks_flat'] = $public;

	$base = $baseline['scenarios'][ $name ] ?? null;
	if ( null === $base ) {
		echo "MISSING BASELINE: {$name}\n";
		$failures++;
		continue;
	}

	$actual = [
		'answers_normalized' => $result['answers'],
		'public_picks'      => $public,
		'notices'           => $result['notices'],
		'total'             => $result['total'],
		'rejected_ids'      => array_map(
			static fn( array $r ): array => [ 'id' => $r['id'], 'reason' => $r['reason'] ],
			$result['rejected']
		),
	];

	$expected = $base;
	// Both sides normalize flex identically (legacy always sets it; the new
	// engine always sets it too), so no field needs to be ignored.

	if ( $canonical( $actual ) !== $canonical( $expected ) ) {
		$failures++;
		echo "MISMATCH: {$name}\n";
		foreach ( [ 'answers_normalized', 'public_picks', 'notices', 'total', 'rejected_ids' ] as $field ) {
			$av = $actual[ $field ] ?? null;
			$bv = $expected[ $field ] ?? null;
			if ( $canonical( $av ) !== $canonical( $bv ) ) {
				echo "  field: {$field}\n";
				echo '  expected: ' . json_encode( $bv, JSON_UNESCAPED_UNICODE ) . "\n";
				echo '  actual:   ' . json_encode( $av, JSON_UNESCAPED_UNICODE ) . "\n";
			}
		}
	}
}

echo $failures ? "PARITY FAILURES: {$failures}\n" : "PARITY OK: " . count( $scenarios ) . " scenarios identical\n";
exit( $failures ? 1 : 0 );
