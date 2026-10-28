<?php
/**
 * BlockHandler::css_text() / css_url() — block attributes used as CSS values.
 *
 * Run from the plugin root:
 *
 *   php tests/BlockCssSanitizerTest.php
 *
 * Anyone who can save a post with the block controls these values; they
 * land in a <style> served to every visitor. Both helpers must drop any
 * value that could terminate a declaration / rule / url() token.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { $s = strip_tags( (string) $s ); return trim( preg_replace( '/[\r\n\t ]+/', ' ', $s ) ); } }
if ( ! function_exists( 'esc_url' ) ) {
	// Mirrors core's allowed-character set closely enough for these cases.
	function esc_url( $u ) { $u = (string) $u; $u = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $u ); return ( $u === '' || ! preg_match( '/^https?:\/\//i', $u ) ) ? '' : $u; }
}

$plugin_root = dirname( __DIR__ );
$src = file_get_contents( $plugin_root . '/modules/frontend/BlockHandler.php' );
$bodies = [];
foreach ( [ 'css_text', 'css_url' ] as $name ) {
	if ( ! preg_match( '/private static function ' . $name . '\(.+?\n\t\}/s', $src, $m ) ) { fwrite( STDERR, "Could not extract {$name}()\n" ); exit( 2 ); }
	$bodies[] = preg_replace( '/^\s*private /', 'public ', $m[0] );
}
eval( 'namespace NeuralForms\\Tests { class Css { ' . implode( "\n", $bodies ) . ' } }' );
$t = fn( $v ) => \NeuralForms\Tests\Css::css_text( $v );
$u = fn( $v ) => \NeuralForms\Tests\Css::css_url( $v );

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	if ( $ok ) $passed++; else $failed++;
	$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
	echo $indicator . '  ' . $label . "\n";
	if ( ! $ok ) { echo "      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n"; }
}

// Legit values pass unchanged
foreach ( [ '16px', '1.5rem', '-4px', '100%', '#ff0000', 'rgba(0,0,0,.5)', 'linear-gradient(90deg,#fff 0%,#000 100%)', 'var(--wp--preset--spacing--50)', '"Open Sans", sans-serif', 'cubic-bezier(0.4,0,0.2,1)', '0 2px 8px rgba(0,0,0,0.2)', '700', 'ease-in-out' ] as $v ) {
	check( "ok: {$v}", $t( $v ), $v, $passed, $failed );
}
// Injection attempts are dropped entirely
foreach ( [ 'x}body{display:none}.nf{', '16px;position:fixed', 'red}</style><script>alert(1)</script>', 'expression(alert(1))', 'url(https://evil/x.png)', '@import "https://evil/x.css"', 'a/*}*/b', 'x\\7D body{', "16px\n}body{" ] as $v ) {
	check( 'dropped: ' . addcslashes( $v, "\n" ), $t( $v ), '', $passed, $failed );
}
check( 'empty → empty', $t( '' ), '', $passed, $failed );
check( 'non-string coerced', $t( 20 ), '20', $passed, $failed );

// css_url
check( 'url ok',                     $u( 'https://x.co/img.png' ), 'https://x.co/img.png', $passed, $failed );
check( 'url with query ok',          $u( 'https://x.co/i.png?v=2&s=1' ), 'https://x.co/i.png?v=2&s=1', $passed, $failed );
check( 'url with ) dropped',         $u( 'https://x.co/a);}' ), '', $passed, $failed );
check( 'url with ; dropped',         $u( 'https://x.co/a;color:red' ), '', $passed, $failed );
check( 'url: space removed by esc_url (core strips it), no breakout', $u( 'https://x.co/a b.png' ), 'https://x.co/ab.png', $passed, $failed );
check( 'url with quote dropped',     $u( "https://x.co/a'" ), '', $passed, $failed );
check( 'javascript: dropped',        $u( 'javascript:alert(1)' ), '', $passed, $failed );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
