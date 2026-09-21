<?php
/**
 * Attribution boundary tests for product-page capture and order matching.
 *
 * Usage: php tests/unit/attribution.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MINUTE_IN_SECONDS', 60 );
require __DIR__ . '/wp-shims.php';
require __DIR__ . '/rest-shims.php';
require __DIR__ . '/../../ts-sound-guide.php';

use TSSoundGuide\Attribution;

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

final class TS_Test_Session {
	/** @var array<string, mixed> */
	public array $values = [];
	public bool $cookie = false;
	public function set( string $key, $value ): void { $this->values[ $key ] = $value; }
	public function get( string $key ) { return $this->values[ $key ] ?? null; }
	public function set_customer_session_cookie( bool $set ): void { $this->cookie = $set; }
}

final class TS_Test_WC {
	public TS_Test_Session $session;
	public function __construct() { $this->session = new TS_Test_Session(); }
}

final class TS_Test_Item {
	public function __construct( private int $product_id, private int $variation_id ) {}
	public function get_product_id(): int { return $this->product_id; }
	public function get_variation_id(): int { return $this->variation_id; }
}

final class TS_Test_Order {
	/** @var array<string, mixed> */
	public array $meta = [];
	/** @param array<int, TS_Test_Item> $items */
	public function __construct( private array $items ) {}
	/** @return array<int, TS_Test_Item> */
	public function get_items(): array { return $this->items; }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
}

$GLOBALS['ts_test_wc'] = new TS_Test_WC();
$GLOBALS['ts_test_is_product'] = true;
$GLOBALS['ts_test_queried_id'] = 201;
function WC(): TS_Test_WC { return $GLOBALS['ts_test_wc']; }
function is_product(): bool { return $GLOBALS['ts_test_is_product']; }
function get_queried_object_id(): int { return $GLOBALS['ts_test_queried_id']; }
function sanitize_text_field( $value ): string { return preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ); }
function wp_unslash( $value ) { return $value; }
function get_transient( string $key ) { return $GLOBALS['ts_test_transients'][ $key ]['value'] ?? false; }

$service = new Attribution();
$answers = [ 'use' => 'music', 'pain' => 'balanced', 'budget' => 6000000 ];
$token   = $service->issue_token( 201, 201, $answers );
check( 'issued token has the public 32-character format', 1 === preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) );

$_GET['ts_sound_ref'] = $token;
$service->capture_token();
check( 'matching product page captures attribution in the WC session', 201 === ( WC()->session->get( 'ts_sound_attribution' )['product_id'] ?? null ) && WC()->session->cookie );

$order = new TS_Test_Order( [ new TS_Test_Item( 201, 0 ) ] );
$service->attach_order_attribution( $order );
check( 'simple product order item receives exact attribution', 201 === ( $order->meta['_ts_sound_guide']['product_id'] ?? null ) );

WC()->session->set( 'ts_sound_attribution', [
	'product_id' => 301, 'variation_id' => 3012, 'at' => time(),
] );
$order = new TS_Test_Order( [ new TS_Test_Item( 301, 3013 ) ] );
$service->attach_order_attribution( $order );
check( 'different variation never receives attribution', ! isset( $order->meta['_ts_sound_guide'] ) );

$order = new TS_Test_Order( [ new TS_Test_Item( 301, 3012 ) ] );
$service->attach_order_attribution( $order );
check( 'matching variable-product variation receives attribution', 301 === ( $order->meta['_ts_sound_guide']['product_id'] ?? null ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
