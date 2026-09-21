<?php
/**
 * Configured landing-template integration contract.
 *
 * Usage: php tests/unit/landing.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/wc-shims.php';

$GLOBALS['ts_test_option'] = [
	'ts_sound_guide_settings' => [ 'page_id' => 0, 'earbuds_term' => 0, 'headphones_term' => 0 ],
];
$GLOBALS['ts_test_page_id'] = 9;
$GLOBALS['ts_test_has_shortcode'] = false;

function get_option( $name, $default = false ) { return $GLOBALS['ts_test_option'][ $name ] ?? $default; }
function get_template_directory_uri(): string { return 'https://store.example/wp-content/themes/amazing'; }
function home_url( string $path = '' ): string { return 'https://store.example' . $path; }
function get_bloginfo( string $field = '' ): string { return 'TehranSpeaker'; }
function rest_url( string $path = '' ): string { return 'https://store.example/wp-json/' . ltrim( $path, '/' ); }
function is_page( $page = null ): bool { return null === $page || 0 === $page || (int) $page === $GLOBALS['ts_test_page_id']; }
function get_post( int $page_id ): object { return (object) [ 'ID' => $page_id, 'post_type' => 'page', 'post_status' => 'publish' ]; }
function get_term_link( int $term_id, string $taxonomy = '' ): string { return "https://store.example/custom-category/{$term_id}/"; }
function get_queried_object(): object { return (object) [ 'post_content' => $GLOBALS['ts_test_has_shortcode'] ? '[ts_sound_guide]' : '' ]; }
function has_shortcode( string $content, string $tag ): bool { return $GLOBALS['ts_test_has_shortcode']; }
function add_shortcode( string $tag, callable $callback ): void {}
function get_header(): void { echo '<header data-theme-header></header>'; }
function get_footer(): void { echo '<footer data-theme-footer></footer>'; }

/* WordPress' post allow-list does not permit script elements. */
function wp_kses_post( string $html ): string {
	return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
}

require __DIR__ . '/../../ts-sound-guide.php';

$landing = ts_sound_guide()->landing_page;
$GLOBALS['ts_test_has_shortcode'] = true;
$shortcode_template = $landing->template( '/theme/page.php' );
$GLOBALS['ts_test_option']['ts_sound_guide_settings']['page_id'] = 9;
ts_sound_guide()->settings->invalidate();
$configured_template = $landing->template( '/theme/page.php' );
$GLOBALS['ts_test_option']['ts_sound_guide_settings']['page_id'] = 0;
$GLOBALS['ts_test_option']['ts_sound_guide_settings']['earbuds_term'] = 11;
$GLOBALS['ts_test_option']['ts_sound_guide_settings']['headphones_term'] = 21;
ts_sound_guide()->settings->invalidate();
$GLOBALS['ts_test_has_shortcode'] = false;

ob_start();
require __DIR__ . '/../../templates/page.php';
$output = (string) ob_get_clean();

$checks = [
	'application registers only callable WordPress callbacks' => array_reduce(
		$GLOBALS['ts_test_actions'] ?? [],
		static fn( bool $valid, array $callbacks ): bool => $valid && ! array_filter( $callbacks, static fn( array $entry ): bool => ! is_callable( $entry[0] ) ),
		true
	),
	'unconfigured shortcode page keeps its theme template' => '/theme/page.php' === $shortcode_template,
	'configured page uses the plugin landing template' => TS_SOUND_GUIDE_DIR . 'templates/page.php' === $configured_template,
	'configured page keeps theme header' => str_contains( $output, 'data-theme-header' ),
	'configured page keeps theme footer' => str_contains( $output, 'data-theme-footer' ),
	'configured page renders the guide once' => 1 === substr_count( $output, 'id="ts-sound"' ),
	'configured page preserves JSON bootstrap for ES modules' => 1 === substr_count( $output, 'id="ts-sound-config"' ),
	'bootstrap exposes configured category URLs' => str_contains( $output, 'custom-category/11/' ) && str_contains( $output, 'custom-category/21/' ),
	'view uses configured earbud URL for the initial category CTA' => str_contains( $output, 'href="https://store.example/custom-category/11/" id="ss-category-link"' ),
	'view uses configured headphone URL in its footer' => str_contains( $output, 'href="https://store.example/custom-category/21/"' ),
];

$pass = 0;
$fail = 0;
foreach ( $checks as $name => $condition ) {
	if ( $condition ) { $pass++; echo "ok  {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
