<?php
/**
 * Answer normalization and the pure recommendation engine.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Recommendation policy. Receives normalized answers and an internal catalog
 * and returns ranked matches, machine-readable rejections, shopper notices,
 * and up to three public picks. Deterministic; no I/O.
 */
final class RecommendationEngine {

	/**
	 * Allowed answer enums.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ENUMS = [
		'flow'      => [ 'earbuds', 'headphones' ],
		'use'       => [ 'commute', 'music', 'work', 'gaming', 'gift' ],
		'pain'      => [ 'noise', 'fit', 'calls', 'switch', 'charge', 'balanced' ],
		'connection' => [ 'wireless', 'usbc', 'aux' ],
		'device'    => [ 'usbc', 'lightning', 'unknown' ],
		'fit'       => [ 'silicone', 'open', 'any', 'long', 'glasses' ],
		'calls'     => [ 'quiet', 'noisy' ],
	];

	/**
	 * Budget bounds in toman.
	 *
	 * @var array{min:float,max:float}
	 */
	private const BUDGET = [ 'min' => 500000.0, 'max' => 500000000.0 ];

	/**
	 * Budget flexibility cap (multiplier).
	 */
	private const FLEX_FACTOR = 1.2;

	/**
	 * Feature reason strings for confirmed capabilities.
	 *
	 * @var array<string, string>
	 */
	private const FEATURES = [
		'anc'       => 'کاهش صدای محیط با ANC',
		'multipoint' => 'اتصال هم‌زمان گوشی و لپ‌تاپ',
	];

	/**
	 * Normalize raw answers: keep only allowed enum values, clamp budget,
	 * resolve conditional fields (device, calls, fit).
	 *
	 * @param array<string, mixed> $input Raw answers.
	 * @return array<string, mixed> Normalized answers.
	 */
	public function normalize( array $input ): array {
		$a = [];
		foreach ( self::ENUMS as $key => $values ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $values, true ) ) {
				$a[ $key ] = $input[ $key ];
			}
		}
		$a['flow']   = $a['flow'] ?? 'earbuds';
		$budget      = $input['budget'] ?? null;
		$a['budget'] = max( self::BUDGET['min'], min( self::BUDGET['max'], is_numeric( $budget ) ? (float) $budget : 6000000.0 ) );
		$a['flex']   = ( $input['flex'] ?? false ) === true;
		if ( ( $a['connection'] ?? '' ) !== 'usbc' ) {
			unset( $a['device'] );
		}
		if ( ( $a['use'] ?? '' ) !== 'work' && ( $a['pain'] ?? '' ) !== 'calls' ) {
			unset( $a['calls'] );
		}
		if ( 'headphones' === $a['flow'] && in_array( $a['fit'] ?? '', [ 'open', 'silicone' ], true ) ) {
			unset( $a['fit'] );
		}
		return $a;
	}

	/**
	 * Select recommendations for normalized answers against a catalog.
	 *
	 * @param array<string, mixed>               $input   Raw answers.
	 * @param array<int, array<string, mixed>>   $catalog Internal catalog.
	 * @return array<string, mixed> Selection result.
	 */
	public function select( array $input, array $catalog ): array {
		$a        = $this->normalize( $input );
		$cap      = $a['budget'] * ( $a['flex'] ? self::FLEX_FACTOR : 1.0 );
		$matched  = [];
		$rejected = [];

		$use  = $a['use'] ?? '';
		$pain = $a['pain'] ?? '';
		$conn = $a['connection'] ?? '';
		$fit  = $a['fit'] ?? '';

		foreach ( $catalog as $p ) {
			$caps   = $p['capabilities'];
			$vs     = $this->available_variants( $p, $cap );
			$reason = $this->rejection( $p, $caps, $a, $vs );

			if ( null !== $reason ) {
				$rejected[] = [ 'id' => $p['id'], 'reason' => $reason ];
				continue;
			}

			usort( $vs, static fn( array $x, array $y ): int => ( $x['price'] <=> $y['price'] ) ?: ( $x['id'] <=> $y['id'] ) );
			$price  = $vs[0]['price'];
			$score  = 50;
			$reasons = [];
			// ANC is a wireless-mode feature: never credit it on a wired path,
			// where the circuit is off and no cable can turn it on.
			$anc_active = 'wireless' === $conn && true === $caps['anc'];

			if ( 'noise' === $pain && $anc_active ) {
				$score   += 25;
				$reasons[] = self::FEATURES['anc'];
			}
			if ( 'switch' === $pain && true === $caps['multipoint'] ) {
				$score   += 25;
				$reasons[] = self::FEATURES['multipoint'];
			}
			if ( 'commute' === $use && $anc_active ) {
				$score   += 12;
				if ( ! in_array( self::FEATURES['anc'], $reasons, true ) ) {
					$reasons[] = self::FEATURES['anc'];
				}
			}
			if ( 'work' === $use && true === $caps['multipoint'] ) {
				$score   += 10;
				if ( ! in_array( self::FEATURES['multipoint'], $reasons, true ) ) {
					$reasons[] = self::FEATURES['multipoint'];
				}
			}
			if ( 'usbc' === $conn ) {
				$reasons[] = 'اتصال سیمی USB-C؛ بدون نیاز به شارژ';
			}
			if ( 'aux' === $conn ) {
				$reasons[] = 'ورودی AUX تأییدشده';
			}
			if ( 'open' === $fit ) {
				$reasons[] = 'بدون سری سیلیکونی داخل گوش';
			}
			if ( 'silicone' === $fit ) {
				$reasons[] = 'سری سیلیکونی قابل تعویض';
			}
			if ( ! $reasons ) {
				$reasons[] = 'نوع اتصال مطابق انتخاب شما';
			}
			$reasons[] = $price <= $a['budget'] ? 'در محدوده بودجه شما' : 'در محدوده افزایش بودجه‌ای که انتخاب کردید';

			$qty    = 0.0;
			$owners = [];
			foreach ( $vs as $v ) {
				$owner = $v['stockOwnerId'] ?? $v['id'];
				if ( isset( $owners[ $owner ] ) ) {
					continue;
				}
				$owners[ $owner ] = true;
				$qty += ( null === $v['qty'] ? 0.0 : max( 0.0, $v['qty'] - ( $v['held'] ?? 0.0 ) ) );
			}

			$matched[] = array_merge( $p, [
				'price'      => $price,
				'variants'   => $vs,
				'fitScore'   => $score,
				'reasons'    => $reasons,
				'qty'        => $qty,
				'overBudget' => $price > $a['budget'],
			] );
		}

		usort( $matched, static fn( array $x, array $y ): int => ( $y['fitScore'] <=> $x['fitScore'] ) ?: ( $x['price'] <=> $y['price'] ) ?: ( $x['id'] <=> $y['id'] ) );

		// Inventory depth only breaks ties after equal suitability and price;
		// parent-managed stock is deduplicated.
		if ( $matched ) {
			$top  = $matched[0];
			$ties = array_values( array_filter( $matched, static fn( array $p ): bool => $p['fitScore'] === $top['fitScore'] && $p['price'] === $top['price'] ) );
			usort( $ties, static fn( array $x, array $y ): int => ( $y['qty'] <=> $x['qty'] ) ?: ( $x['id'] <=> $y['id'] ) );
			if ( $ties[0]['id'] !== $top['id'] ) {
				foreach ( $matched as $i => $p ) {
					if ( $p['id'] === $ties[0]['id'] ) {
						array_splice( $matched, $i, 1 );
						break;
					}
				}
				array_unshift( $matched, $ties[0] );
			}
		}

		$picks = $this->assign_roles( $matched, $use );

		$notices = $this->notices( $a, $use, $pain, $conn, $fit );

		// Eligibility is decided once, above; the three primary cards are only
		// a presentation choice. Every other eligible model stays reachable
		// through the "more options" list, and each one carries the reason it
		// is not one of the primary three, so the management report never has
		// to guess why a matching model was not on a card.
		$pick_ids = array_column( $picks, 'id' );
		$options  = [];
		foreach ( $matched as $index => $p ) {
			$matched[ $index ]['rank']     = $index + 1;
			$matched[ $index ]['exposure'] = in_array( $p['id'], $pick_ids, true ) ? 'primary' : 'more';
			if ( 'more' === $matched[ $index ]['exposure'] ) {
				$options[] = array_merge( $matched[ $index ], [ 'role' => 'گزینه دیگر' ] );
			}
		}
		foreach ( $picks as $index => $pick ) {
			$picks[ $index ]['rank']     = $this->rank_of( $matched, (int) $pick['id'] );
			$picks[ $index ]['exposure'] = 'primary';
		}

		return [
			'answers'  => $a,
			'picks'    => array_slice( $picks, 0, 3 ),
			'options'  => $options,
			'matched'  => $matched,
			'rejected' => $rejected,
			'notices'  => $notices,
			'total'    => count( $matched ),
			'version'  => TS_SOUND_GUIDE_VERSION,
		];
	}

	/**
	 * Why a product cannot be recommended for these answers, or null when it can.
	 *
	 * The reason names the deciding rule. When the rule is blocked because the
	 * store has not listed the capability at all (the value is unknown rather
	 * than an explicit «ندارد»), the reason carries the `_not_listed` suffix so
	 * the coverage report can separate "the store says no" from "the store has
	 * not said anything yet" for every excluded product.
	 *
	 * @param array<string, mixed>             $p    Product.
	 * @param array<string, bool|null>         $caps Tri-state capabilities.
	 * @param array<string, mixed>             $a    Normalized answers.
	 * @param array<int, array<string, mixed>> $vs   Eligible variants within the budget cap.
	 * @return string|null
	 */
	private function rejection( array $p, array $caps, array $a, array $vs ): ?string {
		$suffix = static fn( $value ): string => null === $value ? '_not_listed' : '';
		$use    = $a['use'] ?? '';
		$pain   = $a['pain'] ?? '';
		$conn   = $a['connection'] ?? '';
		$fit    = $a['fit'] ?? '';

		if ( $p['flow'] !== $a['flow'] ) {
			return 'category';
		}
		if ( ! $this->available_variants( $p ) ) {
			return 'unavailable';
		}
		if ( ! $vs ) {
			return 'budget';
		}
		if ( 'wireless' === $conn && true !== $caps['wireless'] ) {
			return 'connection' . $suffix( $caps['wireless'] );
		}
		if ( 'usbc' === $conn ) {
			if ( true !== $caps['usbc'] ) {
				return 'connection' . $suffix( $caps['usbc'] );
			}
			if ( 'usbc' !== ( $a['device'] ?? '' ) ) {
				return 'device';
			}
		}
		if ( 'aux' === $conn && true !== $caps['aux'] ) {
			return 'connection' . $suffix( $caps['aux'] );
		}
		if ( 'noise' === $pain ) {
			if ( true !== $caps['anc'] ) {
				return 'anc' . $suffix( $caps['anc'] );
			}
			if ( true !== $caps['wireless'] || 'wireless' !== $conn ) {
				// ANC only runs while the product is used wirelessly: on a
				// wired connection the circuit is inactive even on hybrid
				// models, so a wired path can never satisfy an ANC priority.
				return 'anc_wired';
			}
		}
		if ( 'switch' === $pain && true !== $caps['multipoint'] ) {
			return 'multipoint' . $suffix( $caps['multipoint'] );
		}
		if ( 'open' === $fit && false !== $caps['silicone'] ) {
			return 'fit' . $suffix( $caps['silicone'] );
		}
		if ( 'silicone' === $fit && true !== $caps['silicone'] ) {
			return 'fit' . $suffix( $caps['silicone'] );
		}
		if ( ( 'work' === $use || 'calls' === $pain ) && 'aux' === $conn && true !== $caps['auxMic'] ) {
			return 'wired_microphone' . $suffix( $caps['auxMic'] );
		}
		if ( 'gaming' === $use && 'wireless' === $conn ) {
			return 'gaming_latency';
		}
		return null;
	}

	/**
	 * Overall rank of a product inside the ranked match list (1-based).
	 *
	 * @param array<int, array<string, mixed>> $matched Ranked matches.
	 * @param int                              $id      Product ID.
	 * @return int
	 */
	private function rank_of( array $matched, int $id ): int {
		foreach ( $matched as $index => $p ) {
			if ( (int) $p['id'] === $id ) {
				return $index + 1;
			}
		}
		return 0;
	}

	/**
	 * The exact effect of every answer on filtering, scoring, and copy.
	 *
	 * This is the documented contract the question flow and the shopper copy
	 * must follow: an answer either filters eligibility, changes the score, or
	 * only changes what is explained — and the interface never promises a
	 * ranking change for an answer that has none. Battery, call, and comfort
	 * answers stay explanation-only because the store has no comparable,
	 * verified numbers for them.
	 *
	 * @return array<string, array{effect:string,detail:string}>
	 */
	public function answer_effects(): array {
		return [
			'flow'       => [ 'effect' => 'filter', 'detail' => 'دسته‌بندی محصول: فقط مدل‌های همان جریان (هندزفری/هدفون).' ],
			'use'        => [ 'effect' => 'filter+score', 'detail' => 'بازی با اتصال بی‌سیم حذف می‌شود؛ رفت‌وآمد با ANC و کار/تماس با اتصال هم‌زمان امتیاز می‌گیرد.' ],
			'pain'       => [ 'effect' => 'filter+score', 'detail' => 'صدای محیط ⇒ شرط ANC (فقط بی‌سیم) و ۲۵ امتیاز؛ جابه‌جایی اتصال ⇒ شرط اتصال هم‌زمان و ۲۵ امتیاز؛ شارژ و متعادل فقط توضیح‌اند.' ],
			'connection' => [ 'effect' => 'filter', 'detail' => 'شرط نوع اتصال (بی‌سیم/USB-C/AUX) و ثبت دلیل نمایش.' ],
			'device'     => [ 'effect' => 'filter', 'detail' => 'در مسیر USB-C فقط دستگاهی که درگاه USB-C دارد.' ],
			'calls'      => [ 'effect' => 'copy', 'detail' => 'فقط نکته کیفیت تماس؛ عدد قابل‌مقایسه‌ای در فروشگاه ثبت نشده، پس رتبه‌بندی تغییر نمی‌کند.' ],
			'fit'        => [ 'effect' => 'filter+copy', 'detail' => 'سری سیلیکونی/باز شرط انتخاب است؛ «استفاده طولانی» و «عینک» فقط نکته راحتی می‌سازند.' ],
			'budget'     => [ 'effect' => 'filter', 'detail' => 'سقف قیمت؛ افزایش ۲۰٪ فقط با تیک خود کاربر (flex) اعمال می‌شود.' ],
			'flex'       => [ 'effect' => 'filter', 'detail' => 'سقف را ۲۰٪ بالا می‌برد و هر پیشنهاد بالای سقف اولیه، مقدار اضافه را نشان می‌دهد.' ],
		];
	}

	/**
	 * Assign the primary, lower-cost, additional-feature, and alternative roles.
	 *
	 * @param array<int, array<string, mixed>> $matched Ranked matches.
	 * @param string                            $use    Answered use.
	 * @return array<int, array<string, mixed>> Up to three picks.
	 */
	private function assign_roles( array $matched, string $use ): array {
		$picks = [];
		if ( ! $matched ) {
			return $picks;
		}
		$picks[] = array_merge( $matched[0], [ 'role' => 'پیشنهاد اصلی' ] );
		$first   = $matched[0];

		$cheaper = array_values( array_filter( $matched, static fn( array $p ): bool => $p['id'] !== $first['id'] && $p['price'] < $first['price'] ) );
		usort( $cheaper, static fn( array $x, array $y ): int => ( $x['price'] <=> $y['price'] ) ?: ( $y['fitScore'] <=> $x['fitScore'] ) );
		if ( $cheaper ) {
			$picks[] = array_merge( $cheaper[0], [ 'role' => 'هزینه کمتر' ] );
		}

		$desired = [];
		if ( 'commute' === $use ) {
			$desired[] = 'anc';
		}
		if ( 'work' === $use ) {
			$desired[] = 'multipoint';
		}
		$upgrades = [];
		foreach ( $matched as $p ) {
			if ( in_array( $p['id'], array_column( $picks, 'id' ), true ) || $p['price'] <= $first['price'] ) {
				continue;
			}
			foreach ( $desired as $feature ) {
				if ( true === $p['capabilities'][ $feature ] && true !== $first['capabilities'][ $feature ] ) {
					$upgrades[] = $p;
					break;
				}
			}
		}
		usort( $upgrades, static fn( array $x, array $y ): int => $x['price'] <=> $y['price'] );
		if ( $upgrades ) {
			$up    = $upgrades[0];
			$extra = [];
			foreach ( $desired as $feature ) {
				if ( true === $up['capabilities'][ $feature ] && true !== $first['capabilities'][ $feature ] ) {
					$extra[] = self::FEATURES[ $feature ];
				}
			}
			$picks[] = array_merge( $up, [ 'role' => 'امکانات بیشتر', 'upgradeReason' => implode( '، ', $extra ) ] );
		}

		if ( count( $picks ) < 3 ) {
			foreach ( $matched as $p ) {
				if ( ! in_array( $p['id'], array_column( $picks, 'id' ), true )
					&& ( $p['capabilities']['anc'] !== $first['capabilities']['anc']
						|| $p['capabilities']['multipoint'] !== $first['capabilities']['multipoint']
						|| $p['capabilities']['silicone'] !== $first['capabilities']['silicone']
						|| $p['capabilities']['wireless'] !== $first['capabilities']['wireless'] ) ) {
					$picks[] = array_merge( $p, [ 'role' => $p['price'] < $first['price'] ? 'هزینه کمتر' : 'گزینه جایگزین' ] );
					break;
				}
			}
		}
		return $picks;
	}

	/**
	 * Shopper notices preserved from the legacy experience.
	 *
	 * @param array<string, mixed> $a Normalized answers.
	 * @param string               $use  Use answer.
	 * @param string               $pain Pain answer.
	 * @param string               $conn Connection answer.
	 * @param string               $fit  Fit answer.
	 * @return array<int, string>
	 */
	private function notices( array $a, string $use, string $pain, string $conn, string $fit ): array {
		$notices = [];
		if ( 'noise' === $pain && 'wireless' !== $conn ) {
			$notices[] = 'حذف نویز فعال (ANC) فقط در حالت بی‌سیم کار می‌کند؛ روی کابل مدار ANC خاموش است، پس برای این اولویت مدل سیمی پیشنهاد نمی‌شود.';
		}
		if ( ( $a['calls'] ?? '' ) === 'noisy' ) {
			$notices[] = 'کیفیت میکروفون در محیط شلوغ به تست نیاز دارد؛ ANC تضمین کیفیت تماس نیست.';
		}
		if ( 'fit' === $pain || in_array( $fit, [ 'long', 'glasses' ], true ) ) {
			$notices[] = 'راحتی و فشار روی گوش شخصی است؛ این پیشنهادها تضمین جاگیری یا راحتی طولانی نیستند.';
		}
		if ( 'usbc' === $conn && ( $a['device'] ?? '' ) === 'usbc' ) {
			$notices[] = 'USB-C بودن درگاه به‌تنهایی کافی نیست؛ پشتیبانی صوتی مدل دستگاه را پیش از خرید بررسی کنید.';
		}
		if ( 'gaming' === $use && 'wireless' === $conn ) {
			$notices[] = 'برای بازی با تأخیر حساس، در سبد فعلی اتصال بی‌سیم آزموده‌شده نداریم؛ مسیر سیمی را بررسی کنید.';
		}
		if ( 'charge' === $pain && 'wireless' === $conn ) {
			$notices[] = 'زمان شارژدهی در شرایط یکسان برای تمام این مدل‌ها تأیید نشده؛ رتبه‌بندی را بر اساس عددهای غیرقابل مقایسه تغییر نداده‌ایم.';
		}
		return $notices;
	}

	/**
	 * Eligible variants for a product within an optional price cap.
	 *
	 * @param array<string, mixed> $p  Product.
	 * @param float                $cap Price cap (INF = no cap).
	 * @return array<int, array<string, mixed>>
	 */
	public function available_variants( array $p, float $cap = INF ): array {
		if ( ( $p['status'] ?? '' ) !== 'publish'
			|| ( $p['lifecycle'] ?? '' ) === 'stop'
			|| ( $p['inStock'] ?? false ) !== true
			|| ( $p['purchasable'] ?? false ) !== true ) {
			return [];
		}
		return array_values( array_filter( $p['variants'] ?? [], static function ( array $v ) use ( $cap ): bool {
			return ! in_array( $v['status'] ?? 'publish', [ 'private', 'draft' ], true )
				&& ( $v['enabled'] ?? true ) !== false
				&& ( $v['inStock'] ?? true ) !== false
				&& ! in_array( $v['stockStatus'] ?? 'instock', [ 'onbackorder', 'outofstock' ], true )
				&& ( $v['backorder'] ?? false ) !== true
				&& $v['price'] > 0
				&& $v['price'] <= $cap
				&& ( null === $v['qty'] || (float) $v['qty'] > (float) ( $v['held'] ?? 0 ) );
		} ) );
	}
}
