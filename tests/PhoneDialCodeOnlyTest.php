<?php
/**
 * Phone field: dial-code-only value counts as empty.
 *
 * Run from the plugin root:
 *
 *   php tests/PhoneDialCodeOnlyTest.php
 *
 * Coverage:
 *   1. normalize_phone(): "+1", "+44", "+1 ", "+1234" → ''; real numbers unchanged
 *   2. optional phone "+1 " passes validation (no errors)
 *   3. required phone "+1" → `required` error, not `invalid_phone`
 *   4. real number still validated (7-15 digits)
 *   5. "+1234567" (7 digits) is a number, not a dial code
 *   6. form-submit.js mirrors the rule
 *   7. SubmitEndpoint sanitizer stores '' for a dial-code-only phone (source guard)
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
if ( ! function_exists( 'is_wp_error' ) )   { function is_wp_error( $t ): bool { return $t instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$a ) { return $value; } }
if ( ! function_exists( '__' ) )            { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) )            { function _n( $a, $b, $n, $d = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'is_email' ) )      { function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) ) : ''; } }
if ( ! function_exists( 'sanitize_key' ) )  { function sanitize_key( $s ) { return is_string( $s ) ? strtolower( preg_replace( '/[^a-z0-9_]/i', '', $s ) ) : ''; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return false; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/core/Capabilities.php';
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';

use NeuralForms\Forms\SubmissionValidator;

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

// 1. normalize_phone
foreach ( [ '+1' => '', '+44' => '', '+1 ' => '', '+1234' => '', '+1 5551234567' => '+1 5551234567', '5551234567' => '5551234567', '+12345' => '+12345', '' => '' ] as $in => $out ) {
	check( "1. normalize_phone('{$in}')", SubmissionValidator::normalize_phone( (string) $in ), $out, $passed, $failed );
}

$optional = [ [ 'id' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'required' => false ] ];
$required = [ [ 'id' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'required' => true ] ];
$errs = fn( $r ) => is_wp_error( $r ) ? ( $r->get_error_data()['fields'] ?? [] ) : ( is_array( $r ) ? $r : [] );
$code = fn( $r ) => $errs( $r )['phone'][0]['code'] ?? null;

// 2. optional + dial code only → valid
check( '2. optional phone "+1 " → no errors',   $code( SubmissionValidator::validate( $optional, [ 'phone' => '+1 ' ] ) ), null, $passed, $failed );
check( '2b. optional phone "+44" → no errors',  $code( SubmissionValidator::validate( $optional, [ 'phone' => '+44' ] ) ), null, $passed, $failed );
check( '2c. optional phone "" → no errors',     $code( SubmissionValidator::validate( $optional, [ 'phone' => '' ] ) ), null, $passed, $failed );

// 3. required + dial code only → required (not invalid_phone)
check( '3. required phone "+1" → required',     $code( SubmissionValidator::validate( $required, [ 'phone' => '+1' ] ) ), 'required', $passed, $failed );

// 4. real numbers still validated
check( '4. "+1 5551234567" valid',              $code( SubmissionValidator::validate( $required, [ 'phone' => '+1 5551234567' ] ) ), null, $passed, $failed );
check( '4b. "+1 555" still invalid_phone',       $code( SubmissionValidator::validate( $required, [ 'phone' => '+1 555' ] ) ), 'invalid_phone', $passed, $failed );
check( '4c. "abc" still invalid_phone',          $code( SubmissionValidator::validate( $optional, [ 'phone' => 'abc' ] ) ), 'invalid_phone', $passed, $failed );

// 5. seven digits after + is a number, not a dial code
check( '5. "+1234567" treated as a number',     $code( SubmissionValidator::validate( $optional, [ 'phone' => '+1234567' ] ) ), null, $passed, $failed );

// 6/7. source guards
$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( '6. form-submit.js treats dial-code-only tel as empty', strpos( $js, "=== 'tel' && /^\\+\\d{1,4}\$/.test( trimmed )" ) !== false, true, $passed, $failed );
$ep = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
check( '7. sanitizer stores normalized phone', (bool) preg_match( "/case 'phone':\\s*case 'tel':.*?SubmissionValidator::normalize_phone\\( sanitize_text_field/s", $ep ), true, $passed, $failed );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
