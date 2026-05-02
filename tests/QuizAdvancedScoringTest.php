<?php
/**
 * Quiz advanced scoring tests (Phase 3).
 *
 * Covers:
 *   - SchemaHelper::normalize_quiz_form_settings (clamping + sanitisation)
 *   - SubmitEndpoint::build_quiz_result_payload extension:
 *       - show_pass_fail off keeps Phase 1/2 shape verbatim
 *       - show_pass_fail on adds passed + passing_score
 *       - percentage >= passing_score -> passed=true
 *       - percentage <  passing_score -> passed=false
 *       - matching result_message included; non-matching omitted
 *       - invalid ranges do not fatal
 *       - weighted option.points awards selected option's points
 *       - no option.points falls back to Phase 1 correct/incorrect scoring
 *       - max_score honors weighted option max
 *
 * Run from the plugin root:
 *
 *   php tests/QuizAdvancedScoringTest.php
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() { return true; } }

// Stubs for SubmitEndpoint imports.
if ( ! class_exists( 'NeuralForms\\Captcha\\CaptchaManager' ) ) {
	eval( 'namespace NeuralForms\\Captcha; class CaptchaManager {}' );
}
if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormRepository { public static function find( int $id ): ?array { return null; } }' );
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {}
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';
require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/SubmitEndpoint.php';

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Rest\Endpoints\SubmitEndpoint;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

$endpoint = new SubmitEndpoint();
$ref      = new ReflectionMethod( $endpoint, 'build_quiz_result_payload' );
$ref->setAccessible( true );
$call = function ( $schema, array $data ) use ( $ref, $endpoint ) {
	return $ref->invoke( $endpoint, $schema, $data );
};

// Helpers.
$mk_quiz = function ( string $id, string $label, array $opts, int $points = 1, string $show = 'after_submit' ) {
	return [
		'id'       => $id,
		'type'     => 'quiz_question',
		'label'    => $label,
		'options'  => $opts,
		'settings' => [
			'points'              => $points,
			'explanation'         => '',
			'show_correct_answer' => $show,
			'randomize_options'   => false,
		],
	];
};

// ---------------------------------------------------------------------------
// 1. normalize_quiz_form_settings: defaults + clamping.
// ---------------------------------------------------------------------------
$default = SchemaHelper::normalize_quiz_form_settings( null );
check( '1a defaults: show_pass_fail',  $default['show_pass_fail'],  false, $cases, $passed, $failed );
check( '1b defaults: passing_score',   $default['passing_score'],   70,    $cases, $passed, $failed );
check( '1c defaults: result_messages', $default['result_messages'], [],    $cases, $passed, $failed );

// 1d. passing_score clamps 0..100.
check( '1d passing_score 150 -> 100',  SchemaHelper::normalize_quiz_form_settings( [ 'passing_score' => 150 ] )['passing_score'], 100, $cases, $passed, $failed );
check( '1e passing_score -10 -> 0',    SchemaHelper::normalize_quiz_form_settings( [ 'passing_score' => -10 ] )['passing_score'], 0,   $cases, $passed, $failed );
check( '1f passing_score "75" -> 75',  SchemaHelper::normalize_quiz_form_settings( [ 'passing_score' => '75' ] )['passing_score'], 75, $cases, $passed, $failed );
check( '1g passing_score "abc" -> 70', SchemaHelper::normalize_quiz_form_settings( [ 'passing_score' => 'abc' ] )['passing_score'], 70, $cases, $passed, $failed );

// 1h. show_pass_fail strict bool.
check( '1h show_pass_fail truthy',
	SchemaHelper::normalize_quiz_form_settings( [ 'show_pass_fail' => '1' ] )['show_pass_fail'], true, $cases, $passed, $failed );

// 1i. result_messages: empty title+message dropped.
$rm_in = [
	[ 'min' => 0, 'max' => 49, 'title' => '',     'message' => '' ],         // dropped
	[ 'min' => 50, 'max' => 79, 'title' => 'Mid', 'message' => '' ],         // kept
	[ 'min' => 80, 'max' => 100, 'title' => '',   'message' => 'Top' ],     // kept
];
$norm = SchemaHelper::normalize_quiz_form_settings( [ 'result_messages' => $rm_in ] );
check( '1i empty rows dropped', count( $norm['result_messages'] ), 2, $cases, $passed, $failed );

// 1j. min > max -> dropped.
$rm_bad = [ [ 'min' => 90, 'max' => 10, 'title' => 'Bad', 'message' => 'no' ] ];
$norm_bad = SchemaHelper::normalize_quiz_form_settings( [ 'result_messages' => $rm_bad ] );
check( '1j min>max dropped', count( $norm_bad['result_messages'] ), 0, $cases, $passed, $failed );

// 1k. min/max clamp to 0..100.
$rm_clamp = [ [ 'min' => -50, 'max' => 200, 'title' => 'C', 'message' => '' ] ];
$norm_clamp = SchemaHelper::normalize_quiz_form_settings( [ 'result_messages' => $rm_clamp ] );
check( '1k min clamps to 0',   $norm_clamp['result_messages'][0]['min'], 0,   $cases, $passed, $failed );
check( '1l max clamps to 100', $norm_clamp['result_messages'][0]['max'], 100, $cases, $passed, $failed );

// 1m. Invalid ranges + valid coexist; invalid filtered, valid kept.
$rm_mix = [
	[ 'min' => 90, 'max' => 10, 'title' => 'X', 'message' => 'no' ],   // invalid range
	[ 'min' => 0,  'max' => 100, 'title' => 'OK', 'message' => 'yep' ], // valid
];
$norm_mix = SchemaHelper::normalize_quiz_form_settings( [ 'result_messages' => $rm_mix ] );
check( '1m invalid range filtered',  count( $norm_mix['result_messages'] ), 1, $cases, $passed, $failed );
check( '1n valid range kept',        $norm_mix['result_messages'][0]['title'], 'OK', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Phase-1/2 shape preserved when show_pass_fail is OFF (default).
// ---------------------------------------------------------------------------
$opts = [
	[ 'label' => 'A', 'value' => 'a', 'is_correct' => false ],
	[ 'label' => 'B', 'value' => 'b', 'is_correct' => true  ],
];
$schema_legacy = [ 'fields' => [ $mk_quiz( 'q1', 'Q?', $opts, 1 ) ] ];
$res = $call( $schema_legacy, [ 'q1' => 'b' ] );
check( '2a passed key absent (show_pass_fail off)',
	array_key_exists( 'passed', $res ),        false, $cases, $passed, $failed );
check( '2b passing_score key absent',
	array_key_exists( 'passing_score', $res ), false, $cases, $passed, $failed );
check( '2c result_message key absent',
	array_key_exists( 'result_message', $res ), false, $cases, $passed, $failed );
check( '2d score still 1',  $res['score'] ?? null, 1, $cases, $passed, $failed );
check( '2e percentage 100', $res['percentage'] ?? null, 100.0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. show_pass_fail ON: passed + passing_score included.
// ---------------------------------------------------------------------------
$schema_pf = [
	'fields' => [ $mk_quiz( 'q1', 'Q?', $opts, 1 ) ],
	'settings' => [ 'quiz' => [ 'show_pass_fail' => true, 'passing_score' => 70 ] ],
];
$res = $call( $schema_pf, [ 'q1' => 'b' ] );
check( '3a passed = true (100% >= 70%)', $res['passed']       ?? null, true,  $cases, $passed, $failed );
check( '3b passing_score = 70',          $res['passing_score'] ?? null, 70,    $cases, $passed, $failed );

// 3c. Wrong answer -> percentage 0 -> failed.
$res_fail = $call( $schema_pf, [ 'q1' => 'a' ] );
check( '3c failed = false (0% < 70%)',   $res_fail['passed']  ?? null, false, $cases, $passed, $failed );

// 3d. Edge: percentage exactly equals passing -> passed = true.
$schema_edge = [
	'fields' => [
		$mk_quiz( 'q1', 'Q1', $opts, 1 ),
		$mk_quiz( 'q2', 'Q2', $opts, 1 ),
	],
	'settings' => [ 'quiz' => [ 'show_pass_fail' => true, 'passing_score' => 50 ] ],
];
$res_edge = $call( $schema_edge, [ 'q1' => 'b', 'q2' => 'a' ] ); // 1/2 = 50%
check( '3d edge case 50% == 50% -> passed', $res_edge['passed'] ?? null, true, $cases, $passed, $failed );

// 3e. passing_score clamped on input.
$schema_clamp = [
	'fields' => [ $mk_quiz( 'q1', 'Q?', $opts, 1 ) ],
	'settings' => [ 'quiz' => [ 'show_pass_fail' => true, 'passing_score' => 200 ] ],
];
$res_clamp = $call( $schema_clamp, [ 'q1' => 'b' ] );
check( '3e passing_score clamps to 100',  $res_clamp['passing_score'] ?? null, 100,  $cases, $passed, $failed );
check( '3f 100% >= 100% -> passed',       $res_clamp['passed']        ?? null, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Result messages: matching range included, non-matching omitted.
// ---------------------------------------------------------------------------
$schema_rm = [
	'fields' => [
		$mk_quiz( 'q1', 'Q1', $opts, 1 ),
		$mk_quiz( 'q2', 'Q2', $opts, 1 ),
	],
	'settings' => [ 'quiz' => [
		'show_pass_fail' => false,
		'result_messages' => [
			[ 'min' => 0,  'max' => 49,  'title' => 'Low',  'message' => 'try again' ],
			[ 'min' => 50, 'max' => 79,  'title' => 'Mid',  'message' => 'good' ],
			[ 'min' => 80, 'max' => 100, 'title' => 'High', 'message' => 'great' ],
		],
	] ],
];
// 100% -> High
$res_high = $call( $schema_rm, [ 'q1' => 'b', 'q2' => 'b' ] );
check( '4a 100% matches High range', $res_high['result_message']['title'] ?? null, 'High', $cases, $passed, $failed );

// 50% -> Mid
$res_mid = $call( $schema_rm, [ 'q1' => 'b', 'q2' => 'a' ] );
check( '4b 50% matches Mid range',   $res_mid['result_message']['title'] ?? null, 'Mid', $cases, $passed, $failed );

// 0% -> Low
$res_low = $call( $schema_rm, [ 'q1' => 'a', 'q2' => 'a' ] );
check( '4c 0% matches Low range',    $res_low['result_message']['title'] ?? null, 'Low', $cases, $passed, $failed );

// 4d. Empty result_messages -> no result_message key.
$schema_empty = [
	'fields' => [ $mk_quiz( 'q1', 'Q1', $opts, 1 ) ],
	'settings' => [ 'quiz' => [ 'result_messages' => [] ] ],
];
$res_empty = $call( $schema_empty, [ 'q1' => 'b' ] );
check( '4d no message -> result_message key omitted',
	array_key_exists( 'result_message', $res_empty ), false, $cases, $passed, $failed );

// 4e. Gap range: 100% with message ranges 0..49 + 50..79 -> no match.
$schema_gap = [
	'fields' => [ $mk_quiz( 'q1', 'Q1', $opts, 1 ) ],
	'settings' => [ 'quiz' => [
		'result_messages' => [
			[ 'min' => 0,  'max' => 49, 'title' => 'Low', 'message' => 'l' ],
			[ 'min' => 50, 'max' => 79, 'title' => 'Mid', 'message' => 'm' ],
		],
	] ],
];
$res_gap = $call( $schema_gap, [ 'q1' => 'b' ] ); // 100% -> no range matches
check( '4e gap range -> no match -> key omitted',
	array_key_exists( 'result_message', $res_gap ), false, $cases, $passed, $failed );

// 4f. Result message + show_pass_fail combine.
$schema_combo = [
	'fields' => [ $mk_quiz( 'q1', 'Q1', $opts, 1 ) ],
	'settings' => [ 'quiz' => [
		'show_pass_fail' => true,
		'passing_score'  => 50,
		'result_messages' => [ [ 'min' => 80, 'max' => 100, 'title' => 'Top', 'message' => 'yay' ] ],
	] ],
];
$res_combo = $call( $schema_combo, [ 'q1' => 'b' ] );
check( '4f passed + result_message coexist',
	[ $res_combo['passed'] ?? null, $res_combo['result_message']['title'] ?? null ],
	[ true, 'Top' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Invalid ranges (in raw input) do not fatal — handled by normalizer
//    upstream of the payload helper, so the payload still computes.
// ---------------------------------------------------------------------------
$schema_bad = [
	'fields' => [ $mk_quiz( 'q1', 'Q1', $opts, 1 ) ],
	'settings' => [ 'quiz' => [
		'result_messages' => [
			[ 'min' => 90, 'max' => 10, 'title' => 'Bad', 'message' => 'inv' ],
		],
	] ],
];
$res_bad = $call( $schema_bad, [ 'q1' => 'b' ] );
check( '5a invalid range silently dropped',
	array_key_exists( 'result_message', $res_bad ), false, $cases, $passed, $failed );
check( '5b score still computed',  $res_bad['score']      ?? null, 1, $cases, $passed, $failed );
check( '5c percentage still 100',  $res_bad['percentage'] ?? null, 100.0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Weighted scoring: option.points awards selected option's points.
// ---------------------------------------------------------------------------
$weighted_opts = [
	[ 'label' => 'A', 'value' => 'a', 'is_correct' => false, 'points' => 0 ],
	[ 'label' => 'B', 'value' => 'b', 'is_correct' => false, 'points' => 1 ], // partial
	[ 'label' => 'C', 'value' => 'c', 'is_correct' => true,  'points' => 3 ], // full
];
$schema_w = [ 'fields' => [ $mk_quiz( 'qw', 'W?', $weighted_opts, 1 ) ] ];

// 6a. Selecting partial-credit option awards 1.
$res_w_partial = $call( $schema_w, [ 'qw' => 'b' ] );
check( '6a partial credit awarded',         $res_w_partial['score']           ?? null, 1, $cases, $passed, $failed );
check( '6b max_score uses option max (3)',  $res_w_partial['max_score']        ?? null, 3, $cases, $passed, $failed );
check( '6c correct still false',            $res_w_partial['questions'][0]['correct'] ?? null, false, $cases, $passed, $failed );
check( '6d points_awarded = 1',             $res_w_partial['questions'][0]['points_awarded']  ?? null, 1, $cases, $passed, $failed );
check( '6e points_possible = 3',            $res_w_partial['questions'][0]['points_possible'] ?? null, 3, $cases, $passed, $failed );

// 6f. Selecting full-credit option awards 3.
$res_w_full = $call( $schema_w, [ 'qw' => 'c' ] );
check( '6f full credit awarded',            $res_w_full['score']     ?? null, 3, $cases, $passed, $failed );
check( '6g 100% percentage',                $res_w_full['percentage']?? null, 100.0, $cases, $passed, $failed );
check( '6h correct true on weighted full',  $res_w_full['questions'][0]['correct'] ?? null, true, $cases, $passed, $failed );

// 6i. Selecting zero-credit option awards 0.
$res_w_zero = $call( $schema_w, [ 'qw' => 'a' ] );
check( '6i zero credit awarded',            $res_w_zero['score']     ?? null, 0, $cases, $passed, $failed );
check( '6j 0% percentage',                  $res_w_zero['percentage']?? null, 0.0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. No option.points -> Phase 1 semantics (correct/incorrect).
// ---------------------------------------------------------------------------
$plain_opts = [
	[ 'label' => 'A', 'value' => 'a', 'is_correct' => false ],
	[ 'label' => 'B', 'value' => 'b', 'is_correct' => true  ],
];
$schema_plain = [ 'fields' => [ $mk_quiz( 'qp', 'P?', $plain_opts, 5 ) ] ];

$res_p_correct = $call( $schema_plain, [ 'qp' => 'b' ] );
check( '7a no weighted -> correct = 5',   $res_p_correct['score']                         ?? null, 5, $cases, $passed, $failed );
check( '7b max_score from field.settings.points (5)', $res_p_correct['max_score']         ?? null, 5, $cases, $passed, $failed );
check( '7c points_possible = 5',          $res_p_correct['questions'][0]['points_possible'] ?? null, 5, $cases, $passed, $failed );

$res_p_wrong = $call( $schema_plain, [ 'qp' => 'a' ] );
check( '7d no weighted -> wrong = 0',     $res_p_wrong['score'] ?? null, 0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Mix: weighted question alongside plain question.
// ---------------------------------------------------------------------------
$schema_mix = [ 'fields' => [
	$mk_quiz( 'qw', 'W?', $weighted_opts, 1 ),
	$mk_quiz( 'qp', 'P?', $plain_opts, 2 ),
] ];

// qw=c (3), qp=b (2) = 5/5 -> 100%
$res_mix_full = $call( $schema_mix, [ 'qw' => 'c', 'qp' => 'b' ] );
check( '8a mixed: weighted+plain max_score',  $res_mix_full['max_score']  ?? null, 5,    $cases, $passed, $failed );
check( '8b mixed: 100% score',                $res_mix_full['score']      ?? null, 5,    $cases, $passed, $failed );
check( '8c mixed: 100% percentage',           $res_mix_full['percentage'] ?? null, 100.0, $cases, $passed, $failed );

// qw=b (1), qp=a (0) = 1/5 -> 20%
$res_mix_mix = $call( $schema_mix, [ 'qw' => 'b', 'qp' => 'a' ] );
check( '8d mixed: 1/5 score',                 $res_mix_mix['score']      ?? null, 1,    $cases, $passed, $failed );
check( '8e mixed: 20% percentage',            $res_mix_mix['percentage'] ?? null, 20.0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. show_pass_fail off but result_messages set -> message still fires.
// ---------------------------------------------------------------------------
$schema_only_rm = [
	'fields' => [ $mk_quiz( 'q1', 'Q?', $opts, 1 ) ],
	'settings' => [ 'quiz' => [
		'show_pass_fail'  => false,
		'result_messages' => [ [ 'min' => 0, 'max' => 100, 'title' => 'Done', 'message' => 'thanks' ] ],
	] ],
];
$res_only_rm = $call( $schema_only_rm, [ 'q1' => 'a' ] );
check( '9a result_message fires without show_pass_fail',
	$res_only_rm['result_message']['title'] ?? null, 'Done', $cases, $passed, $failed );
check( '9b passed key still absent',
	array_key_exists( 'passed', $res_only_rm ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. No quiz fields -> null payload regardless of quiz form settings.
// ---------------------------------------------------------------------------
$schema_no_quiz = [
	'fields' => [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ],
	'settings' => [ 'quiz' => [ 'show_pass_fail' => true, 'passing_score' => 70 ] ],
];
check( '10 no quiz -> null payload',
	$call( $schema_no_quiz, [ 'name' => 'Ada' ] ), null, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. max_score = 0 with show_pass_fail on -> percentage 0, passed = false (0 < 70).
// ---------------------------------------------------------------------------
$zero_pts_opts = [
	[ 'label' => 'A', 'value' => 'a', 'is_correct' => false ],
	[ 'label' => 'B', 'value' => 'b', 'is_correct' => true  ],
];
$schema_zero = [
	'fields' => [ $mk_quiz( 'qz', 'Z?', $zero_pts_opts, 0 ) ],
	'settings' => [ 'quiz' => [ 'show_pass_fail' => true, 'passing_score' => 70 ] ],
];
$res_zero = $call( $schema_zero, [ 'qz' => 'b' ] );
check( '11a max_score = 0',          $res_zero['max_score']  ?? null, 0,   $cases, $passed, $failed );
check( '11b percentage = 0',         $res_zero['percentage'] ?? null, 0.0, $cases, $passed, $failed );
check( '11c failed (0 < 70)',        $res_zero['passed']     ?? null, false, $cases, $passed, $failed );

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
