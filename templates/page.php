<?php
defined('ABSPATH') || exit;
get_header();
echo '<main id="site-main" class="ts-sound-page">';
while(have_posts()){the_post();the_content();}
echo '</main>';
// Keep existing global commerce navigation owned by the amazing theme.
if(defined('THEME_TEMPLATE')&&defined('IS_MOBILE')){
    $parts=IS_MOBILE?['menu','categories','video','search','cart','profile']:[];
    if(IS_MOBILE){foreach($parts as $part){$path=THEME_TEMPLATE.'layout/footer/mobile/'.$part.'.php';if(is_file($path))require $path;}}
    else{$path=THEME_TEMPLATE.'layout/footer/dynamic-iland.php';if(is_file($path))require $path;}
}
get_footer();
