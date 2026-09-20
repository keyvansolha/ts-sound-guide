<?php
/**
 * Plugin Name: TehranSpeaker Sound Guide
 * Description: Adaptive headphone and earbud guide using amazing design tokens and current WooCommerce variation stock.
 * Version: 2.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: ts-sound-guide
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/includes/policy.php';
require_once __DIR__.'/includes/inventory.php';

add_action('rest_api_init',static function(){
    foreach(['recommend','validate'] as $route)register_rest_route('ts-sound/v1','/'.$route,['methods'=>'POST','callback'=>'ts_sound_api','permission_callback'=>'__return_true']);
});

add_action('wp_enqueue_scripts',static function(){
    global $post;
    if(!$post||!has_shortcode($post->post_content,'ts_sound_guide'))return;
    $base=plugin_dir_url(__FILE__);
    // Reuse the actual theme stylesheet; never use a CDN or a parallel brand palette.
    if(!wp_style_is('amazing-theme-system','registered'))wp_register_style('amazing-theme-system',get_template_directory_uri().'/assets/css/theme-system.css',[],null);
    wp_enqueue_style('ts-sound-tokens',$base.'assets/token-bridge.css',['amazing-theme-system'],'2.0.0');
    wp_enqueue_style('ts-sound-guide',$base.'assets/style.css',['ts-sound-tokens'],'2.0.0');
    wp_enqueue_script('ts-sound-guide',$base.'assets/app.js',[],'2.0.0',true);
},1001);

add_shortcode('ts_sound_guide',static function($attrs){
    $attrs=shortcode_atts(['flow'=>'earbuds'],$attrs,'ts_sound_guide');
    $flow=$attrs['flow']==='headphones'?'headphones':'earbuds';
    $heroes=[];foreach(ts_sound_profiles() as $p)if(in_array($p['id'],[8,621],true))$heroes[$p['flow']]=['image'=>$p['imageSource'],'name'=>$p['name']];
    $config=['mode'=>'live','flow'=>$flow,'endpoint'=>rest_url('ts-sound/v1'),'homeUrl'=>home_url('/'),'hero'=>$heroes];
    wp_add_inline_script('ts-sound-guide','window.TSSoundConfig='.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
    $markup=file_get_contents(__DIR__.'/view.html');
    return str_replace(['__LOGO__','__HERO__'],[esc_url(get_template_directory_uri().'/images/logo.svg'),esc_url($heroes[$flow]['image']??'')],$markup);
});

// A dedicated template avoids the default page title and Page Builder container
// wrapping this full-width experience. It only applies to an explicit shortcode.
add_filter('template_include',static function($template){
    if(is_page()){$page=get_queried_object();if($page&&has_shortcode($page->post_content,'ts_sound_guide'))return __DIR__.'/templates/page.php';}
    return $template;
},99);

// Store only a server-generated, short-lived attribution token after product selection.
add_action('template_redirect',static function(){
    if(!is_product()||!isset($_GET['ts_sound_ref'])||!function_exists('WC')||!WC()->session)return;
    $token=sanitize_text_field(wp_unslash($_GET['ts_sound_ref']));
    if(!preg_match('/^[A-Za-z0-9]{32}$/D',$token))return;
    $data=get_transient('ts_sound_ref_'.$token);if(!is_array($data)||(int)$data['product_id']!==get_queried_object_id())return;
    WC()->session->set('ts_sound_attribution',$data);WC()->session->set_customer_session_cookie(true);
});
function ts_sound_attach_order_attribution($order):void {
    if(!function_exists('WC')||!WC()->session)return;$data=WC()->session->get('ts_sound_attribution');
    if(!is_array($data)||time()-(int)($data['at']??0)>30*MINUTE_IN_SECONDS)return;
    foreach($order->get_items() as $item)if((int)$item->get_product_id()===(int)$data['product_id']&&(int)$item->get_variation_id()===(int)$data['variation_id']){$order->update_meta_data('_ts_sound_guide',$data);break;}
}
add_action('woocommerce_checkout_create_order','ts_sound_attach_order_attribution',20,1);
add_action('woocommerce_store_api_checkout_update_order_from_request','ts_sound_attach_order_attribution',20,1);
