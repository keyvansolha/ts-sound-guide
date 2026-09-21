<?php
/**
 * Frozen legacy characterization baseline integrity check.
 *
 * The legacy policy implementation was intentionally removed from production
 * after its outcomes were captured in baseline.json. This command verifies
 * that the snapshot still covers every named fixture scenario and retains the
 * complete comparison shape consumed by tests/unit/parity.php.
 *
 * Usage: php tests/characterization/run.php
 */

declare(strict_types=1);

require __DIR__ . '/fixtures.php';

$baseline = json_decode( (string) file_get_contents( __DIR__ . '/baseline.json' ), true );
if ( ! is_array( $baseline ) || ! is_array( $baseline['scenarios'] ?? null ) ) {
	fwrite( STDERR, "baseline.json missing or invalid\n" );
	exit( 1 );
}

$expected_names = array_keys( ts_sound_fixtures_scenarios() );
$actual_names   = array_keys( $baseline['scenarios'] );
sort( $expected_names );
sort( $actual_names );

$failures = 0;
if ( $expected_names !== $actual_names ) {
	fwrite( STDERR, "baseline scenario names do not match fixtures\n" );
	$failures++;
}

$required_fields = [ 'answers_normalized', 'public_picks', 'notices', 'total', 'rejected_ids' ];
foreach ( $baseline['scenarios'] as $name => $scenario ) {
	if ( ! is_array( $scenario ) || array_diff( $required_fields, array_keys( $scenario ) ) ) {
		fwrite( STDERR, "baseline scenario has incomplete shape: {$name}\n" );
		$failures++;
	}
}

if ( $failures ) {
	fwrite( STDERR, "CHARACTERIZATION FAILURES: {$failures}\n" );
	exit( 1 );
}

echo 'CHARACTERIZATION OK: ' . count( $actual_names ) . " frozen legacy scenarios\n";
