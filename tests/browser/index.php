<?php
/**
 * Test-only real-browser harness for the guide's actual CSS and ES modules.
 */

declare(strict_types=1);

$markup = (string) file_get_contents( __DIR__ . '/../js/fixtures/guide-markup.html' );
$head   = <<<'HTML'
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>TS Sound Guide browser harness</title>
	<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg'/>">
	<link rel="stylesheet" href="../../assets/token-bridge.css">
	<link rel="stylesheet" href="../../assets/style.css">
	<style>html,body{margin:0;min-height:100%;background:var(--surface-page,#fff)}.test-badge{position:fixed;z-index:1000;left:8px;bottom:8px;padding:5px 8px;border-radius:6px;background:#111;color:#fff;font:11px monospace}</style>
</head>
HTML;
$markup = str_replace( '<html><body>', '<html>' . $head . '<body>', $markup );
$markup = str_replace( 'https://store.example/img.webp', 'product.svg', $markup );
$markup = str_replace( 'https://store.example/wp-content/themes/amazing/images/logo.svg', 'product.svg', $markup );
$markup = str_replace(
	'{"mode":"live","flow":"earbuds","endpoint":"https://store.example/wp-json/ts-sound/v1","homeUrl":"https://store.example/","categoryUrls":{"earbuds":"https://store.example/custom-earbuds/","headphones":"https://store.example/custom-headphones/"},"hero":{"earbuds":{"name":"Test Hero","image":"product.svg"},"headphones":null}}',
	'{"mode":"live","flow":"earbuds","endpoint":"/wp-json/ts-sound/v1","homeUrl":"http://127.0.0.1:8765/","categoryUrls":{"earbuds":"/custom-earbuds/","headphones":"/custom-headphones/"},"hero":{"earbuds":{"name":"Test Hero","image":"product.svg"},"headphones":{"name":"Test Headphones","image":"product.svg"}}}',
	$markup
);
$origin = 'http://' . ( $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8765' );
$markup = str_replace( 'http://127.0.0.1:8765/', rtrim( $origin, '/' ) . '/', $markup );

$script = <<<'HTML'
<div class="test-badge" id="test-badge"></div>
<script>
const params = new URLSearchParams(location.search);
const scenario = params.get('scenario') || 'success';
const theme = params.get('theme') || 'light';
document.body.classList.toggle('dark', theme === 'dark');
document.body.dataset.theme = theme;
document.documentElement.dataset.theme = theme;
document.getElementById('test-badge').textContent = `${scenario} / ${theme}`;
window.matchMedia ||= () => ({matches:false,addEventListener(){},removeEventListener(){}});
const product = (id, price, anc = true) => ({
	id, wcId:id, name:`Browser Product ${id}`, flow:'earbuds', url:`${location.origin}/product/${id}/`, image:'product.svg',
	price, wireless:true, usbc:null, aux:null, auxMic:null, anc, multipoint:id === 20, silicone:true, form:'هندزفری تست',
	cautions:['سازگاری دستگاه و راحتی را بررسی کن.'], sources:[{label:'مشخصات فروشگاه',url:`${location.origin}/product/${id}/`}],
	reasons:['اتصال مطابق انتخاب شما','در محدوده بودجه شما'], role:id === 10 ? 'پیشنهاد اصلی' : 'قابلیت بیشتر', overBudget:false,
	variants:[{id:id * 10 + 1,price,attributes:[{name:'رنگ',slug:'pa_color',option:'مشکی'}],label:'مشکی · ۶ ماه',image:'product.svg'}]
});
let recommendCalls = 0;
window.fetch = async (url) => {
	const action = String(url).split('/').pop();
	if (scenario === 'error') throw new TypeError('offline');
	if (action === 'validate' && scenario === 'changed') return {ok:false,status:409,json:async()=>({message:'Stock or price changed'})};
	if (action === 'validate') return {ok:true,status:200,json:async()=>({url:`${location.origin}/product/10/?ts_sound_ref=Ab9x`,productId:10,variationId:101,price:5000000,checkedAt:new Date().toISOString()})};
	recommendCalls++;
	const picks = scenario === 'empty' ? [] : [product(10,5000000,true),product(20,6200000,false)];
	return {ok:true,status:200,json:async()=>({picks,notices:[],total:picks.length,version:'3.1.0',source:'woocommerce',checkedAt:new Date().toISOString(),expiresAt:new Date(Date.now()+45000).toISOString()})};
};
</script>
<script type="module" src="../../assets/js/entry.js"></script>
HTML;
$markup = str_replace( '</body>', $script . '</body>', $markup );

echo $markup;
