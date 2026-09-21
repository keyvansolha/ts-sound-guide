<?php
/**
 * REST controller boundary tests using real plugin services.
 *
 * Usage: php tests/unit/rest.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/rest-shims.php';
require __DIR__ . '/wc-shims.php';
require __DIR__ . '/../../ts-sound-guide.php';

use TSSoundGuide\Attribution;
use TSSoundGuide\CapabilityRegistry;
use TSSoundGuide\CatalogAdapter;
use TSSoundGuide\Rest\RecommendController;
use TSSoundGuide\Rest\ValidateController;
use TSSoundGuide\Settings;

$GLOBALS['ts_test_option'] = [
	'ts_sound_guide_settings' => [ 'page_id' => 0, 'earbuds_term' => 11, 'headphones_term' => 21 ],
];

$pass = 0;
$fail = 0;
function check( string $name, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "ok  {$name}\n";
	} else {
		$fail++;
		echo "FAIL {$name}\n";
	}
}

/** @param array<string, mixed> $payload */
function request( array $payload, string $content_type = 'application/json' ): WP_REST_Request {
	return new WP_REST_Request( (string) json_encode( $payload ), $content_type );
}

/** @return array<string, mixed> */
function complete_answers(): array {
	return [
		'flow' => 'earbuds', 'use' => 'music', 'pain' => 'balanced',
		'connection' => 'wireless', 'fit' => 'any', 'budget' => 6000000, 'flex' => false,
	];
}

ts_wc_product( 201, [
	'type' => 'simple', 'name' => 'Ready Buds', 'cats' => [ 11 ], 'price' => 4000000,
	'attributes' => [ 'pa_bluetooth' => 'دارد', 'pa_noise-cancellation' => 'ندارد' ],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
] );
ts_wc_product( 202, [
	'type' => 'simple', 'name' => 'Wired Buds', 'cats' => [ 11 ], 'price' => 3500000,
	'attributes' => [ 'pa_bluetooth' => 'ندارد', 'pa_connection' => 'AUX' ],
	'variation_attributes' => [ 'pa_color' => 'black', 'pa_guarantee' => '6m' ],
] );

$settings    = new Settings();
$catalog     = new CatalogAdapter( $settings, new CapabilityRegistry() );
$recommend   = new RecommendController( $settings, $catalog );
$validate    = new ValidateController( $settings, $catalog, new Attribution() );

/* A valid recommendation is public-only and explicitly non-cacheable. */
$response = $recommend->handle( request( [ 'answers' => complete_answers() ] ) );
$data     = $response->get_data();
$headers  = $response->get_headers();
check( 'recommend success returns 200', 200 === $response->get_status() );
check( 'recommend success returns WooCommerce source and a pick', 'woocommerce' === ( $data['source'] ?? null ) && 1 === count( $data['picks'] ?? [] ) );
check( 'recommend response omits private inventory fields', ! str_contains( (string) json_encode( $data ), 'stockOwnerId' ) && ! str_contains( (string) json_encode( $data ), '"qty"' ) );
check( 'recommend response sets no-store/noindex headers', str_contains( $headers['Cache-Control'] ?? '', 'no-store' ) && 'noindex, nofollow' === ( $headers['X-Robots-Tag'] ?? '' ) );

/* Invalid transport and answer shapes never fall through to default values. */
$response = $recommend->handle( request( [ 'answers' => complete_answers() ], 'text/plain' ) );
check( 'recommend rejects non-JSON content', 400 === $response->get_status() );
$response = $recommend->handle( new WP_REST_Request( str_repeat( 'x', 4097 ) ) );
check( 'recommend rejects bodies above 4096 bytes', 400 === $response->get_status() );

$answers = complete_answers();
$answers['budget'] = 'not-a-number';
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend rejects invalid budget instead of defaulting it', 400 === $response->get_status() );

$answers = complete_answers();
unset( $answers['flow'] );
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend rejects missing flow instead of defaulting it', 400 === $response->get_status() );

$answers = complete_answers();
$answers['use'] = 'work';
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend requires calls when work makes that question active', 400 === $response->get_status() );

$answers = complete_answers();
$answers['flex'] = 'false';
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend requires flex to be a boolean', 400 === $response->get_status() );

$answers = complete_answers();
$answers['budget'] = 499999;
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend rejects a budget outside the public bounds', 400 === $response->get_status() );

$answers = complete_answers();
$answers['device'] = 'usbc';
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend rejects a device answer when USB-C is not active', 400 === $response->get_status() );

$answers = complete_answers();
$answers['flow'] = 'headphones';
$answers['connection'] = 'usbc';
$answers['device'] = 'usbc';
unset( $answers['fit'] );
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend rejects a connection unavailable for the selected flow', 400 === $response->get_status() );

$answers = complete_answers();
$answers['surprise'] = 'not-allow-listed';
$response = $recommend->handle( request( [ 'answers' => $answers ] ) );
check( 'recommend rejects unknown answer fields', 400 === $response->get_status() );

$response = $recommend->handle( request( [ 'answers' => complete_answers(), 'debug' => true ] ) );
check( 'recommend rejects unknown top-level fields', 400 === $response->get_status() );

/* Health and catalog failures are controlled 503 responses. */
$GLOBALS['ts_test_health'] = [ 'healthy' => false, 'expires_at' => time() + 60 ];
$response = $recommend->handle( request( [ 'answers' => complete_answers() ] ) );
check( 'recommend returns 503 for unhealthy inventory source', 503 === $response->get_status() );
$GLOBALS['ts_test_health'] = null;
$GLOBALS['ts_wc_currency'] = 'USD';
$response = $recommend->handle( request( [ 'answers' => complete_answers() ] ) );
check( 'recommend returns controlled 503 for unsupported currency', 503 === $response->get_status() && 'Inventory unavailable' === ( $response->get_data()['message'] ?? null ) );
$GLOBALS['ts_wc_currency'] = 'IRT';

/* Validation requires the exact still-eligible product, variation, and price. */
$payload = [ 'answers' => complete_answers(), 'productId' => 201, 'variationId' => 201, 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
$data     = $response->get_data();
check( 'validate exact selection returns 200', 200 === $response->get_status() );
check( 'validate success issues a 30-minute token', 1 === count( $GLOBALS['ts_test_transients'] ) && 30 * MINUTE_IN_SECONDS === reset( $GLOBALS['ts_test_transients'] )['expiration'] );
check( 'validate URL contains exact attribution and variation data', str_contains( $data['url'] ?? '', 'ts_sound_ref=' ) && 201 === ( $data['variationId'] ?? null ) );

$payload['price'] = 4000001;
$response = $validate->handle( request( $payload ) );
check( 'validate returns 409 when price changed', 409 === $response->get_status() );

$payload = [ 'answers' => complete_answers(), 'productId' => 999, 'variationId' => 999, 'price' => 1 ];
$response = $validate->handle( request( $payload ) );
check( 'validate returns 409 when product disappeared', 409 === $response->get_status() );

$invalid_answers = complete_answers();
$invalid_answers['budget'] = 'invalid';
$payload = [ 'answers' => $invalid_answers, 'productId' => 201, 'variationId' => 201, 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate applies the same strict answer validation', 400 === $response->get_status() );

$payload = [ 'answers' => complete_answers(), 'productId' => 201, 'variationId' => 201, 'price' => 4000000, 'debug' => true ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects unknown top-level fields', 400 === $response->get_status() );

$GLOBALS['ts_test_health'] = [ 'healthy' => false, 'expires_at' => time() + 60 ];
$payload = [ 'answers' => complete_answers(), 'productId' => 201, 'variationId' => 201, 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate returns 503 for unhealthy inventory source', 503 === $response->get_status() );
$GLOBALS['ts_test_health'] = null;

$payload = [ 'answers' => complete_answers(), 'productId' => 202, 'variationId' => 202, 'price' => 3500000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects a product that is available but ineligible for the submitted answers', 409 === $response->get_status() );

$payload = [ 'answers' => complete_answers(), 'productId' => '201', 'variationId' => 201, 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects string product identifiers', 400 === $response->get_status() );
$payload = [ 'answers' => complete_answers(), 'productId' => 201.9, 'variationId' => 201, 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects fractional product identifiers', 400 === $response->get_status() );
$payload = [ 'answers' => complete_answers(), 'productId' => 201, 'variationId' => '201', 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects string variation identifiers', 400 === $response->get_status() );
$payload = [ 'answers' => complete_answers(), 'productId' => 201, 'variationId' => 201, 'price' => '4000000' ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects string prices', 400 === $response->get_status() );

$GLOBALS['ts_wc_permalink_base'] = 'https://attacker.example/product/';
$payload = [ 'answers' => complete_answers(), 'productId' => 201, 'variationId' => 201, 'price' => 4000000 ];
$response = $validate->handle( request( $payload ) );
check( 'validate rejects purchase URLs outside the configured site origin', 503 === $response->get_status() );
unset( $GLOBALS['ts_wc_permalink_base'] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
