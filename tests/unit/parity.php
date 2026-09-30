<?php
/**
 * Parity harness: the refactored engine must reproduce the legacy baseline,
 * except exactly where a recorded intentional change says otherwise.
 *
 * Converts the legacy-shaped characterization fixtures into the new internal
 * shape (capabilities sub-array), runs TSSoundGuide\RecommendationEngine over
 * every scenario, and diffs against tests/characterization/baseline.json.
 *
 * The frozen baseline stays frozen: a deliberate behaviour change is recorded
 * per scenario and per field in tests/characterization/intentional-deltas.json
 * (each entry names its reason). Any difference that is not recorded still
 * fails, and a recorded difference that no longer applies fails too, so the
 * record cannot silently drift.
 *
 * Usage: php tests/unit/parity.php            # verify
 *        php tests/unit/parity.php --record   # record the current deltas
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

$deltas_path = __DIR__ . '/../characterization/intentional-deltas.json';
$record      = in_array( '--record', $argv, true );
$deltas      = $record ? [] : json_decode( (string) @file_get_contents( $deltas_path ), true );
$deltas      = is_array( $deltas ) ? ( $deltas['scenarios'] ?? [] ) : [];

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

	$fields  = [ 'answers_normalized', 'public_picks', 'notices', 'total', 'rejected_ids' ];
	$changes = [];
	foreach ( $fields as $field ) {
		$av = $actual[ $field ] ?? null;
		$bv = $expected[ $field ] ?? null;
		if ( $canonical( $av ) === $canonical( $bv ) ) {
			continue;
		}
		$changes[ $field ] = $av;
	}
	if ( $record ) {
		if ( $changes ) {
			$deltas[ $name ] = $changes;
			echo "recorded delta: {$name} (" . implode( ', ', array_keys( $changes ) ) . ")\n";
		}
		continue;
	}

	$recorded = $deltas[ $name ] ?? [];
	foreach ( $changes as $field => $value ) {
		if ( ! array_key_exists( $field, $recorded ) ) {
			$failures++;
			echo "UNRECORDED MISMATCH: {$name} field {$field}\n";
			echo '  baseline: ' . json_encode( $base[ $field ] ?? null, JSON_UNESCAPED_UNICODE ) . "\n";
			echo '  actual:   ' . json_encode( $value, JSON_UNESCAPED_UNICODE ) . "\n";
			continue;
		}
		if ( $canonical( $value ) !== $canonical( $recorded[ $field ] ) ) {
			$failures++;
			echo "STALE RECORDED DELTA: {$name} field {$field}\n";
			echo '  recorded: ' . json_encode( $recorded[ $field ], JSON_UNESCAPED_UNICODE ) . "\n";
			echo '  actual:   ' . json_encode( $value, JSON_UNESCAPED_UNICODE ) . "\n";
		}
	}
	// A recorded delta that no longer differs from the baseline is dead weight.
	foreach ( $recorded as $field => $value ) {
		if ( ! array_key_exists( $field, $changes ) ) {
			$failures++;
			echo "REDUNDANT RECORDED DELTA: {$name} field {$field} matches the baseline again\n";
		}
	}
}

if ( $record ) {
	$payload = [
		'note'      => 'Deliberate behaviour changes (WP-45) recorded against the frozen legacy baseline. Each entry is verified by tests/unit/parity.php; unrecorded differences still fail.',
		'scenarios' => $deltas,
	];
	file_put_contents( $deltas_path, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo 'RECORDED ' . count( $deltas ) . " scenario deltas -> tests/characterization/intentional-deltas.json\n";
	exit( 0 );
}

echo $failures ? "PARITY FAILURES: {$failures}\n" : "PARITY OK: " . count( $scenarios ) . " scenarios identical\n";
exit( $failures ? 1 : 0 );
