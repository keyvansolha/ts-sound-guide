<?php
/**
 * Legacy-engine characterization snapshot runner.
 *
 * Runs the CURRENT (pre-refactor) includes/policy.php + includes/inventory.php
 * logic over the synthetic fixtures and prints JSON. The refactored engine must
 * reproduce these outcomes exactly (same picks, roles, reasons, notices,
 * rejections, ordering, prices, and public DTO shape) — this file is the
 * execution record proving the baseline, not part of the shipped plugin.
 *
 * Usage: php tests/characterization/run.php > baseline.json
 */

declare(strict_types=1);

define('ABSNAME', 'characterization'); // allow includes to pass the ABSPATH guard

require __DIR__ . '/fixtures.php';
require __DIR__ . '/../../includes/policy.php';
// includes/inventory.php maps WooCommerce objects; the pure selection code
// under test lives in policy.php. The public-DTO strip is re-implemented here
// from the shipped ts_sound_public_product() allow-list so snapshots include
// the exact public shape without a database.

/**
 * Mirror of the shipped allow-list strip (includes/inventory.php).
 *
 * @param array<string, mixed> $p matched product
 * @return array<string, mixed> public DTO
 */
function characterize_public( array $p ): array {
	$keys = [ 'id', 'wcId', 'name', 'flow', 'url', 'image', 'price', 'wireless', 'usbc', 'aux', 'auxMic', 'silicone', 'anc', 'multipoint', 'form', 'cautions', 'sources', 'reasons', 'role', 'overBudget', 'upgradeReason' ];
	$dto  = array_intersect_key( $p, array_flip( $keys ) );
	$dto['variants'] = array_map(
		static fn( array $v ): array => array_intersect_key( $v, array_flip( [ 'id', 'price', 'attributes', 'label', 'image' ] ) ),
		$p['variants']
	);
	return $dto;
}

$catalog   = ts_sound_fixtures_catalog();
$scenarios = ts_sound_fixtures_scenarios();

$out = [ 'generated_at' => gmdate( 'c' ), 'scenarios' => [] ];
foreach ( $scenarios as $name => $answers ) {
	$result              = ts_sound_select( $answers, $catalog );
	$public              = array_map( 'characterize_public', $result['picks'] );
	$out['scenarios'][ $name ] = [
		'answers_normalized' => $result['answers'],
		'public_picks'       => $public,
		'notices'            => $result['notices'],
		'total'              => $result['total'],
		'rejected_ids'       => array_map(
			static fn( array $r ): array => [ 'id' => $r['id'], 'reason' => $r['reason'] ],
			$result['rejected']
		),
	];
}

echo wp_json_legacy( $out );

/**
 * Minimal wp_json_encode replacement for the snapshot runner (no WP loaded).
 *
 * @param mixed $data data to encode
 * @return string|false JSON or false on failure
 */
function wp_json_legacy( $data ) {
	return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
}
