<?php
/**
 * Star rating field tests.
 *
 * Exercises:
 *   - SchemaHelper recognises rating as a real field, not a layout node.
 *   - FormValidator (schema save) accepts rating fields.
 *   - FormRenderer emits N radio inputs + N star glyphs for a rating
 *     field with stars=N. Distinct N values produce distinct counts.
 *   - SubmissionValidator enforces rating constraints:
 *       * required + missing -> required error
 *       * 1..stars valid
 *       * > stars rejected
 *       * < 1 rejected
 *       * non-numeric rejected
 *       * not required + empty allowed
 *   - Rating works inside row.column.fields and inside step.fields.
 *
 * Run from the plugin root:
 *
 *   php tests/RatingFieldTest.php
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
$f_rating       = [ 'id' => 'rating_feedback', 'type' => 'rating', 'label' => 'Rating',     'required' => false, 'stars' => 5 ];
$f_rating_req   = [ 'id' => 'rating_feedback', 'type' => 'rating', 'label' => 'Rating',     'required' => true,  'stars' => 5 ];
$f_rating_10    = [ 'id' => 'rating_quality',  'type' => 'rating', 'label' => 'Quality',    'required' => false, 'stars' => 10 ];
$f_rating_3     = [ 'id' => 'rating_three',    'type' => 'rating', 'label' => 'Three',      'required' => false, 'stars' => 3 ];
$f_text         = [ 'id' => 'name',            'type' => 'text',   'label' => 'Name' ];
$f_email        = [ 'id' => 'email',           'type' => 'email',  'label' => 'Email' ];

// ---------------------------------------------------------------------------
// 1. FieldRegistry knows about rating.
// ---------------------------------------------------------------------------
check( '1a rating type is registered',          FieldRegistry::is_registered( 'rating' ), true, $cases, $passed, $failed );
check( '1b rating supports stars',
	in_array( 'stars', FieldRegistry::get( 'rating' )['supports'] ?? [], true ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. SchemaHelper classifies rating as a real field, not a layout node.
// ---------------------------------------------------------------------------
check( '2a is_real_field accepts rating',       SchemaHelper::is_real_field( $f_rating ),   true,  $cases, $passed, $failed );
check( '2b is_layout_node rejects rating',      SchemaHelper::is_layout_node( $f_rating ),  false, $cases, $passed, $failed );
check( '2c is_step rejects rating',             SchemaHelper::is_step( $f_rating ),         false, $cases, $passed, $failed );
check( '2d is_section rejects rating',          SchemaHelper::is_section( $f_rating ),      false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. flatten_fields walks rating leaves at every depth.
// ---------------------------------------------------------------------------
$flat_top = SchemaHelper::flatten_fields( [ $f_text, $f_rating ] );
check( '3a flatten includes rating at root',    in_array( 'rating_feedback', array_column( $flat_top, 'id' ), true ), true, $cases, $passed, $failed );

$row_with_rating = [
	'id' => 'row_a', 'type' => 'row',
	'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
	'columns' => [
		[ 'id' => 'col_l', 'fields' => [ $f_text ] ],
		[ 'id' => 'col_r', 'fields' => [ $f_rating ] ],
	],
];
$flat_in_row = SchemaHelper::flatten_fields( [ $row_with_rating ] );
check( '3b flatten reaches rating in row.col.fields',
	array_column( $flat_in_row, 'id' ), [ 'name', 'rating_feedback' ], $cases, $passed, $failed );

$step_with_rating = [
	'id' => 'step_one', 'type' => 'step', 'title' => 'Feedback',
	'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
	'fields' => [ $f_rating, $f_email ],
];
$flat_in_step = SchemaHelper::flatten_fields( [ $step_with_rating ] );
check( '3c flatten reaches rating inside step.fields',
	array_column( $flat_in_step, 'id' ), [ 'rating_feedback', 'email' ], $cases, $passed, $failed );

$step_with_row_with_rating = [
	'id' => 'step_two', 'type' => 'step', 'title' => 'Inside',
	'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
	'fields' => [ $row_with_rating ],
];
$flat_step_row = SchemaHelper::flatten_fields( [ $step_with_row_with_rating ] );
check( '3d flatten reaches rating inside step->row->col',
	array_column( $flat_step_row, 'id' ), [ 'name', 'rating_feedback' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. FormValidator (schema save) accepts rating fields at every depth.
// ---------------------------------------------------------------------------
$v_root  = FormValidator::validate_schema( [ 'fields' => [ $f_rating ] ] );
check( '4a rating at root validates',           $v_root === true,  true, $cases, $passed, $failed );

$v_row   = FormValidator::validate_schema( [ 'fields' => [ $row_with_rating ] ] );
check( '4b rating inside row.column validates', $v_row === true,   true, $cases, $passed, $failed );

$v_step  = FormValidator::validate_schema( [ 'fields' => [ $step_with_rating ] ] );
check( '4c rating inside step validates',       $v_step === true,  true, $cases, $passed, $failed );

$v_steprow = FormValidator::validate_schema( [ 'fields' => [ $step_with_row_with_rating ] ] );
check( '4d rating inside step->row.column validates', $v_steprow === true, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. FormRenderer emits N radio inputs + N star glyphs.
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

$html_5 = FormRenderer::render_preview_form( nf_make_form( [ $f_rating ] ) );
check( '5a renderer emits rating fieldset',        strpos( $html_5, 'neural-forms-form__rating-group' ) !== false, true, $cases, $passed, $failed );
check( '5a renderer emits data-stars="5"',         strpos( $html_5, 'data-stars="5"' ) !== false, true, $cases, $passed, $failed );

// Count radio inputs whose name matches the rating field id.
$radio_count_5 = preg_match_all( '/<input[^>]*type="radio"[^>]*name="rating_feedback"/', $html_5 );
check( '5b 5-star rating emits exactly 5 radios',  $radio_count_5, 5, $cases, $passed, $failed );

// Count star glyphs.
$glyph_count_5 = substr_count( $html_5, 'neural-forms-form__rating-glyph' );
check( '5c 5-star rating emits 5 star glyphs',     $glyph_count_5, 5, $cases, $passed, $failed );

// Distinct stars value renders distinct count.
$html_10 = FormRenderer::render_preview_form( nf_make_form( [ $f_rating_10 ] ) );
$radio_count_10 = preg_match_all( '/<input[^>]*type="radio"[^>]*name="rating_quality"/', $html_10 );
check( '5d 10-star rating emits exactly 10 radios', $radio_count_10, 10, $cases, $passed, $failed );
check( '5e 10-star rating emits data-stars="10"',   strpos( $html_10, 'data-stars="10"' ) !== false, true, $cases, $passed, $failed );

$html_3 = FormRenderer::render_preview_form( nf_make_form( [ $f_rating_3 ] ) );
$radio_count_3 = preg_match_all( '/<input[^>]*type="radio"[^>]*name="rating_three"/', $html_3 );
check( '5f 3-star rating emits exactly 3 radios',  $radio_count_3, 3, $cases, $passed, $failed );

// Required rating: aria-required on the first radio.
$html_req = FormRenderer::render_preview_form( nf_make_form( [ $f_rating_req ] ) );
check( '5g required rating sets required on first radio',
	(bool) preg_match( '/<input[^>]*type="radio"[^>]*value="1"[^>]*required/', $html_req ), true, $cases, $passed, $failed );

// Out-of-bounds stars are clamped (12 -> 10, 0 -> 1).
$f_rating_high = [ 'id' => 'rating_high', 'type' => 'rating', 'label' => 'High', 'stars' => 12 ];
$html_high = FormRenderer::render_preview_form( nf_make_form( [ $f_rating_high ] ) );
$radio_count_high = preg_match_all( '/<input[^>]*type="radio"[^>]*name="rating_high"/', $html_high );
check( '5h stars=12 clamps to 10 radios',          $radio_count_high, 10, $cases, $passed, $failed );

$f_rating_low = [ 'id' => 'rating_low', 'type' => 'rating', 'label' => 'Low', 'stars' => 0 ];
$html_low = FormRenderer::render_preview_form( nf_make_form( [ $f_rating_low ] ) );
$radio_count_low = preg_match_all( '/<input[^>]*type="radio"[^>]*name="rating_low"/', $html_low );
check( '5i stars=0 clamps to 1 radio',             $radio_count_low, 1, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. SubmissionValidator: required + missing fails.
// ---------------------------------------------------------------------------
$res_missing = SubmissionValidator::validate( [ $f_rating_req ], [] );
check( '6a missing required rating fails',         is_wp_error( $res_missing ),                        true,        $cases, $passed, $failed );
$err_missing = $res_missing instanceof WP_Error ? $res_missing->get_error_data() : [];
check( '6b error code is "required"',              $err_missing['fields']['rating_feedback'][0]['code'] ?? null, 'required', $cases, $passed, $failed );

$res_empty = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => '' ] );
check( '6c empty string for required rating fails', is_wp_error( $res_empty ),                          true,        $cases, $passed, $failed );

// Optional + missing = OK.
$res_opt_missing = SubmissionValidator::validate( [ $f_rating ], [] );
check( '6d optional rating + missing passes',      $res_opt_missing,                                   true,        $cases, $passed, $failed );

// Optional + empty = OK.
$res_opt_empty = SubmissionValidator::validate( [ $f_rating ], [ 'rating_feedback' => '' ] );
check( '6e optional rating + empty passes',        $res_opt_empty,                                     true,        $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. SubmissionValidator: in-range numeric values pass.
// ---------------------------------------------------------------------------
foreach ( [ 1, 2, 3, 4, 5 ] as $val ) {
	$res = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => $val ] );
	check( '7 rating value ' . $val . ' (1..5) passes', $res, true, $cases, $passed, $failed );
}
// String form too.
$res_str = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => '4' ] );
check( '7 rating string "4" passes',                $res_str, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. SubmissionValidator: > stars rejected.
// ---------------------------------------------------------------------------
$res_over = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => 6 ] );
check( '8a value 6 with stars=5 fails',             is_wp_error( $res_over ),                          true,            $cases, $passed, $failed );
$err_over = $res_over instanceof WP_Error ? $res_over->get_error_data() : [];
check( '8b error code is "invalid_rating"',         $err_over['fields']['rating_feedback'][0]['code'] ?? null, 'invalid_rating', $cases, $passed, $failed );

$res_way_over = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => 999 ] );
check( '8c value 999 with stars=5 fails',           is_wp_error( $res_way_over ),                      true,            $cases, $passed, $failed );

// 0 and negative also fail.
$res_zero = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => 0 ] );
// Required check fires first for 0? 0 is a numeric "present" but check_required compares to '' / null;
// (int)0 != '' so required passes; then range check fails.
check( '8d value 0 fails range check',              is_wp_error( $res_zero ),                          true,            $cases, $passed, $failed );

$res_neg = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => -1 ] );
check( '8e value -1 fails',                         is_wp_error( $res_neg ),                           true,            $cases, $passed, $failed );

// 10-star field: 7 is valid.
$res_seven = SubmissionValidator::validate( [ $f_rating_10 ], [ 'rating_quality' => 7 ] );
check( '8f value 7 with stars=10 passes',           $res_seven,                                         true,            $cases, $passed, $failed );

// 10-star field: 11 fails.
$f_rating_10_req = array_merge( $f_rating_10, [ 'required' => true ] );
$res_eleven = SubmissionValidator::validate( [ $f_rating_10_req ], [ 'rating_quality' => 11 ] );
check( '8g value 11 with stars=10 fails',           is_wp_error( $res_eleven ),                        true,            $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. SubmissionValidator: non-numeric rejected.
// ---------------------------------------------------------------------------
$res_str_text = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => 'four' ] );
check( '9a string "four" rejected',                 is_wp_error( $res_str_text ),                      true,            $cases, $passed, $failed );

$res_arr = SubmissionValidator::validate( [ $f_rating_req ], [ 'rating_feedback' => [ 1, 2 ] ] );
check( '9b array rejected',                         is_wp_error( $res_arr ),                            true,            $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. Rating works inside row + step submission flows. Flatten produces
//     the rating field, and the validator runs against that flat list
//     same as the SubmitEndpoint pipeline does.
// ---------------------------------------------------------------------------
$flat_row_fields = SchemaHelper::flatten_fields( [ $row_with_rating ] );
$res_row_submit  = SubmissionValidator::validate( $flat_row_fields, [ 'name' => 'Ada', 'rating_feedback' => 3 ] );
check( '10a rating in row.column accepts valid submission', $res_row_submit, true, $cases, $passed, $failed );

$flat_step_fields = SchemaHelper::flatten_fields( [ $step_with_rating ] );
$res_step_submit  = SubmissionValidator::validate( $flat_step_fields, [ 'rating_feedback' => 4, 'email' => 'a@b.test' ] );
check( '10b rating in step accepts valid submission',       $res_step_submit, true, $cases, $passed, $failed );

$flat_step_row_fields = SchemaHelper::flatten_fields( [ $step_with_row_with_rating ] );
$res_steprow_submit   = SubmissionValidator::validate( $flat_step_row_fields, [ 'name' => 'Ada', 'rating_feedback' => 5 ] );
check( '10c rating in step->row.column accepts valid submission', $res_steprow_submit, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. Existing flat schema baseline: validator + flatten unchanged for
//     non-rating shapes. Locks regression coverage when rating logic
//     evolves.
// ---------------------------------------------------------------------------
$flat_base = [ 'fields' => [ $f_text, $f_email ] ];
check( '11a flat schema validates',                 FormValidator::validate_schema( $flat_base ) === true, true, $cases, $passed, $failed );
$flat_base_flat = SchemaHelper::flatten_fields( $flat_base['fields'] );
check( '11b flat schema flatten preserves order',   array_column( $flat_base_flat, 'id' ), [ 'name', 'email' ], $cases, $passed, $failed );

$res_legacy_submit = SubmissionValidator::validate( $flat_base['fields'], [ 'name' => 'Ada', 'email' => 'a@b.test' ] );
check( '11c flat submission still validates',       $res_legacy_submit, true, $cases, $passed, $failed );

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
