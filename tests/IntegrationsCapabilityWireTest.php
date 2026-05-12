<?php
/**
 * Integrations admin-page capability wire-integrity tests.
 *
 * Mirror of MatrixCapabilityWireTest / RepeaterCapabilityWireTest, but
 * scoped to the Lite-side contract that gates the Integrations admin
 * page. Integrations is a UI-only capability (no field type, no
 * validator branch, no submission path) — so the assertions are tighter:
 *
 *   - Capabilities::INTEGRATIONS constant exists and equals
 *     `integrations`.
 *   - Capabilities::all() includes the `integrations` key.
 *   - Lite-only (no filter registered) the cap defaults to FALSE.
 *   - Registering `neural_forms_can_integrations` flips the cap TRUE
 *     (the mechanism Pro's Unlocker uses).
 *   - Removing the filter restores the default-false state.
 *   - The localized snapshot value tracks the filter exactly so the
 *     admin React bundle (which reads
 *     `window.neuralFormsAdmin.plugin.capabilities.integrations`)
 *     receives the correct value on each page load.
 *   - locked_error( INTEGRATIONS ) returns the canonical
 *     `pro_feature_locked` WP_Error so the frontend / REST layer can
 *     route to the right upgrade flow if anything is ever wired up
 *     behind the cap server-side.
 *   - The exact filter tag string is the literal
 *     `neural_forms_can_integrations` — a rename here would silently
 *     break Pro's Unlocker.
 *   - Existing caps (field_matrix, field_repeater) still appear in
 *     the snapshot — defence against an accidental removal when this
 *     cap was added.
 *
 * Run from the plugin root:
 *
 *   php tests/IntegrationsCapabilityWireTest.php
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

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/core/Capabilities.php';

use NeuralForms\Core\Capabilities;

// ─────────────────────── Test harness ───────────────────────

$cases = []; $passed = 0; $failed = 0;
function nf_test( string $name, callable $fn ): void { global $cases; $cases[] = [ 'name' => $name, 'fn' => $fn ]; }
function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException( $msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true ) );
	}
}
function nf_assert_true( $cond, string $msg ): void { if ( ! $cond ) throw new \RuntimeException( $msg ); }
function nf_with_cap( bool $unlocked, callable $fn ): void {
	$GLOBALS['__nf_filters']['neural_forms_can_integrations'] = static function () use ( $unlocked ) {
		return $unlocked;
	};
	try { $fn(); } finally { unset( $GLOBALS['__nf_filters']['neural_forms_can_integrations'] ); }
}

// ─────────────────────── Constant + snapshot shape ───────────────────────

nf_test( 'capabilities: INTEGRATIONS constant value is integrations', function () {
	nf_assert_same( 'integrations', Capabilities::INTEGRATIONS, 'constant must equal "integrations"' );
} );

nf_test( 'capabilities: snapshot includes the integrations key', function () {
	$snap = Capabilities::all();
	nf_assert_true( array_key_exists( 'integrations', $snap ), 'snapshot must carry integrations' );
} );

nf_test( 'capabilities: snapshot still carries field_matrix (no accidental removal)', function () {
	$snap = Capabilities::all();
	nf_assert_true( array_key_exists( 'field_matrix', $snap ), 'matrix cap must coexist with the new integrations cap' );
} );

nf_test( 'capabilities: snapshot still carries field_repeater (no accidental removal)', function () {
	$snap = Capabilities::all();
	nf_assert_true( array_key_exists( 'field_repeater', $snap ), 'repeater cap must coexist with the new integrations cap' );
} );

// ─────────────────────── Default-false (Lite-only) ───────────────────────

nf_test( 'lite-only: can(INTEGRATIONS) defaults to false when no filter is registered', function () {
	nf_assert_same( false, Capabilities::can( Capabilities::INTEGRATIONS ), 'default must be false' );
} );

nf_test( 'lite-only: snapshot integrations value is false in Lite-only', function () {
	$snap = Capabilities::all();
	nf_assert_same( false, $snap['integrations'], 'snapshot integrations must be false in Lite-only' );
} );

// ─────────────────────── Pro flips the filter ───────────────────────

nf_test( 'pro-active: filter override flips can(INTEGRATIONS) to true', function () {
	nf_with_cap( true, function () {
		nf_assert_same( true, Capabilities::can( Capabilities::INTEGRATIONS ), 'filter override must unlock the cap' );
	} );
} );

nf_test( 'pro-active: snapshot reflects the unlocked cap', function () {
	nf_with_cap( true, function () {
		$snap = Capabilities::all();
		nf_assert_same( true, $snap['integrations'], 'snapshot must reflect the override' );
	} );
} );

nf_test( 'pro-active: removing the filter restores default-false', function () {
	nf_with_cap( true, function () {} );
	nf_assert_same( false, Capabilities::can( Capabilities::INTEGRATIONS ), 'cap returns to default after filter removed' );
} );

// ─────────────────────── Filter-tag identity (defence vs rename) ───────────────────────

nf_test( 'filter-tag: cap dispatches through the literal neural_forms_can_integrations tag', function () {
	// Sanity: hooking the exact tag Pro promises must be what Capabilities::can()
	// reads. A future renaming of the constant value would silently break Pro
	// — this assertion makes that drift impossible.
	$GLOBALS['__nf_filters']['neural_forms_can_integrations'] = static function () { return true; };
	try {
		nf_assert_same( true, Capabilities::can( Capabilities::INTEGRATIONS ),
			'Capabilities::can must read the neural_forms_can_integrations filter' );
	} finally {
		unset( $GLOBALS['__nf_filters']['neural_forms_can_integrations'] );
	}
} );

// ─────────────────────── locked_error contract ───────────────────────

nf_test( 'locked_error: code is pro_feature_locked', function () {
	$err = Capabilities::locked_error( Capabilities::INTEGRATIONS );
	nf_assert_true( is_wp_error( $err ), 'must return a WP_Error' );
	nf_assert_same( 'pro_feature_locked', $err->get_error_code(), 'code must be pro_feature_locked' );
} );

nf_test( 'locked_error: data carries feature + status 403', function () {
	$err  = Capabilities::locked_error( Capabilities::INTEGRATIONS );
	$data = $err->get_error_data();
	nf_assert_same( Capabilities::INTEGRATIONS, $data['feature'] ?? null, 'feature must echo the cap key' );
	nf_assert_same( 403, $data['status'] ?? null, 'status must be 403' );
	nf_assert_same( true, $data['requires_pro'] ?? null, 'requires_pro must be true' );
} );

// ─────────────────────── Run ───────────────────────

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
