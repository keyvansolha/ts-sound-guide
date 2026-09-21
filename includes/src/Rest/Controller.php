<?php
/**
 * Shared REST plumbing for the guide controllers.
 *
 * @package TSSoundGuide
 */

namespace TSSoundGuide\Rest;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Common request/response behavior: JSON-only input, 4096-byte body limit,
 * no-store cache headers, and controlled error envelopes.
 */
abstract class Controller {
	/** Allowed values for the always-present answer fields. */
	private const ANSWER_ENUMS = [
		'flow'       => [ 'earbuds', 'headphones' ],
		'use'        => [ 'commute', 'music', 'work', 'gaming', 'gift' ],
		'pain'       => [ 'noise', 'fit', 'calls', 'switch', 'charge', 'balanced' ],
		'connection' => [ 'wireless', 'usbc', 'aux' ],
	];

	/** Every accepted answer key, including conditional fields. */
	private const ANSWER_KEYS = [ 'flow', 'use', 'pain', 'connection', 'device', 'calls', 'fit', 'budget', 'flex' ];

	/**
	 * Maximum accepted request body size in bytes.
	 */
	protected const MAX_BODY_BYTES = 4096;

	/**
	 * Respond with data and the no-store header set.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	protected function respond( $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}

	/**
	 * Controlled error envelope. Internal exception messages never reach
	 * visitors; only the message key the browser expects.
	 *
	 * @param string $message Public message key.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	protected function error( string $message, int $status ): WP_REST_Response {
		return $this->respond( [ 'message' => $message ], $status );
	}

	/**
	 * Validate request shape: size limit and JSON object body.
	 *
	 * Returns the decoded body array or an error response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_REST_Response
	 */
	protected function parse_body( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return $this->error( 'Invalid request', 400 );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->error( 'Invalid answers', 400 );
		}
		return $body;
	}

	/**
	 * Require an exact allow-list of top-level request fields.
	 *
	 * @param array<string, mixed> $body    Decoded request body.
	 * @param array<int, string>   $allowed Allowed field names.
	 * @return bool
	 */
	protected function valid_body_keys( array $body, array $allowed ): bool {
		return [] === array_diff( array_keys( $body ), $allowed );
	}

	/**
	 * Whether an optional upstream inventory-health signal is currently usable.
	 *
	 * A missing signal preserves standalone WooCommerce operation. Once a health
	 * provider is connected, both public routes fail closed on unhealthy or stale
	 * data so recommendation and checkout validation cannot disagree.
	 *
	 * @return bool
	 */
	protected function inventory_healthy(): bool {
		$health = apply_filters( 'ts_sound_inventory_health', null );
		return ! is_array( $health )
			|| ( true === ( $health['healthy'] ?? false )
				&& isset( $health['expires_at'] )
				&& (int) $health['expires_at'] > time() );
	}

	/**
	 * Validate the public answer object without applying engine defaults.
	 *
	 * The pure engine intentionally normalizes legacy/internal callers. The
	 * public REST boundary is stricter: every active question must be present,
	 * inactive conditional answers and unknown keys are rejected, and numeric
	 * budgets must already be inside the documented public range.
	 *
	 * @param mixed $answers Raw answers value.
	 * @return bool True only for a complete browser-reachable answer set.
	 */
	protected function valid_answers( $answers ): bool {
		if ( ! is_array( $answers ) || array_diff( array_keys( $answers ), self::ANSWER_KEYS ) ) {
			return false;
		}
		foreach ( self::ANSWER_ENUMS as $key => $allowed ) {
			if ( ! isset( $answers[ $key ] ) || ! is_string( $answers[ $key ] ) || ! in_array( $answers[ $key ], $allowed, true ) ) {
				return false;
			}
		}

		if ( ! isset( $answers['budget'] )
			|| ( ! is_int( $answers['budget'] ) && ! is_float( $answers['budget'] ) )
			|| (float) $answers['budget'] < 500000.0
			|| (float) $answers['budget'] > 500000000.0 ) {
			return false;
		}
		if ( array_key_exists( 'flex', $answers ) && ! is_bool( $answers['flex'] ) ) {
			return false;
		}

		$flow       = $answers['flow'];
		$connection = $answers['connection'];
		if ( ( 'earbuds' === $flow && ! in_array( $connection, [ 'wireless', 'usbc' ], true ) )
			|| ( 'headphones' === $flow && ! in_array( $connection, [ 'wireless', 'aux' ], true ) ) ) {
			return false;
		}

		if ( 'usbc' === $connection ) {
			if ( ! isset( $answers['device'] ) || ! in_array( $answers['device'], [ 'usbc', 'lightning', 'unknown' ], true ) ) {
				return false;
			}
		} elseif ( array_key_exists( 'device', $answers ) ) {
			return false;
		}

		$needs_calls = 'work' === $answers['use'] || 'calls' === $answers['pain'];
		if ( $needs_calls ) {
			if ( ! isset( $answers['calls'] ) || ! in_array( $answers['calls'], [ 'quiet', 'noisy' ], true ) ) {
				return false;
			}
		} elseif ( array_key_exists( 'calls', $answers ) ) {
			return false;
		}

		if ( 'earbuds' === $flow ) {
			if ( ! isset( $answers['fit'] ) || ! in_array( $answers['fit'], [ 'silicone', 'open', 'any' ], true ) ) {
				return false;
			}
		} elseif ( 'fit' === $answers['pain'] ) {
			if ( ! isset( $answers['fit'] ) || ! in_array( $answers['fit'], [ 'long', 'glasses', 'any' ], true ) ) {
				return false;
			}
		} elseif ( array_key_exists( 'fit', $answers ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a purchase URL uses HTTP(S) and the configured site's origin.
	 *
	 * @param string $url Candidate product URL.
	 * @return bool
	 */
	protected function valid_purchase_url( string $url ): bool {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $target['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}
		$target_port = (int) ( $target['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
		$home_port   = (int) ( $home['port'] ?? ( 'https' === $home_scheme ? 443 : 80 ) );
		return $scheme === $home_scheme
			&& strtolower( (string) ( $target['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) )
			&& $target_port === $home_port;
	}

	/**
	 * Log an exception class without exposing the message to visitors.
	 *
	 * @param \Throwable $e Caught exception.
	 */
	protected function log_failure( \Throwable $e ): void {
		error_log( '[TS Sound Guide] request failed: ' . get_class( $e ) );
	}

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;
}
