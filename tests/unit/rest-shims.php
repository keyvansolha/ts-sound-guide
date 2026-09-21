<?php
/**
 * WordPress REST and transient shims for controller boundary tests.
 */

declare(strict_types=1);

final class WP_REST_Request {
	private string $body;
	private string $content_type;

	public function __construct( string $body, string $content_type = 'application/json' ) {
		$this->body         = $body;
		$this->content_type = $content_type;
	}

	public function get_body(): string { return $this->body; }
	public function get_header( string $name ): string {
		return 'content-type' === strtolower( $name ) ? $this->content_type : '';
	}
	/** @return mixed */
	public function get_json_params() {
		if ( ! str_contains( strtolower( $this->content_type ), 'application/json' ) ) {
			return null;
		}
		return json_decode( $this->body, true );
	}
}

final class WP_REST_Response {
	/** @var mixed */
	private $data;
	private int $status;
	/** @var array<string, string> */
	private array $headers = [];

	/** @param mixed $data Response data. */
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	/** @return mixed */
	public function get_data() { return $this->data; }
	public function get_status(): int { return $this->status; }
	/** @return array<string, string> */
	public function get_headers(): array { return $this->headers; }
}

$GLOBALS['ts_test_health']     = null;
$GLOBALS['ts_test_transients'] = [];
$GLOBALS['ts_test_routes']     = [];

function get_option( $name, $default = false ) {
	return $GLOBALS['ts_test_option'][ $name ] ?? $default;
}

function apply_filters( string $hook, $value ) {
	return 'ts_sound_inventory_health' === $hook ? $GLOBALS['ts_test_health'] : $value;
}

function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['ts_test_routes'][ $namespace . $route ] = $args;
}

function __return_true(): bool { return true; }

function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
	return substr( str_repeat( 'Ab9x', $length ), 0, $length );
}

function set_transient( string $key, $value, int $expiration ): bool {
	$GLOBALS['ts_test_transients'][ $key ] = [ 'value' => $value, 'expiration' => $expiration ];
	return true;
}

function add_query_arg( array $args, string $url ): string {
	$separator = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $separator . http_build_query( $args );
}

function home_url( string $path = '' ): string {
	return 'https://store.example' . $path;
}

function wp_parse_url( string $url ) {
	return parse_url( $url );
}
