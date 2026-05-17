<?php
/**
 * Repeater submission tests (Commit 4).
 *
 * Covers SubmissionValidator's new repeater branches (required +
 * type-check) and SubmitEndpoint::sanitize_repeater(), both driven
 * via reflection so we don't need a full REST request to exercise
 * the pipeline.
 *
 * Run from the plugin root:
 *
 *   php tests/RepeaterSubmissionTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op once the line above defines ABSPATH).
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'sanitize_text_field' ) )    { function sanitize_text_field( $s )    { return is_string( $s ) ? trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) ) : ''; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ){ function sanitize_textarea_field( $s ){ return is_string( $s ) ? strip_tags( $s ) : ''; } }
if ( ! function_exists( 'sanitize_email' ) )         { function sanitize_email( $s )         { return is_string( $s ) ? filter_var( $s, FILTER_SANITIZE_EMAIL ) : ''; } }
if ( ! function_exists( 'esc_url_raw' ) )            { function esc_url_raw( $s )            { return is_string( $s ) ? $s : ''; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return is_scalar( $s ) ? (string) $s : ''; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); } }
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) { return new \WP_Error( 'no_http', 'http disabled' ); }
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';

use NeuralForms\Forms\SubmissionValidator;

// ─────────────────────── Harness ───────────────────────

$cases = []; $passed = 0; $failed = 0;
function nf_test( string $name, callable $fn ): void { global $cases; $cases[] = [ 'name' => $name, 'fn' => $fn ]; }
function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) throw new \RuntimeException( $msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true ) );
}
function nf_assert_true( $cond, string $msg ): void { if ( ! $cond ) throw new \RuntimeException( $msg ); }
function nf_assert_match( string $pattern, $actual, string $msg ): void {
	if ( ! is_string( $actual ) || ! preg_match( $pattern, $actual ) ) {
		throw new \RuntimeException( $msg . ' — pattern: ' . $pattern . ' actual: ' . var_export( $actual, true ) );
	}
}

function nf_repeater_field( array $overrides = [] ): array {
	$base = [
		'id'       => 'team_members',
		'type'     => 'repeater',
		'label'    => 'Team Members',
		'required' => false,
		'settings' => [
			'min_items' => 1,
			'max_items' => 4,
			'columns'   => 2,
			'add_button_label'    => 'Add member',
			'remove_button_label' => 'Remove member',
		],
		'fields' => [
			[ 'id' => 'name', 'type' => 'text',  'label' => 'Name', 'required' => true ],
			[ 'id' => 'role', 'type' => 'radio', 'label' => 'Role',
				'options' => [ [ 'label' => 'Developer', 'value' => 'developer' ], [ 'label' => 'Designer', 'value' => 'designer' ] ] ],
		],
	];
	return array_replace_recursive( $base, $overrides );
}

function nf_sanitize_repeater( $value, array $field ): array {
	require_once dirname( __DIR__ ) . '/modules/rest/endpoints/SubmitEndpoint.php';
	$class    = new \ReflectionClass( \NeuralForms\Rest\Endpoints\SubmitEndpoint::class );
	$instance = $class->newInstanceWithoutConstructor();
	$method   = $class->getMethod( 'sanitize_repeater' );
	$method->setAccessible( true );
	return $method->invoke( $instance, $value, $field );
}

// ─────────────────────── Validation — required + min_items ───────────────────────

nf_test( 'required repeater rejects zero iterations', function () {
	$field = nf_repeater_field( [ 'required' => true ] );
	$res = SubmissionValidator::validate( [ $field ], [ 'team_members' => [] ] );
	nf_assert_true( is_wp_error( $res ), 'expected WP_Error for empty required repeater' );
	$fields = $res->get_error_data()['fields'] ?? [];
	nf_assert_same( 'required', $fields['team_members'][0]['code'] ?? null, 'code should be required' );
} );

nf_test( 'required repeater passes when min items satisfied', function () {
	$field = nf_repeater_field( [ 'required' => true ] );
	$res = SubmissionValidator::validate( [ $field ], [
		'team_members' => [ [ 'name' => 'Arif', 'role' => 'developer' ] ],
	] );
	nf_assert_same( true, $res, 'one valid iteration should satisfy required + min_items=1' );
} );

nf_test( 'optional repeater accepts zero iterations', function () {
	$field = nf_repeater_field( [ 'required' => false, 'settings' => [ 'min_items' => 0 ] ] );
	$res = SubmissionValidator::validate( [ $field ], [ 'team_members' => [] ] );
	nf_assert_same( true, $res, 'optional repeater with min=0 accepts empty' );
} );

// ─────────────────────── Validation — max_items + shape ───────────────────────

nf_test( 'too many iterations rejected with invalid_repeater', function () {
	$field = nf_repeater_field( [ 'settings' => [ 'max_items' => 2 ] ] );
	$rows  = [
		[ 'name' => 'Arif', 'role' => 'developer' ],
		[ 'name' => 'Sara', 'role' => 'designer' ],
		[ 'name' => 'Liam', 'role' => 'developer' ],
	];
	$res = SubmissionValidator::validate( [ $field ], [ 'team_members' => $rows ] );
	nf_assert_true( is_wp_error( $res ), 'over-max iterations must reject' );
	$fields = $res->get_error_data()['fields'] ?? [];
	nf_assert_same( 'invalid_repeater', $fields['team_members'][0]['code'] ?? null, 'code should be invalid_repeater' );
	nf_assert_match( '/at most 2 item/', $fields['team_members'][0]['message'] ?? '', 'message should call out 2 cap' );
} );

nf_test( 'non-array repeater value rejected', function () {
	$field = nf_repeater_field();
	$res = SubmissionValidator::validate( [ $field ], [ 'team_members' => 'not_an_array' ] );
	nf_assert_true( is_wp_error( $res ), 'scalar must reject' );
} );

nf_test( 'scalar inside iteration slot rejected', function () {
	$field = nf_repeater_field();
	$res = SubmissionValidator::validate( [ $field ], [ 'team_members' => [ 'tampered_scalar' ] ] );
	nf_assert_true( is_wp_error( $res ), 'tampered scalar row must reject' );
} );

// ─────────────────────── Validation — per-child required ───────────────────────

nf_test( 'per-iteration child required check fires', function () {
	$field = nf_repeater_field();
	$res = SubmissionValidator::validate( [ $field ], [
		'team_members' => [ [ 'role' => 'developer' ] ], // missing required `name`
	] );
	nf_assert_true( is_wp_error( $res ), 'missing required child in an iteration must fail' );
	$fields = $res->get_error_data()['fields'] ?? [];
	nf_assert_same( 'required', $fields['team_members'][0]['code'] ?? null, 'inner required propagates' );
	nf_assert_match( '/item #1/', $fields['team_members'][0]['message'] ?? '', 'message should locate item #1' );
} );

nf_test( 'per-iteration child format error propagates', function () {
	$field = nf_repeater_field( [
		'fields' => [
			[ 'id' => 'mail', 'type' => 'email', 'label' => 'Email', 'required' => true ],
		],
	] );
	$res = SubmissionValidator::validate( [ $field ], [
		'team_members' => [ [ 'mail' => 'not-an-email' ] ],
	] );
	nf_assert_true( is_wp_error( $res ), 'invalid email child must fail' );
	$fields = $res->get_error_data()['fields'] ?? [];
	nf_assert_same( 'invalid_email', $fields['team_members'][0]['code'] ?? null, 'inner type error propagates' );
} );

// ─────────────────────── Sanitiser ───────────────────────

nf_test( 'sanitize_repeater walks iterations and dispatches per child', function () {
	$field = nf_repeater_field();
	$out = nf_sanitize_repeater( [
		[ 'name' => '  Arif  ', 'role' => 'developer' ],
		[ 'name' => 'Sara',     'role' => 'designer'  ],
	], $field );
	nf_assert_same( 2, count( $out ), 'two iterations in, two out' );
	nf_assert_same( 'Arif', $out[0]['name'], 'text sanitiser trimmed whitespace' );
	nf_assert_same( 'developer', $out[0]['role'], 'radio value passthrough' );
	nf_assert_same( 'designer',  $out[1]['role'], 'second iteration intact' );
} );

nf_test( 'sanitize_repeater drops unknown child keys', function () {
	$field = nf_repeater_field();
	$out = nf_sanitize_repeater( [
		[ 'name' => 'Arif', 'role' => 'developer', 'evil_extra' => 'payload' ],
	], $field );
	nf_assert_true( ! array_key_exists( 'evil_extra', $out[0] ), 'unknown keys must be stripped' );
} );

nf_test( 'sanitize_repeater drops fully-empty optional iterations', function () {
	$field = nf_repeater_field();
	$out = nf_sanitize_repeater( [
		[ 'name' => 'Arif', 'role' => 'developer' ],
		[ 'name' => '',     'role' => ''          ], // all empty — drop
	], $field );
	nf_assert_same( 1, count( $out ), 'fully-empty iteration should be dropped' );
} );

nf_test( 'sanitize_repeater preserves iterations carrying an uploaded file', function () {
	$field = nf_repeater_field( [
		'fields' => [
			[ 'id' => 'name',   'type' => 'text', 'label' => 'Name' ],
			[ 'id' => 'resume', 'type' => 'file', 'label' => 'Resume' ],
		],
	] );
	$file_meta = [ 'attachment_id' => 42, 'url' => 'https://x/cv.pdf', 'filename' => 'cv.pdf', 'mime_type' => 'application/pdf', 'size' => 1234 ];
	$out = nf_sanitize_repeater( [
		[ 'name' => '', 'resume' => $file_meta ],
	], $field );
	nf_assert_same( 1, count( $out ), 'iteration with a file must NOT be dropped even though name is empty' );
	nf_assert_same( $file_meta, $out[0]['resume'] ?? null, 'file metadata passes through untouched' );
} );

nf_test( 'sanitize_repeater normalises non-sequential numeric keys', function () {
	// PHP json_decode produces numeric keys when the JS sent
	// { 0: {...}, 1: {...} } — make sure the sanitizer's
	// array_values() normalises gaps too.
	$field = nf_repeater_field();
	$out = nf_sanitize_repeater( [
		'7' => [ 'name' => 'Arif', 'role' => 'developer' ],
		'2' => [ 'name' => 'Sara', 'role' => 'designer'  ],
	], $field );
	nf_assert_same( 2, count( $out ), 'two iterations preserved regardless of key order' );
	nf_assert_same( [ 0, 1 ], array_keys( $out ), 'output keys are normalised 0..N' );
} );

nf_test( 'sanitize_repeater returns [] for non-array root', function () {
	$out = nf_sanitize_repeater( 'tampered', nf_repeater_field() );
	nf_assert_same( [], $out, 'tampered non-array → empty list' );
} );

// ─────────────────────── Run ───────────────────────

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
