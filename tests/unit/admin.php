<?php
/**
 * Sound Guide administrator settings integration checks.
 *
 * Usage: php tests/unit/admin.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/wc-shims.php';

$GLOBALS['ts_test_option'] = [
	'ts_sound_guide_settings' => [
		'page_id'                  => 9,
		'earbuds_term'             => 11,
		'headphones_term'          => 21,
		'hero_earbuds_product'     => 202,
		'hero_headphones_product'  => 203,
	],
];

function get_option( $name, $default = false ) { return $GLOBALS['ts_test_option'][ $name ] ?? $default; }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability; }
function get_post( int $page_id ): object { return (object) [ 'ID' => $page_id, 'post_type' => 'page', 'post_status' => 'publish' ]; }
function get_pages( array $args = [] ): array { return [ (object) [ 'ID' => 9, 'post_title' => 'راهنما' ] ]; }
function get_the_title( $page ): string { return (string) $page->post_title; }
function settings_fields( string $group ): void {}
function add_shortcode( string $tag, callable $callback ): void {}
function submit_button( string $text = '' ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function selected( $selected, $current = true, bool $echo = true ): string {
	$result = (string) $selected === (string) $current ? 'selected="selected"' : '';
	if ( $echo ) { echo $result; }
	return $result;
}
function get_terms( array $args = [] ): array {
	return [
		(object) [ 'term_id' => 11, 'name' => 'هندزفری' ],
		(object) [ 'term_id' => 21, 'name' => 'هدفون' ],
	];
}
function rest_url( string $path = '' ): string { return 'https://store.example/wp-json/' . ltrim( $path, '/' ); }

require __DIR__ . '/../../ts-sound-guide.php';

ts_wc_product( 201, [
	'type' => 'simple', 'name' => 'Automatic Earbud', 'cats' => [ 11 ],
	'price' => 3000000, 'image_id' => 61,
] );
ts_wc_product( 202, [
	'type' => 'simple', 'name' => 'Selected Earbud', 'cats' => [ 11 ],
	'price' => 5000000, 'image_id' => 62,
] );
ts_wc_product( 203, [
	'type' => 'simple', 'name' => 'Selected Headphone', 'cats' => [ 21 ],
	'price' => 4000000, 'image_id' => 63,
] );

ob_start();
ts_sound_guide()->admin->render_page();
$output = (string) ob_get_clean();

preg_match( '#<select id="ts-sound-hero-earbuds".*?</select>#s', $output, $earbuds_match );
preg_match( '#<select id="ts-sound-hero-headphones".*?</select>#s', $output, $headphones_match );
$earbuds = $earbuds_match[0] ?? '';
$headphones = $headphones_match[0] ?? '';

$checks = [
	'admin renders an earbud hero selector' => str_contains( $earbuds, 'name="ts_sound_guide_settings[hero_earbuds_product]"' ),
	'admin renders a headphone hero selector' => str_contains( $headphones, 'name="ts_sound_guide_settings[hero_headphones_product]"' ),
	'hero selectors provide an automatic fallback' => str_contains( $earbuds, 'value="0"' ) && str_contains( $headphones, 'value="0"' ),
	'earbud selector marks the configured product' => preg_match( '#value="202"\s+selected="selected"#', $earbuds ) === 1,
	'headphone selector marks the configured product' => preg_match( '#value="203"\s+selected="selected"#', $headphones ) === 1,
	'hero choices stay inside their configured flow' => ! str_contains( $earbuds, 'Selected Headphone' ) && ! str_contains( $headphones, 'Selected Earbud' ),
];

$pass = 0;
$fail = 0;
foreach ( $checks as $name => $condition ) {
	if ( $condition ) { $pass++; echo "ok  {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
