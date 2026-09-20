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
			$reason = null;
			$caps   = $p['capabilities'];
			$vs     = $this->available_variants( $p, $cap );

			if ( $p['flow'] !== $a['flow'] ) {
				$reason = 'category';
			} elseif ( ! $this->available_variants( $p ) ) {
				$reason = 'unavailable';
			} elseif ( ! $vs ) {
				$reason = 'budget';
			} elseif ( 'wireless' === $conn && true !== $caps['wireless'] ) {
				$reason = 'connection';
			} elseif ( 'usbc' === $conn && ( true !== $caps['usbc'] || ( $a['device'] ?? '' ) !== 'usbc' ) ) {
				$reason = 'device';
			} elseif ( 'aux' === $conn && true !== $caps['aux'] ) {
				$reason = 'connection';
			} elseif ( 'noise' === $pain && true !== $caps['anc'] ) {
			 $reason = 'anc';
			} elseif ( 'switch' === $pain && true !== $caps['multipoint'] ) {
				$reason = 'multipoint';
			} elseif ( 'open' === $fit && false !== $caps['silicone'] ) {
				$reason = 'fit';
			} elseif ( 'silicone' === $fit && true !== $caps['silicone'] ) {
				$reason = 'fit';
			} elseif ( ( 'work' === $use || 'calls' === $pain ) && 'aux' === $conn && true !== $caps['auxMic'] ) {
				$reason = 'wired_microphone';
			} elseif ( 'gaming' === $use && 'wireless' === $conn ) {
				$reason = 'gaming_latency';
			}

			if ( null !== $reason ) {
				$rejected[] = [ 'id' => $p['id'], 'reason' => $reason ];
				continue;
			}

			usort( $vs, static fn( array $x, array $y ): int => ( $x['price'] <=> $y['price'] ) ?: ( $x['id'] <=> $y['id'] ) );
			$price  = $vs[0]['price'];
			$score  = 50;
			$reasons = [];

			if ( 'noise' === $pain && true === $caps['anc'] ) {
				$score   += 25;
				$reasons[] = self::FEATURES['anc'];
			}
			if ( 'switch' === $pain && true === $caps['multipoint'] ) {
				$score   += 25;
				$reasons[] = self::FEATURES['multipoint'];
			}
			if ( 'commute' === $use && true === $caps['anc'] ) {
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

		return [
			'answers'  => $a,
			'picks'    => array_slice( $picks, 0, 3 ),
			'matched'  => $matched,
			'rejected' => $rejected,
			'notices'  => $notices,
			'total'    => count( $matched ),
			'version'  => TS_SOUND_GUIDE_VERSION,
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
