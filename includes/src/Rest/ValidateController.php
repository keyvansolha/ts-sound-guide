<?php
/**
 * REST controller: POST /wp-json/ts-sound/v1/validate.
 *
 * @package TSSoundGuide\Rest
 */

namespace TSSoundGuide\Rest;

use TSSoundGuide\Attribution;
use TSSoundGuide\CatalogAdapter;
use TSSoundGuide\RecommendationEngine;
use TSSoundGuide\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds the live catalog and confirms the exact selected product,
 * variation, and price before returning the purchase URL. Returns 409 when
 * anything changed.
 */
final class ValidateController extends Controller {

	/**
	 * Catalog adapter.
	 *
	 * @var CatalogAdapter
	 */
	private CatalogAdapter $catalog;

	/**
	 * Recommendation engine (for eligibility re-check).
	 *
	 * @var RecommendationEngine
	 */
	private RecommendationEngine $engine;

	/**
	 * Attribution service.
	 *
	 * @var Attribution
	 */
	private Attribution $attribution;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings    Settings.
	 * @param CatalogAdapter $catalog    Catalog adapter.
	 * @param Attribution   $attribution Attribution service.
	 */
	public function __construct( Settings $settings, CatalogAdapter $catalog, Attribution $attribution ) {
		unset( $settings );
		$this->catalog     = $catalog;
		$this->engine     = new RecommendationEngine();
		$this->attribution = $attribution;
	}

	/**
	 * Register the validate route.
	 */
	public function register_routes(): void {
		register_rest_route(
			TS_SOUND_GUIDE_REST_BASE,
			'/validate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle a validate request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		try {
			$body = $this->parse_body( $request );
			if ( $body instanceof WP_REST_Response ) {
				return $body;
			}
			if ( ! $this->valid_body_keys( $body, [ 'answers', 'productId', 'variationId', 'price' ] ) ) {
				return $this->error( 'Invalid request', 400 );
			}
			if ( ! $this->valid_answers( $body['answers'] ?? null ) ) {
				return $this->error( 'Invalid answers', 400 );
			}
			$answers = $this->engine->normalize( $body['answers'] );
			foreach ( [ 'use', 'pain', 'connection' ] as $required ) {
				if ( empty( $answers[ $required ] ) ) {
					return $this->error( 'Incomplete answers', 400 );
				}
			}
			if ( ( 'usbc' === $answers['connection'] && empty( $answers['device'] ) )
				|| ( 'earbuds' === $answers['flow'] && empty( $answers['fit'] ) ) ) {
				return $this->error( 'Incomplete device/fit', 400 );
			}

			$product_id  = isset( $body['productId'] ) && is_int( $body['productId'] ) ? $body['productId'] : 0;
			$variation_id = isset( $body['variationId'] ) && is_int( $body['variationId'] ) ? $body['variationId'] : 0;
			$price       = $body['price'] ?? null;
			if ( $product_id < 1
				|| $variation_id < 1
				|| ( ! is_int( $price ) && ! is_float( $price ) )
				|| ! is_finite( (float) $price ) ) {
				return $this->error( 'Invalid request', 400 );
			}
			if ( ! $this->inventory_healthy() ) {
				return $this->error( 'Inventory sync unavailable', 503 );
			}

			$catalog   = $this->catalog->catalog();
			$selection = $this->engine->select( $answers, $catalog );
			$chosen    = null;
			foreach ( $selection['picks'] as $pick ) {
				if ( (int) $pick['id'] === $product_id ) {
					$chosen = $pick;
					break;
				}
			}
			if ( null === $chosen ) {
				return $this->error( 'Stock or price changed', 409 );
			}
			$variant = null;
			foreach ( $chosen['variants'] as $v ) {
				if ( (int) $v['id'] === $variation_id ) {
					$variant = $v;
					break;
				}
			}
			if ( null === $variant || abs( (float) $variant['price'] - (float) $price ) > 0.01 ) {
				return $this->error( 'Stock or price changed', 409 );
			}

			if ( ! $this->valid_purchase_url( (string) $chosen['url'] ) ) {
				return $this->error( 'Inventory unavailable', 503 );
			}

			$token = $this->attribution->issue_token( (int) $chosen['wcId'], $variation_id, $answers );
			$url   = $this->attribution->purchase_url( (string) $chosen['url'], $variant, $token );

			$dto = new \TSSoundGuide\PublicDto();
			return $this->respond( $dto->validate_response(
				$url,
				(int) $chosen['id'],
				$variation_id,
				(float) $variant['price'],
				gmdate( 'c' )
			) );
		} catch ( \Throwable $e ) {
			$this->log_failure( $e );
			return $this->error( 'Inventory unavailable', 503 );
		}
	}
}
