<?php
/**
 * Email sender-identity defaults.
 *
 * Run from the plugin root:
 *
 *   php tests/SenderDefaultsTest.php
 *
 * The Settings screen promises: blank Sender name → site title, blank
 * Sender email → WordPress admin email. Exercises
 * NotificationService::resolve_sender() + build_from_headers() with
 * stubbed get_bloginfo / get_option / is_email.
 *
 * Coverage:
 *   1. both set → used verbatim
 *   2. both blank → site title + admin email
 *   3. name only → name + admin email
 *   4. email only → site title + email
 *   5. invalid sender email → admin email
 *   6. invalid admin email too → no From header (wp_mail default)
 *   7. site title with HTML entities is decoded
 *   8. header injection characters stripped from the name
 *   9. blank site title → bare address, no "<>" wrapper
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

	$GLOBALS['__nf_site'] = [ 'name' => 'Acme &amp; Sons', 'admin_email' => 'admin@example.com' ];
	function get_bloginfo( $show = '' ) { return $GLOBALS['__nf_site']['name']; }
	function get_option( $key, $default = false ) { return $GLOBALS['__nf_site'][ $key ] ?? $default; }
	function wp_specialchars_decode( $s, $q = 0 ) { return html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ); }
	function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }

	$plugin_root = dirname( __DIR__ );
	$src = file_get_contents( $plugin_root . '/modules/notifications/NotificationService.php' );
	$bodies = [];
	foreach ( [ 'resolve_sender', 'build_from_headers' ] as $name ) {
		if ( ! preg_match( '/public static function ' . $name . '\(.+?\n\t\}/s', $src, $m ) ) {
			fwrite( STDERR, "Could not extract {$name}() source.\n" );
			exit( 2 );
		}
		$bodies[] = $m[0];
	}
	eval( 'namespace NeuralForms\\Tests { class Sender { ' . implode( "\n\n", $bodies ) . ' } }' );

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
	$from = fn( array $s ) => \NeuralForms\Tests\Sender::build_from_headers( $s );

	check( '1. both set → verbatim',
		$from( [ 'sender_name' => 'Sales', 'sender_email' => 'sales@acme.test' ] ), [ 'From: Sales <sales@acme.test>' ], $passed, $failed );

	check( '2. both blank → site title + admin email',
		$from( [ 'sender_name' => '', 'sender_email' => '' ] ), [ 'From: Acme & Sons <admin@example.com>' ], $passed, $failed );
	check( '2b. keys missing entirely → same defaults',
		$from( [] ), [ 'From: Acme & Sons <admin@example.com>' ], $passed, $failed );

	check( '3. name only → name + admin email',
		$from( [ 'sender_name' => 'Sales', 'sender_email' => '' ] ), [ 'From: Sales <admin@example.com>' ], $passed, $failed );

	check( '4. email only → site title + email',
		$from( [ 'sender_name' => '', 'sender_email' => 'sales@acme.test' ] ), [ 'From: Acme & Sons <sales@acme.test>' ], $passed, $failed );

	check( '5. invalid sender email → admin email',
		$from( [ 'sender_name' => 'Sales', 'sender_email' => 'not-an-email' ] ), [ 'From: Sales <admin@example.com>' ], $passed, $failed );

	$GLOBALS['__nf_site']['admin_email'] = 'broken';
	check( '6. invalid admin email too → no From header',
		$from( [ 'sender_name' => 'Sales', 'sender_email' => '' ] ), [], $passed, $failed );
	$GLOBALS['__nf_site']['admin_email'] = 'admin@example.com';

	check( '7. entities decoded in site title',
		\NeuralForms\Tests\Sender::resolve_sender( [] )['name'], 'Acme & Sons', $passed, $failed );

	check( '8. CR/LF/NULL and structural chars stripped from name',
		$from( [ 'sender_name' => "Evil\r\nBcc: x@y.z, <z> \"q\"\0", 'sender_email' => 'a@b.co' ] ), [ 'From: EvilBcc: x@y.z z q <a@b.co>' ], $passed, $failed );

	$GLOBALS['__nf_site']['name'] = '';
	check( '9. blank site title → bare address',
		$from( [] ), [ 'From: admin@example.com' ], $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
