<?php
/**
 * Quiz Question field tests (Phase 1).
 *
 * Phase 1 scope:
 *   - Schema/render/validation foundation.
 *   - Real-field classification + flatten + reachability inside
 *     row/column and step.
 *   - Renderer outputs radio inputs.
 *   - Renderer NEVER emits is_correct (privacy contract).
 *   - Schema-save validator: at least 2 options, exactly 1 correct.
 *   - SubmissionValidator: required missing, invalid option, valid pass.
 *
 * Run from the plugin root:
 *
 *   php tests/QuizFieldTest.php
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
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'esc_html' ) )    { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )    { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) )     { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ){ function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) )  { function esc_html__( $s, $domain = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr__' ) )  { function esc_attr__( $s, $domain = null ) { return esc_attr( $s ); } }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return false; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $h ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $h, $src = '', $deps = [], $ver = false, $in_footer = false ) {}
}
if ( ! function_exists( 'is_email' ) ) { function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } }
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) { define( 'NEURAL_FORMS_VERSION', '1.1.0-test' ); }
$GLOBALS['__nf_options'] = [];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = [] ) { return $GLOBALS['__nf_options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__nf_options'][ $key ] = $value; return true; }
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';
require_once $plugin_root . '/modules/core/Config.php';
require_once $plugin_root . '/modules/frontend/FormRenderer.php';

use NeuralForms\Forms\FieldRegistry;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\FormValidator;
use NeuralForms\Forms\SubmissionValidator;
use NeuralForms\Frontend\FormRenderer;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// Fixtures.
$quiz_options = [
	[ 'label' => 'Mumbai',    'value' => 'mumbai',    'is_correct' => false ],
	[ 'label' => 'New Delhi', 'value' => 'new_delhi', 'is_correct' => true  ],
	[ 'label' => 'Jaipur',    'value' => 'jaipur',    'is_correct' => false ],
];
$quiz = [
	'id'       => 'quiz_capital',
	'type'     => 'quiz_question',
	'label'    => 'What is the capital of India?',
	'required' => true,
	'options'  => $quiz_options,
	'settings' => SchemaHelper::default_quiz_settings(),
];
$quiz_optional = array_merge( $quiz, [ 'required' => false ] );
$quiz_never = array_merge( $quiz, [
	'settings' => array_merge( SchemaHelper::default_quiz_settings(), [ 'show_correct_answer' => 'never' ] ),
] );

// ---------------------------------------------------------------------------
// 1. FieldRegistry knows quiz_question.
// ---------------------------------------------------------------------------
check( '1a quiz_question type is registered',     FieldRegistry::is_registered( 'quiz_question' ), true, $cases, $passed, $failed );
check( '1b quiz_question supports options',
	in_array( 'options', FieldRegistry::get( 'quiz_question' )['supports'] ?? [], true ),  true, $cases, $passed, $failed );
check( '1c quiz_question supports settings',
	in_array( 'settings', FieldRegistry::get( 'quiz_question' )['supports'] ?? [], true ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. SchemaHelper classifies quiz_question as a real field.
// ---------------------------------------------------------------------------
check( '2a is_real_field accepts quiz_question',     SchemaHelper::is_real_field( $quiz ),      true,  $cases, $passed, $failed );
check( '2b is_layout_node rejects quiz_question',    SchemaHelper::is_layout_node( $quiz ),     false, $cases, $passed, $failed );
check( '2c is_step rejects quiz_question',           SchemaHelper::is_step( $quiz ),            false, $cases, $passed, $failed );
check( '2d is_section rejects quiz_question',        SchemaHelper::is_section( $quiz ),         false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. flatten_fields walks quiz_question leaves at every depth.
// ---------------------------------------------------------------------------
$f_text = [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ];
$flat_top = SchemaHelper::flatten_fields( [ $f_text, $quiz ] );
check( '3a flatten includes quiz_question at root',
	in_array( 'quiz_capital', array_column( $flat_top, 'id' ), true ), true, $cases, $passed, $failed );

$row_with_quiz = [
	'id' => 'row_a', 'type' => 'row',
	'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
	'columns' => [
		[ 'id' => 'col_l', 'fields' => [ $f_text ] ],
		[ 'id' => 'col_r', 'fields' => [ $quiz ] ],
	],
];
$flat_in_row = SchemaHelper::flatten_fields( [ $row_with_quiz ] );
check( '3b flatten reaches quiz in row.col.fields',
	array_column( $flat_in_row, 'id' ), [ 'name', 'quiz_capital' ], $cases, $passed, $failed );

$step_with_quiz = [
	'id' => 'step_one', 'type' => 'step', 'title' => 'Quiz',
	'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
	'fields' => [ $quiz ],
];
$flat_in_step = SchemaHelper::flatten_fields( [ $step_with_quiz ] );
check( '3c flatten reaches quiz inside step.fields',
	array_column( $flat_in_step, 'id' ), [ 'quiz_capital' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. FormValidator (schema save).
// ---------------------------------------------------------------------------
check( '4a quiz at root validates',
	FormValidator::validate_schema( [ 'fields' => [ $quiz ] ] ) === true, true, $cases, $passed, $failed );
check( '4b quiz inside row validates',
	FormValidator::validate_schema( [ 'fields' => [ $row_with_quiz ] ] ) === true, true, $cases, $passed, $failed );
check( '4c quiz inside step validates',
	FormValidator::validate_schema( [ 'fields' => [ $step_with_quiz ] ] ) === true, true, $cases, $passed, $failed );

// 4d. < 2 options rejected.
$quiz_one_opt = array_merge( $quiz, [
	'options' => [ [ 'label' => 'Only', 'value' => 'only', 'is_correct' => true ] ],
] );
$res_one = FormValidator::validate_schema( [ 'fields' => [ $quiz_one_opt ] ] );
check( '4d quiz with 1 option is rejected', is_wp_error( $res_one ), true, $cases, $passed, $failed );

// 4e. zero correct options rejected.
$quiz_zero_correct = array_merge( $quiz, [
	'options' => [
		[ 'label' => 'A', 'value' => 'a', 'is_correct' => false ],
		[ 'label' => 'B', 'value' => 'b', 'is_correct' => false ],
	],
] );
$res_zero = FormValidator::validate_schema( [ 'fields' => [ $quiz_zero_correct ] ] );
check( '4e quiz with 0 correct options is rejected', is_wp_error( $res_zero ), true, $cases, $passed, $failed );

// 4f. multiple correct options rejected.
$quiz_two_correct = array_merge( $quiz, [
	'options' => [
		[ 'label' => 'A', 'value' => 'a', 'is_correct' => true ],
		[ 'label' => 'B', 'value' => 'b', 'is_correct' => true ],
	],
] );
$res_two_correct = FormValidator::validate_schema( [ 'fields' => [ $quiz_two_correct ] ] );
check( '4f quiz with 2 correct options is rejected', is_wp_error( $res_two_correct ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. FormRenderer emits radios + does NOT emit is_correct.
// ---------------------------------------------------------------------------
function nf_make_form( array $fields ): array {
	return [
		'id'          => 0,
		'name'        => 'Test',
		'status'      => 'active',
		'form_schema' => [ 'fields' => $fields ],
		'settings'    => [],
	];
}

$html = FormRenderer::render_preview_form( nf_make_form( [ $quiz ] ) );

check( '5a renderer emits quiz fieldset',
	strpos( $html, 'class="neural-forms-form__quiz"' ) !== false, true, $cases, $passed, $failed );

$radio_count = preg_match_all( '/<input[^>]*type="radio"[^>]*name="quiz_capital"/', $html );
check( '5b renderer emits one radio per option', $radio_count, 3, $cases, $passed, $failed );

check( '5c renderer emits mumbai value',
	strpos( $html, 'value="mumbai"' ) !== false, true, $cases, $passed, $failed );
check( '5d renderer emits new_delhi value',
	strpos( $html, 'value="new_delhi"' ) !== false, true, $cases, $passed, $failed );

// PRIVACY CONTRACT: no is_correct anywhere in the rendered HTML.
check( '5e renderer NEVER emits is_correct',
	stripos( $html, 'is_correct' ), false, $cases, $passed, $failed );
// Defensive: also check there's no data-correct or class hint.
check( '5f renderer emits no data-correct attr',
	stripos( $html, 'data-correct' ), false, $cases, $passed, $failed );
check( '5g renderer emits no --correct class',
	stripos( $html, '--correct' ), false, $cases, $passed, $failed );

// 5h. Required puts required on first radio only.
check( '5h required on first radio',
	(bool) preg_match( '/<input[^>]*type="radio"[^>]*value="mumbai"[^>]*required/', $html ),
	true, $cases, $passed, $failed );

// 5i. Feedback placeholder present + hidden.
check( '5i feedback placeholder emitted',
	strpos( $html, 'data-quiz-feedback="quiz_capital"' ) !== false, true, $cases, $passed, $failed );
check( '5j feedback placeholder is hidden',
	(bool) preg_match( '/data-quiz-feedback="quiz_capital"[^>]*hidden/', $html ),
	true, $cases, $passed, $failed );

// 5k. Form-level summary present + hidden.
check( '5k summary placeholder emitted',
	strpos( $html, 'data-quiz-summary' ) !== false, true, $cases, $passed, $failed );
check( '5l summary placeholder is hidden',
	(bool) preg_match( '/data-quiz-summary[^>]*hidden/', $html ), true, $cases, $passed, $failed );

// 5m. randomize_options=true emits data-quiz-randomize="1".
$quiz_rand = array_merge( $quiz, [
	'settings' => array_merge( SchemaHelper::default_quiz_settings(), [ 'randomize_options' => true ] ),
] );
$html_rand = FormRenderer::render_preview_form( nf_make_form( [ $quiz_rand ] ) );
check( '5m randomize attr emitted when enabled',
	strpos( $html_rand, 'data-quiz-randomize="1"' ) !== false, true, $cases, $passed, $failed );

// 5n. Form WITHOUT quiz fields does NOT emit summary placeholder.
$html_plain = FormRenderer::render_preview_form( nf_make_form( [ $f_text ] ) );
check( '5n no quiz_summary placeholder for plain form',
	strpos( $html_plain, 'data-quiz-summary' ) === false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. SubmissionValidator: required + missing/empty fails.
// ---------------------------------------------------------------------------
$res_missing = SubmissionValidator::validate( [ $quiz ], [] );
check( '6a missing required quiz fails', is_wp_error( $res_missing ), true, $cases, $passed, $failed );
$err_missing = $res_missing instanceof WP_Error ? $res_missing->get_error_data() : [];
check( '6b error code is required',
	$err_missing['fields']['quiz_capital'][0]['code'] ?? null, 'required', $cases, $passed, $failed );

$res_empty = SubmissionValidator::validate( [ $quiz ], [ 'quiz_capital' => '' ] );
check( '6c empty value fails required', is_wp_error( $res_empty ), true, $cases, $passed, $failed );

// 6d. Optional + missing passes.
$res_opt = SubmissionValidator::validate( [ $quiz_optional ], [] );
check( '6d optional quiz + missing passes', $res_opt, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. SubmissionValidator: valid option passes.
// ---------------------------------------------------------------------------
$res_valid = SubmissionValidator::validate( [ $quiz ], [ 'quiz_capital' => 'new_delhi' ] );
check( '7a correct value passes', $res_valid, true, $cases, $passed, $failed );

$res_valid_wrong = SubmissionValidator::validate( [ $quiz ], [ 'quiz_capital' => 'mumbai' ] );
check( '7b incorrect-but-valid value passes (scoring not validation)', $res_valid_wrong, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. SubmissionValidator: invalid/tampered option rejected.
// ---------------------------------------------------------------------------
$res_bad = SubmissionValidator::validate( [ $quiz ], [ 'quiz_capital' => 'tampered' ] );
check( '8a tampered value rejected', is_wp_error( $res_bad ), true, $cases, $passed, $failed );
$err_bad = $res_bad instanceof WP_Error ? $res_bad->get_error_data() : [];
check( '8b error code is invalid_option',
	$err_bad['fields']['quiz_capital'][0]['code'] ?? null, 'invalid_option', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. Quiz inside row + step submission flows.
// ---------------------------------------------------------------------------
$flat_row = SchemaHelper::flatten_fields( [ $row_with_quiz ] );
$res_row_submit = SubmissionValidator::validate(
	$flat_row,
	[ 'name' => 'Ada', 'quiz_capital' => 'new_delhi' ]
);
check( '9a quiz in row.column accepts valid submission', $res_row_submit, true, $cases, $passed, $failed );

$flat_step = SchemaHelper::flatten_fields( [ $step_with_quiz ] );
$res_step_submit = SubmissionValidator::validate(
	$flat_step,
	[ 'quiz_capital' => 'new_delhi' ]
);
check( '9b quiz in step accepts valid submission', $res_step_submit, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. normalize_quiz_settings shape.
// ---------------------------------------------------------------------------
$norm_default = SchemaHelper::normalize_quiz_settings( null );
check( '10a defaults fill in', $norm_default, [
	'points'              => 1,
	'explanation'         => '',
	'show_correct_answer' => 'after_submit',
	'randomize_options'   => false,
], $cases, $passed, $failed );

$norm_unknown = SchemaHelper::normalize_quiz_settings( [
	'points'              => '5',
	'explanation'         => 'Hello',
	'show_correct_answer' => 'rocketship',
	'randomize_options'   => 1,
	'evil'                => 'dropped',
] );
check( '10b unknown enum -> after_submit',     $norm_unknown['show_correct_answer'], 'after_submit', $cases, $passed, $failed );
check( '10c numeric string points -> int',     $norm_unknown['points'],              5,              $cases, $passed, $failed );
check( '10d truthy -> bool',                   $norm_unknown['randomize_options'],   true,           $cases, $passed, $failed );
check( '10e unknown keys dropped',             array_key_exists( 'evil', $norm_unknown ), false,     $cases, $passed, $failed );

$norm_neg = SchemaHelper::normalize_quiz_settings( [ 'points' => -3 ] );
check( '10f negative points clamped to 0',     $norm_neg['points'], 0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. Existing forms unaffected.
// ---------------------------------------------------------------------------
$flat_legacy = [ 'fields' => [ $f_text, [ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ] ] ];
check( '11a legacy schema validates',
	FormValidator::validate_schema( $flat_legacy ) === true, true, $cases, $passed, $failed );
check( '11b legacy submission validates',
	SubmissionValidator::validate( $flat_legacy['fields'], [ 'name' => 'Ada', 'email' => 'a@b.test' ] ),
	true, $cases, $passed, $failed );

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
