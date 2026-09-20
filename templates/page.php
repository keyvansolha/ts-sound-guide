<?php
/**
 * Landing-page template: theme header/footer around the plugin guide.
 *
 * @package TSSoundGuide
 */

defined( 'ABSPATH' ) || exit;

get_header();

echo '<main id="site-main" class="ts-sound-page">';
echo wp_kses_post( ts_sound_guide()->landing_page->render( ts_sound_guide_initial_flow() ) );
echo '</main>';

// Keep existing global commerce navigation owned by the amazing theme.
if ( defined( 'THEME_TEMPLATE' ) && defined( 'IS_MOBILE' ) ) {
	$parts = IS_MOBILE ? [ 'menu', 'categories', 'video', 'search', 'cart', 'profile' ] : [];
	if ( IS_MOBILE ) {
		foreach ( $parts as $part ) {
			$path = THEME_TEMPLATE . 'layout/footer/mobile/' . $part . '.php';
			if ( is_file( $path ) ) {
				require $path;
			}
		}
	} else {
		$path = THEME_TEMPLATE . 'layout/footer/dynamic-iland.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
}

get_footer();

/**
 * The initial flow for the current request (shortcode attribute or default).
 *
 * @return string
 */
function ts_sound_guide_initial_flow(): string {
	$page = get_queried_object();
	if ( $page && has_shortcode( (string) $page->post_content, 'ts_sound_guide' ) ) {
		preg_match( '/\[ts_sound_guide[^\]]*flow=["\'](\w+)/', (string) $page->post_content, $m );
		if ( isset( $m[1] ) && 'headphones' === $m[1] ) {
			return 'headphones';
		}
	}
	return 'earbuds';
}
