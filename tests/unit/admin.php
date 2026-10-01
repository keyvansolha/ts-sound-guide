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
function admin_url( string $path = '' ): string { return 'https://store.example/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( array $args, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }

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
ts_wc_product( 204, [
	'type' => 'simple', 'name' => 'Unavailable Earbud', 'cats' => [ 11 ],
	'price' => 6000000, 'image_id' => 64, 'in_stock' => false, 'purchasable' => false,
] );

ob_start();
ts_sound_guide()->admin->render_page();
$output = (string) ob_get_clean();

preg_match( '#<select id="ts-sound-hero-earbuds".*?</select>#s', $output, $earbuds_match );
preg_match( '#<select id="ts-sound-hero-headphones".*?</select>#s', $output, $headphones_match );
$earbuds = $earbuds_match[0] ?? '';
$headphones = $headphones_match[0] ?? '';
preg_match( '#<section\s+id="ts-sound-information-panel".*?</section>#s', $output, $information_match );
preg_match( '#<section\s+id="ts-sound-commerce-panel".*?</section>#s', $output, $commerce_match );
$information_panel = $information_match[0] ?? '';
$commerce_panel    = $commerce_match[0] ?? '';

$checks = [
	'admin renders an earbud hero selector' => str_contains( $earbuds, 'name="ts_sound_guide_settings[hero_earbuds_product]"' ),
	'admin renders a headphone hero selector' => str_contains( $headphones, 'name="ts_sound_guide_settings[hero_headphones_product]"' ),
	'hero selectors provide an automatic fallback' => str_contains( $earbuds, 'value="0"' ) && str_contains( $headphones, 'value="0"' ),
	'earbud selector marks the configured product' => preg_match( '#value="202"\s+selected="selected"#', $earbuds ) === 1,
	'headphone selector marks the configured product' => preg_match( '#value="203"\s+selected="selected"#', $headphones ) === 1,
	'hero choices stay inside their configured flow' => ! str_contains( $earbuds, 'Selected Headphone' ) && ! str_contains( $headphones, 'Selected Earbud' ),
	/* WP-45 §6: the admin report states which specs are unlisted, separately
	 * from real data defects. */
	'admin reports unlisted specifications as a coverage gap' => str_contains( $output, 'پوشش مشخصات ثبت‌نشده' ) && str_contains( $output, 'st_placeholder_missing' ) === false && str_contains( $output, '<code>silicone</code>' ),
	'coverage gap counts the products missing that spec' => (bool) preg_match( '#<code>silicone</code></td>\s*<td>3</td>#', $output ),
	'admin renders two catalog-health tab labels with issue counts' => str_contains( $output, 'مشکلات اطلاعاتی محصولات موجود (3)' ) && str_contains( $output, 'مشکلات موجودی و فروش (2)' ),
	'health tabs expose matching accessible controls and panels' => str_contains( $output, 'id="ts-sound-information-tab"' ) && str_contains( $output, 'aria-controls="ts-sound-information-panel"' ) && str_contains( $output, 'id="ts-sound-commerce-tab"' ) && str_contains( $output, 'aria-controls="ts-sound-commerce-panel"' ) && str_contains( $output, 'role="tabpanel"' ),
	'information panel contains only currently sellable specification problems' => str_contains( $information_panel, 'Automatic Earbud' ) && ! str_contains( $information_panel, 'Unavailable Earbud' ),
	'commerce panel contains only availability and selling problems' => str_contains( $commerce_panel, 'Unavailable Earbud' ) && ! str_contains( $commerce_panel, 'Automatic Earbud' ),
	'information tab is selected by default and commerce panel is hidden' => (bool) preg_match( '#id="ts-sound-information-tab"[^>]*aria-selected="true"#', $output ) && (bool) preg_match( '#id="ts-sound-commerce-panel"[^>]*hidden#', $output ),
	'refresh action reloads the health page with a cache-busting argument' => (bool) preg_match( '#href="[^"]*page=ts-sound-guide[^"]*ts_sound_tab=information[^"]*ts_sound_refresh=\d+"[^>]*>رفرش گزارش</a>#', $output ),
];

$complete_earbud = [
	'pa_bluetooth' => 'دارد', 'pa_connection' => 'بی‌سیم',
	'pa_noise-cancellation' => 'ندارد', 'pa_qip5asto9pe6c2dzxq' => 'ندارد',
	'pa_inside-the-box' => 'سری سیلیکونی', 'pa_headphones-type' => 'داخل گوش',
];
$complete_headphone = [
	'pa_bluetooth' => 'دارد', 'pa_connection' => 'Bluetooth',
	'pa_aux' => 'ندارد', 'pa_aux-microphone' => 'ندارد',
	'pa_noise-cancellation' => 'ندارد', 'pa_qip5asto9pe6c2dzxq' => 'ندارد',
	'pa_headphones-type' => 'روی گوش',
];
foreach ( [ 201, 202, 204 ] as $id ) {
	$GLOBALS['ts_wc_products'][ $id ]['props']['attributes'] = $complete_earbud;
}
$GLOBALS['ts_wc_products'][203]['props']['attributes']  = $complete_headphone;
$GLOBALS['ts_wc_products'][204]['props']['in_stock']    = true;
$GLOBALS['ts_wc_products'][204]['props']['purchasable'] = true;

ob_start();
ts_sound_guide()->admin->render_page();
$empty_output = (string) ob_get_clean();
$checks['both health tabs render a clear empty state'] = str_contains( $empty_output, 'هیچ مشکل اطلاعاتی برای محصولات قابل‌خرید پیدا نشد.' ) && str_contains( $empty_output, 'هیچ مشکل موجودی یا فروش پیدا نشد.' );

$_GET['ts_sound_tab'] = 'commerce';
ob_start();
ts_sound_guide()->admin->render_page();
$commerce_selected_output = (string) ob_get_clean();
unset( $_GET['ts_sound_tab'] );
$checks['commerce tab selection is reflected server-side'] = (bool) preg_match( '#id="ts-sound-commerce-tab"[^>]*aria-selected="true"#', $commerce_selected_output ) && (bool) preg_match( '#id="ts-sound-information-panel"[^>]*hidden#', $commerce_selected_output );

$pass = 0;
$fail = 0;
foreach ( $checks as $name => $condition ) {
	if ( $condition ) { $pass++; echo "ok  {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
