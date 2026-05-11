<?php
/**
 * Matrix field submission validation tests (Phase 4).
 *
 * Covers SubmissionValidator + the sanitize_matrix() normalizer reached
 * through reflection on SubmitEndpoint. Capability-gated stripping is
 * exercised separately by stubbing the apply_filters() shim for the
 * `neural_forms_can_field_matrix` filter.
 *
 * Run from the plugin root:
 *
 *   php tests/MatrixValidationTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );

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

// Filter shim: $GLOBALS['__nf_filters'][ $tag ] = callable. The matrix
// capability check apply_filters('neural_forms_can_field_matrix', false)
// is the only one we model — its return value flips the gate. Returns
// the default if no filter is registered, mirroring WP behaviour.
$GLOBALS['__nf_filters'] = [];
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( isset( $GLOBALS['__nf_filters'][ $tag ] ) ) {
			$cb = $GLOBALS['__nf_filters'][ $tag ];
			return $cb( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) )     { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) )  { function esc_html__( $s, $domain = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr__' ) )  { function esc_attr__( $s, $domain = null ) { return esc_attr( $s ); } }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) ) : ''; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return is_string( $s ) ? strip_tags( $s ) : ''; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $s ) { return is_string( $s ) ? filter_var( $s, FILTER_SANITIZE_EMAIL ) : ''; }
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_-]/', '-', (string) $s ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ) { return is_string( $s ) ? strtolower( preg_replace( '/[^a-z0-9_]/i', '', $s ) ) : ''; }
}
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return false; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/core/Capabilities.php';
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\SubmissionValidator;

// ---------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------

$cases  = [];
$passed = 0;
$failed = 0;

function nf_test( string $name, callable $fn ): void {
	global $cases;
	$cases[] = [ 'name' => $name, 'fn' => $fn ];
}

function nf_assert_true( $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new \RuntimeException( $msg );
	}
}

function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			$msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true )
		);
	}
}

/**
 * Reusable radio matrix schema.
 */
function nf_matrix_radio_field( bool $required = true ): array {
	return [
		'id'       => 'satisfaction',
		'type'     => 'matrix',
		'label'    => 'Satisfaction',
		'required' => $required,
		'settings' => [
			'selection_type' => 'radio',
			'rows' => [
				[ 'label' => 'Purchase', 'value' => 'purchase' ],
				[ 'label' => 'Service',  'value' => 'service'  ],
			],
			'columns' => [
				[ 'label' => 'Very Satisfied', 'value' => 'very_satisfied', 'score' => 5 ],
				[ 'label' => 'Satisfied',      'value' => 'satisfied',      'score' => 3 ],
				[ 'label' => 'Unhappy',        'value' => 'unhappy',        'score' => 1 ],
			],
		],
	];
}

/**
 * Reusable checkbox matrix schema.
 */
function nf_matrix_checkbox_field( bool $required = true ): array {
	return [
		'id'       => 'features',
		'type'     => 'matrix',
		'label'    => 'Features',
		'required' => $required,
		'settings' => [
			'selection_type' => 'checkbox',
			'rows' => [
				[ 'label' => 'Purchase', 'value' => 'purchase' ],
				[ 'label' => 'Service',  'value' => 'service'  ],
			],
			'columns' => [
				[ 'label' => 'Email', 'value' => 'email', 'score' => 1 ],
				[ 'label' => 'Phone', 'value' => 'phone', 'score' => 1 ],
				[ 'label' => 'Chat',  'value' => 'chat',  'score' => 1 ],
			],
		],
	];
}

/**
 * Build the SubmitEndpoint sanitize_matrix() reflection helper.
 * The class autoloader is not booted in this standalone test, so we
 * require the file directly and reflect into the private method.
 */
function nf_load_sanitizer(): \ReflectionMethod {
	static $method = null;
	if ( $method !== null ) {
		return $method;
	}
	require_once dirname( __DIR__ ) . '/modules/rest/endpoints/SubmitEndpoint.php';
	$class  = new \ReflectionClass( \NeuralForms\Rest\Endpoints\SubmitEndpoint::class );
	$method = $class->getMethod( 'sanitize_matrix' );
	$method->setAccessible( true );
	return $method;
}

function nf_sanitize_matrix( $value, array $field ): array {
	$method = nf_load_sanitizer();
	$class  = new \ReflectionClass( \NeuralForms\Rest\Endpoints\SubmitEndpoint::class );
	// Avoid invoking the constructor (it registers REST routes); use
	// newInstanceWithoutConstructor so we can call the private method
	// on a bare instance.
	$instance = $class->newInstanceWithoutConstructor();
	return $method->invoke( $instance, $value, $field );
}

// ---------------------------------------------------------------------
// Tests — validator
// ---------------------------------------------------------------------

nf_test( 'radio_required_fails_when_any_row_missing', function () {
	$schema = [ nf_matrix_radio_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'satisfaction' => [ 'purchase' => 'very_satisfied' ], // service missing
	] );
	nf_assert_true( is_wp_error( $result ), 'expected WP_Error for partial radio submission' );
	$fields = $result->get_error_data()['fields'] ?? [];
	nf_assert_true( isset( $fields['satisfaction'][0]['code'] ), 'expected error under field id' );
	nf_assert_same( 'required', $fields['satisfaction'][0]['code'], 'code should be required' );
} );

nf_test( 'radio_required_passes_when_all_rows_have_valid_column', function () {
	$schema = [ nf_matrix_radio_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'satisfaction' => [
			'purchase' => 'very_satisfied',
			'service'  => 'satisfied',
		],
	] );
	nf_assert_same( true, $result, 'expected pass for fully-filled radio matrix' );
} );

nf_test( 'radio_optional_allows_empty_submission', function () {
	$schema = [ nf_matrix_radio_field( false ) ];
	$result = SubmissionValidator::validate( $schema, [] );
	nf_assert_same( true, $result, 'expected pass for empty optional radio matrix' );
} );

nf_test( 'radio_rejects_array_value_for_row', function () {
	$schema = [ nf_matrix_radio_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'satisfaction' => [
			'purchase' => [ 'very_satisfied' ], // array under radio
			'service'  => 'satisfied',
		],
	] );
	nf_assert_true( is_wp_error( $result ), 'expected WP_Error for array-under-radio' );
	$fields = $result->get_error_data()['fields'] ?? [];
	nf_assert_same( 'invalid_matrix', $fields['satisfaction'][0]['code'], 'code should be invalid_matrix' );
} );

nf_test( 'radio_rejects_invalid_column', function () {
	$schema = [ nf_matrix_radio_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'satisfaction' => [
			'purchase' => 'tampered',
			'service'  => 'satisfied',
		],
	] );
	nf_assert_true( is_wp_error( $result ), 'expected WP_Error for tampered column' );
	$fields = $result->get_error_data()['fields'] ?? [];
	nf_assert_same( 'invalid_option', $fields['satisfaction'][0]['code'], 'code should be invalid_option' );
} );

nf_test( 'checkbox_required_fails_when_any_row_missing', function () {
	$schema = [ nf_matrix_checkbox_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'features' => [ 'purchase' => [ 'email' ] ], // service missing
	] );
	nf_assert_true( is_wp_error( $result ), 'expected WP_Error for partial checkbox submission' );
	$fields = $result->get_error_data()['fields'] ?? [];
	nf_assert_same( 'required', $fields['features'][0]['code'], 'code should be required' );
} );

nf_test( 'checkbox_required_passes_when_each_row_has_one_or_more', function () {
	$schema = [ nf_matrix_checkbox_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'features' => [
			'purchase' => [ 'email', 'phone' ],
			'service'  => [ 'chat' ],
		],
	] );
	nf_assert_same( true, $result, 'expected pass for fully-filled checkbox matrix' );
} );

nf_test( 'checkbox_optional_allows_empty_submission', function () {
	$schema = [ nf_matrix_checkbox_field( false ) ];
	$result = SubmissionValidator::validate( $schema, [] );
	nf_assert_same( true, $result, 'expected pass for empty optional checkbox matrix' );
} );

nf_test( 'checkbox_rejects_invalid_column', function () {
	$schema = [ nf_matrix_checkbox_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'features' => [
			'purchase' => [ 'email', 'tampered' ],
			'service'  => [ 'chat' ],
		],
	] );
	nf_assert_true( is_wp_error( $result ), 'expected WP_Error for tampered column' );
	$fields = $result->get_error_data()['fields'] ?? [];
	nf_assert_same( 'invalid_option', $fields['features'][0]['code'], 'code should be invalid_option' );
} );

nf_test( 'checkbox_rejects_scalar_value_for_row', function () {
	$schema = [ nf_matrix_checkbox_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'features' => [
			'purchase' => 'email', // scalar under checkbox — strict
			'service'  => [ 'chat' ],
		],
	] );
	nf_assert_true( is_wp_error( $result ), 'expected WP_Error for scalar-under-checkbox' );
	$fields = $result->get_error_data()['fields'] ?? [];
	nf_assert_same( 'invalid_matrix', $fields['features'][0]['code'], 'code should be invalid_matrix' );
} );

nf_test( 'unknown_row_key_is_ignored_in_validation', function () {
	$schema = [ nf_matrix_radio_field( false ) ];
	// Optional matrix with a partially-known submission + bogus row.
	$result = SubmissionValidator::validate( $schema, [
		'satisfaction' => [
			'purchase' => 'satisfied',
			'unknown_row' => 'whatever', // not in schema rows
		],
	] );
	nf_assert_same( true, $result, 'unknown row should not produce a validation error' );
} );

// ---------------------------------------------------------------------
// Tests — sanitizer
// ---------------------------------------------------------------------

nf_test( 'sanitizer_radio_strips_unknown_rows_and_columns', function () {
	$field = nf_matrix_radio_field( true );
	$out = nf_sanitize_matrix( [
		'purchase'    => 'very_satisfied',
		'service'     => 'tampered_column',  // unknown col → drop
		'unknown_row' => 'satisfied',        // unknown row → drop
	], $field );
	nf_assert_same(
		[ 'purchase' => 'very_satisfied' ],
		$out,
		'sanitizer should keep only schema-known row+col pairs'
	);
} );

nf_test( 'sanitizer_checkbox_dedupes_and_filters', function () {
	$field = nf_matrix_checkbox_field( true );
	$out = nf_sanitize_matrix( [
		'purchase' => [ 'email', 'email', 'phone', 'tampered' ],
		'service'  => [ 'chat' ],
		'unknown'  => [ 'email' ],
	], $field );
	nf_assert_same(
		[
			'purchase' => [ 'email', 'phone' ],
			'service'  => [ 'chat' ],
		],
		$out,
		'sanitizer should dedupe and filter to schema-known cols, drop unknown row'
	);
} );

nf_test( 'sanitizer_returns_empty_for_non_array', function () {
	$field = nf_matrix_radio_field( false );
	$out   = nf_sanitize_matrix( 'tampered_scalar', $field );
	nf_assert_same( [], $out, 'sanitizer should normalize non-array to []' );
} );

// ---------------------------------------------------------------------
// Tests — capability gate
// ---------------------------------------------------------------------

nf_test( 'capability_locked_blocks_storage_via_strip', function () {
	// Simulate the SubmitEndpoint strip step in isolation. With cap
	// false (default), matrix data must be removed from $raw_data and
	// the matrix field removed from $schema_fields BEFORE validation
	// runs — that's how a tampered submission can't store matrix data.
	$schema_fields = [ nf_matrix_radio_field( true ) ];
	$raw_data      = [ 'satisfaction' => [ 'purchase' => 'very_satisfied', 'service' => 'satisfied' ] ];

	$cap_open = (bool) apply_filters( 'neural_forms_can_field_matrix', false );
	nf_assert_same( false, $cap_open, 'capability should default to locked' );

	if ( ! $cap_open ) {
		$matrix_ids = [];
		foreach ( $schema_fields as $f ) {
			if ( ( $f['type'] ?? '' ) === 'matrix' ) {
				$matrix_ids[] = $f['id'];
			}
		}
		foreach ( $matrix_ids as $id ) {
			unset( $raw_data[ $id ] );
		}
		$schema_fields = array_values( array_filter(
			$schema_fields,
			static function ( $f ) { return ( $f['type'] ?? '' ) !== 'matrix'; }
		) );
	}

	// After strip: validation sees no matrix field and no matrix data.
	nf_assert_same( [], $raw_data,      'raw_data should be empty after strip' );
	nf_assert_same( [], $schema_fields, 'schema_fields should be empty after strip' );

	$result = SubmissionValidator::validate( $schema_fields, $raw_data );
	nf_assert_same( true, $result, 'post-strip validation should pass (no fields)' );
} );

nf_test( 'capability_open_lets_matrix_validate_normally', function () {
	$GLOBALS['__nf_filters']['neural_forms_can_field_matrix'] = static function () { return true; };
	$cap_open = (bool) apply_filters( 'neural_forms_can_field_matrix', false );
	nf_assert_same( true, $cap_open, 'capability filter override should unlock' );

	$schema = [ nf_matrix_radio_field( true ) ];
	$result = SubmissionValidator::validate( $schema, [
		'satisfaction' => [ 'purchase' => 'satisfied', 'service' => 'satisfied' ],
	] );
	nf_assert_same( true, $result, 'unlocked + valid submission should pass' );

	unset( $GLOBALS['__nf_filters']['neural_forms_can_field_matrix'] );
} );

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------

foreach ( $cases as $case ) {
	try {
		( $case['fn'] )();
		echo "PASS  " . $case['name'] . PHP_EOL;
		$passed++;
	} catch ( \Throwable $e ) {
		echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL;
		$failed++;
	}
}

echo PHP_EOL;
echo $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;

exit( $failed === 0 ? 0 : 1 );
