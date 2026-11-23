<?php
/**
 * Matrix end-to-end wire integrity tests (Phase 7).
 *
 * Defence-in-depth coverage for the Lite-side surface a Pro plugin or
 * the React admin bundle depend on. Each assertion documents a contract
 * Lite has implicitly published — breakage in any of these is a Pro
 * compatibility break.
 *
 * Covers:
 *   * Capabilities::FIELD_MATRIX exists as a constant.
 *   * Capabilities::all() includes the `field_matrix` key.
 *   * In Lite-only state (no Pro filter override) the cap defaults
 *     to FALSE.
 *   * Toggling the `neural_forms_can_field_matrix` filter flips the
 *     cap to TRUE (this is the mechanism Pro's Unlocker uses).
 *   * Capabilities::locked_error( FIELD_MATRIX ) returns a WP_Error
 *     with code `pro_feature_locked` and the FIELD_MATRIX feature key
 *     in its data (so the React layer can route to the right upsell).
 *   * FieldRegistry::is_registered( 'matrix' ) is TRUE regardless of
 *     capability state — existing forms with Matrix open without
 *     a "type not registered" validation error after Pro is removed.
 *   * FormValidator::validate_schema() accepts a saved Matrix-only
 *     schema even when Capabilities::can( FIELD_MATRIX ) is false:
 *     the public API allows Lite to OWN a Matrix field's data, just
 *     not to RENDER it. The form-save endpoint and the AI submission
 *     pipeline run their own gates separately.
 *
 * Run from the plugin root:
 *
 *   php tests/MatrixCapabilityWireTest.php
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

// Lightweight apply_filters shim: each filter registers a single callback
// into $GLOBALS['__nf_filters'][ $tag ] and a test invokes apply_filters()
// which threads the value through that callback (or returns the default
// when none is registered). Mirrors how the real WP behaves for our
// needs without pulling in the full plugin API.
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

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/core/Capabilities.php';
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\FieldRegistry;
use NeuralForms\Forms\FormValidator;

// ─────────────────────── Test harness ───────────────────────

$cases  = [];
$passed = 0;
$failed = 0;

function nf_test( string $name, callable $fn ): void {
	global $cases;
	$cases[] = [ 'name' => $name, 'fn' => $fn ];
}

function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			$msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true )
		);
	}
}

function nf_assert_true( $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new \RuntimeException( $msg );
	}
}

function nf_with_cap( bool $unlocked, callable $fn ): void {
	$GLOBALS['__nf_filters']['neural_forms_can_field_matrix'] = static function () use ( $unlocked ) {
		return $unlocked;
	};
	try {
		$fn();
	} finally {
		unset( $GLOBALS['__nf_filters']['neural_forms_can_field_matrix'] );
	}
}

// ─────────────────────── Constant + snapshot shape ───────────────────────

nf_test( 'capabilities: FIELD_MATRIX constant value is field_matrix', function () {
	nf_assert_same( 'field_matrix', Capabilities::FIELD_MATRIX, 'constant must equal "field_matrix"' );
} );

nf_test( 'capabilities: snapshot includes the field_matrix key', function () {
	$snap = Capabilities::all();
	nf_assert_true( array_key_exists( 'field_matrix', $snap ), 'snapshot must carry field_matrix' );
} );

nf_test( 'capabilities: snapshot also still carries the legacy caps (no accidental removal)', function () {
	$snap = Capabilities::all();
	foreach ( [ 'ai_spam_detect', 'ai_reanalyze', 'ai_refine_reply' ] as $cap ) {
		nf_assert_true( array_key_exists( $cap, $snap ), "snapshot must still include {$cap}" );
	}
} );

// ─────────────────────── Default-false (Lite-only) ───────────────────────

nf_test( 'lite-only: can(FIELD_MATRIX) defaults to false when no filter is registered', function () {
	nf_assert_same( false, Capabilities::can( Capabilities::FIELD_MATRIX ), 'default must be false' );
} );

nf_test( 'lite-only: snapshot field_matrix value is false when no filter is registered', function () {
	$snap = Capabilities::all();
	nf_assert_same( false, $snap['field_matrix'], 'snapshot field_matrix must be false in Lite-only' );
} );

// ─────────────────────── Pro flips the filter ───────────────────────

nf_test( 'pro-active: filter override flips can(FIELD_MATRIX) to true', function () {
	nf_with_cap( true, function () {
		nf_assert_same( true, Capabilities::can( Capabilities::FIELD_MATRIX ), 'filter override must unlock the cap' );
	} );
} );

nf_test( 'pro-active: snapshot reflects the unlocked cap', function () {
	nf_with_cap( true, function () {
		$snap = Capabilities::all();
		nf_assert_same( true, $snap['field_matrix'], 'snapshot must reflect the override' );
	} );
} );

nf_test( 'pro-active: unsetting the filter restores the default-false state', function () {
	nf_with_cap( true, function () {} );
	nf_assert_same( false, Capabilities::can( Capabilities::FIELD_MATRIX ), 'cap returns to default after filter removed' );
} );

// ─────────────────────── locked_error contract ───────────────────────

nf_test( 'locked_error: code is pro_feature_locked', function () {
	$err = Capabilities::locked_error( Capabilities::FIELD_MATRIX );
	nf_assert_true( is_wp_error( $err ), 'must return a WP_Error' );
	nf_assert_same( 'pro_feature_locked', $err->get_error_code(), 'code must be pro_feature_locked' );
} );

nf_test( 'locked_error: data carries the feature key + status 403', function () {
	$err  = Capabilities::locked_error( Capabilities::FIELD_MATRIX );
	$data = $err->get_error_data();
	nf_assert_same( Capabilities::FIELD_MATRIX, $data['feature'] ?? null, 'feature must echo the cap key' );
	nf_assert_same( 403, $data['status'] ?? null, 'status must be 403' );
	nf_assert_same( true, $data['requires_pro'] ?? null, 'requires_pro must be true' );
} );

// ─────────────────────── FieldRegistry contract ───────────────────────

nf_test( 'registry: matrix is registered regardless of capability state', function () {
	nf_assert_same( true, FieldRegistry::is_registered( 'matrix' ),
		'registry must always recognise matrix so existing forms do not fatal' );
	nf_with_cap( true, function () {
		nf_assert_same( true, FieldRegistry::is_registered( 'matrix' ),
			'registry recognises matrix when Pro is active' );
	} );
} );

nf_test( 'registry: matrix supports the expected property set', function () {
	$def = FieldRegistry::get( 'matrix' );
	nf_assert_true( is_array( $def ) && isset( $def['supports'] ), 'matrix definition must include supports' );
	foreach ( [ 'label', 'show_label', 'required', 'settings', 'width' ] as $key ) {
		nf_assert_true( in_array( $key, $def['supports'], true ), "supports must include {$key}" );
	}
} );

// ─────────────────────── FormValidator survives existing Matrix ───────────────────────

nf_test( 'form-save: an existing form containing Matrix validates even with capability false', function () {
	// Simulates the "Pro was active, a Matrix form was saved, then Pro
	// got deactivated" path. Lite must not reject the schema — the
	// admin must still be able to open the form and decide what to do.
	$schema = [
		'fields' => [
			[
				'id'       => 'satisfaction',
				'type'     => 'matrix',
				'label'    => 'How satisfied',
				'required' => false,
				'settings' => [
					'selection_type' => 'radio',
					'rows'    => [ [ 'label' => 'A', 'value' => 'a' ] ],
					'columns' => [
						[ 'label' => 'Good', 'value' => 'good', 'score' => 5 ],
						[ 'label' => 'Bad',  'value' => 'bad',  'score' => 1 ],
					],
				],
			],
		],
		'settings' => [],
	];
	// Cap defaults to false — explicitly assert here for clarity.
	nf_assert_same( false, Capabilities::can( Capabilities::FIELD_MATRIX ), 'cap should be locked at start of case' );
	$result = FormValidator::validate_schema( $schema );
	nf_assert_same( true, $result, 'existing matrix schema must validate even with cap locked' );
} );

nf_test( 'form-save: matrix schema also validates when capability is unlocked', function () {
	nf_with_cap( true, function () {
		$schema = [
			'fields' => [
				[
					'id'       => 'features',
					'type'     => 'matrix',
					'label'    => 'Features',
					'required' => false,
					'settings' => [
						'selection_type' => 'checkbox',
						'rows'    => [
							[ 'label' => 'Purchase', 'value' => 'purchase' ],
							[ 'label' => 'Service',  'value' => 'service'  ],
						],
						'columns' => [
							[ 'label' => 'Email', 'value' => 'email', 'score' => 1 ],
							[ 'label' => 'Phone', 'value' => 'phone', 'score' => 1 ],
						],
					],
				],
			],
			'settings' => [],
		];
		$result = FormValidator::validate_schema( $schema );
		nf_assert_same( true, $result, 'matrix schema must validate with cap unlocked' );
	} );
} );

// ─────────────────────── Field-id pattern survives matrix ───────────────────────

nf_test( 'form-save: matrix field id pattern is unchanged (lower + digits + underscore)', function () {
	$schema = [
		'fields' => [
			[
				'id'       => 'matrix_q1',
				'type'     => 'matrix',
				'label'    => 'Q1',
				'settings' => [
					'selection_type' => 'radio',
					'rows'    => [ [ 'label' => 'R', 'value' => 'r' ] ],
					'columns' => [
						[ 'label' => 'A', 'value' => 'a' ],
						[ 'label' => 'B', 'value' => 'b' ],
					],
				],
			],
		],
		'settings' => [],
	];
	$result = FormValidator::validate_schema( $schema );
	nf_assert_same( true, $result, 'matrix field with snake_case id should pass' );
} );

// ─────────────────────── Run ───────────────────────

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
