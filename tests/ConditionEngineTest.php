<?php
/**
 * Shared condition engine (1.2.0) — PHP side.
 *
 * Runs tests/fixtures/conditions.json against ConditionEngine::evaluate()
 * and SubmitEndpoint::resolve_hidden_field_ids(), then checks
 * normalize(), for_attribute() and validate(). The JS side runs the
 * same fixtures in tests/ConditionEngineTest.js.
 *
 *   php tests/ConditionEngineTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
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
	require_once $plugin_root . '/modules/forms/ConditionEngine.php';
	require_once $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php';
}

namespace NeuralForms\Tests {

	use ReflectionMethod;
	use NeuralForms\Forms\ConditionEngine;
	use NeuralForms\Rest\Endpoints\SubmitEndpoint;

	$passed = 0;
	$failed = 0;
	function check( string $label, $actual, $expected, &$passed, &$failed ): void {
		if ( $actual === $expected ) {
			echo "\033[32mPASS\033[0m  $label\n";
			$passed++;
		} else {
			echo "\033[31mFAIL\033[0m  $label\n";
			echo "       expected: " . var_export( $expected, true ) . "\n";
			echo "       actual:   " . var_export( $actual, true ) . "\n";
			$failed++;
		}
	}

	$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/conditions.json' ), true );
	if ( ! is_array( $fixtures ) ) {
		echo "FAIL  fixtures unreadable\n";
		exit( 1 );
	}

	// ── evaluate fixtures ─────────────────────────────────────────────
	foreach ( $fixtures['evaluate'] as $case ) {
		check(
			'evaluate: ' . $case['name'],
			ConditionEngine::evaluate( $case['logic'], $case['data'], $case['types'] ?? [] ),
			$case['expected'],
			$passed,
			$failed
		);
	}

	// ── resolve fixtures (SubmitEndpoint::resolve_hidden_field_ids) ───
	$gate      = new SubmitEndpoint();
	$resolve_m = new ReflectionMethod( SubmitEndpoint::class, 'resolve_hidden_field_ids' );
	$resolve_m->setAccessible( true );
	foreach ( $fixtures['resolve'] as $case ) {
		$ids = $resolve_m->invoke( $gate, $case['fields'], $case['data'] );
		sort( $ids );
		$exp = $case['expected_hidden'];
		sort( $exp );
		check( 'resolve: ' . $case['name'], $ids, $exp, $passed, $failed );
	}

	// ── normalize ─────────────────────────────────────────────────────
	$legacy = [ 'enabled' => true, 'field' => 'a', 'operator' => 'equals', 'value' => 'x' ];
	$n      = ConditionEngine::normalize( $legacy );
	check( 'normalize legacy → version 2', $n['version'], 2, $passed, $failed );
	check( 'normalize legacy → one rule', $n['rules'], [ [ 'field' => 'a', 'operator' => 'equals', 'value' => 'x' ] ], $passed, $failed );
	check( 'normalize legacy → action show / match all', [ $n['action'], $n['match'] ], [ 'show', 'all' ], $passed, $failed );
	check( 'normalize null → disabled, no rules', ConditionEngine::normalize( null )['rules'], [], $passed, $failed );
	check( 'normalize numeric value is stringified', ConditionEngine::normalize( [ 'field' => 'n', 'operator' => 'gte', 'value' => 5 ] )['rules'][0]['value'], '5', $passed, $failed );
	check( 'is_active: enabled + rule', ConditionEngine::is_active( $legacy ), true, $passed, $failed );
	check( 'is_active: disabled', ConditionEngine::is_active( [ 'enabled' => false, 'field' => 'a', 'operator' => 'equals', 'value' => 'x' ] ), false, $passed, $failed );
	check( 'is_active: enabled but no field', ConditionEngine::is_active( [ 'enabled' => true, 'field' => '', 'operator' => 'equals' ] ), false, $passed, $failed );
	check( 'references dedupes across groups', ConditionEngine::references( [
		'enabled' => true, 'rules' => [ [ 'field' => 'a', 'operator' => 'equals', 'value' => '1' ] ],
		'groups'  => [ [ 'match' => 'any', 'rules' => [ [ 'field' => 'b', 'operator' => 'empty' ], [ 'field' => 'a', 'operator' => 'empty' ] ] ] ],
	] ), [ 'a', 'b' ], $passed, $failed );

	// ── for_attribute ─────────────────────────────────────────────────
	$attr = ConditionEngine::for_attribute( $legacy );
	check( 'for_attribute keys (no groups)', array_keys( $attr ), [ 'v', 'action', 'match', 'rules' ], $passed, $failed );
	check( 'for_attribute never leaks enabled', isset( $attr['enabled'] ), false, $passed, $failed );
	$attr2 = ConditionEngine::for_attribute( [ 'enabled' => true, 'rules' => [ [ 'field' => 'a', 'operator' => 'empty' ] ], 'groups' => [ [ 'match' => 'any', 'rules' => [ [ 'field' => 'b', 'operator' => 'empty' ] ] ] ] ] );
	check( 'for_attribute includes groups when present', isset( $attr2['groups'] ) && count( $attr2['groups'] ) === 1, true, $passed, $failed );

	// ── validate ──────────────────────────────────────────────────────
	$ids = [ 'a', 'b', 'c' ];
	$v   = static function ( $cl, bool $advanced = false, string $owner = 'c' ) use ( $ids ) {
		$r = ConditionEngine::validate( $cl, $ids, $advanced, $owner, 'Field C' );
		return $r === true ? true : $r->get_error_code();
	};
	check( 'validate: null passes', $v( null ), true, $passed, $failed );
	check( 'validate: disabled passes even if broken', $v( [ 'enabled' => false, 'field' => 'nope', 'operator' => 'zzz' ] ), true, $passed, $failed );
	check( 'validate: legacy ok', $v( $legacy ), true, $passed, $failed );
	check( 'validate: flat v2 ok', $v( [ 'enabled' => true, 'match' => 'any', 'rules' => [ [ 'field' => 'a', 'operator' => 'equals', 'value' => '1' ], [ 'field' => 'b', 'operator' => 'gte', 'value' => '2' ] ] ] ), true, $passed, $failed );
	check( 'validate: unknown operator rejected', $v( [ 'enabled' => true, 'field' => 'a', 'operator' => 'zzz', 'value' => '' ] ), 'invalid_form_data', $passed, $failed );
	check( 'validate: unknown field rejected', $v( [ 'enabled' => true, 'field' => 'missing', 'operator' => 'equals', 'value' => '' ] ), 'invalid_form_data', $passed, $failed );
	check( 'validate: self reference rejected', $v( [ 'enabled' => true, 'field' => 'c', 'operator' => 'equals', 'value' => '' ] ), 'invalid_form_data', $passed, $failed );
	check( 'validate: bad action rejected', $v( [ 'enabled' => true, 'action' => 'toggle', 'rules' => [ [ 'field' => 'a', 'operator' => 'empty' ] ] ] ), 'invalid_form_data', $passed, $failed );
	check( 'validate: bad match rejected', $v( [ 'enabled' => true, 'match' => 'some', 'rules' => [ [ 'field' => 'a', 'operator' => 'empty' ] ] ] ), 'invalid_form_data', $passed, $failed );
	$too_many = [];
	for ( $i = 0; $i <= ConditionEngine::MAX_RULES; $i++ ) { $too_many[] = [ 'field' => 'a', 'operator' => 'empty' ]; }
	check( 'validate: too many rules rejected', $v( [ 'enabled' => true, 'rules' => $too_many ] ), 'invalid_form_data', $passed, $failed );
	$grouped = [ 'enabled' => true, 'rules' => [ [ 'field' => 'a', 'operator' => 'empty' ] ], 'groups' => [ [ 'match' => 'any', 'rules' => [ [ 'field' => 'b', 'operator' => 'empty' ] ] ] ] ];
	check( 'validate: groups locked without Pro', $v( $grouped, false ), 'pro_feature_locked', $passed, $failed );
	check( 'validate: groups allowed with Pro', $v( $grouped, true ), true, $passed, $failed );
	$deep = $grouped;
	$deep['groups'][0]['groups'] = [ [ 'rules' => [ [ 'field' => 'a', 'operator' => 'empty' ] ] ] ];
	check( 'validate: nested groups too deep', $v( $deep, true ), 'invalid_form_data', $passed, $failed );
	$bad_in_group = $grouped;
	$bad_in_group['groups'][0]['rules'][0]['field'] = 'ghost';
	check( 'validate: unknown field inside group rejected', $v( $bad_in_group, true ), 'invalid_form_data', $passed, $failed );
	$err = ConditionEngine::validate( [ 'enabled' => true, 'field' => 'ghost', 'operator' => 'equals' ], $ids, false, 'c', 'Field C' );
	check( 'validate: message names owner and field', strpos( $err->get_error_message(), 'Field C' ) !== false && strpos( $err->get_error_message(), 'ghost' ) !== false, true, $passed, $failed );
	check( 'validate: 422 status', $err->get_error_data()['status'], 422, $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
