<?php
/**
 * REST controller: POST /wp-json/ts-sound/v1/recommend.
 *
 * @package TSSoundGuide\Rest
 */

namespace TSSoundGuide\Rest;

use TSSoundGuide\CatalogAdapter;
use TSSoundGuide\PublicDto;
use TSSoundGuide\RecommendationEngine;
use TSSoundGuide\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes answers, builds the live catalog, runs the engine, and returns
 * public recommendation DTOs with a short expiry.
 */
final class RecommendController extends Controller {

	/**
	 * Catalog adapter.
	 *
	 * @var CatalogAdapter
	 */
	private CatalogAdapter $catalog;

	/**
	 * Recommendation engine.
	 *
	 * @var RecommendationEngine
	 */
	private RecommendationEngine $engine;

	/**
	 * DTO mapper.
	 *
	 * @var PublicDto
	 */
	private PublicDto $dto;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings (unused here; catalog reads terms).
	 * @param CatalogAdapter $catalog  Catalog adapter.
	 */
	public function __construct( Settings $settings, CatalogAdapter $catalog ) {
		unset( $settings ); // Terms flow through the adapter.
		$this->catalog = $catalog;
		$this->engine = new RecommendationEngine();
		$this->dto    = new PublicDto();
	}

	/**
	 * Register the recommend route.
	 */
	public function register_routes(): void {
		register_rest_route(
			TS_SOUND_GUIDE_REST_BASE,
			'/recommend',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle a recommend request.
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
			if ( ! is_array( $body['answers'] ?? null ) ) {
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

			$health = apply_filters( 'ts_sound_inventory_health', null );
			if ( is_array( $health )
				&& ( ( $health['healthy'] ?? false ) !== true
					|| ! isset( $health['expires_at'] )
					|| (int) $health['expires_at'] <= time() ) ) {
				return $this->error( 'Inventory sync unavailable', 503 );
			}

			$catalog = $this->catalog->catalog();
			$result  = $this->engine->select( $answers, $catalog );

			return $this->respond( $this->dto->recommend_response(
				$this->dto->products( $result['picks'] ),
				$result['notices'],
				$result['total'],
				gmdate( 'c' ),
				gmdate( 'c', time() + 45 )
			) );
		} catch ( \Throwable $e ) {
			$this->log_failure( $e );
			return $this->error( 'Inventory unavailable', 503 );
		}
	}
}
