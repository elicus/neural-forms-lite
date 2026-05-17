<?php
/**
 * Quiz Results endpoint tests (Phase 2).
 *
 * Covers:
 *   - permission gate
 *   - 404 when form missing
 *   - empty `questions[]` for forms without quiz fields
 *   - per-question correct_answers / incorrect_answers / correct_percentage
 *   - per-option `is_correct` echoed + `Other` bucket for unknown values
 *   - average_score, average_percentage, max_score
 *   - excludes trashed entries
 *   - malformed entries do not fatal
 *   - polls + quiz coexist on the same form
 *   - GET /forms enrichment includes has_quiz + quiz_count
 *
 * Run from the plugin root:
 *
 *   php tests/QuizResultsEndpointTest.php
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
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } }
if ( ! function_exists( 'current_time' ) )    { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }
if ( ! function_exists( 'register_rest_route' ) )  { function register_rest_route() { return true; } }
if ( ! defined( 'NEURAL_FORMS_CAP_MANAGE_FORMS' ) ) {
	define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' );
}

$GLOBALS['__nf_user_can'] = false;
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return ! empty( $GLOBALS['__nf_user_can'] ); }
}

// $wpdb shim covering both aggregate_quiz_answers AND count_form_entries.
class NF_Quiz_Endpoint_WPDB {
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
		// Pattern: SELECT id, submitted_at, data FROM ... WHERE form_id = %d AND status != %s ORDER BY id DESC LIMIT %d OFFSET %d
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
		// id DESC
		usort( $matches, static fn( $a, $b ) => (int) $b['id'] - (int) $a['id'] );
		return array_slice( $matches, (int) $offset, (int) $limit );
	}
}
$GLOBALS['wpdb'] = new NF_Quiz_Endpoint_WPDB();

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';

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

require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/PollsEndpoint.php';
require_once $plugin_root . '/modules/rest/endpoints/FormsEndpoint.php';

use NeuralForms\Rest\Endpoints\PollsEndpoint;
use NeuralForms\Rest\Endpoints\FormsEndpoint;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

$endpoint = new PollsEndpoint();

// Fixture quiz field factory.
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

// ---------------------------------------------------------------------------
// 1. Permission gate.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_user_can'] = false;
check( '1a permission denied without cap', $endpoint->check_permission(), false, $cases, $passed, $failed );
$GLOBALS['__nf_user_can'] = true;
check( '1b permission allowed with cap',   $endpoint->check_permission(), true,  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Form not found -> 404.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = null;
$req['id'] = 99;
$res404 = $endpoint->get_all_quiz_results( $req );
check( '2a missing form returns WP_Error',  is_wp_error( $res404 ), true, $cases, $passed, $failed );
check( '2b error code form_not_found',
	$res404 instanceof WP_Error ? $res404->get_error_code() : null, 'form_not_found', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Form with no quiz fields returns empty questions[] (200).
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id'          => 12,
	'name'        => 'Plain',
	'status'      => 'active',
	'form_schema' => [ 'fields' => [
		[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
	] ],
	'settings'    => [],
];
$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'name' => 'Ada' ] ) ],
];
$req['id'] = 12;
$res_empty = $endpoint->get_all_quiz_results( $req );
check( '3a no-quiz form returns 200 shape',     is_array( $res_empty ),                  true,    $cases, $passed, $failed );
check( '3b form_id echoed',                     $res_empty['form_id'] ?? null,           12,      $cases, $passed, $failed );
check( '3c form_name echoed',                   $res_empty['form_name'] ?? null,         'Plain', $cases, $passed, $failed );
check( '3d quiz_count is 0',                    $res_empty['quiz_count'] ?? null,        0,       $cases, $passed, $failed );
check( '3e questions empty',                    $res_empty['questions'] ?? null,         [],      $cases, $passed, $failed );
check( '3f total_attempts 0',                   $res_empty['total_attempts'] ?? null,    0,       $cases, $passed, $failed );
check( '3g max_score 0',                        $res_empty['max_score'] ?? null,         0,       $cases, $passed, $failed );
check( '3h average_score 0',                    $res_empty['average_score'] ?? null,     0.0,     $cases, $passed, $failed );
check( '3i average_percentage 0',               $res_empty['average_percentage'] ?? null, 0.0,    $cases, $passed, $failed );
check( '3j total_entries reflects DB',          $res_empty['total_entries'] ?? null,     1,       $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Form with two quiz questions: counts + percentages + averages.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id'          => 12,
	'name'        => 'Quiz Form',
	'status'      => 'active',
	'form_schema' => [ 'fields' => [
		$q_capital,
		[
			'id' => 'row_1', 'type' => 'row',
			'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
			'columns' => [ [ 'id' => 'col_1', 'fields' => [ $q_math ] ] ],
		],
	] ],
	'settings' => [],
];

$wpdb->rows = [
	// Entry 1: q_capital correct (1pt), q_math correct (2pt) = 3
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new',
	  'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '4' ] ) ],
	// Entry 2: q_capital correct (1pt), q_math wrong (0pt) = 1
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new',
	  'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '3' ] ) ],
	// Entry 3: q_capital wrong, q_math missing = 0
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new',
	  'data' => json_encode( [ 'quiz_capital' => 'mumbai' ] ) ],
	// Entry 4: trashed (excluded)
	[ 'id' => 4, 'form_id' => 12, 'status' => 'trash',
	  'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '4' ] ) ],
];

$res = $endpoint->get_all_quiz_results( $req );

check( '4a quiz_count = 2',                     $res['quiz_count'] ?? null,          2, $cases, $passed, $failed );
check( '4b max_score = 3',                      $res['max_score']  ?? null,          3, $cases, $passed, $failed );
check( '4c total_attempts excludes trash (3)',  $res['total_attempts'] ?? null,      3, $cases, $passed, $failed );
check( '4d total_entries reflects non-trash',   $res['total_entries']  ?? null,      3, $cases, $passed, $failed );

// average_score = (3 + 1 + 0) / 3 = 1.33
check( '4e average_score',                      $res['average_score'] ?? null,       1.33, $cases, $passed, $failed );
// average_percentage = (3+1+0) / (3 * 3) * 100 = 44.44
check( '4f average_percentage',                 $res['average_percentage'] ?? null,  44.44, $cases, $passed, $failed );

// Per-question validation.
$qcap = $res['questions'][0] ?? [];
$qmth = $res['questions'][1] ?? [];
check( '5a question order preserved',           array_column( $res['questions'], 'field_id' ),
	[ 'quiz_capital', 'quiz_2plus2' ], $cases, $passed, $failed );

check( '5b q_capital total_answers = 3',        $qcap['total_answers']      ?? null, 3, $cases, $passed, $failed );
check( '5c q_capital correct_answers = 2',      $qcap['correct_answers']    ?? null, 2, $cases, $passed, $failed );
check( '5d q_capital incorrect_answers = 1',    $qcap['incorrect_answers']  ?? null, 1, $cases, $passed, $failed );
check( '5e q_capital correct_percentage = 66.67', $qcap['correct_percentage']?? null, 66.67, $cases, $passed, $failed );
check( '5f q_capital points_possible = 1',      $qcap['points_possible']    ?? null, 1, $cases, $passed, $failed );

check( '5g q_math total_answers = 2',           $qmth['total_answers']      ?? null, 2, $cases, $passed, $failed );
check( '5h q_math correct_answers = 1',         $qmth['correct_answers']    ?? null, 1, $cases, $passed, $failed );
check( '5i q_math points_possible = 2',         $qmth['points_possible']    ?? null, 2, $cases, $passed, $failed );

// Per-option breakdown.
$cap_opts = $qcap['options'] ?? [];
check( '6a q_capital option count = 3',         count( $cap_opts ),                     3, $cases, $passed, $failed );
check( '6b option order preserved',             array_column( $cap_opts, 'value' ),
	[ 'mumbai', 'new_delhi', 'jaipur' ], $cases, $passed, $failed );
check( '6c new_delhi is_correct = true',        $cap_opts[1]['is_correct'] ?? null,     true, $cases, $passed, $failed );
check( '6d new_delhi answers = 2',              $cap_opts[1]['answers']    ?? null,     2,    $cases, $passed, $failed );
check( '6e new_delhi percentage = 66.67',       $cap_opts[1]['percentage'] ?? null,     66.67, $cases, $passed, $failed );
check( '6f mumbai answers = 1',                 $cap_opts[0]['answers']    ?? null,     1,    $cases, $passed, $failed );
check( '6g jaipur answers = 0 (still emitted)', $cap_opts[2]['answers']    ?? null,     0,    $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Unknown values collapse into "__other__".
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Q', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $q_capital ] ], 'settings' => [],
];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'quiz_capital' => 'removed_option' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'quiz_capital' => 'gone_too' ] ) ],
];
$res_other = $endpoint->get_all_quiz_results( $req );
$other_opts = $res_other['questions'][0]['options'] ?? [];
$other = array_values( array_filter( $other_opts, static fn ( $o ) => $o['value'] === '__other__' ) );
check( '7a __other__ row present',              count( $other ),               1, $cases, $passed, $failed );
check( '7b __other__ collapses unknown values', $other[0]['answers'] ?? null,  2, $cases, $passed, $failed );
check( '7c __other__ percentage (2/3)',         $other[0]['percentage'] ?? null, 66.67, $cases, $passed, $failed );
check( '7d __other__ is_correct = false',       $other[0]['is_correct'] ?? null, false, $cases, $passed, $failed );

// 7e. Other omitted when no unknowns.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ) ],
];
$res_no_other = $endpoint->get_all_quiz_results( $req );
$values_no_other = array_column( $res_no_other['questions'][0]['options'] ?? [], 'value' );
check( '7e __other__ omitted when no unknowns',
	in_array( '__other__', $values_no_other, true ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Malformed entries do not fatal.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => 'not-json' ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => '[]' ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'quiz_capital' => 'new_delhi' ] ) ],
];
$res_mal = $endpoint->get_all_quiz_results( $req );
check( '8a malformed entries do not fatal',     is_array( $res_mal ),                              true, $cases, $passed, $failed );
check( '8b only valid row counted',             $res_mal['questions'][0]['correct_answers'] ?? null, 1,  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. Quiz inside step is reachable.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Step', 'status' => 'active',
	'form_schema' => [ 'fields' => [
		[
			'id' => 'step_1', 'type' => 'step', 'title' => 'X',
			'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
			'fields' => [ $q_capital, $q_math ],
		],
	] ],
	'settings' => [],
];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '4' ] ) ],
];
$res_step = $endpoint->get_all_quiz_results( $req );
check( '9a step polls reached', array_column( $res_step['questions'] ?? [], 'field_id' ),
	[ 'quiz_capital', 'quiz_2plus2' ], $cases, $passed, $failed );
check( '9b step max_score 3',   $res_step['max_score'] ?? null, 3, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. Polls + quiz coexist on the same form (cross-section sanity check).
// ---------------------------------------------------------------------------
$poll_field = [
	'id' => 'poll_x', 'type' => 'poll', 'label' => 'P?',
	'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ],
	'settings' => [ 'results_visibility' => 'after_vote', 'chart_type' => 'bar' ],
];
$GLOBALS['__nf_form_fixture'] = [
	'id' => 12, 'name' => 'Mixed', 'status' => 'active',
	'form_schema' => [ 'fields' => [ $poll_field, $q_capital ] ],
	'settings' => [],
];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'a', 'quiz_capital' => 'new_delhi' ] ) ],
];
$res_mix_quiz = $endpoint->get_all_quiz_results( $req );
$res_mix_poll = $endpoint->get_all_poll_results( $req );
check( '10a quiz endpoint sees only quiz',  array_column( $res_mix_quiz['questions'] ?? [], 'field_id' ),
	[ 'quiz_capital' ], $cases, $passed, $failed );
check( '10b poll endpoint sees only poll',  array_column( $res_mix_poll['polls'] ?? [], 'field_id' ),
	[ 'poll_x' ], $cases, $passed, $failed );
check( '10c quiz_count = 1',                $res_mix_quiz['quiz_count'] ?? null, 1, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. GET /forms enrichment includes has_quiz + quiz_count.
// ---------------------------------------------------------------------------
$ref = new ReflectionMethod( FormsEndpoint::class, 'quiz_count_for_schema' );
$ref->setAccessible( true );

check( '11a quiz_count null -> 0',                  $ref->invoke( null, null ), 0, $cases, $passed, $failed );
check( '11b quiz_count root -> 1',                  $ref->invoke( null, [ 'fields' => [ $q_capital ] ] ), 1, $cases, $passed, $failed );
check( '11c quiz_count row.column -> 1',            $ref->invoke( null, [ 'fields' => [
	[
		'id' => 'row_1', 'type' => 'row',
		'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
		'columns' => [ [ 'id' => 'col_1', 'fields' => [ $q_capital ] ] ],
	],
] ] ), 1, $cases, $passed, $failed );
check( '11d quiz_count step -> 2',                  $ref->invoke( null, [ 'fields' => [
	[
		'id' => 'step_1', 'type' => 'step', 'title' => 'X',
		'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
		'fields' => [ $q_capital, $q_math ],
	],
] ] ), 2, $cases, $passed, $failed );

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
