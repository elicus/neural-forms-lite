<?php
/**
 * Repeater end-to-end wire integrity tests (Commit 1).
 *
 * Mirror of MatrixCapabilityWireTest. Locks down the Lite-side surface
 * that Pro and the React admin bundle treat as a public contract.
 * Breakage in any of these assertions is a Pro compatibility break.
 *
 * Covers:
 *   - Capabilities::FIELD_REPEATER constant exists and has the
 *     expected `field_repeater` value.
 *   - Capabilities::all() includes the `field_repeater` key.
 *   - Lite-only (no Pro filter override) the cap defaults to FALSE.
 *   - Toggling `neural_forms_can_field_repeater` flips the cap TRUE
 *     (the mechanism Pro's Unlocker uses).
 *   - locked_error( FIELD_REPEATER ) returns the canonical
 *     `pro_feature_locked` WP_Error so the frontend can route to
 *     the right upgrade flow.
 *   - FieldRegistry::is_registered( 'repeater' ) is TRUE regardless
 *     of capability state so existing saved forms never fatal.
 *   - FormValidator::validate_schema() accepts a saved Repeater
 *     schema (with empty children template) even when the cap is
 *     locked — Lite must let the admin OPEN such a form, just not
 *     render it on the frontend.
 *
 * Run from the plugin root:
 *
 *   php tests/RepeaterCapabilityWireTest.php
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

$cases = []; $passed = 0; $failed = 0;
function nf_test( string $name, callable $fn ): void { global $cases; $cases[] = [ 'name' => $name, 'fn' => $fn ]; }
function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException( $msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true ) );
	}
}
function nf_assert_true( $cond, string $msg ): void { if ( ! $cond ) throw new \RuntimeException( $msg ); }
function nf_with_cap( bool $unlocked, callable $fn ): void {
	$GLOBALS['__nf_filters']['neural_forms_can_field_repeater'] = static function () use ( $unlocked ) {
		return $unlocked;
	};
	try { $fn(); } finally { unset( $GLOBALS['__nf_filters']['neural_forms_can_field_repeater'] ); }
}

// ─────────────────────── Constant + snapshot shape ───────────────────────

nf_test( 'capabilities: FIELD_REPEATER constant value is field_repeater', function () {
	nf_assert_same( 'field_repeater', Capabilities::FIELD_REPEATER, 'constant must equal "field_repeater"' );
} );

nf_test( 'capabilities: snapshot includes the field_repeater key', function () {
	$snap = Capabilities::all();
	nf_assert_true( array_key_exists( 'field_repeater', $snap ), 'snapshot must carry field_repeater' );
} );

nf_test( 'capabilities: snapshot still carries field_matrix (no accidental removal)', function () {
	$snap = Capabilities::all();
	nf_assert_true( array_key_exists( 'field_matrix', $snap ), 'matrix cap must coexist with the new repeater cap' );
} );

// ─────────────────────── Default-false (Lite-only) ───────────────────────

nf_test( 'lite-only: can(FIELD_REPEATER) defaults to false when no filter is registered', function () {
	nf_assert_same( false, Capabilities::can( Capabilities::FIELD_REPEATER ), 'default must be false' );
} );

nf_test( 'lite-only: snapshot field_repeater value is false in Lite-only', function () {
	$snap = Capabilities::all();
	nf_assert_same( false, $snap['field_repeater'], 'snapshot field_repeater must be false in Lite-only' );
} );

// ─────────────────────── Pro flips the filter ───────────────────────

nf_test( 'pro-active: filter override flips can(FIELD_REPEATER) to true', function () {
	nf_with_cap( true, function () {
		nf_assert_same( true, Capabilities::can( Capabilities::FIELD_REPEATER ), 'filter override must unlock the cap' );
	} );
} );

nf_test( 'pro-active: snapshot reflects the unlocked cap', function () {
	nf_with_cap( true, function () {
		$snap = Capabilities::all();
		nf_assert_same( true, $snap['field_repeater'], 'snapshot must reflect the override' );
	} );
} );

nf_test( 'pro-active: removing the filter restores default-false', function () {
	nf_with_cap( true, function () {} );
	nf_assert_same( false, Capabilities::can( Capabilities::FIELD_REPEATER ), 'cap returns to default after filter removed' );
} );

// ─────────────────────── locked_error contract ───────────────────────

nf_test( 'locked_error: code is pro_feature_locked', function () {
	$err = Capabilities::locked_error( Capabilities::FIELD_REPEATER );
	nf_assert_true( is_wp_error( $err ), 'must return a WP_Error' );
	nf_assert_same( 'pro_feature_locked', $err->get_error_code(), 'code must be pro_feature_locked' );
} );

nf_test( 'locked_error: data carries feature + status 403', function () {
	$err  = Capabilities::locked_error( Capabilities::FIELD_REPEATER );
	$data = $err->get_error_data();
	nf_assert_same( Capabilities::FIELD_REPEATER, $data['feature'] ?? null, 'feature must echo the cap key' );
	nf_assert_same( 403, $data['status'] ?? null, 'status must be 403' );
	nf_assert_same( true, $data['requires_pro'] ?? null, 'requires_pro must be true' );
} );

// ─────────────────────── FieldRegistry contract ───────────────────────

nf_test( 'registry: repeater is registered regardless of capability state', function () {
	nf_assert_same( true, FieldRegistry::is_registered( 'repeater' ),
		'registry must always recognise repeater so existing forms do not fatal' );
	nf_with_cap( true, function () {
		nf_assert_same( true, FieldRegistry::is_registered( 'repeater' ),
			'registry recognises repeater when Pro is active' );
	} );
} );

nf_test( 'registry: repeater supports the expected property set', function () {
	$def = FieldRegistry::get( 'repeater' );
	nf_assert_true( is_array( $def ) && isset( $def['supports'] ), 'repeater definition must include supports' );
	foreach ( [ 'label', 'show_label', 'required', 'settings', 'width', 'fields' ] as $key ) {
		nf_assert_true( in_array( $key, $def['supports'], true ), "supports must include {$key}" );
	}
} );

// ─────────────────────── FormValidator survives existing Repeater ───────────────────────

nf_test( 'form-save: an existing Repeater schema validates even with cap locked', function () {
	// Simulates the "Pro was active, a Repeater form was saved, then
	// Pro got deactivated" path. Lite must not reject the schema —
	// the admin must still be able to open the form.
	$schema = [
		'fields' => [
			[
				'id'       => 'team_members',
				'type'     => 'repeater',
				'label'    => 'Team Members',
				'required' => false,
				'settings' => [
					'min_items'           => 1,
					'max_items'           => 4,
					'columns'             => 2,
					'add_button_label'    => 'Add member',
					'remove_button_label' => 'Remove member',
				],
				// Commit 1 stores the children template even though the
				// builder UI for editing it lands in Commit 2.
				'fields' => [
					[ 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ],
				],
			],
		],
		'settings' => [],
	];
	nf_assert_same( false, Capabilities::can( Capabilities::FIELD_REPEATER ), 'cap should be locked at start of case' );
	$result = FormValidator::validate_schema( $schema );
	nf_assert_same( true, $result, 'existing repeater schema must validate even with cap locked' );
} );

nf_test( 'form-save: repeater schema validates with cap unlocked too', function () {
	nf_with_cap( true, function () {
		$schema = [
			'fields' => [
				[
					'id'       => 'team_members',
					'type'     => 'repeater',
					'label'    => 'Team Members',
					'settings' => [
						'min_items' => 1, 'max_items' => 5, 'columns' => 1,
						'add_button_label' => 'Add item', 'remove_button_label' => 'Remove',
					],
					'fields'   => [],
				],
			],
			'settings' => [],
		];
		$result = FormValidator::validate_schema( $schema );
		nf_assert_same( true, $result, 'repeater schema must validate with cap unlocked' );
	} );
} );

nf_test( 'form-save: empty repeater fields template still validates', function () {
	// Commit 1 only adds the registry + capability. The child-DnD UI
	// arrives in Commit 2. Until then the builder may write a
	// repeater whose `fields` template is empty — that must not
	// break schema save.
	$schema = [
		'fields' => [
			[
				'id'       => 'r1',
				'type'     => 'repeater',
				'label'    => 'Items',
				'settings' => [ 'min_items' => 0, 'max_items' => 5, 'columns' => 1, 'add_button_label' => 'Add', 'remove_button_label' => 'Remove' ],
				'fields'   => [],
			],
		],
		'settings' => [],
	];
	$result = FormValidator::validate_schema( $schema );
	nf_assert_same( true, $result, 'repeater with empty children template must validate' );
} );

// ─────────────────────── Run ───────────────────────

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
