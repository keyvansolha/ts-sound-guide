<?php
/**
 * Capability registry: tri-state mapping from WooCommerce attributes.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Single, immutable registry describing every recommendation capability: the
 * WooCommerce attribute it is read from, the explicit value fragments accepted
 * as yes/no, the product categories that imply the capability, and the admin
 * label plus correction hint used in diagnostics.
 *
 * Resolution order (first match wins):
 *  1. Category authority: a product in a category that names the capability
 *     (e.g. «هندزفری نویز کنسلینگ») has it — true, regardless of attributes.
 *  2. Explicit attribute value: an existing attribute value answers yes/no.
 *  3. Category negation: a category that explicitly excludes the capability
 *     (e.g. «هدفون سیمی») sets it to false.
 *  4. Absence policy: a missing/empty attribute follows the per-capability
 *     `absence` policy, which defaults to false ("not listed means it does
 *     not have it").
 *
 * Tri-state semantics:
 *  - true  (yes): category-implied or explicitly supported;
 *  - false (no):  explicitly unsupported, category-negated, or absent;
 *  - null  (unknown): only for contradictory/uninterpretable attribute text,
 *    which is surfaced to administrators as a data-quality issue.
 *
 * Inference stays forbidden for *affirmative* claims from unrelated evidence:
 * USB audio is never guessed from a USB-C charging connector; ANC is never
 * guessed from ENC or generic noise-reduction prose; AUX microphone support is
 * never guessed from the mere presence of a microphone.
 */
final class CapabilityRegistry {

	public const YES     = true;
	public const NO      = false;
	public const UNKNOWN = null;

	/**
	 * Capability definitions keyed by internal capability name.
	 *
	 * Each definition contains:
	 *  - attribute: primary WooCommerce attribute taxonomy (pa_*);
	 *  - attribute_alt: optional secondary taxonomy consulted when the
	 *    primary yields unknown (e.g. fit from box contents or form factor);
	 *  - yes / no: exact value fragments for explicit answers;
	 *  - categories: category-name fragments that imply the capability (rule 1);
	 *  - category_negations: category-name fragments that exclude it (rule 3);
	 *  - absence: value used when the attribute is missing/empty (rule 4);
	 *  - label: human-readable label for admin diagnostics;
	 *  - field: plain-language instruction naming the WooCommerce field to
	 *    correct when the capability stays unknown.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function capabilities(): array {
		return [
			'wireless'   => [
				'attribute' => 'pa_bluetooth',
				'yes'       => [ 'دارد', 'بلوتوث', 'Bluetooth', 'bluetooth', 'BT' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ 'بلوتوث', 'بی‌سیم', 'بی سیم', 'wireless', 'bluetooth' ],
				'category_negations' => [ 'سیمی', 'باسیم', 'با سیم', 'wired' ],
				'absence'   => self::NO,
				'label'     => 'اتصال بی‌سیم',
				'field'     => 'ویژگی «بلوتوث» محصول یا دسته‌بندی بی‌سیم',
			],
			'usbc'       => [
				'attribute' => 'pa_connection',
				'yes'       => [ 'USB-C', 'USB C', 'usbc', 'USB Type-C', 'Type-C' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ 'USB-C', 'USB C', 'تایپ سی', 'تایپ-سی', 'usb-c' ],
				'category_negations' => [],
				'absence'   => self::NO,
				'label'     => 'صدای USB-C',
				'field'     => 'ویژگی «نوع اتصال» محصول (مقدار صوتی USB-C، نه شارژ)',
			],
			'aux'        => [
				'attribute' => 'pa_aux',
				'yes'       => [ 'دارد', 'AUX', 'aux', '3.5', 'ورودی صدا' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ 'AUX', 'aux', 'جک صدای', 'جک ۳.۵', 'جک 3.5' ],
				'category_negations' => [],
				'absence'   => self::NO,
				'label'     => 'ورودی AUX',
				'field'     => 'ویژگی «AUX» محصول',
			],
			'auxMic'     => [
				'attribute' => 'pa_aux-microphone',
				'yes'       => [ 'دارد', 'میکروفون', 'microphone' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				// Only AUX-specific categories may imply this; a generic
				// microphone category never does.
				'categories'         => [ 'میکروفون AUX', 'AUX میکروفون', 'میکروفون سیمی' ],
				'category_negations' => [],
				'absence'   => self::NO,
				'label'     => 'میکروفون در حالت AUX',
				'field'     => 'ویژگی «میکروفون AUX» محصول (وجود میکروفون به‌تنهایی کافی نیست)',
			],
			'anc'        => [
				'attribute' => 'pa_noise-cancellation',
				'yes'       => [ 'ANC', 'Active Noise', 'Adaptive Noise', 'حذف نویز فعال', 'حذف نویز تطبیقی' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ 'نویز کنسلینگ', 'نویزکنسلینگ', 'نویز کنسل', 'حذف نویز', 'ANC' ],
				'category_negations' => [],
				'absence'   => self::NO,
				'label'     => 'ANC برای شنیدن',
				'field'     => 'ویژگی «حذف نویز» محصول (ANC فعال برای شنیدن؛ ENC مربوط به تماس است)',
			],
			'multipoint' => [
				'attribute' => 'pa_qip5asto9pe6c2dzxq',
				'yes'       => [ 'دارد', 'Multipoint', 'multipoint' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ 'دو دستگاه', 'اتصال هم‌زمان', 'هم‌زمان', 'مولتی پوینت', 'multipoint' ],
				'category_negations' => [],
				'absence'   => self::NO,
				'label'     => 'اتصال هم‌زمان دو دستگاه',
				'field'     => 'ویژگی «اتصال هم‌زمان» محصول یا دسته‌بندی دو دستگاه',
			],
			'silicone'   => [
				'attribute'      => 'pa_inside-the-box',
				'attribute_alt'  => 'pa_headphones-type',
				'yes'            => [ 'سیلیکونی' ],
				'no'             => [ 'بدون سری سیلیکونی', 'open-ear', 'open ear', 'نیمه داخل گوش' ],
				'categories'         => [ 'سیلیکونی' ],
				'category_negations' => [ 'اوپن ایر', 'open air', 'open-ear', 'بدون سری' ],
				'absence'   => self::NO,
				'label'          => 'فرم سری (سیلیکونی/باز)',
				'field'          => 'ویژگی «داخل جعبه» یا «نوع هدفون» محصول',
			],
		];
	}

	/**
	 * Category-name fragments that imply each capability (rule 1).
	 *
	 * Keyed by capability; filterable with `ts_sound_guide_category_signals`
	 * so a store can add its own category vocabulary without code changes.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function category_signals(): array {
		$signals = [];
		foreach ( $this->capabilities() as $capability => $def ) {
			$signals[ $capability ] = (array) ( $def['categories'] ?? [] );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$signals = (array) apply_filters( 'ts_sound_guide_category_signals', $signals );
		}
		return $signals;
	}

	/**
	 * Category-name fragments that explicitly exclude a capability (rule 3).
	 *
	 * @return array<string, array<int, string>>
	 */
	public function category_negations(): array {
		$negations = [];
		foreach ( $this->capabilities() as $capability => $def ) {
			$negations[ $capability ] = (array) ( $def['category_negations'] ?? [] );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$negations = (array) apply_filters( 'ts_sound_guide_category_negations', $negations );
		}
		return $negations;
	}

	/**
	 * Whether any of the product's category names implies the capability.
	 *
	 * @param string                     $capability Capability key.
	 * @param array<int, array<string, string>> $categories Category rows (name/slug).
	 * @return bool
	 */
	public function category_implies( string $capability, array $categories ): bool {
		$fragments = $this->category_signals()[ $capability ] ?? [];
		return $fragments && $this->categories_match( $categories, $fragments );
	}

	/**
	 * Whether any of the product's category names excludes the capability.
	 *
	 * @param string                     $capability Capability key.
	 * @param array<int, array<string, string>> $categories Category rows (name/slug).
	 * @return bool
	 */
	public function category_negates( string $capability, array $categories ): bool {
		$fragments = $this->category_negations()[ $capability ] ?? [];
		return $fragments && $this->categories_match( $categories, $fragments );
	}

	/**
	 * Match category rows against fragments (name or slug, case-insensitive).
	 *
	 * @param array<int, array<string, string>> $categories Category rows.
	 * @param array<int, string>                $fragments Fragments.
	 * @return bool
	 */
	private function categories_match( array $categories, array $fragments ): bool {
		foreach ( $categories as $category ) {
			$haystack = $this->normalize_text(
				(string) ( $category['name'] ?? '' ) . ' ' . (string) ( $category['slug'] ?? '' )
			);
			if ( '' !== $haystack && $this->contains_any( $haystack, $fragments ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve one capability using the documented precedence.
	 *
	 * @param string                            $capability Capability key.
	 * @param array<string, string>             $raw        Attribute values keyed by taxonomy.
	 * @param array<int, array<string, string>> $categories Product categories (name/slug).
	 * @return bool|null Tri-state value.
	 */
	public function resolve( string $capability, array $raw, array $categories = [] ): ?bool {
		$defs = $this->capabilities();
		if ( ! isset( $defs[ $capability ] ) ) {
			return self::UNKNOWN;
		}
		$def = $defs[ $capability ];

		// 1. Category authority wins over everything else.
		if ( $this->category_implies( $capability, $categories ) ) {
			return self::YES;
		}

		// 2. Explicit attribute value (primary, then alternate taxonomy).
		$primary   = trim( (string) ( $raw[ $def['attribute'] ] ?? '' ) );
		$alternate = ! empty( $def['attribute_alt'] ) ? trim( (string) ( $raw[ $def['attribute_alt'] ] ?? '' ) ) : '';
		$text      = '' !== $primary ? $primary : $alternate;
		$value     = '' !== $text ? $this->match( $text, $def ) : null;
		if ( null !== $value ) {
			return $value;
		}

		// 3. Category negation.
		if ( $this->category_negates( $capability, $categories ) ) {
			return self::NO;
		}

		// 4. Absence policy: a missing/empty attribute is "no" by default.
		//    A non-answering *alternate* value (e.g. a plain form-factor name
		//    such as «روی گوش») also falls through to the absence policy —
		//    only the capability's own attribute can make the answer unknown.
		if ( '' === $primary ) {
			return ( $def['absence'] ?? self::NO ) === self::YES ? self::YES : self::NO;
		}

		// 5. Own attribute present but contradictory or uninterpretable:
		//    unknown, reported to administrators as a data-quality issue.
		return self::UNKNOWN;
	}

	/**
	 * Whether the product's attribute text is present but contradictory, or
	 * disagrees with an authoritative category (admin data-quality signal).
	 *
	 * @param string                            $capability Capability key.
	 * @param array<string, string>             $raw        Attribute values.
	 * @param array<int, array<string, string>> $categories Product categories.
	 * @return string|null Conflict kind: 'contradiction', 'category_conflict', or null.
	 */
	public function conflict( string $capability, array $raw, array $categories = [] ): ?string {
		$defs = $this->capabilities();
		if ( ! isset( $defs[ $capability ] ) ) {
			return null;
		}
		$def      = $defs[ $capability ];
		$primary  = trim( (string) ( $raw[ $def['attribute'] ] ?? '' ) );
		$alternate = ! empty( $def['attribute_alt'] ) ? trim( (string) ( $raw[ $def['attribute_alt'] ] ?? '' ) ) : '';
		$effective = '' !== $primary ? $primary : $alternate;
		if ( '' === $effective ) {
			return null; // Absent: governed by the absence policy, not a conflict.
		}

		$normalized = $this->normalize_text( $effective );
		$has_yes    = $this->contains_any( $normalized, $def['yes'] );
		$has_no     = $this->contains_any( $normalized, $def['no'] );

		// Explicit category authority against an explicit opposite attribute.
		if ( $this->category_implies( $capability, $categories ) && $has_no && ! $has_yes ) {
			return 'category_conflict';
		}
		// A value that asserts both is contradictory.
		if ( $has_yes && $has_no ) {
			$without_no = str_ireplace( $def['no'], ' ', $normalized );
			if ( $this->contains_any( $without_no, [ 'دارد', 'yes', 'supported', 'پشتیبانی می‌کند' ] ) ) {
				return 'contradiction';
			}
			return null; // Negative phrase only (e.g. "بدون ANC"): explicit no.
		}
		// Unreadable text is only reported for the primary attribute: the
		// alternate source (e.g. form factor) legitimately carries values that
		// are neither a yes nor a no for the capability.
		if ( '' !== $primary && ! $has_yes && ! $has_no ) {
			return 'unreadable';
		}
		return null;
	}

	/**
	 * Interpret one attribute value against a definition's yes/no fragments.
	 *
	 * Contradictory values (both yes and no fragments) stay unknown.
	 *
	 * @param string                $value Raw attribute text.
	 * @param array<string, mixed>  $def   Capability definition.
	 * @return bool|null
	 */
	public function match( string $value, array $def ): ?bool {
		$value = $this->normalize_text( $value );
		if ( '' === $value ) {
			return self::UNKNOWN;
		}
		$has_no  = $this->contains_any( $value, $def['no'] );
		if ( $has_no ) {
			// Negative Persian phrases often contain affirmative fragments
			// ("ندارد" contains "دارد"), while phrases such as "بدون ANC"
			// retain the capability name. Treat an explicit negative as no unless
			// a separate positive assertion remains after removing the negatives.
			$without_no = str_ireplace( $def['no'], ' ', $value );
			if ( $this->contains_any( $without_no, [ 'دارد', 'yes', 'supported', 'پشتیبانی می‌کند' ] ) ) {
				return self::UNKNOWN;
			}
			return self::NO;
		}
		$has_yes = $this->contains_any( $value, $def['yes'] );
		if ( $has_yes ) {
			return self::YES;
		}
		return self::UNKNOWN;
	}

	/**
	 * Normalize attribute text for matching: trim, strip tags, unify digits and
	 * collapse whitespace. Persian text is matched as-is (no case folding).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public function normalize_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = str_replace( [ '‌', '‍' ], '', $text ); // ZWNJ/ZWSP.
		return trim( $text );
	}

	/**
	 * Whether the haystack contains any fragment (case-insensitive).
	 *
	 * @param string         $haystack Normalized value.
	 * @param array<string>  $needles  Fragments.
	 * @return bool
	 */
	public function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== mb_stripos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
