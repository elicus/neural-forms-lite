<?php
/**
 * Poll field tests (Phase 1).
 *
 * Phase 1 scope:
 *   - Schema/render/validation foundation.
 *   - Poll active in builder, saves + submits like a real choice field.
 *   - No results aggregation, no public results endpoint, no AJAX
 *     integration. The renderer emits an EMPTY results placeholder
 *     when results_visibility !== 'never'; populating it is Phase 3.
 *
 * Run from the plugin root:
 *
 *   php tests/PollFieldTest.php
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

// ---------------------------------------------------------------------------
// Fixtures.
// ---------------------------------------------------------------------------
$f_text  = [ 'id' => 'name',    'type' => 'text',  'label' => 'Name' ];
$f_email = [ 'id' => 'email',   'type' => 'email', 'label' => 'Email' ];

$poll_options = [
	[ 'label' => 'Website Design',         'value' => 'website_design' ],
	[ 'label' => 'WordPress Development',  'value' => 'wordpress_development' ],
	[ 'label' => 'Maintenance',            'value' => 'maintenance' ],
];

$f_poll = [
	'id'       => 'poll_favorite_service',
	'type'     => 'poll',
	'label'    => 'Which service are you most interested in?',
	'required' => false,
	'options'  => $poll_options,
	'settings' => SchemaHelper::default_poll_settings(),
];

$f_poll_required = array_merge( $f_poll, [ 'required' => true ] );
$f_poll_never    = array_merge( $f_poll, [
	'settings' => array_merge( SchemaHelper::default_poll_settings(), [ 'results_visibility' => 'never' ] ),
] );
$f_poll_always   = array_merge( $f_poll, [
	'settings' => array_merge( SchemaHelper::default_poll_settings(), [ 'results_visibility' => 'always' ] ),
] );

// ---------------------------------------------------------------------------
// 1. FieldRegistry knows poll.
// ---------------------------------------------------------------------------
check( '1a poll type is registered',           FieldRegistry::is_registered( 'poll' ), true, $cases, $passed, $failed );
check( '1b poll supports options',
	in_array( 'options', FieldRegistry::get( 'poll' )['supports'] ?? [], true ),  true, $cases, $passed, $failed );
check( '1c poll supports settings',
	in_array( 'settings', FieldRegistry::get( 'poll' )['supports'] ?? [], true ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. SchemaHelper classifies poll as a real field.
// ---------------------------------------------------------------------------
check( '2a is_real_field accepts poll',        SchemaHelper::is_real_field( $f_poll ),  true,  $cases, $passed, $failed );
check( '2b is_layout_node rejects poll',       SchemaHelper::is_layout_node( $f_poll ), false, $cases, $passed, $failed );
check( '2c is_step rejects poll',              SchemaHelper::is_step( $f_poll ),        false, $cases, $passed, $failed );
check( '2d is_section rejects poll',           SchemaHelper::is_section( $f_poll ),     false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. flatten_fields walks poll leaves at every depth.
// ---------------------------------------------------------------------------
$flat_top = SchemaHelper::flatten_fields( [ $f_text, $f_poll ] );
check( '3a flatten includes poll at root',
	in_array( 'poll_favorite_service', array_column( $flat_top, 'id' ), true ), true, $cases, $passed, $failed );

$row_with_poll = [
	'id' => 'row_a', 'type' => 'row',
	'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
	'columns' => [
		[ 'id' => 'col_l', 'fields' => [ $f_text ] ],
		[ 'id' => 'col_r', 'fields' => [ $f_poll ] ],
	],
];
$flat_in_row = SchemaHelper::flatten_fields( [ $row_with_poll ] );
check( '3b flatten reaches poll in row.col.fields',
	array_column( $flat_in_row, 'id' ), [ 'name', 'poll_favorite_service' ], $cases, $passed, $failed );

$step_with_poll = [
	'id' => 'step_one', 'type' => 'step', 'title' => 'Feedback',
	'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
	'fields' => [ $f_poll, $f_email ],
];
$flat_in_step = SchemaHelper::flatten_fields( [ $step_with_poll ] );
check( '3c flatten reaches poll inside step.fields',
	array_column( $flat_in_step, 'id' ), [ 'poll_favorite_service', 'email' ], $cases, $passed, $failed );

$step_with_row_poll = [
	'id' => 'step_two', 'type' => 'step', 'title' => 'Inside',
	'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
	'fields' => [ $row_with_poll ],
];
$flat_step_row = SchemaHelper::flatten_fields( [ $step_with_row_poll ] );
check( '3d flatten reaches poll inside step->row->col',
	array_column( $flat_step_row, 'id' ), [ 'name', 'poll_favorite_service' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. FormValidator (schema save) accepts poll fields at every depth.
// ---------------------------------------------------------------------------
check( '4a poll at root validates',                 FormValidator::validate_schema( [ 'fields' => [ $f_poll ] ] ) === true,         true, $cases, $passed, $failed );
check( '4b poll inside row.column validates',       FormValidator::validate_schema( [ 'fields' => [ $row_with_poll ] ] ) === true,  true, $cases, $passed, $failed );
check( '4c poll inside step validates',             FormValidator::validate_schema( [ 'fields' => [ $step_with_poll ] ] ) === true, true, $cases, $passed, $failed );
check( '4d poll inside step->row.column validates', FormValidator::validate_schema( [ 'fields' => [ $step_with_row_poll ] ] ) === true, true, $cases, $passed, $failed );

// 4e. Poll with empty options array must be rejected at save time.
$f_poll_empty = array_merge( $f_poll, [ 'options' => [] ] );
$res_empty_opts = FormValidator::validate_schema( [ 'fields' => [ $f_poll_empty ] ] );
check( '4e poll with empty options is rejected',
	is_wp_error( $res_empty_opts ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. FormRenderer emits radio inputs with correct name + values.
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

$html_default = FormRenderer::render_preview_form( nf_make_form( [ $f_poll ] ) );

check( '5a renderer emits poll fieldset',
	strpos( $html_default, 'class="neural-forms-form__poll"' ) !== false, true, $cases, $passed, $failed );
$radio_count = preg_match_all( '/<input[^>]*type="radio"[^>]*name="poll_favorite_service"/', $html_default );
check( '5b renderer emits one radio per option',  $radio_count, 3, $cases, $passed, $failed );
check( '5c renderer emits website_design value',  strpos( $html_default, 'value="website_design"' ) !== false, true, $cases, $passed, $failed );
check( '5d renderer emits wordpress_development', strpos( $html_default, 'value="wordpress_development"' ) !== false, true, $cases, $passed, $failed );
check( '5e renderer emits maintenance value',     strpos( $html_default, 'value="maintenance"' ) !== false, true, $cases, $passed, $failed );
check( '5f renderer keeps schema label as legend',
	strpos( $html_default, 'Which service are you most interested in?' ) !== false, true, $cases, $passed, $failed );

// 5g. Required poll: required attribute lands on the first radio.
$html_required = FormRenderer::render_preview_form( nf_make_form( [ $f_poll_required ] ) );
check( '5g required poll sets required on first radio',
	(bool) preg_match( '/<input[^>]*type="radio"[^>]*value="website_design"[^>]*required/', $html_required ),
	true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Results placeholder visibility.
// ---------------------------------------------------------------------------
// Default field uses results_visibility = 'after_vote'.
check( '6a after_vote emits results placeholder',
	strpos( $html_default, 'data-poll-results="poll_favorite_service"' ) !== false, true, $cases, $passed, $failed );
check( '6a placeholder carries visibility attribute',
	strpos( $html_default, 'data-poll-visibility="after_vote"' ) !== false, true, $cases, $passed, $failed );
check( '6a placeholder is hidden by default',
	strpos( $html_default, 'data-poll-visibility="after_vote" data-poll-chart="bar" hidden' ) !== false, true, $cases, $passed, $failed );

// 6b. always emits placeholder.
$html_always = FormRenderer::render_preview_form( nf_make_form( [ $f_poll_always ] ) );
check( '6b always emits results placeholder',
	strpos( $html_always, 'data-poll-results="poll_favorite_service"' ) !== false, true, $cases, $passed, $failed );
check( '6b always carries visibility attribute',
	strpos( $html_always, 'data-poll-visibility="always"' ) !== false, true, $cases, $passed, $failed );

// 6c. never does NOT emit placeholder.
$html_never = FormRenderer::render_preview_form( nf_make_form( [ $f_poll_never ] ) );
check( '6c never omits results placeholder',
	strpos( $html_never, 'neural-forms-form__poll-results' ) === false, true, $cases, $passed, $failed );
check( '6c never still emits poll fieldset',
	strpos( $html_never, 'class="neural-forms-form__poll"' ) !== false, true, $cases, $passed, $failed );

// 6d. Unknown visibility coerces to default 'after_vote' -> placeholder
//     emitted with after_vote attribute.
$f_poll_bogus = array_merge( $f_poll, [
	'settings' => [ 'results_visibility' => 'rocketship', 'chart_type' => 'bar', 'allow_multiple' => false, 'randomize_options' => false ],
] );
$html_bogus = FormRenderer::render_preview_form( nf_make_form( [ $f_poll_bogus ] ) );
check( '6d unknown visibility falls back to after_vote',
	strpos( $html_bogus, 'data-poll-visibility="after_vote"' ) !== false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. SubmissionValidator: required + missing/empty fails.
// ---------------------------------------------------------------------------
$res_missing = SubmissionValidator::validate( [ $f_poll_required ], [] );
check( '7a missing required poll fails',          is_wp_error( $res_missing ), true, $cases, $passed, $failed );
$err_missing = $res_missing instanceof WP_Error ? $res_missing->get_error_data() : [];
check( '7b error code is "required"',             $err_missing['fields']['poll_favorite_service'][0]['code'] ?? null, 'required', $cases, $passed, $failed );

$res_empty = SubmissionValidator::validate( [ $f_poll_required ], [ 'poll_favorite_service' => '' ] );
check( '7c empty string for required poll fails', is_wp_error( $res_empty ),   true, $cases, $passed, $failed );

// 7d. Optional + missing passes.
$res_opt = SubmissionValidator::validate( [ $f_poll ], [] );
check( '7d optional poll + missing passes',       $res_opt, true, $cases, $passed, $failed );

// 7e. Optional + empty passes.
$res_opt_empty = SubmissionValidator::validate( [ $f_poll ], [ 'poll_favorite_service' => '' ] );
check( '7e optional poll + empty passes',         $res_opt_empty, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. SubmissionValidator: valid option passes.
// ---------------------------------------------------------------------------
foreach ( [ 'website_design', 'wordpress_development', 'maintenance' ] as $val ) {
	$res = SubmissionValidator::validate( [ $f_poll_required ], [ 'poll_favorite_service' => $val ] );
	check( '8 poll value "' . $val . '" passes', $res, true, $cases, $passed, $failed );
}

// ---------------------------------------------------------------------------
// 9. SubmissionValidator: invalid / tampered option rejected.
// ---------------------------------------------------------------------------
$res_bad = SubmissionValidator::validate( [ $f_poll_required ], [ 'poll_favorite_service' => 'tampered_value' ] );
check( '9a tampered value rejected',              is_wp_error( $res_bad ), true, $cases, $passed, $failed );
$err_bad = $res_bad instanceof WP_Error ? $res_bad->get_error_data() : [];
check( '9b error code is "invalid_option"',       $err_bad['fields']['poll_favorite_service'][0]['code'] ?? null, 'invalid_option', $cases, $passed, $failed );

$res_unknown = SubmissionValidator::validate( [ $f_poll_required ], [ 'poll_favorite_service' => 'nonexistent' ] );
check( '9c unknown value rejected',               is_wp_error( $res_unknown ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. Poll inside row/column + step submission flows: flatten + validate
//     mirrors the SubmitEndpoint pipeline.
// ---------------------------------------------------------------------------
$flat_row_fields = SchemaHelper::flatten_fields( [ $row_with_poll ] );
$res_row_submit  = SubmissionValidator::validate(
	$flat_row_fields,
	[ 'name' => 'Ada', 'poll_favorite_service' => 'maintenance' ]
);
check( '10a poll in row.column accepts valid submission', $res_row_submit, true, $cases, $passed, $failed );

$flat_step_fields = SchemaHelper::flatten_fields( [ $step_with_poll ] );
$res_step_submit  = SubmissionValidator::validate(
	$flat_step_fields,
	[ 'poll_favorite_service' => 'website_design', 'email' => 'a@b.test' ]
);
check( '10b poll in step accepts valid submission',       $res_step_submit, true, $cases, $passed, $failed );

$flat_steprow_fields = SchemaHelper::flatten_fields( [ $step_with_row_poll ] );
$res_steprow_submit  = SubmissionValidator::validate(
	$flat_steprow_fields,
	[ 'name' => 'Ada', 'poll_favorite_service' => 'wordpress_development' ]
);
check( '10c poll in step->row.column accepts valid submission', $res_steprow_submit, true, $cases, $passed, $failed );

// 10d. Tampered value inside a row still rejected.
$res_row_bad = SubmissionValidator::validate(
	$flat_row_fields,
	[ 'name' => 'Ada', 'poll_favorite_service' => 'totally_fake' ]
);
check( '10d poll in row tampered value rejected',         is_wp_error( $res_row_bad ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. Existing forms unaffected: flat schema still validates and submits
//     identically.
// ---------------------------------------------------------------------------
$flat_base = [ 'fields' => [ $f_text, $f_email ] ];
check( '11a flat schema validates',                FormValidator::validate_schema( $flat_base ) === true, true, $cases, $passed, $failed );
$flat_base_flat = SchemaHelper::flatten_fields( $flat_base['fields'] );
check( '11b flat schema flatten preserves order',  array_column( $flat_base_flat, 'id' ), [ 'name', 'email' ], $cases, $passed, $failed );

$res_legacy_submit = SubmissionValidator::validate( $flat_base['fields'], [ 'name' => 'Ada', 'email' => 'a@b.test' ] );
check( '11c flat submission still validates',      $res_legacy_submit, true, $cases, $passed, $failed );

// 11d. Renderer of a non-poll form does NOT emit poll markup.
$html_legacy = FormRenderer::render_preview_form( nf_make_form( [ $f_text, $f_email ] ) );
check( '11d renderer emits no poll fieldset for non-poll forms',
	strpos( $html_legacy, 'neural-forms-form__poll' ) === false, true, $cases, $passed, $failed );
check( '11e renderer emits no poll-results placeholder for non-poll forms',
	strpos( $html_legacy, 'neural-forms-form__poll-results' ) === false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 12. Poll-settings normalizer locks the schema shape.
// ---------------------------------------------------------------------------
$norm_default = SchemaHelper::normalize_poll_settings( null );
check( '12a normalizer fills defaults',
	$norm_default,
	[
		'results_visibility' => 'after_vote',
		'chart_type'         => 'bar',
		'allow_multiple'     => false,
		'randomize_options'  => false,
	],
	$cases, $passed, $failed
);

$norm_unknown = SchemaHelper::normalize_poll_settings( [
	'results_visibility' => 'aliens',
	'chart_type'         => 'pie',
	'allow_multiple'     => 'truthy',
	'randomize_options'  => 1,
	'evil'               => 'dropped',
] );
check( '12b unknown enums fall back to defaults',
	[ $norm_unknown['results_visibility'], $norm_unknown['chart_type'] ],
	[ 'after_vote', 'bar' ],
	$cases, $passed, $failed
);
check( '12c truthy strings cast to bool',          $norm_unknown['allow_multiple'],    true, $cases, $passed, $failed );
check( '12d unknown keys are dropped',             array_key_exists( 'evil', $norm_unknown ), false, $cases, $passed, $failed );

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
