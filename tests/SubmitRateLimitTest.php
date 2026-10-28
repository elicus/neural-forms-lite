<?php
/**
 * Lite submission rate-limit + client-IP resolution tests.
 *
 * Run from the plugin root:
 *
 *   php tests/SubmitRateLimitTest.php
 *
 * Exercises SubmitEndpoint::resolve_ip(), ::ip_in_list() and
 * ::default_rate_limit() in isolation. The three methods are extracted
 * from the source file and re-emitted as public on a stub class so no
 * WordPress bootstrap is needed; transients are an in-memory array.
 *
 * Coverage:
 *   1. no headers → REMOTE_ADDR
 *   2. X-Forwarded-For ignored when no trusted proxies are declared
 *   3. junk X-Forwarded-For ("x") no longer blanks the IP
 *   4. junk REMOTE_ADDR → empty string
 *   5. trusted proxy + X-Forwarded-For → client hop
 *   6. right-most untrusted hop wins (not left-most)
 *   7. malformed hop aborts the walk → REMOTE_ADDR
 *   8. CF-Connecting-IP honoured only behind a trusted proxy
 *   9. CIDR matching, IPv4 and IPv6, exact and ranged, family mismatch
 *  10. limiter: 10 allowed, 11th blocked
 *  11. limiter: buckets are per form
 *  12. limiter: empty IP is still limited (shared bucket)
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) {
			global $__nf_filters;
			if ( ! empty( $__nf_filters[ $hook ] ) ) {
				foreach ( $__nf_filters[ $hook ] as $cb ) {
					$value = $cb( $value, ...$args );
				}
			}
			return $value;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
			global $__nf_filters;
			$__nf_filters[ $hook ][] = $cb;
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			global $__nf_transients;
			return $__nf_transients[ $key ] ?? false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			global $__nf_transients;
			$__nf_transients[ $key ] = $value;
			return true;
		}
	}

	$GLOBALS['__nf_filters']    = [];
	$GLOBALS['__nf_transients'] = [];

	$plugin_root = dirname( __DIR__ );
	$method_src  = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );

	$bodies = [];
	foreach ( [ 'resolve_ip', 'ip_in_list', 'default_rate_limit' ] as $name ) {
		if ( ! preg_match( '/private (?:static )?function ' . $name . '\(.+?\n\t\}/s', $method_src, $m ) ) {
			fwrite( STDERR, "Could not extract {$name}() source.\n" );
			exit( 2 );
		}
		$bodies[] = preg_replace( '/^\s*private /', 'public ', $m[0] );
	}

	eval( '
		namespace NeuralForms\\Tests {
			class SubmitEndpointIp {
				private const RATE_LIMIT_TRANSIENT_PREFIX = "neural_forms_lite_rate_limit_";
				' . implode( "\n\n", $bodies ) . '
			}
		}
	' );

	$ep = new \NeuralForms\Tests\SubmitEndpointIp();

	$passed = 0;
	$failed = 0;

	function check( string $label, $actual, $expected, int &$passed, int &$failed ): void {
		$ok = $actual === $expected;
		if ( $ok ) $passed++; else $failed++;
		$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
		echo $indicator . '  ' . $label . "\n";
		if ( ! $ok ) {
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	function with_server( array $server, callable $fn ) {
		$backup  = $_SERVER;
		$_SERVER = $server;
		try {
			return $fn();
		} finally {
			$_SERVER = $backup;
		}
	}

	function set_trusted( array $list ): void {
		$GLOBALS['__nf_filters']['neural_forms_trusted_proxies'] = [ function () use ( $list ) { return $list; } ];
	}

	// ─── 1-4: untrusted default ─────────────────────────────────

	set_trusted( [] );

	check( '1. no headers → REMOTE_ADDR',
		with_server( [ 'REMOTE_ADDR' => '203.0.113.9' ], fn() => $ep->resolve_ip() ),
		'203.0.113.9', $passed, $failed );

	check( '2. X-Forwarded-For ignored when no trusted proxies',
		with_server( [ 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'203.0.113.9', $passed, $failed );

	check( '3. junk X-Forwarded-For does not blank the IP',
		with_server( [ 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => 'x' ], fn() => $ep->resolve_ip() ),
		'203.0.113.9', $passed, $failed );

	check( '3b. CF-Connecting-IP ignored when no trusted proxies',
		with_server( [ 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'203.0.113.9', $passed, $failed );

	check( '4. junk REMOTE_ADDR → empty string',
		with_server( [ 'REMOTE_ADDR' => 'not-an-ip' ], fn() => $ep->resolve_ip() ),
		'', $passed, $failed );

	check( '4b. missing REMOTE_ADDR → empty string',
		with_server( [], fn() => $ep->resolve_ip() ),
		'', $passed, $failed );

	// ─── 5-8: behind a trusted proxy ────────────────────────────

	set_trusted( [ '10.0.0.0/8', '203.0.113.9' ] );

	check( '5. trusted proxy + single X-Forwarded-For hop → client',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'1.2.3.4', $passed, $failed );

	check( '5b. exact-match trusted proxy also honours the header',
		with_server( [ 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'1.2.3.4', $passed, $failed );

	check( '6. right-most untrusted hop wins (client-prepended value ignored)',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 1.2.3.4, 10.0.0.7' ], fn() => $ep->resolve_ip() ),
		'1.2.3.4', $passed, $failed );

	check( '6b. every hop trusted → REMOTE_ADDR',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.2' ], fn() => $ep->resolve_ip() ),
		'10.0.0.5', $passed, $failed );

	check( '7. malformed right-most hop aborts walk → REMOTE_ADDR',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, x' ], fn() => $ep->resolve_ip() ),
		'10.0.0.5', $passed, $failed );

	check( '7b. malformed left-most hop is never reached',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'x, 1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'1.2.3.4', $passed, $failed );

	check( '8. CF-Connecting-IP wins behind a trusted proxy',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '5.5.5.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'5.5.5.5', $passed, $failed );

	check( '8b. invalid CF-Connecting-IP falls through to X-Forwarded-For',
		with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => 'nope', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], fn() => $ep->resolve_ip() ),
		'1.2.3.4', $passed, $failed );

	check( '8c. IPv6 trusted range + IPv6 client',
		(function () use ( $ep ) {
			set_trusted( [ '2400:cb00::/32' ] );
			return with_server( [ 'REMOTE_ADDR' => '2400:cb00:1::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::7' ], fn() => $ep->resolve_ip() );
		})(),
		'2001:db8::7', $passed, $failed );

	check( '8d. non-array filter value is ignored',
		(function () use ( $ep ) {
			$GLOBALS['__nf_filters']['neural_forms_trusted_proxies'] = [ fn() => '10.0.0.0/8' ];
			return with_server( [ 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ], fn() => $ep->resolve_ip() );
		})(),
		'10.0.0.5', $passed, $failed );

	// ─── 9: ip_in_list ──────────────────────────────────────────

	check( '9a. exact IPv4 match',            $ep->ip_in_list( '192.0.2.1', [ '192.0.2.1' ] ),          true,  $passed, $failed );
	check( '9b. IPv4 /24 inside',             $ep->ip_in_list( '192.0.2.200', [ '192.0.2.0/24' ] ),     true,  $passed, $failed );
	check( '9c. IPv4 /24 outside',            $ep->ip_in_list( '192.0.3.1', [ '192.0.2.0/24' ] ),       false, $passed, $failed );
	check( '9d. IPv4 /25 boundary inside',    $ep->ip_in_list( '192.0.2.127', [ '192.0.2.0/25' ] ),     true,  $passed, $failed );
	check( '9e. IPv4 /25 boundary outside',   $ep->ip_in_list( '192.0.2.128', [ '192.0.2.0/25' ] ),     false, $passed, $failed );
	check( '9f. IPv4 /0 matches everything',  $ep->ip_in_list( '8.8.8.8', [ '0.0.0.0/0' ] ),            true,  $passed, $failed );
	check( '9g. IPv6 /32 inside',             $ep->ip_in_list( '2400:cb00:ffff::1', [ '2400:cb00::/32' ] ), true, $passed, $failed );
	check( '9h. IPv6 /32 outside',            $ep->ip_in_list( '2400:cb01::1', [ '2400:cb00::/32' ] ),  false, $passed, $failed );
	check( '9i. family mismatch is not a match', $ep->ip_in_list( '192.0.2.1', [ '::/0' ] ),           false, $passed, $failed );
	check( '9j. invalid entries are skipped',  $ep->ip_in_list( '192.0.2.1', [ 'garbage', '1.2.3.4/99', '', '192.0.2.1' ] ), true, $passed, $failed );
	check( '9k. out-of-range prefix is skipped', $ep->ip_in_list( '192.0.2.1', [ '192.0.2.0/33' ] ),    false, $passed, $failed );
	check( '9l. empty list',                   $ep->ip_in_list( '192.0.2.1', [] ),                      false, $passed, $failed );

	// ─── 10-12: default_rate_limit ──────────────────────────────

	$GLOBALS['__nf_transients'] = [];
	$blocked_at = null;
	for ( $i = 1; $i <= 11; $i++ ) {
		if ( $ep->default_rate_limit( '198.51.100.1', 1 ) ) {
			$blocked_at = $i;
			break;
		}
	}
	check( '10. 10 attempts allowed, 11th blocked', $blocked_at, 11, $passed, $failed );
	check( '10b. still blocked on the 12th', $ep->default_rate_limit( '198.51.100.1', 1 ), true, $passed, $failed );

	check( '11. same IP, different form → separate bucket', $ep->default_rate_limit( '198.51.100.1', 2 ), false, $passed, $failed );
	check( '11b. different IP, same form → separate bucket', $ep->default_rate_limit( '198.51.100.2', 1 ), false, $passed, $failed );

	$GLOBALS['__nf_transients'] = [];
	$blocked_at = null;
	for ( $i = 1; $i <= 11; $i++ ) {
		if ( $ep->default_rate_limit( '', 1 ) ) {
			$blocked_at = $i;
			break;
		}
	}
	check( '12. empty IP is still limited (shared bucket)', $blocked_at, 11, $passed, $failed );

	check( '12b. transient key uses the plugin prefix',
		strpos( (string) array_key_first( $GLOBALS['__nf_transients'] ), 'neural_forms_lite_rate_limit_' ), 0, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
