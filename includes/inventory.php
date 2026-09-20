<?php
defined('ABSPATH') || exit;
/** Use the store's WC sellable inventory after its existing Sepidar pipeline.
 * No stock/price writes and no warehouse credentials in the browser.
 */
function ts_sound_profiles():array {
    static $profiles=null;
    if($profiles===null)$profiles=json_decode(file_get_contents(__DIR__.'/profiles.json'),true)?:[];
    return apply_filters('ts_sound_profiles',$profiles);
}
function ts_sound_variant($v,$parent):?array {
    if(!$v||$v->get_status()!=='publish'||!$v->is_purchasable()||!$v->is_in_stock()||$v->get_stock_status()!=='instock'||$v->is_on_backorder(1))return null;
    if($v->is_type('variation')&&!$v->variation_is_visible())return null;
    $price=(float)wc_get_price_to_display($v);
    $currency=get_woocommerce_currency();
    if($currency==='IRR')$price/=10;
    elseif(!in_array($currency,['IRT','TOMAN'],true))return null;
    if($price<=0)return null;
    $stockOwner=$v->get_stock_managed_by_id()===$parent->get_id()?$parent:$v;
    $qty=$stockOwner->managing_stock()?$stockOwner->get_stock_quantity():null;
    $held=function_exists('wc_get_held_stock_quantity')?(float)wc_get_held_stock_quantity($stockOwner):0;
    if($stockOwner->managing_stock()&&($qty===null||(float)$qty-$held<1))return null;
    $attributes=[];$labels=[];$query=[];
    foreach($v->get_attributes() as $key=>$slug){
        if(!is_string($slug)||$slug==='')continue;
        $label=$slug;
        if(taxonomy_exists($key)){$term=get_term_by('slug',$slug,$key);if($term&&!is_wp_error($term))$label=$term->name;}
        $attributes[]=['name'=>wc_attribute_label($key),'slug'=>$key,'option'=>$label];$labels[]=$label;$query['attribute_'.$key]=$slug;
    }
    // The amazing purchase panel requires both color and guarantee for a variation.
    if($v->is_type('variation')&&(!isset($query['attribute_pa_color'])||!isset($query['attribute_pa_guarantee'])))return null;
    return ['id'=>$v->get_id(),'image'=>wp_get_attachment_image_url((int)$v->get_image_id('edit'),'woocommerce_single')?:null,'stockOwnerId'=>$stockOwner->get_id(),'price'=>$price,'qty'=>$qty===null?null:(float)$qty,'held'=>$held,'inStock'=>true,'status'=>'publish','stockStatus'=>'instock','enabled'=>true,'backorder'=>false,'attributes'=>$attributes,'label'=>implode(' · ',$labels)?:'مدل موجود','query'=>$query];
}
function ts_sound_catalog():array {
    if(!function_exists('wc_get_product'))throw new RuntimeException('WooCommerce unavailable');
    $items=[];$curated=[];foreach(ts_sound_profiles() as $s)$curated[(int)$s['wcId']]=$s;
    $products=[];
    // Discovery reads the categories on every request, so a newly stocked SKU
    // does not require rebuilding the landing. Curated facts override raw fields.
    if(function_exists('wc_get_products')){
        $page=1;
        do{$batch=wc_get_products(['status'=>'publish','stock_status'=>'instock','type'=>['simple','variable'],'category'=>['headphone','handsfree'],'limit'=>100,'page'=>$page,'paginate'=>true]);
            if(!is_object($batch)||!isset($batch->products,$batch->total_pages))throw new RuntimeException('Catalog query failed');
            foreach($batch->products as $p)$products[$p->get_id()]=$p;
            $page++;
            if($page>50&&$page<=$batch->total_pages)throw new RuntimeException('Catalog limit exceeded');
        }while($page<=$batch->total_pages);
    }else{foreach($curated as $id=>$s){$p=wc_get_product($id);if($p)$products[$id]=$p;}}
    foreach($products as $p){
        $spec=$curated[$p->get_id()]??ts_sound_profile_from_product($p);
        if(!$p||$p->get_status()!=='publish'||$p->get_catalog_visibility()==='hidden'||!$p->is_in_stock()||get_post_meta($p->get_id(),'product-status',true)==='stop')continue;
        $ids=$p->is_type('variable')?$p->get_children():[$p->get_id()];$variants=[];
        foreach($ids as $id){$v=ts_sound_variant(wc_get_product($id),$p);if($v)$variants[]=$v;}
        if(!$variants)continue;
        $image=wp_get_attachment_image_url($p->get_image_id(),'woocommerce_single')?:($spec['imageSource']??'');
        $items[]=array_merge($spec,['url'=>get_permalink($p->get_id()),'image'=>$image,'variants'=>$variants,'status'=>'publish','lifecycle'=>'active','inStock'=>true,'purchasable'=>true]);
    }
    return $items;
}
function ts_sound_profile_from_product($p):array {
    $attribute=static fn($key)=>trim(wp_strip_all_tags((string)$p->get_attribute($key)));
    $yes=static function($s){if($s==='')return null;if(str_contains($s,'ندارد'))return false;return str_contains($s,'دارد')?true:null;};
    $connection=$attribute('pa_connection');$bluetooth=$attribute('pa_bluetooth');$noise=$attribute('pa_noise-cancellation');$form=$attribute('pa_headphones-type');$box=$attribute('pa_inside-the-box');
    $wireless=preg_match('/دارد|\d+[.]\d+|Bluetooth/iu',$bluetooth)&&!preg_match('/ندارد|فاقد|بدون|یافت نشد/iu',$bluetooth)?true:null;
    if($wireless===null&&str_contains($connection,'بلوتوث'))$wireless=true;
    $anc=preg_match('/\bANC\b|Active Noise|Adaptive Noise|حذف نویز فعال|حذف نویز تطبیقی/iu',$noise)?true:null;
    if(preg_match('/ندارد|فاقد|بدون/iu',$noise))$anc=false;
    elseif($anc!==true&&preg_match('/\bENC\b/iu',$noise))$anc=false;
    $silicone=null;
    if(str_contains($box,'سیلیکونی'))$silicone=true;
    if(preg_match('/open[- ]ear|نیمه داخل گوش|بدون سری سیلیکونی/iu',$form))$silicone=false;
    $earTerms='handsfree';$term=get_term_by('slug','handsfree','product_cat');
    if($term&&!is_wp_error($term)&&isset($term->term_id)){$children=get_term_children((int)$term->term_id,'product_cat');$earTerms=is_wp_error($children)?[(int)$term->term_id]:array_merge([(int)$term->term_id],array_map('intval',$children));}
    $flow=has_term($earTerms,'product_cat',$p->get_id())?'earbuds':'headphones';
    // Negative WC IDs are disjoint from the legacy positive MyTS profile IDs.
    return ['id'=>-$p->get_id(),'wcId'=>$p->get_id(),'name'=>wp_strip_all_tags($p->get_name()),'flow'=>$flow,'wireless'=>$wireless,'usbc'=>null,'aux'=>$yes($attribute('pa_aux')),'auxMic'=>null,'anc'=>$anc,'multipoint'=>$yes($attribute('pa_qip5asto9pe6c2dzxq')),'silicone'=>$silicone,'form'=>$form?:($flow==='earbuds'?'هندزفری':'هدفون'),'cautions'=>['مشخصات این مدل از فهرست فروشگاه خوانده شده است؛ سازگاری دستگاه و راحتی را پیش از خرید بررسی کن.'],'sources'=>[['label'=>'مشخصات ثبت‌شده در فروشگاه','url'=>get_permalink($p->get_id())]]];
}
function ts_sound_public_product(array $p):array {
    $keys=['id','wcId','name','flow','url','image','price','wireless','usbc','aux','auxMic','silicone','anc','multipoint','form','cautions','sources','reasons','role','overBudget','upgradeReason'];
    $dto=array_intersect_key($p,array_flip($keys));
    $dto['variants']=array_map(static fn($v)=>array_intersect_key($v,array_flip(['id','price','attributes','label','image'])),$p['variants']);
    return $dto;
}
function ts_sound_response($data,int $status=200){
    $r=new WP_REST_Response($data,$status);$r->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');$r->header('Pragma','no-cache');$r->header('X-Robots-Tag','noindex, nofollow');return $r;
}
function ts_sound_api($request){
    try{
        if(strlen($request->get_body())>4096)return ts_sound_response(['message'=>'Invalid request'],400);
        $body=$request->get_json_params();
        if(!is_array($body)||!is_array($body['answers']??null))return ts_sound_response(['message'=>'Invalid answers'],400);
        $a=ts_sound_normalize($body['answers']);
        foreach(['use','pain','connection'] as $required)if(empty($a[$required]))return ts_sound_response(['message'=>'Incomplete answers'],400);
        if(($a['connection']==='usbc'&&empty($a['device']))||($a['flow']==='earbuds'&&empty($a['fit'])))return ts_sound_response(['message'=>'Incomplete device/fit'],400);
        // Optional verified health provider. A stale explicit result fails closed.
        // null means upstream freshness is not instrumented; it is NOT a fresh heartbeat.
        $health=apply_filters('ts_sound_inventory_health',null);
        if(is_array($health)&&(($health['healthy']??false)!==true||!isset($health['expires_at'])||(int)$health['expires_at']<=time()))return ts_sound_response(['message'=>'Inventory sync unavailable'],503);
        $r=ts_sound_select($a,ts_sound_catalog());
        if(str_ends_with($request->get_route(),'/validate')){
            $chosen=null;$variant=null;
            foreach($r['matched'] as $p)if($p['id']===(int)($body['productId']??0)){$chosen=$p;break;}
            if($chosen)foreach($chosen['variants'] as $v)if($v['id']===(int)($body['variationId']??0)){$variant=$v;break;}
            if(!$variant||!is_numeric($body['price']??null)||abs((float)$variant['price']-(float)$body['price'])>0.01)return ts_sound_response(['message'=>'Stock or price changed'],409);
            $attribution=['v'=>'2.0.0','use'=>$a['use'],'pain'=>$a['pain'],'budget'=>$a['budget'],'product_id'=>$chosen['wcId'],'variation_id'=>$variant['id'],'at'=>time()];
            $token=wp_generate_password(32,false,false);set_transient('ts_sound_ref_'.$token,$attribution,30*MINUTE_IN_SECONDS);
            $url=add_query_arg(array_merge($variant['query'],['ts_sound_ref'=>$token]),$chosen['url']);
            return ts_sound_response(['url'=>$url,'productId'=>$chosen['id'],'variationId'=>$variant['id'],'price'=>$variant['price'],'checkedAt'=>gmdate('c')]);
        }
        return ts_sound_response(['picks'=>array_map('ts_sound_public_product',$r['picks']),'notices'=>$r['notices'],'total'=>$r['total'],'version'=>$r['version'],'source'=>'woocommerce','checkedAt'=>gmdate('c'),'expiresAt'=>gmdate('c',time()+45)]);
    }catch(Throwable $e){error_log('[TS Sound Guide] inventory read failed: '.get_class($e));return ts_sound_response(['message'=>'Inventory unavailable'],503);}
}
