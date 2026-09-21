/* Guide state and transitions. Pure with respect to the DOM root. */
import { questions, feedback, useLabels } from './questions.js';
import { request, parseDigits } from './rest.js';
import { focusSection, icon, card, selectedVariant } from './render.js';
import { fa, esc } from './format.js';
import { track } from './analytics.js';

/** Create the guide controller bound to a root element and config. */
const createState = ( root, config ) => {
	const $ = ( id ) => root.querySelector( '#' + id );
	let answers = { flow: config.flow || 'earbuds' };
	let step = 0;
	let result = null;
	let seq = 0;
	let controller = null;
	let closed = true;
	let expiredTimer = null;
	let selectedVariants = {};

	/* ---------- question rendering ---------- */
	const renderQuestion = () => {
		const qs = questions( answers );
		step = Math.min( step, qs.length - 1 );
		const q = qs[ step ];
		$( 'ss-question' ).textContent = q.title;
		$( 'ss-question-help' ).textContent = q.help;
		$( 'ss-scene' ).innerHTML = icon( q.scene );
		$( 'ss-scene-title' ).textContent = q.sceneTitle;
		$( 'ss-scene-copy' ).textContent = q.sceneCopy;
		$( 'ss-step-label' ).textContent = `${ fa( step + 1 ) } از ${ fa( qs.length ) }`;
		const pct = Math.round( ( ( step + 1 ) / qs.length ) * 100 );
		$( 'ss-progress-fill' ).style.width = pct + '%';
		root.querySelector( '.ss-progress' ).setAttribute( 'aria-valuenow', pct );
		$( 'ss-back' ).hidden = step === 0;
		$( 'ss-next' ).textContent = q.key === 'budget' ? 'دیدن انتخاب‌های من ←' : 'ادامه ←';
		$( 'ss-feedback' ).textContent = feedback[ answers[ q.key ] ] || '';
		$( 'ss-trail' ).innerHTML = qs.slice( 0, step ).filter( ( x ) => answers[ x.key ] ).map( ( x ) => `<button data-edit="${ x.key }">${ esc( x.options?.find( ( o ) => o[ 0 ] === answers[ x.key ] )?.[ 1 ] || answers[ x.key ] ) }</button>` ).join( '' );
		if ( q.key === 'budget' ) {
			if ( ! answers.budget ) {
				answers.budget = answers.flow === 'headphones' ? 9000000 : 6000000;
			}
			renderBudget();
			$( 'ss-next' ).disabled = false;
		} else {
			$( 'ss-options' ).innerHTML = '<div class="ss-options-grid" role="group" aria-label="' + esc( q.title ) + '">' + q.options.map( ( [ val, title, sub ] ) => `<button class="ss-option" data-answer="${ val }" aria-pressed="${ answers[ q.key ] === val }"><span class="ss-option-icon">${ icon( val ) }</span><span><b>${ title }</b><small>${ sub }</small></span></button>` ).join( '' ) + '</div>';
			$( 'ss-next' ).disabled = ! answers[ q.key ];
		}
		const el = $( 'ss-step-content' );
		el.classList.remove( 'ss-enter' );
		void el.offsetWidth;
		el.classList.add( 'ss-enter' );
		track( root, 'question_view', { question: q.key, position: step + 1 } );
	};

	const renderBudget = () => {
		$( 'ss-options' ).innerHTML = `<div class="ss-budget"><label for="ss-budget-range">سقف بودجه</label><div class="ss-budget-value"><strong id="ss-budget-number">${ fa( answers.budget ) }</strong><span>تومان</span></div><input id="ss-budget-range" type="range" min="500000" max="20000000" step="100000" value="${ Math.min( answers.budget, 20000000 ) }" aria-valuetext="${ fa( answers.budget ) } تومان"><div class="ss-budget-presets">${ [ 4000000, 6000000, 9000000, 16000000 ].map( ( n ) => `<button data-budget="${ n }">${ fa( n / 1000000 ) } میلیون</button>` ).join( '' ) }</div><label class="ss-budget-exact" for="ss-budget-exact">مبلغ دلخواه (تومان)<input id="ss-budget-exact" inputmode="numeric" type="text" value="${ answers.budget }" maxlength="12"></label><label><input id="ss-flex" type="checkbox" ${ answers.flex ? 'checked' : '' }>اگر تفاوت کاربردی دارد، تا ۲۰٪ بیشتر هم بررسی کن.</label></div>`;
	};

	/** Validate + store a budget value; returns ok. */
	const budget = ( value ) => {
		const n = parseDigits( value );
		const ok = Number.isFinite( n ) && n >= 500000 && n <= 500000000;
		$( 'ss-next' ).disabled = ! ok;
		if ( ! ok ) {
			$( 'ss-feedback' ).textContent = 'مبلغی بین ۵۰۰ هزار تا ۵۰۰ میلیون تومان وارد کن.';
			return false;
		}
		answers.budget = n;
		$( 'ss-budget-number' ).textContent = fa( n );
		$( 'ss-budget-range' ).setAttribute( 'aria-valuetext', fa( n ) + ' تومان' );
		$( 'ss-feedback' ).textContent = 'سقف انتخاب: ' + fa( n * ( answers.flex ? 1.2 : 1 ) ) + ' تومان';
		return true;
	};

	/* ---------- flow and lifecycle ---------- */
	const setFlow = ( flow ) => {
		answers = { flow };
		step = 0;
		result = null;
		selectedVariants = {};
		seq++;
		controller?.abort();
		$( 'ss-results' ).hidden = true;
		$( 'ss-quiz' ).hidden = true;
		closed = true;
		root.querySelectorAll( '[data-flow]' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b.dataset.flow === flow ) ) );
		const h = config.hero?.[ flow ];
		const img = $( 'ss-hero-product' );
		if ( h && img ) {
			img.src = h.image;
			img.alt = h.name;
			img.hidden = false;
			$( 'ss-hero-label' ).textContent = h.name;
		} else if ( img ) {
			img.hidden = true;
			img.removeAttribute( 'src' );
			$( 'ss-hero-label' ).textContent = '';
		}
		root.querySelector( '.ss-stage-number' ).textContent = flow === 'headphones' ? '02 / 02' : '01 / 02';
		$( 'ss-category-link' ).href = config.categoryUrls?.[ flow ] || config.homeUrl;
	};

	const start = ( preset ) => {
		seq++;
		controller?.abort();
		closed = false;
		step = 0;
		if ( preset ) {
			answers = { flow: answers.flow, use: preset };
			step = 1;
		} else if ( result ) {
			step = 0;
		}
		$( 'ss-quiz' ).hidden = false;
		$( 'ss-results' ).hidden = true;
		renderQuestion();
		focusSection( $( 'ss-quiz' ) );
		track( root, 'start', { flow: answers.flow, preset: preset || 'none' } );
	};

	/* ---------- results ---------- */
	const invalidState = ( message ) => {
		root.querySelectorAll( '.ss-buy' ).forEach( ( b ) => {
			b.disabled = true;
		} );
		$( 'ss-stock-status' ).textContent = message;
		$( 'ss-stock-status' ).dataset.state = 'error';
		$( 'ss-retry' ).hidden = false;
	};

	const refresh = async ( { focus = false } = {} ) => {
		const my = ++seq;
		controller?.abort();
		controller = new AbortController();
		clearTimeout( expiredTimer );
		$( 'ss-results' ).hidden = false;
		$( 'ss-stock-status' ).textContent = 'در حال بررسی موجودی قابل خرید…';
		$( 'ss-stock-status' ).dataset.state = 'loading';
		root.querySelectorAll( '.ss-buy' ).forEach( ( b ) => {
			b.disabled = true;
		} );
		try {
			const r = await request( config, 'recommend', { answers }, controller );
			if ( my !== seq || closed ) {
				return;
			}
			result = r;
			renderResults();
			expiredTimer = setTimeout( () => invalidState( 'اعتبار موجودی تمام شد؛ برای ادامه دوباره بررسی کن.' ), Math.max( 0, Date.parse( r.expiresAt ) - Date.now() ) );
			if ( focus ) {
				focusSection( $( 'ss-results' ) );
			}
			track( root, 'results_view', { flow: answers.flow, use: answers.use, count: r.total, products: r.picks.map( ( x ) => x.id ) } );
		} catch ( e ) {
			if ( my !== seq || e.name === 'AbortError' ) {
				return;
			}
			invalidState( 'موجودی قابل تأیید نیست. برای ادامه دوباره بررسی کن.' );
			track( root, 'stock_check_failed' );
			if ( focus ) {
				focusSection( $( 'ss-results' ) );
			}
		}
	};

	const renderResults = () => {
		const r = result;
		$( 'ss-result-title' ).textContent = {
			commute: 'برای مسیرهای شلوغ تو.', music: 'برای وقتِ موسیقی تو.', work: 'برای روز کاری تو.', gaming: 'برای بازی، با اتصال درست.', gift: 'برای یک هدیه حساب‌شده.',
		}[ answers.use ] || 'برای ریتم زندگی تو.';
		$( 'ss-result-summary' ).textContent = `${ useLabels[ answers.use ] || '' } · سقف بودجه ${ fa( answers.budget ) } تومان${ answers.flex ? ' · بررسی تا ۲۰٪ بیشتر' : '' }`;
		$( 'ss-stock-status' ).dataset.state = 'ok';
		$( 'ss-stock-status' ).textContent = 'موجودی فروشگاه بررسی شد؛ پیش از خرید دوباره بررسی می‌شود.';
		$( 'ss-retry' ).hidden = true;
		$( 'ss-notices' ).innerHTML = r.notices.map( ( n ) => '<p class="ss-notice">' + esc( n ) + '</p>' ).join( '' );
		$( 'ss-results-grid' ).innerHTML = r.picks.map( ( p, i ) => card( p, i, answers, selectedVariants ) ).join( '' );
		$( 'ss-compare' ).hidden = r.picks.length < 2;
		$( 'ss-compare-table' ).hidden = true;
		$( 'ss-empty' ).hidden = r.picks.length > 0;
		if ( ! r.picks.length ) {
			renderEmpty();
		}
		$( 'ss-announcement' ).textContent = fa( r.picks.length ) + ' پیشنهاد پیدا شد.';
	};

	const renderEmpty = () => {
		let message = 'با این ترکیب نیاز و بودجه، در سبد بررسی‌شده گزینه قابل پیشنهادی نداریم.';
		if ( answers.connection === 'usbc' && answers.device !== 'usbc' ) {
			message = 'اتصال مستقیم USB-C برای دستگاه انتخاب‌شده تأیید نشده است.';
		} else if ( answers.fit === 'open' && answers.pain === 'noise' ) {
			message = 'در سبد بررسی‌شده، مدل بدون سری سیلیکونی با ANC نداریم.';
		} else if ( answers.connection === 'aux' && ( answers.use === 'work' || answers.pain === 'calls' ) ) {
			message = 'میکروفون در حالت AUX برای این مدل‌ها تأیید نشده است.';
		} else if ( answers.use === 'gaming' && answers.connection === 'wireless' ) {
			message = 'برای بازی با تأخیر حساس، مدل بی‌سیم آزموده‌شده در این سبد نداریم.';
		}
		$( 'ss-empty' ).innerHTML = `<h3>بی‌دلیل یک مدل پیشنهاد نمی‌دهیم.</h3><p>${ message }</p><div class="ss-empty-actions"><button class="ss-outline" data-edit="connection">بررسی نوع اتصال</button><button class="ss-outline" data-edit="budget">تغییر بودجه</button><button class="ss-outline" data-edit="pain">بازبینی اولویت‌ها</button></div>`;
		track( root, 'no_match', { flow: answers.flow, use: answers.use, pain: answers.pain, connection: answers.connection, budget: answers.budget } );
	};

	const buy = async ( id ) => {
		if ( ! result ) {
			return;
		}
		const p = result.picks.find( ( x ) => x.id === id );
		if ( ! p ) {
			return;
		}
		const v = selectedVariant( p, selectedVariants );
		const button = root.querySelector( `[data-buy="${ id }"]` );
		const requestSeq = seq;
		button.disabled = true;
		button.textContent = 'در حال بررسی…';
		track( root, 'buy_check', { productId: id, variationId: v.id } );
		try {
			const data = await request( config, 'validate', { answers, productId: id, variationId: v.id, price: v.price }, controller );
			if ( requestSeq !== seq || closed ) {
				return;
			}
			track( root, 'product_click', { productId: id, variationId: v.id } );
			const url = new URL( data.url, location.href );
			if ( ! /^https?:$/.test( url.protocol ) ) {
				throw new Error( 'url' );
			}
			if ( url.origin !== new URL( config.homeUrl ).origin ) {
				throw new Error( 'origin' );
			}
			location.assign( url.href );
		} catch ( e ) {
			if ( requestSeq !== seq || closed ) {
				return;
			}
			button.disabled = false;
			button.innerHTML = 'بررسی و رفتن به خرید <span aria-hidden="true">←</span>';
			if ( e.status === 409 ) {
				await refresh();
				$( 'ss-stock-status' ).textContent = 'قیمت، رنگ یا موجودی تغییر کرده است. پیشنهاد تازه را بررسی و دوباره انتخاب کن.';
				track( root, 'inventory_changed', { productId: id } );
			} else {
				invalidState( 'بررسی نهایی انجام نشد؛ تا تأیید موجودی امکان ادامه نیست.' );
				track( root, 'stock_check_failed' );
			}
		}
	};

	const compare = () => {
		const ps = result.picks;
		const rows = [
			[ 'فرم', ( p ) => p.form || 'ثبت نشده' ],
			[ 'اتصال', ( p ) => p.usbc ? 'سیمی USB-C' : p.aux === true ? ( p.wireless ? 'بلوتوث و AUX' : 'سیمی AUX' ) : p.wireless ? 'بلوتوث' : 'تأیید نشده' ],
			[ 'ANC برای شنیدن', ( p ) => p.anc === true ? 'دارد' : p.anc === false ? 'ندارد' : 'تأیید نشده' ],
			[ 'اتصال دو دستگاه', ( p ) => p.multipoint === true ? 'دارد' : p.multipoint === false ? 'ندارد' : 'تأیید نشده' ],
			[ 'قیمت ترکیب انتخاب‌شده', ( p ) => fa( selectedVariant( p, selectedVariants ).price ) + ' تومان' ],
			[ 'نکته قبل از خرید', ( p ) => p.cautions?.[ 0 ] || '' ],
		];
		$( 'ss-compare-table' ).innerHTML = '<table><caption>مقایسه ویژگی‌های مرتبط با انتخاب شما</caption><thead><tr><th scope="col">ویژگی</th>' + ps.map( ( p ) => '<th scope="col"><bdi>' + esc( p.name ) + '</bdi></th>' ).join( '' ) + '</tr></thead><tbody>' + rows.map( ( [ name, get ] ) => '<tr><th scope="row">' + name + '</th>' + ps.map( ( p ) => '<td>' + esc( get( p ) ) + '</td>' ).join( '' ) + '</tr>' ).join( '' ) + '</tbody></table>';
		$( 'ss-compare-table' ).hidden = false;
		$( 'ss-compare-table' ).scrollIntoView?.( { block: 'nearest' } );
		track( root, 'compare', { products: ps.map( ( p ) => p.id ) } );
	};

	/* ---------- transitions exposed to the DOM controller ---------- */
	const answer = ( val ) => {
		const q = questions( answers )[ step ];
		if ( ! q || q.key === 'budget' || ! q.options.some( ( x ) => x[ 0 ] === val ) ) {
			return;
		}
		answers[ q.key ] = val;
		if ( [ 'use', 'pain' ].includes( q.key ) ) {
			delete answers.calls;
		}
		if ( q.key === 'pain' && answers.flow === 'headphones' ) {
			delete answers.fit;
		}
		if ( q.key === 'connection' ) {
			delete answers.device;
		}
		root.querySelectorAll( '[data-answer]' ).forEach( ( x ) => x.setAttribute( 'aria-pressed', String( x.dataset.answer === val ) ) );
		$( 'ss-next' ).disabled = false;
		$( 'ss-feedback' ).textContent = feedback[ val ] || 'انتخابت ثبت شد؛ ادامه بده.';
		track( root, 'answer', { question: q.key, answer: val } );
	};

	const setPreset = ( preset ) => {
		answers = { flow: answers.flow };
		answers.use = preset;
	};

	const editQuestion = ( key ) => {
		closed = false;
		$( 'ss-quiz' ).hidden = false;
		$( 'ss-results' ).hidden = true;
		seq++;
		controller?.abort();
		step = Math.max( 0, questions( answers ).findIndex( ( x ) => x.key === key ) );
		renderQuestion();
		focusSection( $( 'ss-quiz' ) );
	};

	const close = () => {
		closed = true;
		seq++;
		controller?.abort();
		$( 'ss-quiz' ).hidden = true;
		$( 'ss-start' ).focus();
	};

	const next = () => {
		if ( $( 'ss-next' ).disabled ) {
			return;
		}
		if ( questions( answers )[ step ].key === 'budget' ) {
			$( 'ss-quiz' ).hidden = true;
			closed = false;
			refresh( { focus: true } );
		} else {
			step++;
			renderQuestion();
			$( 'ss-question' ).focus( { preventScroll: true } );
		}
	};

	const back = () => {
		step = Math.max( 0, step - 1 );
		renderQuestion();
	};

	const toggleMotion = () => {
		const off = root.dataset.motion !== 'off';
		root.dataset.motion = off ? 'off' : 'on';
		const button = $( 'ss-motion' );
		button.setAttribute( 'aria-pressed', String( off ) );
		button.textContent = off ? 'پخش حرکت‌ها ▷' : 'توقف حرکت‌ها Ⅱ';
	};

	const setFlex = ( on ) => {
		answers.flex = on;
	};

	const selectVariant = ( productId, variantId ) => {
		selectedVariants[ productId ] = variantId;
		const p = result?.picks.find( ( x ) => x.id === productId );
		if ( ! p ) {
			return;
		}
		const guideCard = root.querySelector( `[data-product="${ productId }"]` );
		const v = selectedVariant( p, selectedVariants );
		guideCard.querySelector( '.ss-price-number' ).textContent = fa( v.price );
		const img = guideCard.querySelector( '.ss-product-photo img' );
		img.src = v.image || p.image || '';
		img.hidden = ! ( v.image || p.image );
		guideCard.querySelector( '.ss-photo-caption' ).hidden = ! ! ( v.image || p.image );
		const warning = guideCard.querySelector( '.ss-over-budget' );
		warning.hidden = v.price <= answers.budget;
		warning.textContent = fa( Math.max( 0, v.price - answers.budget ) ) + ' تومان بالاتر از سقف اولیه؛ با اجازه افزایش بودجه';
		$( 'ss-compare-table' ).hidden = true;
		track( root, 'variant_select', { productId, variationId: variantId } );
	};

	const isVisible = () => ! closed && ! $( 'ss-results' ).hidden;
	const hasResult = () => !! result;
	const currentAnswers = () => answers;
	const currentStep = () => step;

	return {
		setFlow, start, refresh, buy, compare, answer, setPreset, editQuestion,
		close, next, back, toggleMotion, setFlex, budget, selectVariant,
		isVisible, hasResult, currentAnswers, currentStep, renderQuestion,
	};
};

export { createState };
