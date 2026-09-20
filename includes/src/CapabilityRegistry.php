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
 * as yes/no, and the admin label plus correction hint used in diagnostics.
 *
 * Tri-state semantics:
 *  - true  (yes): explicitly supported by an existing attribute value;
 *  - false (no):  explicitly unsupported by an existing attribute value;
 *  - null  (unknown): absent, empty, contradictory, or not interpretable.
 *
 * Inference is forbidden: USB audio is never guessed from a USB-C charging
 * connector; ANC is never guessed from ENC or generic noise-reduction prose;
 * AUX microphone support is never guessed from the presence of a microphone;
 * wireless, fit, or compatibility are never guessed from product names or
 * marketing prose.
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
				'label'     => 'اتصال بی‌سیم',
				'field'     => 'ویژگی «بلوتوث» محصول',
			],
			'usbc'       => [
				'attribute' => 'pa_connection',
				'yes'       => [ 'USB-C', 'USB C', 'usbc', 'USB Type-C', 'Type-C' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'label'     => 'صدای USB-C',
				'field'     => 'ویژگی «نوع اتصال» محصول (مقدار صوتی USB-C، نه شارژ)',
			],
			'aux'        => [
				'attribute' => 'pa_aux',
				'yes'       => [ 'دارد', 'AUX', 'aux', '3.5', 'ورودی صدا' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'label'     => 'ورودی AUX',
				'field'     => 'ویژگی «AUX» محصول',
			],
			'auxMic'     => [
				'attribute' => 'pa_aux-microphone',
				'yes'       => [ 'دارد', 'میکروفون', 'microphone' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'label'     => 'میکروفون در حالت AUX',
				'field'     => 'ویژگی «میکروفون AUX» محصول (وجود میکروفون به‌تنهایی کافی نیست)',
			],
			'anc'        => [
				'attribute' => 'pa_noise-cancellation',
				'yes'       => [ 'ANC', 'Active Noise', 'Adaptive Noise', 'حذف نویز فعال', 'حذف نویز تطبیقی' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون', 'ENC' ],
				'label'     => 'ANC برای شنیدن',
				'field'     => 'ویژگی «حذف نویز» محصول (ANC فعال برای شنیدن؛ ENC مربوط به تماس است)',
			],
			'multipoint' => [
				'attribute' => 'pa_qip5asto9pe6c2dzxq',
				'yes'       => [ 'دارد', 'Multipoint', 'multipoint' ],
				'no'        => [ 'ندارد', 'فاقد', 'بدون' ],
				'label'     => 'اتصال هم‌زمان دو دستگاه',
				'field'     => 'ویژگی «اتصال هم‌زمان» محصول',
			],
			'silicone'   => [
				'attribute'      => 'pa_inside-the-box',
				'attribute_alt'  => 'pa_headphones-type',
				'yes'            => [ 'سیلیکونی' ],
				'no'             => [ 'بدون سری سیلیکونی', 'open-ear', 'open ear', 'نیمه داخل گوش' ],
				'label'          => 'فرم سری (سیلیکونی/باز)',
				'field'          => 'ویژگی «داخل جعبه» یا «نوع هدفون» محصول',
			],
		];
	}

	/**
	 * Resolve one capability from raw product attribute values.
	 *
	 * @param string                $capability Capability key.
	 * @param array<string, string> $raw        Attribute values keyed by taxonomy.
	 * @return bool|null Tri-state value.
	 */
	public function resolve( string $capability, array $raw ): ?bool {
		$defs = $this->capabilities();
		if ( ! isset( $defs[ $capability ] ) ) {
			return self::UNKNOWN;
		}
		$def = $defs[ $capability ];
		$value = $this->match( (string) ( $raw[ $def['attribute'] ] ?? '' ), $def );
		if ( null !== $value || empty( $def['attribute_alt'] ) ) {
			return $value;
		}
		return $this->match( (string) ( $raw[ $def['attribute_alt'] ] ?? '' ), $def );
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
		$has_yes = $this->contains_any( $value, $def['yes'] );
		$has_no  = $this->contains_any( $value, $def['no'] );
		if ( $has_yes && $has_no ) {
			return self::UNKNOWN; // Contradictory.
		}
		if ( $has_yes ) {
			return self::YES;
		}
		if ( $has_no ) {
			return self::NO;
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
