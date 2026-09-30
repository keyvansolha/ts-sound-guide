<?php
/**
 * Public DTO mapper: the allow-listed product shape served to the browser.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Maps internal products to the public representation. Internal ranking
 * scores, stock depth, held reservations, and diagnostics never leave the
 * server through this class.
 */
final class PublicDto {

	/**
	 * Product fields exposed to the browser.
	 *
	 * @var array<int, string>
	 */
	private const PRODUCT_KEYS = [
		'id', 'wcId', 'name', 'flow', 'url', 'image', 'price', 'wireless', 'usbc',
		'aux', 'auxMic', 'silicone', 'anc', 'multipoint', 'form', 'cautions',
		'sources', 'reasons', 'role', 'overBudget', 'upgradeReason',
	];

	/**
	 * Variant fields exposed to the browser.
	 *
	 * @var array<int, string>
	 */
	private const VARIANT_KEYS = [ 'id', 'price', 'attributes', 'label', 'image' ];

	/**
	 * Map an internal product (with role/reasons merged) to the public DTO.
	 *
	 * @param array<string, mixed> $p Internal product.
	 * @return array<string, mixed> Public DTO.
	 */
	public function product( array $p ): array {
		$dto = array_intersect_key( $p, array_flip( self::PRODUCT_KEYS ) );
		if ( isset( $p['capabilities'] ) && is_array( $p['capabilities'] ) ) {
			foreach ( $p['capabilities'] as $key => $value ) {
				$dto[ $key ] = $value;
			}
		}
		$dto['variants'] = array_map(
			static fn( array $v ): array => array_intersect_key( $v, array_flip( self::VARIANT_KEYS ) ),
			isset( $p['variants'] ) && is_array( $p['variants'] ) ? $p['variants'] : []
		);
		return $dto;
	}

	/**
	 * Map a list of products.
	 *
	 * @param array<int, array<string, mixed>> $products Internal products.
	 * @return array<int, array<string, mixed>>
	 */
	public function products( array $products ): array {
		return array_map( [ $this, 'product' ], $products );
	}

	/**
	 * The recommend response envelope.
	 *
	 * `picks` carries the three primary cards; `options` carries every other
	 * eligible model, so matching products are never unreachable just because
	 * they lost the primary-selection tie-break. `optionsTotal` is the full
	 * number of extra eligible models, so the browser can state the true count
	 * even when the shipped list is capped.
	 *
	 * @param array<int, array<string, mixed>> $picks    Public picks.
	 * @param array<int, array<string, mixed>> $options  Public extra options.
	 * @param array<int, string>               $notices  Shopper notices.
	 * @param int                              $total    Matched count.
	 * @param int                              $options_total Extra options count.
	 * @param string                           $checkedAt ISO timestamp.
	 * @param string                           $expiresAt ISO timestamp.
	 * @return array<string, mixed>
	 */
	public function recommend_response( array $picks, array $options, array $notices, int $total, int $options_total, string $checked_at, string $expires_at ): array {
		return [
			'picks'        => $picks,
			'options'      => $options,
			'optionsTotal' => $options_total,
			'notices'      => $notices,
			'total'        => $total,
			'version'      => TS_SOUND_GUIDE_VERSION,
			'source'       => 'woocommerce',
			'checkedAt'    => $checked_at,
			'expiresAt'    => $expires_at,
		];
	}

	/**
	 * The validate response envelope.
	 *
	 * @param string $url      Verified purchase URL with attribution token.
	 * @param int    $product_id Internal product id.
	 * @param int    $variation_id Verified variation id.
	 * @param float  $price   Verified price.
	 * @param string $checked_at ISO timestamp.
	 * @return array<string, mixed>
	 */
	public function validate_response( string $url, int $product_id, int $variation_id, float $price, string $checked_at ): array {
		return [
			'url'        => $url,
			'productId'  => $product_id,
			'variationId' => $variation_id,
			'price'      => $price,
			'checkedAt'  => $checked_at,
		];
	}
}
