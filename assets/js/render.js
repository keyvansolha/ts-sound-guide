/* Accessible rendering helpers, product cards, comparison, and empty states. */
import { fa, esc } from './format.js';

const paths = { commute: 'M8 3h8a3 3 0 0 1 3 3v11H5V6a3 3 0 0 1 3-3ZM5 11h14M8 17l-3 4m11-4 3 4M8 7h2m4 0h2M8 14h.01M16 14h.01', music: 'M9 18V5l12-2v13M9 9l12-2M9 18a3 3 0 1 1-3-3c1.7 0 3 1.3 3 3Zm12-2a3 3 0 1 1-3-3c1.7 0 3 1.3 3 3Z', work: 'M3 4h18v12H3ZM8 20h8m-4-4v4M7 8h3m-3 4h7', gaming: 'M8 7h8c3 0 4 2 5 6l1 5c0 3-3 4-5 1l-2-2H9l-2 2c-2 3-5 2-5-1l1-5c1-4 2-6 5-6ZM8 10v5m-2.5-2.5h5M16 11h.01M19 14h.01', gift: 'M3 8h18v4H3ZM5 12v9h14v-9M12 8v13m0-13H8a3 3 0 1 1 3-3l1 3Zm0 0h4a3 3 0 1 0-3-3l-1 3Z', noise: 'M9 5 5 9H2v6h3l4 4V5Zm5 4 6 6m0-6-6 6', fit: 'M8 9a4 4 0 0 1 8 0c0 5-4 3-4 7a3 3 0 0 1-6 0M4 8a8 8 0 1 1 15 4', calls: 'M7 3h3l1 5-3 2c1 3 3 5 6 6l2-3 5 1v3c0 3-2 4-4 4C9 20 4 15 3 7c0-2 1-4 4-4Z', switch: 'M4 3h7v15H4ZM15 8h6v13h-6M7 15h.01M11 7h8m-2-2 2 2-2 2M15 18h-4m2-2-2 2 2 2', charge: 'M3 6h17v12H3ZM22 10v4M12 8l-3 5h5l-3 3', balanced: 'M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-7 0v6', wireless: 'm11 3 7 5-7 4 7 4-7 5V3ZM5 7l6 5-6 5', usbc: 'M7 3v6h10V3M9 3v3m6-3v3m-3 3v5c0 4-6 1-6 5v2', aux: 'M8 3v6m8-6v6M6 9h12v4a6 6 0 0 1-12 0V9Zm6 10v3', silicone: 'M12 3a7 7 0 0 1 5 12l-2 3v3H9v-3l-2-3a7 7 0 0 1 5-12Zm-3 8a3 3 0 1 0 6 0 3 3 0 0 0-6 0Z', open: 'M6 13V9a6 6 0 0 1 12 0v4M3 12h5v8H3Zm13 0h5v8h-5Z', long: 'M12 3a9 9 0 1 0 9 9 9 9 0 0 0-9-9Zm0 4v6l4 2', glasses: 'M3 12a4 4 0 1 0 8 0 4 4 0 0 0-8 0Zm10 0a4 4 0 1 0 8 0 4 4 0 0 0-8 0ZM11 12h2M3 12l1-8m17 8-1-8', quiet: 'M12 3v18M7 8v8M2 10v4M17 8v8M22 10v4', noisy: 'M2 8v8M7 3v18M12 7v10M17 2v20M22 9v6', unknown: 'M9 7a3 3 0 1 1 5 3l-2 2v2m0 4h.01M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Z', lightning: 'm13 2-8 12h6l-1 8 9-13h-6l1-7Z', budget: 'M3 6h18v14H3ZM3 6l14-3v3m-1 6h5v4h-5v-4Z' };

/** Inline SVG icon markup for a key. */
const icon = ( k ) => `<svg class="ss-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${ paths[ k ] || paths.balanced }"/></svg>`;

/** Hydrate static [data-icon] placeholders. */
const hydrateIcons = ( root ) => {
	root.querySelectorAll( '[data-icon]' ).forEach( ( el ) => {
		el.innerHTML = icon( el.dataset.icon );
	} );
};

/** Move focus and scroll to a section respecting reduced motion. */
const focusSection = ( el ) => {
	el.scrollIntoView?.( { block: 'start', behavior: matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'instant' : 'smooth' } );
	el.querySelector( 'h2' )?.focus( { preventScroll: true } );
};

/** Human label for a variant (color/guarantee). */
const variantLabel = ( v ) => v.label || ( v.attributes || [] ).map( ( a ) => ( {
	Black: 'مشکی', White: 'سفید', Blue: 'آبی', Red: 'قرمز', Purple: 'بنفش', Beige: 'بژ', 'Dark Gray': 'خاکستری تیره', 'Digital Lavender': 'یاسی', 'Cosmic Black': 'مشکی', 'Gold Tone': 'طلایی', 'Black Anthracite': 'مشکی آنتراسیت', Timber: 'چوبی',
}[ a.option ] || a.option ) ).join( ' · ' );

/** The variant currently selected for a product (first by default). */
const selectedVariant = ( p, selectedVariants ) => p.variants.find( ( v ) => v.id === selectedVariants[ p.id ] ) || p.variants[ 0 ];

/** One product card. */
const card = ( p, index, answers, selectedVariants ) => {
	const v = selectedVariant( p, selectedVariants );
	selectedVariants[ p.id ] = v.id;
	return `<article class="ss-product ss-enter" data-product="${ p.id }" style="animation-delay:${ index * 60 }ms"><div class="ss-role"><span>${ esc( p.role ) }</span><span aria-hidden="true">${ index === 0 ? '✦' : '↗' }</span></div><div class="ss-product-photo"><img src="${ esc( v.image || p.image || '' ) }" alt="${ esc( p.name ) }" width="250" height="205" loading="lazy" ${ ( v.image || p.image ) ? '' : 'hidden' }><small class="ss-photo-caption" ${ ( v.image || p.image ) ? 'hidden' : '' }>تصویر مدل</small></div><div class="ss-product-body"><h3><bdi>${ esc( p.name ) }</bdi></h3><p class="ss-form">${ esc( p.form || 'فرم محصول ثبت نشده' ) }</p><ul class="ss-reasons">${ p.reasons.slice( 0, 3 ).map( ( s ) => '<li>' + esc( s ) + '</li>' ).join( '' ) }</ul>${ p.upgradeReason ? '<p class="ss-caution">قابلیت اضافه: ' + esc( p.upgradeReason ) + '</p>' : '' }<p class="ss-caution"><strong>قبل از انتخاب:</strong> ${ esc( p.cautions?.[ 0 ] || 'سازگاری دستگاه و راحتی را بررسی کن.' ) }</p><p class="ss-over-budget" ${ v.price > answers.budget ? '' : 'hidden' }>${ fa( Math.max( 0, v.price - answers.budget ) ) } تومان بالاتر از سقف اولیه؛ با اجازه افزایش بودجه</p><div class="ss-product-price"><strong class="ss-price-number">${ fa( v.price ) }</strong><small>تومان</small></div><label class="ss-variant-label" for="ss-variant-${ p.id }">رنگ و گارانتی</label><select id="ss-variant-${ p.id }" data-variant="${ p.id }">${ p.variants.map( ( x ) => `<option value="${ x.id }" ${ x.id === v.id ? 'selected' : '' }>${ esc( variantLabel( x ) ) } · ${ fa( x.price ) }</option>` ).join( '' ) }</select><button class="ss-primary ss-buy" data-buy="${ p.id }">بررسی و رفتن به خرید <span aria-hidden="true">←</span></button><details><summary>مبنای این پیشنهاد چیست؟</summary><p>پاسخ‌های شما، مشخصات ثبت‌شده و موجودی قابل خرید فروشگاه؛ ترتیب پیشنهاد، امتیاز کیفیت صدا نیست.</p>${ ( p.sources || [] ).slice( 0, 2 ).map( ( s ) => `<p><a href="${ esc( s.url ) }" target="_blank" rel="noopener noreferrer">${ esc( s.label ) } ↗</a></p>` ).join( '' ) }</details></div></article>`;
};

export { icon, hydrateIcons, focusSection, variantLabel, selectedVariant, card, paths };
