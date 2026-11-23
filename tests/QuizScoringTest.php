<?php
/**
 * Quiz scoring tests (Phase 1).
 *
 * Exercises SubmitEndpoint::build_quiz_result_payload() via reflection,
 * the same way PollPublicResultsTest exercises build_poll_results_payload.
 *
 *   - all-correct -> 100%
 *   - all-wrong -> 0%
 *   - mixed -> partial
 *   - max_score === 0 -> percentage 0 (no /0)
 *   - missing/optional answer -> 0 awarded for that question
 *   - show_correct_answer === 'never' strips correct_value /
 *     correct_label / explanation while keeping the verdict + score
 *   - quiz absent from schema -> null payload (response omits `quiz`)
 *   - quiz inside step / row.column reachable
 *
 * Run from the plugin root:
 *
 *   php tests/QuizScoringTest.php
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() { return true; } }

// Stubs / shim classes the SubmitEndpoint file imports.
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
$call     = function ( $schema, array $data ) use ( $ref, $endpoint ) {
	return $ref->invoke( $endpoint, $schema, $data );
};

// Fixtures.
$mk_quiz = function ( string $id, string $label, array $opts, int $points = 1, string $show = 'after_submit', string $explanation = '' ): array {
	return [
		'id'       => $id,
		'type'     => 'quiz_question',
		'label'    => $label,
		'required' => false,
		'options'  => $opts,
		'settings' => [
			'points'              => $points,
			'explanation'         => $explanation,
			'show_correct_answer' => $show,
			'randomize_options'   => false,
		],
	];
};

$q1 = $mk_quiz( 'quiz_capital', 'Capital of India?', [
	[ 'label' => 'Mumbai',    'value' => 'mumbai',    'is_correct' => false ],
	[ 'label' => 'New Delhi', 'value' => 'new_delhi', 'is_correct' => true  ],
	[ 'label' => 'Jaipur',    'value' => 'jaipur',    'is_correct' => false ],
], 1, 'after_submit', 'New Delhi is the capital of India.' );

$q2 = $mk_quiz( 'quiz_2plus2', '2 + 2?', [
	[ 'label' => '3', 'value' => '3', 'is_correct' => false ],
	[ 'label' => '4', 'value' => '4', 'is_correct' => true ],
	[ 'label' => '5', 'value' => '5', 'is_correct' => false ],
], 2 );

$q3_never = $mk_quiz( 'quiz_secret', 'Secret answer?', [
	[ 'label' => 'A', 'value' => 'a', 'is_correct' => false ],
	[ 'label' => 'B', 'value' => 'b', 'is_correct' => true  ],
], 1, 'never', 'Hidden explanation' );

$q_zero_points = $mk_quiz( 'quiz_zero', 'Ungraded?', [
	[ 'label' => 'A', 'value' => 'a', 'is_correct' => true ],
	[ 'label' => 'B', 'value' => 'b', 'is_correct' => false ],
], 0 );

// ---------------------------------------------------------------------------
// 1. No quiz fields -> null (response omits `quiz`).
// ---------------------------------------------------------------------------
$res_none = $call( [ 'fields' => [
	[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
] ], [ 'name' => 'Ada' ] );
check( '1a no quiz fields -> null', $res_none, null, $cases, $passed, $failed );

// 1b. Empty schema -> null.
check( '1b empty fields -> null', $call( [ 'fields' => [] ], [] ), null, $cases, $passed, $failed );

// 1c. Non-array schema -> null.
check( '1c non-array schema -> null', $call( null, [] ), null, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Single quiz, all-correct -> 100%.
// ---------------------------------------------------------------------------
$res = $call( [ 'fields' => [ $q1 ] ], [ 'quiz_capital' => 'new_delhi' ] );
check( '2a score = 1',           $res['score']      ?? null, 1,    $cases, $passed, $failed );
check( '2b max_score = 1',       $res['max_score']  ?? null, 1,    $cases, $passed, $failed );
check( '2c percentage = 100',    $res['percentage'] ?? null, 100.0, $cases, $passed, $failed );
check( '2d 1 question entry',    count( $res['questions'] ?? [] ), 1, $cases, $passed, $failed );
check( '2e correct = true',      $res['questions'][0]['correct'] ?? null, true, $cases, $passed, $failed );
check( '2f points_awarded = 1',  $res['questions'][0]['points_awarded'] ?? null, 1, $cases, $passed, $failed );
check( '2g selected_value',      $res['questions'][0]['selected_value'] ?? null, 'new_delhi', $cases, $passed, $failed );
check( '2h selected_label',      $res['questions'][0]['selected_label'] ?? null, 'New Delhi', $cases, $passed, $failed );
check( '2i show_correct_answer',$res['questions'][0]['show_correct_answer'] ?? null, true, $cases, $passed, $failed );
check( '2j correct_value emitted (after_submit)', $res['questions'][0]['correct_value'] ?? null, 'new_delhi', $cases, $passed, $failed );
check( '2k correct_label emitted (after_submit)', $res['questions'][0]['correct_label'] ?? null, 'New Delhi', $cases, $passed, $failed );
check( '2l explanation emitted (after_submit)',   $res['questions'][0]['explanation']   ?? null, 'New Delhi is the capital of India.', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Single quiz, all-wrong -> 0%.
// ---------------------------------------------------------------------------
$res = $call( [ 'fields' => [ $q1 ] ], [ 'quiz_capital' => 'mumbai' ] );
check( '3a score = 0',           $res['score']      ?? null, 0,   $cases, $passed, $failed );
check( '3b percentage = 0',      $res['percentage'] ?? null, 0.0, $cases, $passed, $failed );
check( '3c correct = false',     $res['questions'][0]['correct'] ?? null, false, $cases, $passed, $failed );
check( '3d points_awarded = 0',  $res['questions'][0]['points_awarded'] ?? null, 0, $cases, $passed, $failed );
check( '3e selected_label = Mumbai', $res['questions'][0]['selected_label'] ?? null, 'Mumbai', $cases, $passed, $failed );
// Even on incorrect, after_submit still leaks the correct answer to the user
// so they can learn (privacy-by-design only blocks pre-submit, not feedback).
check( '3f correct_label still emitted on wrong (after_submit)', $res['questions'][0]['correct_label'] ?? null, 'New Delhi', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Multiple quizzes, mixed -> partial score.
// ---------------------------------------------------------------------------
$res = $call(
	[ 'fields' => [ $q1, $q2 ] ],
	[ 'quiz_capital' => 'new_delhi', 'quiz_2plus2' => '3' ]
);
check( '4a score = 1 (q1 right, q2 wrong)',   $res['score']     ?? null, 1, $cases, $passed, $failed );
check( '4b max_score = 3',                    $res['max_score'] ?? null, 3, $cases, $passed, $failed );
check( '4c percentage = 33.33',               $res['percentage']?? null, 33.33, $cases, $passed, $failed );
check( '4d question order preserved',         array_column( $res['questions'] ?? [], 'field_id' ),
	[ 'quiz_capital', 'quiz_2plus2' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Optional/missing answer -> question entry with null + 0 awarded.
// ---------------------------------------------------------------------------
$res = $call( [ 'fields' => [ $q1, $q2 ] ], [ 'quiz_capital' => 'new_delhi' ] );
$q2_entry = $res['questions'][1] ?? [];
check( '5a missing answer key present + value null',
	array_key_exists( 'selected_value', $q2_entry ) && $q2_entry['selected_value'] === null, true,
	$cases, $passed, $failed );
check( '5b missing label key present + value null',
	array_key_exists( 'selected_label', $q2_entry ) && $q2_entry['selected_label'] === null, true,
	$cases, $passed, $failed );
check( '5c missing answer correct=false',       $q2_entry['correct'] ?? null, false, $cases, $passed, $failed );
check( '5d missing answer points_awarded=0',    $q2_entry['points_awarded'] ?? null, 0, $cases, $passed, $failed );
check( '5e overall score still 1',              $res['score'] ?? null, 1, $cases, $passed, $failed );
check( '5f overall max still 3',                $res['max_score'] ?? null, 3, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. show_correct_answer = "never" strips correct details.
// ---------------------------------------------------------------------------
$res = $call( [ 'fields' => [ $q3_never ] ], [ 'quiz_secret' => 'a' ] );
$q3_entry = $res['questions'][0];
check( '6a never -> show_correct_answer = false',  $q3_entry['show_correct_answer'] ?? null, false, $cases, $passed, $failed );
check( '6b never -> verdict still present',        $q3_entry['correct'] ?? null, false, $cases, $passed, $failed );
check( '6c never -> points_awarded still present', $q3_entry['points_awarded'] ?? null, 0, $cases, $passed, $failed );
check( '6d never -> correct_value omitted',
	array_key_exists( 'correct_value', $q3_entry ), false, $cases, $passed, $failed );
check( '6e never -> correct_label omitted',
	array_key_exists( 'correct_label', $q3_entry ), false, $cases, $passed, $failed );
check( '6f never -> explanation omitted',
	array_key_exists( 'explanation', $q3_entry ),  false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. max_score === 0 -> percentage 0 (no /0).
// ---------------------------------------------------------------------------
$res = $call( [ 'fields' => [ $q_zero_points ] ], [ 'quiz_zero' => 'a' ] );
check( '7a 0-point question max_score=0',  $res['max_score']  ?? null, 0,   $cases, $passed, $failed );
check( '7b 0-point question score=0',      $res['score']      ?? null, 0,   $cases, $passed, $failed );
check( '7c 0-point percentage=0 (no /0)',  $res['percentage'] ?? null, 0.0, $cases, $passed, $failed );
// Verdict is still meaningful even when ungraded.
check( '7d 0-point correct=true',          $res['questions'][0]['correct'] ?? null, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Quiz inside step + row.column reachable.
// ---------------------------------------------------------------------------
$schema_step = [ 'fields' => [
	[
		'id' => 'step_1', 'type' => 'step', 'title' => 'Q',
		'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
		'fields' => [ $q1 ],
	],
] ];
$res = $call( $schema_step, [ 'quiz_capital' => 'new_delhi' ] );
check( '8a score across step', $res['score'] ?? null, 1, $cases, $passed, $failed );
check( '8b question reached',
	array_column( $res['questions'] ?? [], 'field_id' ), [ 'quiz_capital' ], $cases, $passed, $failed );

$schema_row = [ 'fields' => [
	[
		'id' => 'row_1', 'type' => 'row',
		'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
		'columns' => [
			[ 'id' => 'col_l', 'fields' => [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ] ],
			[ 'id' => 'col_r', 'fields' => [ $q2 ] ],
		],
	],
] ];
$res = $call( $schema_row, [ 'name' => 'Ada', 'quiz_2plus2' => '4' ] );
check( '8c score across row.column', $res['score'] ?? null, 2, $cases, $passed, $failed );
check( '8d question reached',
	array_column( $res['questions'] ?? [], 'field_id' ), [ 'quiz_2plus2' ], $cases, $passed, $failed );

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
