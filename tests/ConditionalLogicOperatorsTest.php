<?php
/**
 * Conditional logic gte / lte operator tests.
 *
 * Locks the numeric-comparison branch of
 * SubmitEndpoint::evaluate_conditional() so the JS evaluator
 * (form-submit.js) and PHP evaluator stay in sync — both sides
 * MUST behave identically: a field hidden on the client must also
 * be hidden on the server so submitted values can't leak past the
 * visibility gate.
 *
 * Run from the plugin root:
 *
 *   php tests/ConditionalLogicOperatorsTest.php
 *
 * Coverage:
 *   - gte: equal, greater, less, non-numeric control, non-numeric
 *     rule value, missing control field.
 *   - lte: equal, greater, less, non-numeric guards.
 *   - Existing operators (equals, not_equals, contains, empty,
 *     not_empty) still work — regression for the switch statement.
 *   - Unknown operator returns true (safe default).
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! class_exists( 'WP_REST_Request' ) ) { class WP_REST_Request {} }
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) {
				$this->code = $code; $this->message = $message; $this->data = $data;
			}
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php';
}

namespace NeuralForms\Tests {

	use ReflectionMethod;
	use NeuralForms\Rest\Endpoints\SubmitEndpoint;

	$passed = 0;
	$failed = 0;
	function check( string $label, $actual, $expected, &$passed, &$failed ): void {
		$ok = $actual === $expected;
		if ( $ok ) {
			echo "\033[32mPASS\033[0m  $label\n";
			$passed += 1;
		} else {
			echo "\033[31mFAIL\033[0m  $label\n";
			echo "       expected: " . var_export( $expected, true ) . "\n";
			echo "       actual:   " . var_export( $actual,   true ) . "\n";
			$failed += 1;
		}
	}

	$gate   = new SubmitEndpoint();
	$method = new ReflectionMethod( SubmitEndpoint::class, 'evaluate_conditional' );
	$method->setAccessible( true );

	$eval = static function ( array $logic, array $data, array $types = [] ) use ( $gate, $method ) {
		return $method->invoke( $gate, $logic, $data, $types );
	};
	$resolve_m = new ReflectionMethod( SubmitEndpoint::class, 'resolve_hidden_field_ids' );
	$resolve_m->setAccessible( true );
	$resolve = static function ( array $fields, array $data ) use ( $gate, $resolve_m ) {
		$ids = $resolve_m->invoke( $gate, $fields, $data );
		sort( $ids );
		return $ids;
	};

	// ─── 1.1.3: choice controllers (checkbox / radio) ────────────────

	$cb = [ 'consent' => 'checkbox' ];
	check( 'checkbox absent → empty (not_empty rule hides)',
		$eval( [ 'field' => 'consent', 'operator' => 'not_empty', 'value' => '' ], [], $cb ), false, $passed, $failed );
	check( 'checkbox absent → empty ("empty" rule shows)',
		$eval( [ 'field' => 'consent', 'operator' => 'empty', 'value' => '' ], [], $cb ), true, $passed, $failed );
	foreach ( [ 0, '0', false, '' ] as $v ) {
		check( 'checkbox ' . var_export( $v, true ) . ' → empty',
			$eval( [ 'field' => 'consent', 'operator' => 'empty', 'value' => '' ], [ 'consent' => $v ], $cb ), true, $passed, $failed );
		check( 'checkbox ' . var_export( $v, true ) . ' does not equal "1"',
			$eval( [ 'field' => 'consent', 'operator' => 'equals', 'value' => '1' ], [ 'consent' => $v ], $cb ), false, $passed, $failed );
	}
	foreach ( [ 1, '1', true ] as $v ) {
		check( 'checkbox ' . var_export( $v, true ) . ' → "1" (equals 1 shows)',
			$eval( [ 'field' => 'consent', 'operator' => 'equals', 'value' => '1' ], [ 'consent' => $v ], $cb ), true, $passed, $failed );
		check( 'checkbox ' . var_export( $v, true ) . ' → not_empty shows',
			$eval( [ 'field' => 'consent', 'operator' => 'not_empty', 'value' => '' ], [ 'consent' => $v ], $cb ), true, $passed, $failed );
	}
	check( 'acceptance absent → empty',
		$eval( [ 'field' => 'terms', 'operator' => 'not_empty', 'value' => '' ], [], [ 'terms' => 'acceptance' ] ), false, $passed, $failed );
	check( 'radio absent → empty (equals Yes hides)',
		$eval( [ 'field' => 'has_site', 'operator' => 'equals', 'value' => 'Yes' ], [], [ 'has_site' => 'radio' ] ), false, $passed, $failed );
	check( 'radio absent → not_equals Yes shows',
		$eval( [ 'field' => 'has_site', 'operator' => 'not_equals', 'value' => 'Yes' ], [], [ 'has_site' => 'radio' ] ), true, $passed, $failed );
	check( 'text controller absent → still safe-show fallback',
		$eval( [ 'field' => 'name', 'operator' => 'equals', 'value' => 'x' ], [], [ 'name' => 'text' ] ), true, $passed, $failed );
	check( 'untyped controller absent → safe-show fallback (back-compat)',
		$eval( [ 'field' => 'name', 'operator' => 'equals', 'value' => 'x' ], [] ), true, $passed, $failed );

	// ─── 1.1.3: resolve_hidden_field_ids (the Step 5a input) ─────────

	$f = static function ( string $id, string $type, ?array $cl = null, bool $required = false ): array {
		$x = [ 'id' => $id, 'type' => $type, 'label' => $id, 'required' => $required ];
		if ( $cl ) $x['conditional_logic'] = array_merge( [ 'enabled' => true ], $cl );
		return $x;
	};
	$fields = [
		$f( 'project',  'select' ),
		$f( 'site_url', 'url',   [ 'field' => 'project',  'operator' => 'equals',    'value' => 'Fixes to an existing site' ], true ),
		$f( 'has_site', 'radio' ),
		$f( 'launch',   'date',  [ 'field' => 'has_site', 'operator' => 'equals',    'value' => 'Yes' ], true ),
		$f( 'call_me',  'checkbox' ),
		$f( 'phone',    'phone', [ 'field' => 'call_me',  'operator' => 'not_empty', 'value' => '' ], true ),
		$f( 'best_time','text',  [ 'field' => 'phone',    'operator' => 'not_empty', 'value' => '' ] ),
	];
	check( 'report case 1: select = new website → required URL hidden',
		$resolve( $fields, [ 'project' => 'A new website', 'has_site' => 'Yes', 'call_me' => 1, 'phone' => '+1 5551234567' ] ), [ 'site_url' ], $passed, $failed );
	check( 'report case 2: radio No → required date hidden',
		$resolve( $fields, [ 'project' => 'Fixes to an existing site', 'has_site' => 'No', 'call_me' => 1, 'phone' => '+1 5551234567' ] ), [ 'launch' ], $passed, $failed );
	check( 'report case 3: checkbox unticked (posted 0) → required phone hidden + chained best_time hidden',
		$resolve( $fields, [ 'project' => 'Fixes to an existing site', 'has_site' => 'Yes', 'call_me' => 0 ] ), [ 'best_time', 'phone' ], $passed, $failed );
	check( 'report case 3b: checkbox unticked (not posted, multipart) → same',
		$resolve( $fields, [ 'project' => 'Fixes to an existing site', 'has_site' => 'Yes' ] ), [ 'best_time', 'phone' ], $passed, $failed );
	check( 'all conditions met → nothing hidden',
		$resolve( $fields, [ 'project' => 'Fixes to an existing site', 'has_site' => 'Yes', 'call_me' => '1', 'phone' => '+1 5551234567' ] ), [], $passed, $failed );
	check( 'chain: controller visible but its own rule fails → dependent hidden too',
		$resolve( $fields, [ 'project' => 'Fixes to an existing site', 'has_site' => 'Yes', 'call_me' => '1', 'phone' => '' ] ), [ 'best_time' ], $passed, $failed );

	// Step 5a must strip hidden ids from $schema_fields too (source guard).
	$src = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
	check( 'Step 5a removes hidden fields from $schema_fields (not just $raw_data)',
		(bool) preg_match( '/\$_hidden_ids = \$this->resolve_hidden_field_ids\(.*?\$schema_fields\s+= array_values\(\s*array_filter\(\s*\$schema_fields/s', $src ), true, $passed, $failed );

	// ─── gte ─────────────────────────────────────────────────────────

	check( 'gte 5 >= 5 → true',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => '5' ], [ 'age' => 5 ] ),
		true, $passed, $failed );

	check( 'gte 6 >= 5 → true',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => '5' ], [ 'age' => 6 ] ),
		true, $passed, $failed );

	check( 'gte 4 >= 5 → false',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => '5' ], [ 'age' => 4 ] ),
		false, $passed, $failed );

	check( 'gte "5.5" >= "5" → true (float strings)',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => '5' ], [ 'age' => '5.5' ] ),
		true, $passed, $failed );

	check( 'gte "abc" >= "5" → false (control not numeric)',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => '5' ], [ 'age' => 'abc' ] ),
		false, $passed, $failed );

	check( 'gte "5" >= "abc" → false (rule not numeric)',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => 'abc' ], [ 'age' => '5' ] ),
		false, $passed, $failed );

	check( 'gte missing control field → true (safe fallback)',
		$eval( [ 'field' => 'age', 'operator' => 'gte', 'value' => '5' ], [] ),
		true, $passed, $failed );

	// ─── lte ─────────────────────────────────────────────────────────

	check( 'lte 5 <= 5 → true',
		$eval( [ 'field' => 'age', 'operator' => 'lte', 'value' => '5' ], [ 'age' => 5 ] ),
		true, $passed, $failed );

	check( 'lte 4 <= 5 → true',
		$eval( [ 'field' => 'age', 'operator' => 'lte', 'value' => '5' ], [ 'age' => 4 ] ),
		true, $passed, $failed );

	check( 'lte 6 <= 5 → false',
		$eval( [ 'field' => 'age', 'operator' => 'lte', 'value' => '5' ], [ 'age' => 6 ] ),
		false, $passed, $failed );

	check( 'lte "abc" <= "5" → false (control not numeric)',
		$eval( [ 'field' => 'age', 'operator' => 'lte', 'value' => '5' ], [ 'age' => 'abc' ] ),
		false, $passed, $failed );

	check( 'lte negative numbers: -5 <= 0 → true',
		$eval( [ 'field' => 'temp', 'operator' => 'lte', 'value' => '0' ], [ 'temp' => '-5' ] ),
		true, $passed, $failed );

	// ─── Regression: existing operators still work ───────────────────

	check( 'equals: "yes" === "yes" → true',
		$eval( [ 'field' => 'q', 'operator' => 'equals', 'value' => 'yes' ], [ 'q' => 'yes' ] ),
		true, $passed, $failed );

	check( 'not_equals: "yes" !== "no" → true',
		$eval( [ 'field' => 'q', 'operator' => 'not_equals', 'value' => 'no' ], [ 'q' => 'yes' ] ),
		true, $passed, $failed );

	check( 'contains: "hello world" contains "world" → true',
		$eval( [ 'field' => 'q', 'operator' => 'contains', 'value' => 'world' ], [ 'q' => 'hello world' ] ),
		true, $passed, $failed );

	check( 'empty: "" is empty → true',
		$eval( [ 'field' => 'q', 'operator' => 'empty', 'value' => '' ], [ 'q' => '' ] ),
		true, $passed, $failed );

	check( 'not_empty: "x" is not empty → true',
		$eval( [ 'field' => 'q', 'operator' => 'not_empty', 'value' => '' ], [ 'q' => 'x' ] ),
		true, $passed, $failed );

	// ─── Unknown operator → safe true ───────────────────────────────

	check( 'unknown operator → true (safe default)',
		$eval( [ 'field' => 'q', 'operator' => 'zzz_unknown', 'value' => 'x' ], [ 'q' => 'x' ] ),
		true, $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
