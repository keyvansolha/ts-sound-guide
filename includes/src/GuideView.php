<?php
/**
 * Guide view component: renders the guide markup from bootstrap config.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Componentized PHP view. No product name, ID, image, URL, or recommendation
 * is hardcoded here; dynamic values are escaped for their output context.
 */
final class GuideView {

	/**
	 * Bootstrap configuration (public only).
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Heroes per flow.
	 *
	 * @var array<string, array{name:string,image:string}|null>
	 */
	private array $heroes;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                          $config Public bootstrap config.
	 * @param array<string, array{name:string,image:string}|null> $heroes  Heroes.
	 */
	public function __construct( array $config, array $heroes ) {
		$this->config = $config;
		$this->heroes = $heroes;
	}

	/**
	 * Render the guide.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$hero_earbuds   = $this->heroes['earbuds'] ?? null;
		$hero_headphones = $this->heroes['headphones'] ?? null;
		$logo           = esc_url( get_template_directory_uri() . '/images/logo.svg' );
		$initial        = 'headphones' === ( $this->config['flow'] ?? 'earbuds' ) ? 'headphones' : 'earbuds';
		$initial_hero   = 'headphones' === $initial ? $hero_headphones : $hero_earbuds;
		$hero_img       = $initial_hero ? esc_url( $initial_hero['image'] ) : '';
		$hero_name      = $initial_hero ? esc_attr( $initial_hero['name'] ) : '';
		$hero_alt       = $initial_hero ? esc_attr( $initial_hero['name'] ) : '';

		$config_json = wp_json_encode( $this->config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$config_json = false === $config_json ? '{}' : $config_json;

		ob_start();
		?>
<div class="ts-sound" id="ts-sound" dir="rtl" lang="fa">
	<a class="ss-skip" href="#ss-quiz">رفتن به راهنمای انتخاب</a>
	<header class="ss-nav ss-wrap">
		<a class="ss-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>، صفحه اصلی"><img src="<?php echo $logo; ?>" width="40" height="40" alt=""><span><strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong><small lang="en" dir="ltr">TEHRAN SPEAKER</small></span></a>
		<span class="ss-nav-label">راهنمای انتخاب شخصی</span>
		<button class="ss-text-button" id="ss-motion" aria-pressed="false">توقف حرکت‌ها <span aria-hidden="true">Ⅱ</span></button>
	</header>
	<div class="ss-content">
		<section class="ss-hero ss-wrap" aria-labelledby="ss-title">
			<div class="ss-hero-copy">
				<div class="ss-eyebrow"><span class="ss-signal" aria-hidden="true"></span> هر روز تو. صدای تو.</div>
				<h1 id="ss-title">دنیات رو<br><em>روی صدای خودت</em><br>تنظیم کن.</h1>
				<p class="ss-lead">از شلوغی مسیر تا پلی‌لیست آخر شب.<br>هدفون یا هندزفری‌ای پیدا کن که به زندگی تو می‌خورد.</p>
				<div class="ss-flow" role="group" aria-label="نوع محصول"><button data-flow="earbuds" aria-pressed="<?php echo 'earbuds' === $initial ? 'true' : 'false'; ?>">هندزفری</button><button data-flow="headphones" aria-pressed="<?php echo 'headphones' === $initial ? 'true' : 'false'; ?>">هدفون</button></div>
				<button class="ss-primary ss-start" id="ss-start">انتخاب من رو پیدا کن <span class="ss-arrow" aria-hidden="true">←</span></button>
				<div class="ss-under-cta">چند سؤال کوتاه · دلیل روشن برای هر پیشنهاد</div>
			</div>
			<div class="ss-stage" aria-label="تصویر محصولات هدفون و هندزفری">
				<div class="ss-orbit ss-orbit-one" aria-hidden="true"></div><div class="ss-orbit ss-orbit-two" aria-hidden="true"></div>
				<div class="ss-stage-number" aria-hidden="true"><?php echo 'headphones' === $initial ? '02 / 02' : '01 / 02'; ?></div>
				<div class="ss-stage-disc"><?php if ( '' !== $hero_img ) : ?><img id="ss-hero-product" src="<?php echo $hero_img; ?>" alt="<?php echo $hero_alt; ?>" width="520" height="520" fetchpriority="high"><?php else : ?><span class="ss-hero-placeholder" aria-hidden="true"></span><?php endif; ?></div>
				<div class="ss-stage-tag"><span class="ss-equalizer" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span><span>با ریتم خودت زندگی کن</span></div>
				<div class="ss-stage-foot"><span id="ss-hero-label" dir="ltr"><?php echo $hero_name; ?></span><span>انتخاب با توست ↖</span></div>
			</div>
		</section>
		<div class="ss-wrap"><div class="ss-usecases" aria-label="شروع بر اساس کاربرد"><span>از کجا شروع کنیم؟</span><button data-preset="commute"><span data-icon="commute"></span> رفت‌وآمد</button><button data-preset="music"><span data-icon="music"></span> موسیقی</button><button data-preset="work"><span data-icon="work"></span> کار و تماس</button><button data-preset="gaming"><span data-icon="gaming"></span> بازی</button></div></div>
		<section class="ss-quiz-shell ss-wrap" id="ss-quiz" aria-labelledby="ss-question" hidden>
			<div class="ss-quiz-top"><span class="ss-kicker">انتخابی که به تو می‌آید</span><span id="ss-step-label"></span><button class="ss-text-button" id="ss-close">بستن ×</button></div>
			<div class="ss-progress" role="progressbar" aria-label="پیشرفت راهنما" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="ss-progress-fill"></span></div>
			<div class="ss-quiz-layout">
				<aside class="ss-companion"><div class="ss-companion-art" id="ss-scene" aria-hidden="true"></div><p class="ss-companion-title" id="ss-scene-title">از روزمره‌ات شروع کنیم.</p><p id="ss-scene-copy">لازم نیست مشخصات فنی را از حفظ باشی.</p><div class="ss-answer-trail" id="ss-trail" aria-label="پاسخ‌های شما"></div></aside>
				<div class="ss-question-area"><div id="ss-step-content"><h2 id="ss-question" tabindex="-1"></h2><p class="ss-question-help" id="ss-question-help"></p><div id="ss-options"></div></div><p id="ss-feedback" class="ss-feedback" aria-live="polite"></p><div class="ss-step-actions"><button class="ss-text-button" id="ss-back">→ قبلی</button><button class="ss-primary" id="ss-next" disabled>ادامه ←</button></div></div>
			</div>
		</section>
		<section class="ss-results ss-wrap" id="ss-results" aria-labelledby="ss-result-title" hidden>
			<div class="ss-result-top"><div><div class="ss-eyebrow">این انتخاب‌ها را بررسی کن</div><h2 id="ss-result-title" tabindex="-1">برای ریتم زندگی تو.</h2><p class="ss-result-summary" id="ss-result-summary"></p></div><button class="ss-outline" id="ss-edit">ویرایش پاسخ‌ها ↗</button></div>
			<div class="ss-stock-status" id="ss-stock-status" aria-live="polite"></div><div id="ss-notices"></div>
			<div id="ss-results-grid" class="ss-results-grid"></div>
			<div class="ss-empty" id="ss-empty" hidden></div>
			<div class="ss-result-actions"><button id="ss-compare" class="ss-outline">مقایسه همین انتخاب‌ها</button><button id="ss-retry" class="ss-outline" hidden>تلاش دوباره برای بررسی موجودی</button><a href="<?php echo esc_url( home_url( '/product-category/headphone/handsfree/' ) ); ?>" id="ss-category-link">دیدن همه محصولات دسته‌بندی ↗</a></div>
			<div class="ss-compare" id="ss-compare-table" hidden></div>
		</section>
		<section class="ss-editorial ss-wrap" aria-labelledby="ss-editorial-title">
			<div class="ss-editorial-heading"><span class="ss-kicker">انتخاب خوب، از سؤال خوب شروع می‌شود.</span><h2 id="ss-editorial-title">کدام بخش روزت<br>صدای بهتری می‌خواهد؟</h2></div>
			<button class="ss-story ss-story-feature" data-preset="commute"><span class="ss-story-icon" data-icon="commute" aria-hidden="true"></span><span class="ss-story-number">01</span><strong>مسیر شلوغ.<br>پلی‌لیست خودت.</strong><span>راهنمای انتخاب برای رفت‌وآمد <b aria-hidden="true">↖</b></span></button>
			<button class="ss-story" data-preset="work"><span class="ss-story-icon" data-icon="work" aria-hidden="true"></span><span class="ss-story-number">02</span><strong>از جلسه<br>تا آهنگ بعدی.</strong><span>کار، تماس و جابه‌جایی بین دستگاه‌ها <b aria-hidden="true">↖</b></span></button>
		</section>
		<section class="ss-faq ss-wrap" aria-labelledby="ss-faq-title"><div><span class="ss-kicker">قبل از انتخاب</span><h2 id="ss-faq-title">چیزهایی که<br>واقعاً فرق می‌کنند.</h2></div><div class="ss-faq-list">
			<details><summary>نویز کنسلینگ برای موسیقی و تماس یکی است؟</summary><p>خیر. ANC صدای محیطی را که شما می‌شنوید کاهش می‌دهد. پردازش میکروفون به صدایی مربوط است که طرف مقابل دریافت می‌کند؛ وجود یکی، کیفیت دیگری را ثابت نمی‌کند.</p></details>
			<details><summary>اگر هندزفری در گوشم راحت نباشد چه؟</summary><p>فرم گوش، اندازه سری و نوع استفاده مهم‌اند. می‌توانید مسیر بدون سری سیلیکونی را انتخاب کنید؛ هیچ مشخصه‌ای راحتی یک مدل را برای همه تضمین نمی‌کند.</p></details>
			<details><summary>هزینه بیشتر، همیشه انتخاب بهتری است؟</summary><p>وقتی به‌صرفه است که قابلیت اضافه را واقعاً بخواهید. در نتیجه، تفاوت کاربرد و هزینه مشخص می‌شود و سقف بودجه بدون انتخاب خودتان افزایش پیدا نمی‌کند.</p></details>
			<details><summary>اگر یکی از رنگ‌ها ناموجود شود چه؟</summary><p>در نسخه متصل به فروشگاه، فقط ترکیب رنگ و گارانتی قابل خرید پیشنهاد می‌شود. موجودی و قیمت پیش از رفتن به صفحه خرید دوباره بررسی می‌شوند.</p></details>
		</div></section>
	</div>
	<footer class="ss-footer ss-wrap"><span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span><span>صدایی که با تو جور است.</span><a href="<?php echo esc_url( home_url( '/product-category/headphone/' ) ); ?>">هدفون و هندزفری ↗</a></footer>
	<div class="ss-sr" id="ss-announcement" role="status" aria-live="polite"></div>
</div>
<script type="application/json" id="ts-sound-config"><?php echo $config_json; ?></script>
		<?php
		return (string) ob_get_clean();
	}
}
