<?php
/**
 * Repeater schema-validation tests (Commit 2).
 *
 * Exercises FormValidator::validate_schema() against the new
 * Repeater branch:
 *
 *   - Allowed child types pass through.
 *   - Blocked child types (nested repeater / matrix / layout / poll /
 *     quiz_question) are rejected with a specific error message.
 *   - Off-whitelist exotic types are rejected with the generic
 *     "not in allow list" message.
 *   - Settings clamps: min/max bounds, columns range, hard max cap.
 *   - Child uniqueness is scoped to the repeater (a child id that
 *     matches a top-level field id is fine — they live in separate
 *     namespaces because rendered iterations prefix the child ids).
 *   - Each child still runs through validate_field, so a malformed
 *     child (missing id, unregistered type) fails the parent save.
 *
 * Run from the plugin root:
 *
 *   php tests/RepeaterSchemaTest.php
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
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';

use NeuralForms\Forms\FormValidator;

// ─────────────────────── Test harness ───────────────────────

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

function nf_repeater( array $children = [], array $settings = [] ): array {
	return [
		'id'    => 'team_members',
		'type'  => 'repeater',
		'label' => 'Team Members',
		'settings' => array_merge( [
			'min_items'           => 1,
			'max_items'           => 4,
			'columns'             => 2,
			'add_button_label'    => 'Add member',
			'remove_button_label' => 'Remove member',
		], $settings ),
		'fields' => $children,
	];
}

function nf_validate( array $field ) {
	$schema = [ 'fields' => [ $field ], 'settings' => [] ];
	return FormValidator::validate_schema( $schema );
}

// ─────────────────────── Allowed children ───────────────────────

nf_test( 'repeater: all v1 allowed child types validate', function () {
	$children = [
		[ 'id' => 'name',     'type' => 'text',       'label' => 'Name' ],
		[ 'id' => 'mail',     'type' => 'email',      'label' => 'Email' ],
		[ 'id' => 'phone',    'type' => 'phone',      'label' => 'Phone' ],
		[ 'id' => 'count',    'type' => 'number',     'label' => 'Count' ],
		[ 'id' => 'web',      'type' => 'url',        'label' => 'URL' ],
		[ 'id' => 'note',     'type' => 'textarea',   'label' => 'Notes' ],
		[ 'id' => 'pick',     'type' => 'select',     'label' => 'Pick',     'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ] ],
		[ 'id' => 'choice',   'type' => 'radio',      'label' => 'Choice',   'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ] ],
		[ 'id' => 'agree',    'type' => 'checkbox',   'label' => 'Agree' ],
		[ 'id' => 'multi',    'type' => 'checkboxes', 'label' => 'Multi',    'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ] ],
		[ 'id' => 'when',     'type' => 'date',       'label' => 'Date' ],
		[ 'id' => 'doc',      'type' => 'file',       'label' => 'Resume' ],
	];
	$res = nf_validate( nf_repeater( $children ) );
	nf_assert_same( true, $res, 'all whitelisted child types must validate together' );
} );

// ─────────────────────── Blocked children ───────────────────────

nf_test( 'repeater: nested repeater is rejected with explicit message', function () {
	$inner = [ 'id' => 'inner', 'type' => 'repeater', 'label' => 'Inner', 'settings' => [ 'min_items' => 0, 'max_items' => 2, 'columns' => 1 ], 'fields' => [] ];
	$res   = nf_validate( nf_repeater( [ $inner ] ) );
	nf_assert_true( is_wp_error( $res ), 'nested repeater must be rejected' );
	nf_assert_match( '/cannot contain "repeater"/', $res->get_error_message(), 'message should call out repeater' );
} );

nf_test( 'repeater: matrix child is rejected', function () {
	$matrix = [ 'id' => 'm', 'type' => 'matrix', 'label' => 'M', 'settings' => [ 'selection_type' => 'radio', 'rows' => [], 'columns' => [] ] ];
	$res    = nf_validate( nf_repeater( [ $matrix ] ) );
	nf_assert_true( is_wp_error( $res ), 'matrix-in-repeater must be rejected' );
	nf_assert_match( '/cannot contain "matrix"/', $res->get_error_message(), 'message should call out matrix' );
} );

nf_test( 'repeater: row layout child is rejected', function () {
	$row = [ 'id' => 'row_1', 'type' => 'row', 'columns' => [ [ 'id' => 'col_1', 'fields' => [] ] ] ];
	$res = nf_validate( nf_repeater( [ $row ] ) );
	nf_assert_true( is_wp_error( $res ), 'row inside repeater must be rejected' );
	nf_assert_match( '/cannot contain "row"/', $res->get_error_message(), 'message should call out row' );
} );

nf_test( 'repeater: step layout child is rejected', function () {
	$step = [ 'id' => 'step_1', 'type' => 'step', 'fields' => [] ];
	$res  = nf_validate( nf_repeater( [ $step ] ) );
	nf_assert_true( is_wp_error( $res ), 'step inside repeater must be rejected' );
	nf_assert_match( '/cannot contain "step"/', $res->get_error_message(), 'message should call out step' );
} );

nf_test( 'repeater: poll child is rejected', function () {
	$poll = [ 'id' => 'p', 'type' => 'poll', 'label' => 'Poll', 'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ] ];
	$res  = nf_validate( nf_repeater( [ $poll ] ) );
	nf_assert_true( is_wp_error( $res ), 'poll inside repeater must be rejected' );
	nf_assert_match( '/cannot contain "poll"/', $res->get_error_message(), 'message should call out poll' );
} );

nf_test( 'repeater: quiz_question child is rejected', function () {
	$q   = [ 'id' => 'q', 'type' => 'quiz_question', 'label' => 'Q',
		'options' => [
			[ 'label' => 'A', 'value' => 'a', 'is_correct' => true ],
			[ 'label' => 'B', 'value' => 'b', 'is_correct' => false ],
		],
	];
	$res = nf_validate( nf_repeater( [ $q ] ) );
	nf_assert_true( is_wp_error( $res ), 'quiz_question inside repeater must be rejected' );
	nf_assert_match( '/cannot contain "quiz_question"/', $res->get_error_message(), 'message should call out quiz_question' );
} );

nf_test( 'repeater: exotic off-whitelist child rejected by allowlist gate', function () {
	$res = nf_validate( nf_repeater( [ [ 'id' => 'r', 'type' => 'rating', 'label' => 'Stars', 'stars' => 5 ] ] ) );
	// rating is NOT in REPEATER_BLOCKED_CHILD_TYPES (it's just not in the
	// allowlist either), so the error comes from the positive allowlist
	// check rather than the blocked-types message.
	nf_assert_true( is_wp_error( $res ), 'rating-in-repeater must be rejected' );
	nf_assert_match( '/Allowed child types/', $res->get_error_message(), 'message should list allowlist' );
} );

// ─────────────────────── Settings clamps ───────────────────────

nf_test( 'repeater: negative min_items rejected', function () {
	$res = nf_validate( nf_repeater( [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ], [ 'min_items' => -1 ] ) );
	nf_assert_true( is_wp_error( $res ), 'min_items < 0 must reject' );
} );

nf_test( 'repeater: max_items > 20 rejected (hard cap)', function () {
	$res = nf_validate( nf_repeater( [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ], [ 'min_items' => 1, 'max_items' => 21 ] ) );
	nf_assert_true( is_wp_error( $res ), 'max_items > 20 must reject' );
	nf_assert_match( '/cannot exceed 20/', $res->get_error_message(), 'message should call out the 20 cap' );
} );

nf_test( 'repeater: max_items below min_items rejected', function () {
	$res = nf_validate( nf_repeater( [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ], [ 'min_items' => 5, 'max_items' => 2 ] ) );
	nf_assert_true( is_wp_error( $res ), 'max < min must reject' );
} );

nf_test( 'repeater: min_items = 0 accepted (truly optional repeater)', function () {
	$res = nf_validate( nf_repeater( [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ], [ 'min_items' => 0, 'max_items' => 5 ] ) );
	nf_assert_same( true, $res, 'min_items 0 must validate' );
} );

// ─────────────────────── Children edge cases ───────────────────────

nf_test( 'repeater: empty children template validates (Commit 2 default state)', function () {
	$res = nf_validate( nf_repeater( [] ) );
	nf_assert_same( true, $res, 'empty children template must validate' );
} );

nf_test( 'repeater: child id matching top-level id is fine (separate namespaces)', function () {
	// The "name" child id collides with a top-level "name" field id —
	// validator must not flag this because the repeater scopes child
	// ids internally and rendered iterations prefix them on the wire.
	$schema = [
		'fields' => [
			[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
			nf_repeater( [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Member Name' ] ] ),
		],
		'settings' => [],
	];
	$res = FormValidator::validate_schema( $schema );
	nf_assert_same( true, $res, 'top-level + child id collision must be allowed' );
} );

nf_test( 'repeater: duplicate child ids WITHIN the repeater are rejected', function () {
	$res = nf_validate( nf_repeater( [
		[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
		[ 'id' => 'name', 'type' => 'text', 'label' => 'Other Name' ],
	] ) );
	nf_assert_true( is_wp_error( $res ), 'duplicate child id within repeater must reject' );
	nf_assert_match( '/Duplicate field id/', $res->get_error_message(), 'standard duplicate-id message reused' );
} );

nf_test( 'repeater: malformed child (missing id) fails through validate_field', function () {
	$res = nf_validate( nf_repeater( [ [ 'type' => 'text', 'label' => 'Anon' ] ] ) );
	nf_assert_true( is_wp_error( $res ), 'missing-id child must fail validation' );
	nf_assert_match( '/missing an id/', $res->get_error_message(), 'standard missing-id message reused' );
} );

nf_test( 'repeater: malformed children entry (not an array) is rejected', function () {
	$res = nf_validate( nf_repeater( [ 'not_a_child_array' ] ) );
	nf_assert_true( is_wp_error( $res ), 'non-array child entry must be rejected' );
} );

// ─────────────────────── Run ───────────────────────

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
