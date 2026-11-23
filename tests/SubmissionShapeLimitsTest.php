<?php
/**
 * SubmissionValidator shape + size guards (1.1.3 security hardening).
 *
 * Run from the plugin root:
 *
 *   php tests/SubmissionShapeLimitsTest.php
 *
 * Coverage:
 *   1. array sent for a scalar field → invalid_value (was a TypeError in is_email() on PHP 8 → 500)
 *   2. object sent → invalid_value
 *   3. string over the per-type cap → too_long; at the cap → accepted
 *   4. per-type caps: text 1000, textarea 20000, email 254, url 2048
 *   5. filter can raise / disable the cap
 *   6. array fields accept arrays; > 200 items → too_many
 *   7. validate_single_field() applies the same guard (repeater children)
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
$GLOBALS['__nf_filters'] = [];
if ( ! function_exists( 'is_wp_error' ) )   { function is_wp_error( $t ): bool { return $t instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$a ) { return isset( $GLOBALS['__nf_filters'][ $tag ] ) ? ( $GLOBALS['__nf_filters'][ $tag ] )( $value, ...$a ) : $value; } }
if ( ! function_exists( '__' ) )            { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) )            { function _n( $a, $b, $n, $d = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'is_email' ) )      { function is_email( $s ) { return strlen( $s ) > 0 && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } } // strlen(): mirrors core's TypeError on arrays
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'sanitize_key' ) )  { function sanitize_key( $s ) { return is_string( $s ) ? strtolower( preg_replace( '/[^a-z0-9_]/i', '', $s ) ) : ''; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return false; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/core/Capabilities.php';
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';

use NeuralForms\Forms\SubmissionValidator;

$passed = 0; $failed = 0;
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
$f = fn( string $id, string $type, bool $req = false ) => [ 'id' => $id, 'type' => $type, 'label' => ucfirst( $id ), 'required' => $req ];
$code = function ( $schema, $data, $id ) {
	$r = SubmissionValidator::validate( $schema, $data );
	if ( is_wp_error( $r ) ) return $r->get_error_data()['fields'][ $id ][0]['code'] ?? 'wp_error';
	return is_array( $r ) ? ( $r[ $id ][0]['code'] ?? null ) : null;
};

// 1-2. wrong shape
check( '1. array for email → invalid_value (no TypeError)', $code( [ $f( 'email', 'email' ) ], [ 'email' => [ 'x' => 1 ] ], 'email' ), 'invalid_value', $passed, $failed );
check( '1b. array for text → invalid_value',               $code( [ $f( 'name', 'text' ) ], [ 'name' => [ 'a' ] ], 'name' ), 'invalid_value', $passed, $failed );
check( '1c. array for required phone → invalid_value',     $code( [ $f( 'phone', 'phone', true ) ], [ 'phone' => [ '1' ] ], 'phone' ), 'invalid_value', $passed, $failed );
check( '2. object → invalid_value',                        $code( [ $f( 'name', 'text' ) ], [ 'name' => (object) [ 'a' => 1 ] ], 'name' ), 'invalid_value', $passed, $failed );

// 3-4. length caps
check( '3a. text 1000 chars accepted',    $code( [ $f( 'name', 'text' ) ], [ 'name' => str_repeat( 'a', 1000 ) ], 'name' ), null, $passed, $failed );
check( '3b. text 1001 chars → too_long',  $code( [ $f( 'name', 'text' ) ], [ 'name' => str_repeat( 'a', 1001 ) ], 'name' ), 'too_long', $passed, $failed );
check( '4a. textarea 20000 accepted',     $code( [ $f( 'msg', 'textarea' ) ], [ 'msg' => str_repeat( 'b', 20000 ) ], 'msg' ), null, $passed, $failed );
check( '4b. textarea 20001 → too_long',   $code( [ $f( 'msg', 'textarea' ) ], [ 'msg' => str_repeat( 'b', 20001 ) ], 'msg' ), 'too_long', $passed, $failed );
check( '4c. email 255 → too_long',        $code( [ $f( 'email', 'email' ) ], [ 'email' => str_repeat( 'e', 250 ) . '@x.co' ], 'email' ), 'too_long', $passed, $failed );
check( '4d. url 2049 → too_long',         $code( [ $f( 'site', 'url' ) ], [ 'site' => 'https://x.co/' . str_repeat( 'p', 2040 ) ], 'site' ), 'too_long', $passed, $failed );
check( '4e. max_length_for defaults',     [ SubmissionValidator::max_length_for( 'text' ), SubmissionValidator::max_length_for( 'textarea' ), SubmissionValidator::max_length_for( 'email' ), SubmissionValidator::max_length_for( 'url' ) ], [ 1000, 20000, 254, 2048 ], $passed, $failed );

// 5. filter
$GLOBALS['__nf_filters']['neural_forms_max_field_length'] = fn( $max, $type ) => $type === 'text' ? 5000 : $max;
check( '5a. filter raises text cap',      $code( [ $f( 'name', 'text' ) ], [ 'name' => str_repeat( 'a', 4000 ) ], 'name' ), null, $passed, $failed );
$GLOBALS['__nf_filters']['neural_forms_max_field_length'] = fn( $max ) => 0;
check( '5b. filter 0 disables cap',       $code( [ $f( 'msg', 'textarea' ) ], [ 'msg' => str_repeat( 'b', 30000 ) ], 'msg' ), null, $passed, $failed );
unset( $GLOBALS['__nf_filters']['neural_forms_max_field_length'] );

// 6. array fields
$cbs = [ 'id' => 'tags', 'type' => 'checkboxes', 'label' => 'Tags', 'options' => [ [ 'label' => 'A', 'value' => 'a' ] ] ];
check( '6a. checkboxes array accepted',   $code( [ $cbs ], [ 'tags' => [ 'a' ] ], 'tags' ), null, $passed, $failed );
check( '6b. 201 items → too_many',        $code( [ $cbs ], [ 'tags' => array_fill( 0, 201, 'a' ) ], 'tags' ), 'too_many', $passed, $failed );
check( '6c. 200 items passes the shape guard', $code( [ $cbs ], [ 'tags' => array_fill( 0, 200, 'a' ) ], 'tags' ), null, $passed, $failed );

// 7. single-field path
check( '7. validate_single_field guards shape', SubmissionValidator::validate_single_field( $f( 'name', 'text' ), [ 'x' ] )['code'] ?? null, 'invalid_value', $passed, $failed );
check( '7b. validate_single_field too_long',    SubmissionValidator::validate_single_field( $f( 'name', 'text' ), str_repeat( 'a', 1001 ) )['code'] ?? null, 'too_long', $passed, $failed );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
