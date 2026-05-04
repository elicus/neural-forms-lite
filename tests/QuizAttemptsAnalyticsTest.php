<?php
/**
 * Quiz Results — attempts + insights tests.
 *
 * Targets the new fields added to GET /forms/{id}/quiz:
 *   - attempts[] (latest 50, identity heuristic, per-question answers)
 *   - attempts_limit / attempts_total
 *   - insights[] (rule-based)
 *   - highest_score / lowest_score
 *   - pass_rate / passing_score (when show_pass_fail enabled)
 *
 * Run from the plugin root:
 *
 *   php tests/QuizAttemptsAnalyticsTest.php
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
if ( ! function_exists( 'is_wp_error' ) )    { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) )    { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )    { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $opts = 0, $d = 512 ) { return json_encode( $v, $opts, $d ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }
if ( ! function_exists( 'register_rest_route' ) )  { function register_rest_route() { return true; } }
if ( ! function_exists( 'current_time' ) )         { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! defined( 'NEURAL_FORMS_CAP_MANAGE_FORMS' ) ) { define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['__nf_user_can'] = true;
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return ! empty( $GLOBALS['__nf_user_can'] ); } }

class NF_Attempts_WPDB {
	public string $prefix = 'wp_';
	public array  $rows   = [];
	public function prepare( $sql, ...$args ) { return [ 'sql' => $sql, 'args' => $args ]; }
	public function get_col( $query ) {
		[ , $form_id, $status_filter ] = $query['args'];
		$out = [];
		foreach ( $this->rows as $r ) {
			if ( (int) $r['form_id'] !== (int) $form_id ) continue;
			if ( $r['status'] === $status_filter ) continue;
			$out[] = $r['data'];
		}
		return $out;
	}
	public function get_var( $query ) {
		[ , $form_id, $status_filter ] = $query['args'];
		$n = 0;
		foreach ( $this->rows as $r ) {
			if ( (int) $r['form_id'] !== (int) $form_id ) continue;
			if ( $r['status'] === $status_filter ) continue;
			$n++;
		}
		return $n;
	}
	public function get_results( $query, $output = null ) {
		[ , $form_id, $status_filter, $limit, $offset ] = $query['args'];
		$matches = [];
		foreach ( $this->rows as $r ) {
			if ( (int) $r['form_id'] !== (int) $form_id ) continue;
			if ( $r['status'] === $status_filter ) continue;
			$matches[] = [
				'id'           => $r['id'],
				'submitted_at' => $r['submitted_at'] ?? '2026-05-02 10:00:00',
				'data'         => $r['data'],
			];
		}
		usort( $matches, static fn( $a, $b ) => (int) $b['id'] - (int) $a['id'] );
		return array_slice( $matches, (int) $offset, (int) $limit );
	}
}
$GLOBALS['wpdb'] = new NF_Attempts_WPDB();

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

$GLOBALS['__nf_form_fixture'] = null;
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormRepository { public static function find( int $id ): ?array { return $GLOBALS["__nf_form_fixture"]; } }' );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		public array $a = [];
		public function offsetExists( $k ): bool   { return array_key_exists( $k, $this->a ); }
		#[\ReturnTypeWillChange]
		public function offsetGet( $k )            { return $this->a[ $k ] ?? null; }
		public function offsetSet( $k, $v ): void  { $this->a[ $k ] = $v; }
		public function offsetUnset( $k ): void    { unset( $this->a[ $k ] ); }
	}
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/PollsEndpoint.php';

use NeuralForms\Rest\Endpoints\PollsEndpoint;

$cases = [];
$passed = 0; $failed = 0;
function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

$endpoint = new PollsEndpoint();

// Quiz field factory.
$mk_quiz = function ( string $id, string $label, array $opts, int $points = 1 ) {
	return [
		'id'       => $id,
		'type'     => 'quiz_question',
		'label'    => $label,
		'options'  => $opts,
		'settings' => [
			'points'              => $points,
			'explanation'         => '',
			'show_correct_answer' => 'after_submit',
			'randomize_options'   => false,
		],
	];
};

$q_capital = $mk_quiz( 'quiz_capital', 'Capital of India?', [
	[ 'label' => 'Mumbai',    'value' => 'mumbai',    'is_correct' => false ],
	[ 'label' => 'New Delhi', 'value' => 'new_delhi', 'is_correct' => true  ],
	[ 'label' => 'Jaipur',    'value' => 'jaipur',    'is_correct' => false ],
], 1 );

$q_math = $mk_quiz( 'quiz_2plus2', '2 + 2?', [
	[ 'label' => '3', 'value' => '3', 'is_correct' => false ],
	[ 'label' => '4', 'value' => '4', 'is_correct' => true ],
], 2 );

$req = new WP_REST_Request();
$req['id'] = 12;

// ---------------------------------------------------------------------------
// 1. Empty quiz form (no entries) → empty attempts + "no responses" insight.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Empty', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital ] ],
	'settings' => [],
];
$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = [];
$res = $endpoint->get_all_quiz_results( $req );
check( '1a empty form attempts is empty array', $res['attempts'] ?? null, [], $cases, $passed, $failed );
check( '1b attempts_limit = 50',                $res['attempts_limit'] ?? null, 50, $cases, $passed, $failed );
check( '1c attempts_total = 0',                 $res['attempts_total'] ?? null, 0, $cases, $passed, $failed );
check( '1d highest_score = 0',                  $res['highest_score'] ?? null, 0, $cases, $passed, $failed );
check( '1e lowest_score = 0',                   $res['lowest_score']  ?? null, 0, $cases, $passed, $failed );
check( '1f insights has "no responses" entry',
	is_array( $res['insights'] ?? null ) && ( $res['insights'][0]['type'] ?? null ) === 'info', true,
	$cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Identity heuristic: email field wins.
// ---------------------------------------------------------------------------
$form_with_email = [
	'id' => 12, 'name' => 'Survey', 'status' => 'active',
	'form_schema' => [ 'fields' => [
		[ 'id' => 'email_field', 'type' => 'email', 'label' => 'Email' ],
		[ 'id' => 'name_field',  'type' => 'text',  'label' => 'Your name' ],
		$q_capital,
	] ],
	'settings' => [],
];
$GLOBALS['__nf_form_fixture'] = $form_with_email;
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:45:00',
	  'data' => json_encode( [ 'email_field' => 'john@example.com', 'name_field' => 'John Doe', 'quiz_capital' => 'new_delhi' ] ) ],
];
$res = $endpoint->get_all_quiz_results( $req );
check( '2 identity uses email field',  $res['attempts'][0]['identity'] ?? null, 'john@example.com', $cases, $passed, $failed );

// 2b. No email → name field (id contains "name").
$form_name_only = [
	'id' => 12, 'name' => 'F', 'status' => 'active',
	'form_schema' => [ 'fields' => [
		[ 'id' => 'first_name', 'type' => 'text', 'label' => 'First name' ],
		$q_capital,
	] ],
	'settings' => [],
];
$GLOBALS['__nf_form_fixture'] = $form_name_only;
$wpdb->rows = [
	[ 'id' => 5, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	  'data' => json_encode( [ 'first_name' => 'Ada Lovelace', 'quiz_capital' => 'new_delhi' ] ) ],
];
$res = $endpoint->get_all_quiz_results( $req );
check( '2b identity uses name field when no email', $res['attempts'][0]['identity'] ?? null, 'Ada Lovelace', $cases, $passed, $failed );

// 2c. Neither email nor name → "Entry #ID".
$form_anon = [
	'id' => 12, 'name' => 'F', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital ] ],
	'settings' => [],
];
$GLOBALS['__nf_form_fixture'] = $form_anon;
$wpdb->rows = [
	[ 'id' => 99, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	  'data' => json_encode( [ 'quiz_capital' => 'mumbai' ] ) ],
];
$res = $endpoint->get_all_quiz_results( $req );
check( '2c identity falls back to Entry #ID', $res['attempts'][0]['identity'] ?? null, 'Entry #99', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Per-attempt scoring matches submit-side semantics.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Q', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital, $q_math ] ],
	'settings' => [],
];
$wpdb->rows = [
	// Entry 1: capital correct (1), math correct (2) = 3/3 → 100%.
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	  'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '4' ] ) ],
	// Entry 2: capital correct (1), math wrong (0) = 1/3 → 33.33%.
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:30:00',
	  'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '3' ] ) ],
	// Entry 3: both wrong = 0/3 → 0%.
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 11:00:00',
	  'data' => json_encode( [ 'quiz_capital' => 'mumbai', 'quiz_2plus2' => '5' ] ) ],
];
$res = $endpoint->get_all_quiz_results( $req );

check( '3a attempts count = 3', count( $res['attempts'] ), 3, $cases, $passed, $failed );
check( '3b ordered newest first (entry 3 first)', $res['attempts'][0]['entry_id'] ?? null, 3, $cases, $passed, $failed );
// Latest-first → [entry_3, entry_2, entry_1].
check( '3c entry 1 score 3',     $res['attempts'][2]['score']      ?? null, 3, $cases, $passed, $failed );
check( '3d entry 1 percentage',  $res['attempts'][2]['percentage'] ?? null, 100.0, $cases, $passed, $failed );
check( '3e entry 2 score 1',     $res['attempts'][1]['score']      ?? null, 1, $cases, $passed, $failed );
check( '3f entry 2 percentage',  $res['attempts'][1]['percentage'] ?? null, 33.33, $cases, $passed, $failed );
check( '3g entry 3 score 0',     $res['attempts'][0]['score']      ?? null, 0, $cases, $passed, $failed );
check( '3h entry 3 percentage',  $res['attempts'][0]['percentage'] ?? null, 0.0,  $cases, $passed, $failed );

// 3i. Per-question answers structure.
$ans = $res['attempts'][2]['answers'] ?? [];
check( '3i answers length = 2',   count( $ans ), 2, $cases, $passed, $failed );
check( '3j first answer correct', $ans[0]['correct'] ?? null, true, $cases, $passed, $failed );
check( '3k first answer selected_label', $ans[0]['selected_label'] ?? null, 'New Delhi', $cases, $passed, $failed );
check( '3l first answer correct_label',  $ans[0]['correct_label']  ?? null, 'New Delhi', $cases, $passed, $failed );
check( '3m first answer points_awarded', $ans[0]['points_awarded'] ?? null, 1, $cases, $passed, $failed );

// 3n. correct_count / incorrect_count per attempt.
check( '3n entry 1 correct_count = 2', $res['attempts'][2]['correct_count'] ?? null, 2, $cases, $passed, $failed );
check( '3o entry 1 incorrect_count = 0', $res['attempts'][2]['incorrect_count'] ?? null, 0, $cases, $passed, $failed );
check( '3p entry 3 correct_count = 0', $res['attempts'][0]['correct_count'] ?? null, 0, $cases, $passed, $failed );
check( '3q entry 3 incorrect_count = 2', $res['attempts'][0]['incorrect_count'] ?? null, 2, $cases, $passed, $failed );

// 3r. Highest / lowest score across attempts.
check( '3r highest_score = 3', $res['highest_score'] ?? null, 3, $cases, $passed, $failed );
check( '3s lowest_score = 0',  $res['lowest_score']  ?? null, 0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. attempts_limit caps the response (50). Trash entries excluded.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Big', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital ] ],
	'settings' => [],
];
$bulk = [];
for ( $i = 1; $i <= 60; $i++ ) {
	$bulk[] = [
		'id' => $i, 'form_id' => 12,
		'status' => $i === 7 ? 'trash' : 'new', // one trashed
		'submitted_at' => sprintf( '2026-05-02 10:%02d:00', $i ),
		'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ),
	];
}
$wpdb->rows = $bulk;
$res = $endpoint->get_all_quiz_results( $req );
check( '4a attempts capped at 50', count( $res['attempts'] ), 50, $cases, $passed, $failed );
check( '4b attempts_limit echoed', $res['attempts_limit'] ?? null, 50, $cases, $passed, $failed );
check( '4c attempts_total excludes trash', $res['attempts_total'] ?? null, 59, $cases, $passed, $failed );
$ids = array_column( $res['attempts'], 'entry_id' );
check( '4d trash entry id 7 NOT in attempts', in_array( 7, $ids, true ), false, $cases, $passed, $failed );
check( '4e newest entry first', $res['attempts'][0]['entry_id'] ?? null, 60, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. show_pass_fail enabled → pass_rate + per-attempt passed flag.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'PF', 'status' => 'active',
	'form_schema' => [
		'fields' => [ $q_capital, $q_math ],
		'settings' => [ 'quiz' => [ 'show_pass_fail' => true, 'passing_score' => 60 ] ],
	],
	'settings' => [],
];
$wpdb->rows = [
	// All correct (3/3 = 100%) → passes.
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	  'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '4' ] ) ],
	// All wrong (0/3 = 0%) → fails.
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:30:00',
	  'data' => json_encode( [ 'quiz_capital' => 'mumbai', 'quiz_2plus2' => '3' ] ) ],
];
$res = $endpoint->get_all_quiz_results( $req );
check( '5a passing_score echoed', $res['passing_score'] ?? null, 60, $cases, $passed, $failed );
check( '5b pass_rate = 50.0',     $res['pass_rate']      ?? null, 50.0, $cases, $passed, $failed );
$by_id = [];
foreach ( $res['attempts'] as $a ) { $by_id[ $a['entry_id'] ] = $a; }
check( '5c entry 1 passed = true',  $by_id[1]['passed'] ?? null, true,  $cases, $passed, $failed );
check( '5d entry 2 passed = false', $by_id[2]['passed'] ?? null, false, $cases, $passed, $failed );

// 5e. show_pass_fail OFF → no pass_rate / passed keys.
$GLOBALS['__nf_form_fixture']['form_schema']['settings'] = [];
$res = $endpoint->get_all_quiz_results( $req );
check( '5e pass_rate omitted when show_pass_fail off',     array_key_exists( 'pass_rate', $res ),     false, $cases, $passed, $failed );
check( '5f passed key absent on attempts when off',         array_key_exists( 'passed', $res['attempts'][0] ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Insights — high correct rate, low correct rate, most-picked wrong.
// ---------------------------------------------------------------------------

// 6a. High overall correct rate (≥80%) → success insight.
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'High', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital ] ],
	'settings' => [],
];
$wpdb->rows = [];
for ( $i = 1; $i <= 9; $i++ ) {
	$wpdb->rows[] = [ 'id' => $i, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
		'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ) ];
}
$wpdb->rows[] = [ 'id' => 10, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	'data' => json_encode( [ 'quiz_capital' => 'mumbai' ] ) ];
$res = $endpoint->get_all_quiz_results( $req );
$types = array_column( $res['insights'] ?? [], 'type' );
check( '6a high correct rate triggers success insight',
	in_array( 'success', $types, true ), true, $cases, $passed, $failed );

// 6b. Low overall correct rate (<50%) → warning insight.
$wpdb->rows = [];
for ( $i = 1; $i <= 8; $i++ ) {
	$wpdb->rows[] = [ 'id' => $i, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
		'data' => json_encode( [ 'quiz_capital' => 'mumbai' ] ) ];
}
$wpdb->rows[] = [ 'id' => 9, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ) ];
$res = $endpoint->get_all_quiz_results( $req );
$types = array_column( $res['insights'] ?? [], 'type' );
check( '6b low correct rate triggers warning insight',
	in_array( 'warning', $types, true ), true, $cases, $passed, $failed );

// 6c. Most-picked wrong answer insight.
// Setup: 5 picks of mumbai (wrong), 2 picks of new_delhi (correct).
$wpdb->rows = [];
for ( $i = 1; $i <= 5; $i++ ) {
	$wpdb->rows[] = [ 'id' => $i, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
		'data' => json_encode( [ 'quiz_capital' => 'mumbai' ] ) ];
}
for ( $i = 6; $i <= 7; $i++ ) {
	$wpdb->rows[] = [ 'id' => $i, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
		'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ) ];
}
$res = $endpoint->get_all_quiz_results( $req );
$titles = array_column( $res['insights'] ?? [], 'title' );
check( '6c "Most-picked wrong answer" insight present',
	in_array( 'Most-picked wrong answer', $titles, true ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Unknown selected values do not fatal.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Tampered', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital ] ],
	'settings' => [],
];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'submitted_at' => '2026-05-02 10:00:00',
	  'data' => json_encode( [ 'quiz_capital' => 'tampered_value' ] ) ],
];
$res = $endpoint->get_all_quiz_results( $req );
check( '7a unknown value does not fatal',           is_array( $res ), true, $cases, $passed, $failed );
check( '7b attempt has 1 entry',                    count( $res['attempts'] ), 1, $cases, $passed, $failed );
check( '7c selected_label key present + value null',
	array_key_exists( 'selected_label', $res['attempts'][0]['answers'][0] )
		&& $res['attempts'][0]['answers'][0]['selected_label'] === null,
	true, $cases, $passed, $failed );
check( '7d selected_value preserved as raw',        $res['attempts'][0]['answers'][0]['selected_value'] ?? null, 'tampered_value', $cases, $passed, $failed );
check( '7e correct = false on unknown',             $res['attempts'][0]['answers'][0]['correct'] ?? null, false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
foreach ( $cases as $row ) {
	[ $ok, $label, $actual, $expected ] = $row;
	if ( $ok ) {
		echo "\033[32mPASS\033[0m  $label\n";
	} else {
		echo "\033[31mFAIL\033[0m  $label\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed === 0 ? 0 : 1 );
