<?php
/**
 * Bootstrap metadata contract consumed by WordPress plugin discovery.
 *
 * Usage: php tests/unit/bootstrap.php
 */

declare(strict_types=1);

$source = (string) file_get_contents( __DIR__ . '/../../ts-sound-guide.php', false, null, 0, 8192 );

/** Match WordPress' line-oriented plugin-header convention. */
function plugin_header( string $source, string $field ): string {
	$pattern = '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi';
	return preg_match( $pattern, $source, $match ) ? trim( $match[1] ) : '';
}

$expected = [
	'Plugin Name'      => 'TehranSpeaker Sound Guide',
	'Version'          => '3.1.0',
	'Requires PHP'     => '8.0',
	'Requires Plugins' => 'woocommerce',
	'Text Domain'      => 'ts-sound-guide',
];

$pass = 0;
$fail = 0;
foreach ( $expected as $field => $value ) {
	if ( $value === plugin_header( $source, $field ) ) {
		$pass++;
		echo "ok  WordPress discovers {$field}\n";
	} else {
		$fail++;
		echo "FAIL WordPress discovers {$field}\n";
	}
}

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
