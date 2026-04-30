<?php
/**
 * Multi-step schema tests (1.1.0 Phase 1).
 *
 * Locks the layout-step contract:
 *
 *   - Steps are top-level only. Nested steps are rejected by the
 *     validator and dropped by the lenient normalizer.
 *   - Step ids never become entry data (strip_layout_keys).
 *   - flatten_fields recurses into step.fields and never emits step
 *     nodes - so existing pipelines (SubmitEndpoint required-field
 *     loop, ExportEndpoint column discovery, NotificationService
 *     summary lookup) reach fields nested inside steps unchanged.
 *   - Old flat schemas and row/column-only schemas continue to behave
 *     identically (no auto-wrapping into a default step).
 *
 * Run from the plugin root:
 *
 *   php tests/MultiStepSchemaTest.php
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

// Renderer shims (used by Phase 3 render tests). Minimal escapers
// matching WordPress core semantics enough for the assertions we run.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return (string) $s; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $s ) { return (string) $s; }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $domain = null ) { return esc_html( $s ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return false; }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $h ) {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $h, $src = '', $deps = [], $ver = false, $in_footer = false ) {}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; }
}
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) {
	define( 'NEURAL_FORMS_VERSION', '1.1.0-test' );
}
$GLOBALS['__nf_options'] = [];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = [] ) {
		return $GLOBALS['__nf_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__nf_options'][ $key ] = $value;
		return true;
	}
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/core/Config.php';
require_once $plugin_root . '/modules/frontend/FormRenderer.php';

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\FormValidator;
use NeuralForms\Frontend\FormRenderer;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// ---------------------------------------------------------------------------
// Sample fixtures.
// ---------------------------------------------------------------------------
$f_name  = [ 'id' => 'first_name', 'type' => 'text',  'label' => 'First name', 'required' => true ];
$f_email = [ 'id' => 'email',      'type' => 'email', 'label' => 'Email',      'required' => true ];
$f_phone = [ 'id' => 'phone',      'type' => 'phone', 'label' => 'Phone' ];
$f_msg   = [ 'id' => 'message',    'type' => 'textarea', 'label' => 'Message', 'required' => true ];

$step1 = [
	'id'    => 'step_contact',
	'type'  => 'step',
	'title' => 'Contact',
	'description' => 'Tell us how to reach you.',
	'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
	'fields' => [ $f_name, $f_email ],
];
$step2 = [
	'id'    => 'step_message',
	'type'  => 'step',
	'title' => 'Message',
	'description' => '',
	'settings' => [ 'button_next' => 'Send', 'button_previous' => 'Back' ],
	'fields' => [ $f_msg ],
];

// ---------------------------------------------------------------------------
// 1. Predicates.
// ---------------------------------------------------------------------------
check( 'P1 is_step on step',          SchemaHelper::is_step( $step1 ),     true,  $cases, $passed, $failed );
check( 'P2 is_step on row',           SchemaHelper::is_step( [ 'type' => 'row' ] ),    false, $cases, $passed, $failed );
check( 'P3 is_step on field',         SchemaHelper::is_step( $f_name ),    false, $cases, $passed, $failed );
check( 'P4 is_step on null',          SchemaHelper::is_step( null ),       false, $cases, $passed, $failed );

check( 'P5 is_layout_node on step',   SchemaHelper::is_layout_node( $step1 ),    true,  $cases, $passed, $failed );
check( 'P6 is_layout_node on row',    SchemaHelper::is_layout_node( [ 'type' => 'row' ] ),    true,  $cases, $passed, $failed );
check( 'P7 is_layout_node on field',  SchemaHelper::is_layout_node( $f_name ),    false, $cases, $passed, $failed );

check( 'P8 is_real_field rejects step',  SchemaHelper::is_real_field( $step1 ),  false, $cases, $passed, $failed );
check( 'P9 is_real_field accepts text',  SchemaHelper::is_real_field( $f_name ), true,  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. flatten_fields walks step.fields and skips step nodes.
// ---------------------------------------------------------------------------
$two_steps = [ $step1, $step2 ];
$flat_two_steps = SchemaHelper::flatten_fields( $two_steps );

check( '2a flatten yields all real fields',         count( $flat_two_steps ), 3, $cases, $passed, $failed );
check( '2b flatten preserves visual order',         array_column( $flat_two_steps, 'id' ),
	[ 'first_name', 'email', 'message' ], $cases, $passed, $failed );
check( '2c flatten omits step ids',                 in_array( 'step_contact', array_column( $flat_two_steps, 'id' ), true ), false, $cases, $passed, $failed );
check( '2d flatten omits second step id',           in_array( 'step_message', array_column( $flat_two_steps, 'id' ), true ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. flatten_fields walks step -> row -> column -> field.
// ---------------------------------------------------------------------------
$step_with_row = [
	'id'    => 'step_full',
	'type'  => 'step',
	'title' => 'Profile',
	'fields' => [
		$f_name,
		[
			'id' => 'row_a', 'type' => 'row',
			'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
			'columns'  => [
				[ 'id' => 'col_l', 'fields' => [ $f_email ] ],
				[ 'id' => 'col_r', 'fields' => [ $f_phone ] ],
			],
		],
		$f_msg,
	],
];

$flat_deep = SchemaHelper::flatten_fields( [ $step_with_row ] );
check( '3a flatten step->row->col->field count',    count( $flat_deep ), 4, $cases, $passed, $failed );
check( '3b flatten step->row->col->field order',    array_column( $flat_deep, 'id' ),
	[ 'first_name', 'email', 'phone', 'message' ], $cases, $passed, $failed );
check( '3c flatten omits step + row + col ids',
	count( array_intersect( [ 'step_full', 'row_a', 'col_l', 'col_r' ], array_column( $flat_deep, 'id' ) ) ),
	0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Validator accepts a valid 2-step top-level schema.
// ---------------------------------------------------------------------------
$valid_two_step = FormValidator::validate_schema( [ 'fields' => $two_steps ] );
check( '4 two-step schema validates', $valid_two_step === true, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Validator rejects nested step inside step.
// ---------------------------------------------------------------------------
$nested_step_in_step = [ 'fields' => [
	[
		'id' => 'step_outer', 'type' => 'step', 'title' => 'Outer',
		'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
		'fields' => [
			[
				'id' => 'step_inner', 'type' => 'step', 'title' => 'Inner',
				'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
				'fields' => [],
			],
		],
	],
] ];
$res5 = FormValidator::validate_schema( $nested_step_in_step );
check( '5 nested step in step rejected', is_wp_error( $res5 ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Validator rejects step inside a column.
// ---------------------------------------------------------------------------
$step_in_col = [ 'fields' => [
	[
		'id' => 'row_x', 'type' => 'row',
		'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
		'columns' => [
			[ 'id' => 'col_x', 'fields' => [
				[
					'id' => 'step_inside_col', 'type' => 'step', 'title' => 'Bad',
					'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
					'fields' => [],
				],
			] ],
		],
	],
] ];
$res6 = FormValidator::validate_schema( $step_in_col );
check( '6 step inside column rejected', is_wp_error( $res6 ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Validator rejects step inside a row's column.fields (same surface).
// ---------------------------------------------------------------------------
$step_in_row_col = [ 'fields' => [
	[
		'id' => 'row_y', 'type' => 'row',
		'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
		'columns' => [
			[ 'id' => 'col_y1', 'fields' => [ $f_name ] ],
			[ 'id' => 'col_y2', 'fields' => [
				[
					'id' => 'step_dropped', 'type' => 'step', 'title' => 'Bad',
					'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
					'fields' => [],
				],
			] ],
		],
	],
] ];
$res7 = FormValidator::validate_schema( $step_in_row_col );
check( '7 step inside row.column rejected', is_wp_error( $res7 ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Validator validates required fields inside steps. Required check
//    against a flattened submission map verifies the pipeline reaches
//    fields nested inside steps.
// ---------------------------------------------------------------------------
$submitted = [ 'email' => 'a@b.test' ];
$flat_for_validation = SchemaHelper::flatten_fields( [ $step1, $step2 ] );
$missing_required = [];
foreach ( $flat_for_validation as $f ) {
	if ( ! empty( $f['required'] ) && ! array_key_exists( $f['id'], $submitted ) ) {
		$missing_required[] = $f['id'];
	}
}
check( '8 required fields nested in steps reachable',
	$missing_required, [ 'first_name', 'message' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. EntryRepository::strip_layout_keys drops step_*.
// ---------------------------------------------------------------------------
require_once $plugin_root . '/modules/storage/EntryRepository.php';
$strip = new ReflectionMethod( \NeuralForms\Storage\EntryRepository::class, 'strip_layout_keys' );
$strip->setAccessible( true );

$contaminated = [
	'first_name'      => 'Ada',
	'email'           => 'a@b.test',
	'message'         => 'Hello',
	'step_contact'    => [ 'type' => 'step', 'fields' => [] ],
	'step_message'    => 'leak attempt',
	'row_a'           => [ 'type' => 'row',  'columns' => [] ],
	'col_l'           => [ 'fields' => [] ],
];
$cleaned = $strip->invoke( null, $contaminated );

check( '9a strip drops step_* keys (object value)',  array_key_exists( 'step_contact', $cleaned ), false, $cases, $passed, $failed );
check( '9b strip drops step_* keys (string value)',  array_key_exists( 'step_message', $cleaned ), false, $cases, $passed, $failed );
check( '9c strip drops row_* keys',                  array_key_exists( 'row_a', $cleaned ),        false, $cases, $passed, $failed );
check( '9d strip drops col_* keys',                  array_key_exists( 'col_l', $cleaned ),        false, $cases, $passed, $failed );
check( '9e strip keeps real-field keys',             $cleaned,
	[ 'first_name' => 'Ada', 'email' => 'a@b.test', 'message' => 'Hello' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. Strip also drops by type marker (defensive for arrays without prefix).
// ---------------------------------------------------------------------------
$contaminated_typed = [
	'name'   => 'Ada',
	'weird'  => [ 'type' => 'step', 'title' => 'oops' ],
];
$cleaned_typed = $strip->invoke( null, $contaminated_typed );
check( '10 strip drops array with type==="step"',    array_key_exists( 'weird', $cleaned_typed ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. Backward compatibility: old flat schema and row/column-only
//     schema produce identical flatten/validate output as before steps.
// ---------------------------------------------------------------------------
$flat = [ 'fields' => [ $f_name, $f_email, $f_msg ] ];
$flat_valid = FormValidator::validate_schema( $flat );
$flat_flat  = SchemaHelper::flatten_fields( $flat['fields'] );
check( '11a flat schema still validates',            $flat_valid === true, true, $cases, $passed, $failed );
check( '11b flat flatten unchanged',                 array_column( $flat_flat, 'id' ),
	[ 'first_name', 'email', 'message' ], $cases, $passed, $failed );

$row_only = [ 'fields' => [
	[
		'id' => 'row_z', 'type' => 'row',
		'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
		'columns' => [
			[ 'id' => 'col_z1', 'fields' => [ $f_name ] ],
			[ 'id' => 'col_z2', 'fields' => [ $f_email ] ],
		],
	],
] ];
$row_only_valid = FormValidator::validate_schema( $row_only );
$row_only_flat  = SchemaHelper::flatten_fields( $row_only['fields'] );
check( '11c row/column-only still validates',        $row_only_valid === true, true, $cases, $passed, $failed );
check( '11d row/column-only flatten unchanged',      array_column( $row_only_flat, 'id' ),
	[ 'first_name', 'email' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 12. normalize_step + normalize_schema canonicalisation.
// ---------------------------------------------------------------------------
$bare_step = [ 'id' => 'step_bare', 'type' => 'step' ];
$norm_bare = SchemaHelper::normalize_step( $bare_step );
check( '12a normalize_step defaults missing title',          $norm_bare['title'],        '', $cases, $passed, $failed );
check( '12b normalize_step defaults missing description',    $norm_bare['description'],  '', $cases, $passed, $failed );
check( '12c normalize_step defaults button_next',            $norm_bare['settings']['button_next'],     'Next', $cases, $passed, $failed );
check( '12d normalize_step defaults button_previous',        $norm_bare['settings']['button_previous'], 'Back', $cases, $passed, $failed );
check( '12e normalize_step defaults fields[]',               $norm_bare['fields'], [], $cases, $passed, $failed );

$schema_norm = SchemaHelper::normalize_schema( [ 'fields' => $two_steps ] );
check( '12f normalize_schema preserves step count',          count( $schema_norm['fields'] ), 2, $cases, $passed, $failed );
check( '12g normalize_schema first step id intact',          $schema_norm['fields'][0]['id'], 'step_contact', $cases, $passed, $failed );
check( '12h normalize_schema first step title intact',       $schema_norm['fields'][0]['title'], 'Contact', $cases, $passed, $failed );
check( '12i normalize_schema first step inner field count',  count( $schema_norm['fields'][0]['fields'] ), 2, $cases, $passed, $failed );

// Normalize drops a nested step on lenient read.
$step_with_nested = [
	'id' => 'step_outer2', 'type' => 'step', 'title' => 'Outer',
	'fields' => [
		$f_name,
		[ 'id' => 'step_inner2', 'type' => 'step', 'title' => 'Inner', 'fields' => [] ],
	],
];
$norm_nested = SchemaHelper::normalize_step( $step_with_nested );
check( '12j normalize drops nested step inside step',
	array_column( $norm_nested['fields'], 'id' ), [ 'first_name' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 13. Mixed top-level schema: a flat field, a row, AND a step coexist
//     and all three flatten in source order.
// ---------------------------------------------------------------------------
$mixed_top = [
	$f_name,
	[
		'id' => 'row_mid', 'type' => 'row',
		'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
		'columns' => [ [ 'id' => 'col_mid', 'fields' => [ $f_email ] ] ],
	],
	[
		'id' => 'step_end', 'type' => 'step', 'title' => 'Last',
		'settings' => [ 'button_next' => 'Send', 'button_previous' => 'Back' ],
		'fields' => [ $f_msg ],
	],
];
$mixed_valid = FormValidator::validate_schema( [ 'fields' => $mixed_top ] );
check( '13a mixed top-level schema validates',       $mixed_valid === true, true, $cases, $passed, $failed );
$mixed_flat = SchemaHelper::flatten_fields( $mixed_top );
check( '13b mixed schema flatten preserves order',   array_column( $mixed_flat, 'id' ),
	[ 'first_name', 'email', 'message' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 14. Frontend renderer (Phase 3): step wrappers, nav markup,
//     stacked fallback, no name="step_*", multi-step flag on form.
// ---------------------------------------------------------------------------

function nf_make_form( array $top_fields ): array {
	return [
		'id'          => 0,
		'name'        => 'Test',
		'status'      => 'active',
		'form_schema' => [ 'fields' => $top_fields ],
		'settings'    => [],
	];
}

// 14a. Two-step schema renders step wrappers with data-step-index.
$two_step_form = nf_make_form( [ $step1, $step2 ] );
$html_two_step = FormRenderer::render_preview_form( $two_step_form );

check( '14a render emits first step wrapper',          strpos( $html_two_step, 'data-step-index="0"' ) !== false, true, $cases, $passed, $failed );
check( '14a render emits second step wrapper',         strpos( $html_two_step, 'data-step-index="1"' ) !== false, true, $cases, $passed, $failed );
check( '14a render emits step container class',        strpos( $html_two_step, 'class="neural-forms-form__step"' ) !== false, true, $cases, $passed, $failed );

// 14b. Step title and description render.
check( '14b render emits step title',                  strpos( $html_two_step, '<h3 class="neural-forms-form__step-title">Contact</h3>' ) !== false, true, $cases, $passed, $failed );
check( '14b render emits step description',           strpos( $html_two_step, 'Tell us how to reach you.' ) !== false, true, $cases, $passed, $failed );

// 14c. Form gains data-neural-forms-multistep="1" + step count.
check( '14c form has multistep attribute',             strpos( $html_two_step, 'data-neural-forms-multistep="1"' ) !== false, true, $cases, $passed, $failed );
check( '14c form has step-count attribute',            strpos( $html_two_step, 'data-neural-forms-step-count="2"' ) !== false, true, $cases, $passed, $failed );

// 14d. Steps-nav buttons present with correct labels.
check( '14d nav block present',                        strpos( $html_two_step, 'class="neural-forms-form__steps-nav"' ) !== false, true, $cases, $passed, $failed );
check( '14d prev button present',                      strpos( $html_two_step, 'data-step-action="prev"' ) !== false, true, $cases, $passed, $failed );
check( '14d next button present',                      strpos( $html_two_step, 'data-step-action="next"' ) !== false, true, $cases, $passed, $failed );
check( '14d next button label from first step',        strpos( $html_two_step, '>Next</button>' ) !== false, true, $cases, $passed, $failed );
check( '14d prev button label from first step',        strpos( $html_two_step, '>Back</button>' ) !== false, true, $cases, $passed, $failed );

// 14e. Submit button still present (graceful no-JS fallback) and
//      tagged as a multistep submit.
check( '14e submit present with multistep modifier',   strpos( $html_two_step, 'neural-forms-form__submit--multistep' ) !== false, true, $cases, $passed, $failed );
check( '14e submit type=submit present',               strpos( $html_two_step, 'type="submit"' ) !== false, true, $cases, $passed, $failed );

// 14f. Step IDs never emitted as input names.
check( '14f no name="step_contact" input',             strpos( $html_two_step, 'name="step_contact"' ) === false, true, $cases, $passed, $failed );
check( '14f no name="step_message" input',             strpos( $html_two_step, 'name="step_message"' ) === false, true, $cases, $passed, $failed );
check( '14f real field name="first_name" present',     strpos( $html_two_step, 'name="first_name"' ) !== false, true, $cases, $passed, $failed );
check( '14f real field name="email" present',          strpos( $html_two_step, 'name="email"' ) !== false, true, $cases, $passed, $failed );
check( '14f real field name="message" present',        strpos( $html_two_step, 'name="message"' ) !== false, true, $cases, $passed, $failed );

// 14g. Per-step button label data-attributes for JS swap.
check( '14g step carries data-step-button-next',       strpos( $html_two_step, 'data-step-button-next="Next"' ) !== false, true, $cases, $passed, $failed );
check( '14g second step carries Send label',           strpos( $html_two_step, 'data-step-button-next="Send"' ) !== false, true, $cases, $passed, $failed );

// 14h. Single-step form: no nav block, no multistep flag.
$single_step_form = nf_make_form( [ $step1 ] );
$html_single = FormRenderer::render_preview_form( $single_step_form );
check( '14h single-step has no nav block',             strpos( $html_single, 'class="neural-forms-form__steps-nav"' ) === false, true, $cases, $passed, $failed );
check( '14h single-step has no multistep flag',        strpos( $html_single, 'data-neural-forms-multistep' ) === false, true, $cases, $passed, $failed );
check( '14h single-step still emits step wrapper',     strpos( $html_single, 'class="neural-forms-form__step"' ) !== false, true, $cases, $passed, $failed );

// 14i. Flat (no-step) schema is unchanged - no step markup at all.
$flat_form = nf_make_form( [ $f_name, $f_email ] );
$html_flat = FormRenderer::render_preview_form( $flat_form );
check( '14i flat schema emits no step wrapper',        strpos( $html_flat, 'neural-forms-form__step' ) === false, true, $cases, $passed, $failed );
check( '14i flat schema emits no nav block',           strpos( $html_flat, 'neural-forms-form__steps-nav' ) === false, true, $cases, $passed, $failed );
check( '14i flat schema emits no multistep flag',      strpos( $html_flat, 'data-neural-forms-multistep' ) === false, true, $cases, $passed, $failed );
check( '14i flat schema renders fields normally',      strpos( $html_flat, 'name="first_name"' ) !== false, true, $cases, $passed, $failed );

// 14j. Mixed root field + steps does not fatal and emits both.
$mixed_form = nf_make_form( [ $f_name, $step2 ] );
$html_mixed = FormRenderer::render_preview_form( $mixed_form );
check( '14j mixed form renders root field',            strpos( $html_mixed, 'name="first_name"' ) !== false, true, $cases, $passed, $failed );
check( '14j mixed form renders step wrapper',          strpos( $html_mixed, 'data-step-index="0"' ) !== false, true, $cases, $passed, $failed );
check( '14j mixed form has no nav (only 1 step)',      strpos( $html_mixed, 'data-neural-forms-multistep' ) === false, true, $cases, $passed, $failed );

// 14k. Row inside step renders correctly with column wrapper class
//      and the inner field's name attribute.
$step_with_row_form = nf_make_form( [ $step_with_row ] );
$html_step_row = FormRenderer::render_preview_form( $step_with_row_form );
check( '14k row inside step emits row wrapper',        strpos( $html_step_row, 'neural-forms-form__row--cols-2' ) !== false, true, $cases, $passed, $failed );
check( '14k row inside step emits column',             strpos( $html_step_row, 'neural-forms-form__row__column' ) !== false, true, $cases, $passed, $failed );
check( '14k inner field of step row keeps name',       strpos( $html_step_row, 'name="email"' ) !== false, true, $cases, $passed, $failed );
check( '14k step wrapper still emitted',               strpos( $html_step_row, 'class="neural-forms-form__step"' ) !== false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
echo "\n";
foreach ( $cases as [ $ok, $label, $actual, $expected ] ) {
	$mark = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
	echo "$mark  $label\n";
	if ( ! $ok ) {
		echo "       expected: " . var_export( $expected, true ) . "\n";
		echo "       actual:   " . var_export( $actual,   true ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed === 0 ? 0 : 1 );
