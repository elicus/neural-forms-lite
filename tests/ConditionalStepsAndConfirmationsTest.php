<?php
/**
 * A2 (1.2.0): step eligibility, section logic, conditional confirmations.
 *
 *   - resolve_steps fixtures through SubmitEndpoint::resolve_hidden_field_ids()
 *   - SubmitEndpoint::resolve_success_outcome() first-match semantics
 *   - FormSettingsSanitizer::sanitize_on_success() shape + caps
 *   - FormValidator: step logic (earlier steps only, Pro), section logic
 *     (Pro), validate_settings_logic()
 *
 *   php tests/ConditionalStepsAndConfirmationsTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	$GLOBALS['nf_test_filters'] = array();
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['nf_test_filters'] ) ? $GLOBALS['nf_test_filters'][ $hook ] : $value; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
	if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return preg_match( '#^https?://#i', (string) $u ) ? (string) $u : ''; } }
	if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return strip_tags( (string) $s, '<p><a><strong><em><br>' ); } }
	if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	if ( ! class_exists( 'WP_REST_Request' ) ) { class WP_REST_Request {} }
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}
	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/forms/ConditionEngine.php';
	require_once $plugin_root . '/modules/forms/SchemaHelper.php';
	require_once $plugin_root . '/modules/forms/FieldRegistry.php';
	require_once $plugin_root . '/modules/forms/FormValidator.php';
	require_once $plugin_root . '/modules/forms/FormSettingsSanitizer.php';
	require_once $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php';
}

namespace NeuralForms\Tests {

	use ReflectionMethod;
	use NeuralForms\Forms\FormValidator;
	use NeuralForms\Forms\FormSettingsSanitizer;
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
	function pro( bool $on ): void {
		$GLOBALS['nf_test_filters']['neural_forms_can_conditional_logic_advanced'] = $on;
	}

	$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/conditions.json' ), true );
	$gate      = new SubmitEndpoint();
	$resolve_m = new ReflectionMethod( SubmitEndpoint::class, 'resolve_hidden_field_ids' );
	$resolve_m->setAccessible( true );
	$outcome_m = new ReflectionMethod( SubmitEndpoint::class, 'resolve_success_outcome' );
	$outcome_m->setAccessible( true );

	// ── step fixtures ─────────────────────────────────────────────────
	foreach ( $fixtures['resolve_steps'] as $case ) {
		$flat = \NeuralForms\Forms\SchemaHelper::flatten_fields( $case['nodes'] );
		$ids  = $resolve_m->invoke( $gate, $flat, $case['data'], $case['nodes'] );
		sort( $ids );
		$exp = $case['expected_hidden'];
		sort( $exp );
		check( 'steps: ' . $case['name'], $ids, $exp, $passed, $failed );
	}
	// Without top nodes the step pass is skipped (back-compat call shape).
	$c0   = $fixtures['resolve_steps'][0];
	$flat = \NeuralForms\Forms\SchemaHelper::flatten_fields( $c0['nodes'] );
	check( 'steps: legacy 2-arg call ignores step logic', $resolve_m->invoke( $gate, $flat, $c0['data'] ), [], $passed, $failed );

	// ── confirmation outcome ──────────────────────────────────────────
	$types = [ 'service' => 'select', 'budget' => 'number' ];
	$form  = [ 'settings' => [ 'on_success' => [
		'mode' => 'message', 'message' => 'default',
		'rules' => [
			[ 'id' => 'r1', 'conditions' => [ 'enabled' => true, 'rules' => [ [ 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ], [ 'field' => 'budget', 'operator' => 'gte', 'value' => '5000' ] ] ], 'mode' => 'redirect', 'url' => 'https://example.test/book' ],
			[ 'id' => 'r2', 'conditions' => [ 'enabled' => true, 'rules' => [ [ 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ] ] ], 'mode' => 'message', 'message' => '<p>Thanks <strong>web</strong></p><script>x</script>' ],
			[ 'id' => 'r3', 'conditions' => [ 'enabled' => true, 'rules' => [ [ 'field' => 'service', 'operator' => 'equals', 'value' => 'seo' ] ] ], 'mode' => 'redirect', 'url' => '' ],
		],
	] ] ];
	check( 'outcome: first match wins (redirect)', $outcome_m->invoke( $gate, $form, [ 'service' => 'web', 'budget' => '9000' ], $types ), [ 'mode' => 'redirect', 'message' => '', 'url' => 'https://example.test/book' ], $passed, $failed );
	$o = $outcome_m->invoke( $gate, $form, [ 'service' => 'web', 'budget' => '100' ], $types );
	check( 'outcome: second rule when first fails', $o['mode'], 'message', $passed, $failed );
	check( 'outcome: message passed through kses (script dropped)', strpos( $o['message'], '<script>' ) === false && strpos( $o['message'], '<strong>' ) !== false, true, $passed, $failed );
	check( 'outcome: redirect rule without URL is skipped → null (default)', $outcome_m->invoke( $gate, $form, [ 'service' => 'seo' ], $types ), null, $passed, $failed );
	check( 'outcome: no match → null', $outcome_m->invoke( $gate, $form, [ 'service' => 'other' ], $types ), null, $passed, $failed );
	check( 'outcome: no rules → null', $outcome_m->invoke( $gate, [ 'settings' => [ 'on_success' => [ 'mode' => 'message' ] ] ], [ 'service' => 'web' ], $types ), null, $passed, $failed );

	// ── sanitizer ─────────────────────────────────────────────────────
	$san = FormSettingsSanitizer::sanitize( [ 'on_success' => [
		'mode' => 'message', 'message' => 'hi',
		'rules' => [
			[ 'id' => 'Rule One!', 'conditions' => [ 'enabled' => true, 'field' => 'a', 'operator' => 'equals', 'value' => 'x' ], 'mode' => 'redirect', 'url' => 'javascript:alert(1)', 'bogus' => 1 ],
			[ 'conditions' => [ 'enabled' => true, 'rules' => [] ], 'mode' => 'message', 'message' => 'orphan' ],
			[ 'conditions' => [ 'enabled' => true, 'rules' => [ [ 'field' => 'b', 'operator' => 'empty' ] ] ], 'mode' => 'weird', 'message' => '<em>ok</em><script>no</script>' ],
		],
	] ] );
	$rules = $san['on_success']['rules'];
	check( 'sanitize: rule without conditions dropped', count( $rules ), 2, $passed, $failed );
	check( 'sanitize: id sanitised', $rules[0]['id'], 'ruleone', $passed, $failed );
	check( 'sanitize: legacy single-rule conditions normalised to v2', $rules[0]['conditions']['rules'], [ [ 'field' => 'a', 'operator' => 'equals', 'value' => 'x' ] ], $passed, $failed );
	check( 'sanitize: conditions forced enabled', $rules[0]['conditions']['enabled'], true, $passed, $failed );
	check( 'sanitize: javascript URL stripped', $rules[0]['url'], '', $passed, $failed );
	check( 'sanitize: unknown keys dropped', array_keys( $rules[0] ), [ 'id', 'conditions', 'mode', 'message', 'url' ], $passed, $failed );
	check( 'sanitize: unknown mode → message', $rules[1]['mode'], 'message', $passed, $failed );
	check( 'sanitize: message kses', $rules[1]['message'], '<em>ok</em>no', $passed, $failed );
	check( 'sanitize: missing id generated', strpos( $rules[1]['id'], 'rule_' ) === 0, true, $passed, $failed );
	check( 'sanitize: default outcome untouched', [ $san['on_success']['mode'], $san['on_success']['message'] ], [ 'message', 'hi' ], $passed, $failed );
	$san2 = FormSettingsSanitizer::sanitize( [ 'on_success' => [ 'mode' => 'message', 'rules' => [] ] ] );
	check( 'sanitize: empty rules key removed', isset( $san2['on_success']['rules'] ), false, $passed, $failed );
	$san3 = FormSettingsSanitizer::sanitize( [ 'on_success' => [ 'mode' => 'redirect', 'url' => 'https://x.test' ] ] );
	check( 'sanitize: on_success without rules round-trips', $san3['on_success'], [ 'mode' => 'redirect', 'url' => 'https://x.test' ], $passed, $failed );

	// ── validator: steps ──────────────────────────────────────────────
	$f = static function ( string $id, string $type = 'text', ?array $cl = null ): array {
		$x = [ 'id' => $id, 'type' => $type, 'label' => $id ];
		if ( $cl ) $x['conditional_logic'] = $cl;
		return $x;
	};
	$step = static function ( string $id, array $fields, ?array $cl = null ): array {
		$x = [ 'id' => $id, 'type' => 'step', 'title' => $id, 'fields' => $fields ];
		if ( $cl ) $x['conditional_logic'] = $cl;
		return $x;
	};
	$rule_on = static function ( string $field ): array {
		return [ 'enabled' => true, 'version' => 2, 'rules' => [ [ 'field' => $field, 'operator' => 'not_empty', 'value' => '' ] ] ];
	};
	$code = static function ( $r ) { return $r === true ? true : $r->get_error_code(); };

	pro( true );
	$good = [ 'fields' => [ $step( 's1', [ $f( 'a' ) ] ), $step( 's2', [ $f( 'b' ) ], $rule_on( 'a' ) ) ] ];
	check( 'validator: step depends on earlier step → ok (Pro)', $code( FormValidator::validate_schema( $good ) ), true, $passed, $failed );
	$own = [ 'fields' => [ $step( 's1', [ $f( 'a' ) ] ), $step( 's2', [ $f( 'b' ) ], $rule_on( 'b' ) ) ] ];
	$r = FormValidator::validate_schema( $own );
	check( 'validator: step depends on its own field → 422', $code( $r ), 'invalid_form_data', $passed, $failed );
	check( 'validator: … with an earlier-steps message', strpos( $r->get_error_message(), 'earlier steps' ) !== false, true, $passed, $failed );
	$later = [ 'fields' => [ $step( 's1', [ $f( 'a' ) ] ), $step( 's2', [ $f( 'b' ) ], $rule_on( 'c' ) ), $step( 's3', [ $f( 'c' ) ] ) ] ];
	check( 'validator: step depends on a later step → 422', $code( FormValidator::validate_schema( $later ) ), 'invalid_form_data', $passed, $failed );
	$first = [ 'fields' => [ $step( 's1', [ $f( 'a' ) ], $rule_on( 'a' ) ), $step( 's2', [ $f( 'b' ) ] ) ] ];
	check( 'validator: first step cannot have logic → 422', $code( FormValidator::validate_schema( $first ) ), 'invalid_form_data', $passed, $failed );
	$disabled_first = [ 'fields' => [ $step( 's1', [ $f( 'a' ) ], [ 'enabled' => false, 'field' => 'a', 'operator' => 'empty' ] ), $step( 's2', [ $f( 'b' ) ] ) ] ];
	check( 'validator: disabled step logic is ignored', $code( FormValidator::validate_schema( $disabled_first ) ), true, $passed, $failed );
	pro( false );
	check( 'validator: step logic without Pro → pro_feature_locked', $code( FormValidator::validate_schema( $good ) ), 'pro_feature_locked', $passed, $failed );

	// ── validator: sections ───────────────────────────────────────────
	$sec = static function ( ?array $cl ): array {
		$x = [ 'id' => 'sec1', 'type' => 'section', 'title' => 'T', 'description' => '', 'settings' => [ 'style' => 'default' ] ];
		if ( $cl ) $x['conditional_logic'] = $cl;
		return $x;
	};
	pro( true );
	check( 'validator: section logic ok (Pro)', $code( FormValidator::validate_schema( [ 'fields' => [ $f( 'a' ), $sec( $rule_on( 'a' ) ) ] ] ) ), true, $passed, $failed );
	check( 'validator: section logic bad ref → 422', $code( FormValidator::validate_schema( [ 'fields' => [ $f( 'a' ), $sec( $rule_on( 'zz' ) ) ] ] ) ), 'invalid_form_data', $passed, $failed );
	pro( false );
	check( 'validator: section logic without Pro → locked', $code( FormValidator::validate_schema( [ 'fields' => [ $f( 'a' ), $sec( $rule_on( 'a' ) ) ] ] ) ), 'pro_feature_locked', $passed, $failed );
	check( 'validator: section without logic fine in Lite', $code( FormValidator::validate_schema( [ 'fields' => [ $f( 'a' ), $sec( null ) ] ] ) ), true, $passed, $failed );

	// ── validator: settings (confirmations) ───────────────────────────
	$schema = [ 'fields' => [ $f( 'a' ), $f( 'b' ) ] ];
	$ok_rules = [ 'on_success' => [ 'mode' => 'message', 'rules' => [ [ 'id' => 'r', 'conditions' => $rule_on( 'a' ), 'mode' => 'message', 'message' => 'hi', 'url' => '' ] ] ] ];
	pro( true );
	check( 'settings: rules ok (Pro)', $code( FormValidator::validate_settings_logic( $ok_rules, $schema ) ), true, $passed, $failed );
	check( 'settings: no rules → ok', $code( FormValidator::validate_settings_logic( [ 'on_success' => [ 'mode' => 'message' ] ], $schema ) ), true, $passed, $failed );
	$bad_ref = $ok_rules; $bad_ref['on_success']['rules'][0]['conditions'] = $rule_on( 'nope' );
	check( 'settings: bad ref → 422', $code( FormValidator::validate_settings_logic( $bad_ref, $schema ) ), 'invalid_form_data', $passed, $failed );
	$no_url = $ok_rules; $no_url['on_success']['rules'][0]['mode'] = 'redirect';
	check( 'settings: redirect without URL → 422', $code( FormValidator::validate_settings_logic( $no_url, $schema ) ), 'invalid_form_data', $passed, $failed );
	$no_cond = $ok_rules; $no_cond['on_success']['rules'][0]['conditions'] = [ 'enabled' => true, 'rules' => [] ];
	check( 'settings: rule without conditions → 422', $code( FormValidator::validate_settings_logic( $no_cond, $schema ) ), 'invalid_form_data', $passed, $failed );
	pro( false );
	check( 'settings: rules without Pro → locked', $code( FormValidator::validate_settings_logic( $ok_rules, $schema ) ), 'pro_feature_locked', $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
