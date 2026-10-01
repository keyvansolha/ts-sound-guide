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
 *     (e.g. «نویز کنسلینگ» (151), «بی سیم | بلوتوث» (144)) has it — true,
 *     regardless of attributes.
 *  2. Explicit attribute value: an existing attribute value answers yes/no.
 *     The primary attribute is read first; when it carries text but answers
 *     neither yes nor no for this capability (e.g. «اقلام داخل جعبه» that
 *     never mentions tips), the alternate attribute (e.g. the headphone form
 *     factor) is consulted before the value is treated as unreadable.
 *  3. Category negation: a category that explicitly excludes the capability
 *     (e.g. «با سیم | سیمی» (34745)) sets it to false.
 *  4. Absence: a missing/empty attribute is `unknown` — the store has not
 *     listed the feature, and an unlisted feature is never presented to the
 *     shopper as a claim in either direction.
 *  5. Present but contradictory or unreadable text: `unknown`, reported in
 *     catalog health with the field to correct.
 *
 * Tri-state semantics:
 *  - true  (yes): category-implied or explicitly supported;
 *  - false (no):  explicitly unsupported or category-negated;
 *  - null  (unknown): not listed, or listed but contradictory/unreadable.
 *
 * Inference stays forbidden for *affirmative* claims from unrelated evidence:
 * USB audio is never guessed from a USB-C charging connector; ANC is never
 * guessed from ENC or generic noise-reduction prose; AUX microphone support is
 * never guessed from the mere presence of a microphone. A value that says the
 * USB-C port is for charging only is an explicit *no* for USB-C audio, and is
 * never turned into a yes by the mere presence of the string "USB-C".
 */
final class CapabilityRegistry {

	public const YES     = true;
	public const NO      = false;
	public const UNKNOWN = null;

	/*
	 * Category term IDs from the store's product_cat tree.
	 *
	 * Capability authority comes from the «ویژگی ها» feature tree (139),
	 * whose categories are explicit feature claims about a product:
	 *   - 144 «بی سیم | بلوتوث»  -> wireless
	 *   - 34745 «با سیم | سیمی»  -> not wireless
	 *   - 151 «نویز کنسلینگ»     -> listening ANC
	 *
	 * Everything else in the tree describes product form or type (هدفون دور
	 * گوشی، ایرباد، هدفون گیمینگ …) and grants no capability.
	 */
	public const CAT_BI_WIRELESS = 144;   // ویژگی ها → بی سیم | بلوتوث.
	public const CAT_WIRED       = 34745; // ویژگی ها → با سیم | سیمی.
	public const CAT_ANC         = 151;   // ویژگی ها → نویز کنسلینگ.
	public const CAT_WIRELESS_HEADPHONE = 119; // هدفون → هدفون بی سیم.

	/**
	 * Value fragments that mean "silicone ear tips are supplied".
	 *
	 * The store writes this in several ways across «اقلام داخل جعبه» and the
	 * headphone form factor; all of them name the tips, which is the only
	 * evidence that counts. A bare «سری» is never enough (it also appears in
	 * «سری میکروفون»), and a silicone part alone («گیره سیلیکونی») is not a
	 * tip either.
	 *
	 * @var array<int, string>
	 */
	private const SILICONE_YES = [
		'ایرتیپ', 'ارتیپ', 'آرتیپ', 'ear tip', 'eartip',
		'سری سیلیکون', 'سریهای سیلیکون', 'سری های سیلیکون',
		'سری قرار گیرنده', 'سریهای قرار گیرنده', 'قرار گیرنده در گوش',
		'سری داخل گوش', 'سریهای داخل گوش',
	];

	/**
	 * Value fragments that mean "no silicone tips" / open fit.
	 *
	 * @var array<int, string>
	 */
	private const SILICONE_NO = [
		'بدون سری سیلیکون', 'بدون ایرتیپ', 'بدون ارتیپ', 'بدون قرار گیرنده',
		'open-ear', 'open ear', 'نیمه داخل گوش', 'گوشباز', 'گوش باز',
		'بدون ورود به مجرای گوش', 'قلاب دور گوش',
	];

	/**
	 * Whole-value affirmatives the storefront uses in a feature-named field.
	 *
	 * «دارد» in a field that itself names the feature («حذف نویز», «بلوتوث»,
	 * «اتصال هم‌زمان») is a plain yes; it is matched as the *whole* value so an
	 * ambiguous mixed value such as «دارد / میکروفون (noise isolation)» stays
	 * unreadable instead of being promoted to a claim.
	 *
	 * @var array<int, string>
	 */
	private const AFFIRMATIVE_VALUES = [ 'دارد', 'دارد.', 'بله', 'yes', 'supported' ];

	/**
	 * Value fragments that name a USB-C connector at all.
	 *
	 * @var array<int, string>
	 */
	private const USBC_CONNECTOR = [ 'USB-C', 'USB C', 'usbc', 'USB Type-C', 'Type-C', 'تایپ سی', 'تایپ-سی' ];

	/**
	 * Value fragments that prove the USB-C connector carries *audio*.
	 *
	 * @var array<int, string>
	 */
	private const USBC_AUDIO = [ 'صوتی', 'صدا', 'آدیو', 'audio', 'dac', 'lossless', 'پخش کابلی', 'پخش موسیقی', 'سازگاری پخش', 'مانیتورینگ' ];

	/**
	 * Value fragments that describe charging only.
	 *
	 * A storefront that lists a USB-C *charging* port has not claimed USB-C
	 * audio; with no audio wording in the same value, the honest answer is
	 * «ندارد» rather than an inferred yes.
	 *
	 * @var array<int, string>
	 */
	private const USBC_CHARGING_ONLY = [ 'فقط برای شارژ', 'فقط شارژ', 'برای شارژ', 'شارژ کیس', 'کیس شارژ', 'کابل شارژ', 'شارژر' ];

	/**
	 * Connection modes that explicitly carry audio somewhere other than USB-C.
	 *
	 * These values are valid answers in the shared «نوع اتصال» field. When no
	 * USB-C connector is named, they mean USB-C audio is not a supported mode;
	 * they are not malformed product data.
	 *
	 * @var array<int, string>
	 */
	private const USBC_OTHER_AUDIO_MODES = [
		'بی سیم', 'بی‌سیم', 'بیسیم', 'بلوتوث', 'Bluetooth',
		'AUX', '3.5', 'جک صدا', 'Lightning', 'لایتنینگ',
	];

	/**
	 * Capability definitions keyed by internal capability name.
	 *
	 * Each definition contains:
	 *  - attribute: primary WooCommerce attribute taxonomy (pa_*);
	 *  - attribute_alt: optional secondary taxonomy consulted when the
	 *    primary answers neither yes nor no (e.g. fit from box contents or
	 *    form factor);
	 *  - yes / no: exact value fragments for explicit answers;
	 *  - categories: category term IDs (int) and/or name fragments (string)
	 *    that grant the capability (rule 1);
	 *  - category_negations: same shape, but exclude the capability (rule 3);
	 *  - absence: value used when the attribute is missing/empty (rule 4);
	 *  - label: human-readable label for admin diagnostics;
	 *  - field: plain-language instruction naming the WooCommerce field to
	 *    correct when the capability stays unknown.
	 *
	 * Category entries are deliberately limited to categories that exist in
	 * this store's tree and state a capability; form/type categories grant
	 * nothing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function capabilities(): array {
		return [
			'wireless'   => [
				'attribute' => 'pa_bluetooth',
				// A Bluetooth version on the Bluetooth field («نسخه 5.0») states
				// presence without repeating the word.
				'yes'       => [ 'دارد', 'بلوتوث', 'Bluetooth', 'bluetooth', 'BT', 'نسخه' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ self::CAT_BI_WIRELESS, self::CAT_WIRELESS_HEADPHONE, 'بی سیم', 'بلوتوث' ],
				// A product can support Bluetooth and a cable at the same time;
				// wired-capable categories therefore never negate Bluetooth.
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
				'label'     => 'اتصال بی‌سیم',
				'field'     => 'ویژگی «بلوتوث» محصول یا دسته‌بندی «بی سیم | بلوتوث»',
			],
			'usbc'       => [
				'attribute' => 'pa_connection',
				'yes'       => [ 'USB-C', 'USB C', 'usbc', 'USB Type-C', 'Type-C' ],
				// Charging-only wording and explicit negatives.
				'no'        => [ 'ندارد', 'فاقد', 'بدون', 'فقط برای شارژ', 'فقط شارژ' ],
				'connector' => self::USBC_CONNECTOR,
				'audio'     => self::USBC_AUDIO,
				'charging'  => self::USBC_CHARGING_ONLY,
				'other_audio_modes' => self::USBC_OTHER_AUDIO_MODES,
				// No USB-C category exists in the store tree; only the
				// attribute can confirm USB-C audio.
				'categories'         => [],
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
				'label'     => 'صدای USB-C',
				'field'     => 'ویژگی «نوع اتصال» محصول (مقدار صوتی USB-C، نه شارژ)',
			],
			'aux'        => [
				'attribute' => 'pa_aux',
				'yes'       => [ 'دارد', 'AUX', 'aux', '3.5', 'ورودی صدا' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				// «با سیم | سیمی» only rules wireless out; it does not say
				// whether the wire is AUX, so it grants nothing here.
				'categories'         => [],
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
				'label'     => 'ورودی AUX',
				'field'     => 'ویژگی «AUX» محصول',
			],
			'auxMic'     => [
				'attribute' => 'pa_aux-microphone',
				'yes'       => [ 'دارد', 'میکروفون', 'microphone' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				// The store has a «میکروفون» product category (111) and a
				// «مکالمه تلفنی» feature (143); neither states microphone
				// support in AUX mode, so neither grants this capability.
				'categories'         => [],
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
				'label'     => 'میکروفون در حالت AUX',
				'field'     => 'ویژگی «میکروفون AUX» محصول (وجود میکروفون به‌تنهایی کافی نیست)',
			],
			'anc'        => [
				'attribute' => 'pa_noise-cancellation',
				'yes'       => [ 'ANC', 'Active Noise', 'Adaptive Noise', 'حذف نویز فعال', 'حذف نویز تطبیقی' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'categories'         => [ self::CAT_ANC, 'نویز کنسلینگ' ],
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
				'label'     => 'ANC برای شنیدن',
				'field'     => 'ویژگی «حذف نویز» محصول یا دسته‌بندی «نویز کنسلینگ»',
			],
			'multipoint' => [
				'attribute' => 'pa_qip5asto9pe6c2dzxq',
				'yes'       => [ 'دارد', 'Multipoint', 'multipoint' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				// No multipoint category exists in the store tree.
				'categories'         => [],
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
				'label'     => 'اتصال هم‌زمان دو دستگاه',
				'field'     => 'ویژگی «اتصال هم‌زمان» محصول',
			],
			'silicone'   => [
				'attribute'      => 'pa_inside-the-box',
				'attribute_alt'  => 'pa_headphones-type',
				'yes'            => self::SILICONE_YES,
				'no'             => self::SILICONE_NO,
				// Form categories (ایرباد، ایرفون، دور گوشی …) describe shape,
				// not tip material, so none of them grants a fit answer; the
				// headphone-type *attribute text* only answers when it names
				// the tips (e.g. «داخل گوش (In-Ear) با سری سیلیکونی»).
				'categories'         => [],
				'category_negations' => [],
				'absence'   => self::UNKNOWN,
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
	 * Match category rows against registry entries.
	 *
	 * Entries may be term IDs (int, matched against the row's id) or name
	 * fragments (string, matched case-insensitively against the category name
	 * and slug). Term IDs are the primary, data-derived form; fragments let a
	 * re-created category with the same name keep working.
	 *
	 * @param array<int, array<string, string>> $categories Category rows.
	 * @param array<int, int|string>            $entries    Registry entries.
	 * @return bool
	 */
	private function categories_match( array $categories, array $entries ): bool {
		$ids       = [];
		$fragments = [];
		foreach ( $entries as $entry ) {
			if ( is_int( $entry ) || ( is_string( $entry ) && ctype_digit( $entry ) ) ) {
				$ids[] = (int) $entry;
				continue;
			}
			if ( is_string( $entry ) && '' !== trim( $entry ) ) {
				$fragments[] = $entry;
			}
		}
		foreach ( $categories as $category ) {
			if ( $ids && in_array( (int) ( $category['id'] ?? 0 ), $ids, true ) ) {
				return true;
			}
			if ( ! $fragments ) {
				continue;
			}
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
		$result = $this->interpretation( $capability, $raw, $categories );
		return $result['value'];
	}

	/**
	 * Resolve one capability and report which source decided it.
	 *
	 * Sources: 'category' (authority), 'attribute', 'attribute_alt',
	 * 'category_negation', 'absent' (the store listed nothing) and
	 * 'unreadable' (listed, but not an answer in either direction).
	 *
	 * @param string                            $capability Capability key.
	 * @param array<string, string>             $raw        Attribute values keyed by taxonomy.
	 * @param array<int, array<string, string>> $categories Product categories.
	 * @return array{value:bool|null,source:string}
	 */
	public function interpretation( string $capability, array $raw, array $categories = [] ): array {
		$defs = $this->capabilities();
		if ( ! isset( $defs[ $capability ] ) ) {
			return [ 'value' => self::UNKNOWN, 'source' => 'absent' ];
		}
		$def = $defs[ $capability ];

		// 1. Category authority wins over everything else.
		if ( $this->category_implies( $capability, $categories ) ) {
			return [ 'value' => self::YES, 'source' => 'category' ];
		}

		// 2. Explicit attribute value: primary first, then the alternate
		//    source when the primary text answers neither yes nor no.
		$primary   = trim( (string) ( $raw[ $def['attribute'] ] ?? '' ) );
		$alternate = ! empty( $def['attribute_alt'] ) ? trim( (string) ( $raw[ $def['attribute_alt'] ] ?? '' ) ) : '';
		$value     = '' !== $primary ? $this->match( $primary, $def ) : null;
		if ( null !== $value ) {
			return [ 'value' => $value, 'source' => 'attribute' ];
		}
		$alt_value = '' !== $alternate ? $this->match( $alternate, $def ) : null;
		if ( null !== $alt_value ) {
			return [ 'value' => $alt_value, 'source' => 'attribute_alt' ];
		}

		// 3. Category negation.
		if ( $this->category_negates( $capability, $categories ) ) {
			return [ 'value' => self::NO, 'source' => 'category_negation' ];
		}

		// 4. Absence policy: nothing listed is never a claim in either
		//    direction, so it stays unknown and is not counted as a defect.
		//    This covers the case where only the *secondary* source carries
		//    text: a form-factor name such as «روی گوش» is not an answer about
		//    tip material, so it neither grants nor denies the capability.
		if ( '' === $primary ) {
			$absence = $def['absence'] ?? self::UNKNOWN;
			if ( self::YES === $absence || self::NO === $absence ) {
				return [ 'value' => $absence, 'source' => 'absent' ];
			}
			return [ 'value' => self::UNKNOWN, 'source' => 'absent' ];
		}

		// 5. Listed but contradictory or uninterpretable: unknown, reported
		//    to administrators as a data-quality issue.
		return [ 'value' => self::UNKNOWN, 'source' => 'unreadable' ];
	}

	/**
	 * Whether the product's attribute text is present but contradictory, or
	 * disagrees with an authoritative category (admin data-quality signal).
	 *
	 * Both directions of a category/attribute disagreement are reported: a
	 * category that grants the capability while the attribute denies it, and
	 * a category that denies the capability while the attribute claims it.
	 * Category authority still decides the shopper-facing value; the conflict
	 * is surfaced instead of being hidden.
	 *
	 * @param string                            $capability Capability key.
	 * @param array<string, string>             $raw        Attribute values.
	 * @param array<int, array<string, string>> $categories Product categories.
	 * @return string|null Conflict kind: 'contradiction', 'category_conflict', 'category_negation_conflict', or null.
	 */
	public function conflict( string $capability, array $raw, array $categories = [] ): ?string {
		$defs = $this->capabilities();
		if ( ! isset( $defs[ $capability ] ) ) {
			return null;
		}
		$def      = $defs[ $capability ];
		$primary  = trim( (string) ( $raw[ $def['attribute'] ] ?? '' ) );
		$alternate = ! empty( $def['attribute_alt'] ) ? trim( (string) ( $raw[ $def['attribute_alt'] ] ?? '' ) ) : '';
		$primary_value   = '' !== $primary ? $this->match( $primary, $def ) : null;
		$alternate_value = '' !== $alternate ? $this->match( $alternate, $def ) : null;
		$effective       = null !== $primary_value
			? $primary
			: ( null !== $alternate_value ? $alternate : ( '' !== $primary ? $primary : $alternate ) );
		if ( '' === $effective ) {
			return null; // Absent: governed by the absence policy, not a conflict.
		}

		$normalized = $this->normalize_text( $effective );
		$has_yes    = $this->contains_any( $normalized, $def['yes'] );
		$has_no     = $this->contains_any( $normalized, $def['no'] );
		if ( ! empty( $def['connector'] ) && ! empty( $def['audio'] ) ) {
			$has_connector = $this->contains_any( $normalized, (array) $def['connector'] );
			$has_audio     = $this->contains_any( $normalized, (array) $def['audio'] );
			$has_yes       = $has_connector && $has_audio;
			$has_no        = $has_no
				|| ( $has_connector
					&& $this->contains_any( $normalized, (array) ( $def['charging'] ?? [] ) )
					&& ! $has_audio )
				|| ( ! $has_connector && $this->contains_any( $normalized, (array) ( $def['other_audio_modes'] ?? [] ) ) );
		}

		// Explicit category authority against an explicit opposite attribute.
		if ( $this->category_implies( $capability, $categories ) && $has_no && ! $has_yes ) {
			return 'category_conflict';
		}
		// A negating category against an affirmative attribute.
		if ( $this->category_negates( $capability, $categories ) && $has_yes && ! $has_no ) {
			return 'category_negation_conflict';
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
		if ( '' !== $primary && null === $primary_value && null === $alternate_value && ! $has_yes && ! $has_no ) {
			return 'unreadable';
		}
		return null;
	}

	/**
	 * Interpret one attribute value against a definition's yes/no fragments.
	 *
	 * Contradictory values (both yes and no fragments) stay unknown. A USB-C
	 * connector described as charging-only, with no audio wording anywhere in
	 * the same value, is an explicit no for USB-C audio.
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
		if ( ! empty( $def['connector'] ) && ! empty( $def['audio'] ) ) {
			$has_connector = $this->contains_any( $value, (array) $def['connector'] );
			$has_audio     = $this->contains_any( $value, (array) $def['audio'] );
			if ( $has_connector ) {
				if ( $has_audio ) {
					return self::YES;
				}
				if ( $this->contains_any( $value, (array) ( $def['charging'] ?? [] ) ) ) {
					return self::NO;
				}
				return self::UNKNOWN;
			}
			if ( $this->contains_any( $value, (array) ( $def['other_audio_modes'] ?? [] ) ) ) {
				return self::NO;
			}
		}
		$has_yes = $this->contains_any( $value, $def['yes'] );
		if ( $has_yes ) {
			return self::YES;
		}
		// A field that names the feature and says only «دارد» is a plain yes.
		foreach ( self::AFFIRMATIVE_VALUES as $affirmative ) {
			if ( 0 === mb_stripos( $value, $affirmative ) && mb_strlen( $value ) === mb_strlen( $affirmative ) ) {
				return self::YES;
			}
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
