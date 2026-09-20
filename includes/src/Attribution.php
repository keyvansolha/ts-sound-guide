<?php
/**
 * Attribution service: short-lived tokens, session capture, order metadata.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide;

defined( 'ABSPATH' ) || exit;

/**
 * After successful final validation the server issues a random 30-minute
 * attribution token. The product page copies it into the WooCommerce session
 * only when it resolves to that product; order metadata is attached only when
 * the exact product and variation are in the order. Coarse answer enums and
 * commerce identifiers only — never personal free text.
 */
final class Attribution {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'ts_sound_ref_';

	/**
	 * WooCommerce session key.
	 */
	private const SESSION_KEY = 'ts_sound_attribution';

	/**
	 * Order metadata key (kept from v2 for compatibility).
	 */
	private const ORDER_META = '_ts_sound_guide';

	/**
	 * Issue a 30-minute attribution token for a product+variation.
	 *
	 * @param int                    $wc_product_id WooCommerce product ID.
	 * @param int                    $variation_id  Variation ID.
	 * @param array<string, mixed>   $answers       Normalized answers.
	 * @return string 32-character token.
	 */
	public function issue_token( int $wc_product_id, int $variation_id, array $answers ): string {
		$token = wp_generate_password( 32, false, false );
		$data   = [
			'v'            => TS_SOUND_GUIDE_VERSION,
			'use'          => $answers['use'] ?? null,
			'pain'         => $answers['pain'] ?? null,
			'budget'       => (float) ( $answers['budget'] ?? 0 ),
			'product_id'   => $wc_product_id,
			'variation_id' => $variation_id,
			'at'           => time(),
		];
		set_transient( self::PREFIX . $token, $data, TS_SOUND_GUIDE_TRANSIENT_EXPIRY );
		return $token;
	}

	/**
	 * Build the purchase URL: variation query args plus the token.
	 *
	 * @param string               $product_url Product permalink.
	 * @param array<string, mixed> $variant     Verified variant.
	 * @param string               $token       Attribution token.
	 * @return string
	 */
	public function purchase_url( string $product_url, array $variant, string $token ): string {
		$query = is_array( $variant['query'] ?? null ) ? $variant['query'] : [];
		return (string) add_query_arg( array_merge( $query, [ 'ts_sound_ref' => $token ] ), $product_url );
	}

	/**
	 * Capture the token on the matching product page into the WC session.
	 */
	public function capture_token(): void {
		if ( ! is_product() || ! isset( $_GET['ts_sound_ref'] ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['ts_sound_ref'] ) );
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) ) {
			return;
		}
		$data = get_transient( self::PREFIX . $token );
		if ( ! is_array( $data ) || (int) ( $data['product_id'] ?? 0 ) !== (int) get_queried_object_id() ) {
			return;
		}
		WC()->session->set( self::SESSION_KEY, $data );
		WC()->session->set_customer_session_cookie( true );
	}

	/**
	 * Attach order metadata when the exact product and variation are present.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function attach_order_attribution( $order ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$data = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $data ) || time() - (int) ( $data['at'] ?? 0 ) > TS_SOUND_GUIDE_TRANSIENT_EXPIRY ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === (int) $data['product_id']
				&& (int) $item->get_variation_id() === (int) $data['variation_id'] ) {
				$order->update_meta_data( self::ORDER_META, $data );
				break;
			}
		}
	}
}
