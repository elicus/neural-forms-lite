<?php
/**
 * Public poll results tests (Phase 3).
 *
 * Exercises:
 *   - FormRenderer: results_visibility="never"      omits the placeholder.
 *   - FormRenderer: results_visibility="after_vote" emits hidden empty placeholder.
 *   - FormRenderer: results_visibility="always"     pre-renders the result bars
 *     server-side using EntryRepository::aggregate_poll_votes(), with both
 *     chart_type="bar" (track + fill) and chart_type="percentage" (head only).
 *   - SubmitEndpoint::build_poll_results_payload (via reflection) attaches
 *     poll results for every visibility != "never" poll on the form,
 *     omits "never" polls, returns [] when no poll fields exist,
 *     groups unknown values under "__other__".
 *   - Existing schema/validate tests remain green (run from the harness).
 *
 * Run from the plugin root:
 *
 *   php tests/PollPublicResultsTest.php
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
if ( ! function_exists( 'current_time' ) )    { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return false; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $h ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $h, $src = '', $deps = [], $ver = false, $in_footer = false ) {}
}
if ( ! function_exists( 'is_email' ) ) { function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } }
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) { define( 'NEURAL_FORMS_VERSION', '1.1.0-test' ); }
if ( ! defined( 'NEURAL_FORMS_CAP_MANAGE_FORMS' ) ) {
	define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' );
}
$GLOBALS['__nf_options'] = [];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = [] ) { return $GLOBALS['__nf_options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__nf_options'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }

// $wpdb shim covering aggregate_poll_votes().
class NF_Public_WPDB {
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
}
$GLOBALS['wpdb'] = new NF_Public_WPDB();

// Stub Logger (used by EntryRepository).
if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';
require_once $plugin_root . '/modules/core/Config.php';
require_once $plugin_root . '/modules/frontend/FormRenderer.php';

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
$poll_options = [
	[ 'label' => 'Website Design',         'value' => 'website_design' ],
	[ 'label' => 'WordPress Development',  'value' => 'wordpress_development' ],
	[ 'label' => 'Maintenance',            'value' => 'maintenance' ],
];

function nf_make_form( int $form_id, array $fields ): array {
	return [
		'id'          => $form_id,
		'name'        => 'Test',
		'status'      => 'active',
		'form_schema' => [ 'fields' => $fields ],
		'settings'    => [],
	];
}

function nf_poll( string $id, array $opts, string $visibility = 'after_vote', string $chart = 'bar' ): array {
	return [
		'id'       => $id,
		'type'     => 'poll',
		'label'    => 'Q?',
		'options'  => $opts,
		'settings' => [
			'results_visibility' => $visibility,
			'chart_type'         => $chart,
			'allow_multiple'     => false,
			'randomize_options'  => false,
		],
	];
}

// ---------------------------------------------------------------------------
// 1. results_visibility="never" omits placeholder.
// ---------------------------------------------------------------------------
$poll_never = nf_poll( 'poll_never', $poll_options, 'never' );
$html_never = FormRenderer::render_preview_form( nf_make_form( 12, [ $poll_never ] ) );
check( '1a never omits results placeholder',
	strpos( $html_never, 'neural-forms-form__poll-results' ) === false, true, $cases, $passed, $failed );
check( '1b never still emits poll fieldset',
	strpos( $html_never, 'class="neural-forms-form__poll"' ) !== false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. results_visibility="after_vote" emits hidden empty placeholder.
// ---------------------------------------------------------------------------
$poll_after = nf_poll( 'poll_after', $poll_options, 'after_vote' );
$html_after = FormRenderer::render_preview_form( nf_make_form( 12, [ $poll_after ] ) );

check( '2a after_vote emits placeholder div',
	strpos( $html_after, 'data-poll-results="poll_after"' ) !== false, true, $cases, $passed, $failed );
check( '2b after_vote placeholder is hidden',
	(bool) preg_match( '/data-poll-results="poll_after"[^>]*hidden/', $html_after ), true, $cases, $passed, $failed );
check( '2c after_vote placeholder has no inner bars',
	strpos( $html_after, 'neural-forms-form__poll-bars' ) === false, true, $cases, $passed, $failed );
check( '2d after_vote placeholder has no inner total',
	strpos( $html_after, 'neural-forms-form__poll-total' ) === false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. results_visibility="always" pre-renders bars at SSR time.
// ---------------------------------------------------------------------------
$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_always' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_always' => 'website_design' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_always' => 'maintenance' ] ) ],
];
$poll_always = nf_poll( 'poll_always', $poll_options, 'always', 'bar' );
$html_always = FormRenderer::render_preview_form( nf_make_form( 12, [ $poll_always ] ) );

check( '3a always emits placeholder div',
	strpos( $html_always, 'data-poll-results="poll_always"' ) !== false, true, $cases, $passed, $failed );
check( '3b always placeholder is NOT hidden',
	(bool) preg_match( '/data-poll-results="poll_always"[^>]*hidden/', $html_always ), false, $cases, $passed, $failed );
check( '3c always emits total votes line',
	strpos( $html_always, 'Total votes: 3' ) !== false, true, $cases, $passed, $failed );
check( '3d always emits bars list',
	strpos( $html_always, 'class="neural-forms-form__poll-bars"' ) !== false, true, $cases, $passed, $failed );
check( '3e always emits bar track for chart=bar',
	strpos( $html_always, 'neural-forms-form__poll-bar-track' ) !== false, true, $cases, $passed, $failed );
check( '3f always renders website_design bar with 2 votes (66.67%)',
	(bool) preg_match( '/Website Design[^<]*<\/span>\s*<span[^>]*>2 \(66\.67%\)/', $html_always ),
	true, $cases, $passed, $failed );
check( '3g always renders maintenance bar with 1 vote (33.33%)',
	(bool) preg_match( '/Maintenance[^<]*<\/span>\s*<span[^>]*>1 \(33\.33%\)/', $html_always ),
	true, $cases, $passed, $failed );
check( '3h zero-vote option still rendered (wordpress_development 0)',
	(bool) preg_match( '/WordPress Development[^<]*<\/span>\s*<span[^>]*>0 \(0%\)/', $html_always ),
	true, $cases, $passed, $failed );

// 3i. Bar fill width matches percentage.
check( '3i bar fill width=66.67%',
	strpos( $html_always, 'style="width:66.67%"' ) !== false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. chart_type="percentage" omits the bar track.
// ---------------------------------------------------------------------------
$poll_pct = nf_poll( 'poll_pct', $poll_options, 'always', 'percentage' );
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_pct' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_pct' => 'maintenance' ] ) ],
];
$html_pct = FormRenderer::render_preview_form( nf_make_form( 12, [ $poll_pct ] ) );

check( '4a percentage chart still emits bars list',
	strpos( $html_pct, 'class="neural-forms-form__poll-bars"' ) !== false, true, $cases, $passed, $failed );
check( '4b percentage chart sets data-poll-chart=percentage',
	strpos( $html_pct, 'data-poll-chart="percentage"' ) !== false, true, $cases, $passed, $failed );
check( '4c percentage chart OMITS bar-track',
	strpos( $html_pct, 'neural-forms-form__poll-bar-track' ) === false, true, $cases, $passed, $failed );
check( '4d percentage chart still emits count text (50%)',
	strpos( $html_pct, '1 (50%)' ) !== false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. always with unknown old values: collapses into "Other" SSR row.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_always' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_always' => 'removed_option' ] ) ],
];
$html_other = FormRenderer::render_preview_form( nf_make_form( 12, [ $poll_always ] ) );
check( '5a SSR includes Other row when unknown values present',
	strpos( $html_other, '>Other<' ) !== false, true, $cases, $passed, $failed );
check( '5b SSR Other row carries 1 vote (50%)',
	(bool) preg_match( '/>Other<\/span>\s*<span[^>]*>1 \(50%\)/', $html_other ),
	true, $cases, $passed, $failed );

// 5c. No unknown values -> no Other row.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_always' => 'website_design' ] ) ],
];
$html_no_other = FormRenderer::render_preview_form( nf_make_form( 12, [ $poll_always ] ) );
check( '5c no Other row when only known values',
	strpos( $html_no_other, '>Other<' ) === false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Submit-response payload helper (via reflection).
//
// The endpoint's normal HTTP path requires a fully-bootstrapped
// SubmitEndpoint -> CaptchaManager -> Logger pipeline, which is more
// than a unit test should reach for. The poll-results piggyback is
// computed by SubmitEndpoint::build_poll_results_payload(), so we
// invoke that directly with the same signature it sees in production.
// ---------------------------------------------------------------------------

// Stub FormRepository for the endpoint constructor's dependency tree
// (it's only used by the live submit() path; build_poll_results_payload
// takes the already-loaded form_schema array directly).
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormRepository { public static function find( int $id ): ?array { return null; } }' );
}

// Stub the rest of SubmitEndpoint's import surface that PHP must
// resolve to load the file. None of these are touched by the helper.
foreach ( [ 'CaptchaManager' => 'Captcha', 'Logger2' => 'Core' ] as $stub => $ns ) { /* no-op marker */ }

// Provide minimal stand-ins for the imports SubmitEndpoint declares.
if ( ! class_exists( 'NeuralForms\\Captcha\\CaptchaManager' ) ) {
	eval( 'namespace NeuralForms\\Captcha; class CaptchaManager {}' );
}
// Allow eventual SubmitEndpoint bootstrap. WP_REST_Request just needs
// to exist for the typehint; the helper path never instantiates it.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {}
}

require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/SubmitEndpoint.php';

use NeuralForms\Rest\Endpoints\SubmitEndpoint;

$endpoint = new SubmitEndpoint();
$ref      = new ReflectionMethod( $endpoint, 'build_poll_results_payload' );
$ref->setAccessible( true );

$call = function ( int $form_id, array $schema ) use ( $ref, $endpoint ) {
	return $ref->invoke( $endpoint, $form_id, $schema );
};

// 6a. Form with no poll fields -> empty array (no `polls` key).
$plain_schema = [ 'fields' => [
	[ 'id' => 'name',  'type' => 'text',  'label' => 'Name' ],
	[ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ],
] ];
check( '6a no poll fields -> empty payload', $call( 12, $plain_schema ), [], $cases, $passed, $failed );

// 6b. visibility="never" poll is omitted from the payload.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_never' => 'website_design' ] ) ],
];
$schema_never_only = [ 'fields' => [ nf_poll( 'poll_never', $poll_options, 'never' ) ] ];
check( '6b never-only schema returns empty payload', $call( 12, $schema_never_only ), [], $cases, $passed, $failed );

// 6c. after_vote poll is included with totals + chart_type + visibility.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_after' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_after' => 'website_design' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_after' => 'maintenance' ] ) ],
];
$schema_after = [ 'fields' => [ nf_poll( 'poll_after', $poll_options, 'after_vote', 'bar' ) ] ];
$payload_after = $call( 12, $schema_after );

check( '6c after_vote payload keyed by field id',
	array_keys( $payload_after ), [ 'poll_after' ], $cases, $passed, $failed );
check( '6c after_vote total_votes',
	$payload_after['poll_after']['total_votes'] ?? null, 3, $cases, $passed, $failed );
check( '6c after_vote chart_type echoed',
	$payload_after['poll_after']['chart_type'] ?? null, 'bar', $cases, $passed, $failed );
check( '6c after_vote visibility echoed',
	$payload_after['poll_after']['results_visibility'] ?? null, 'after_vote', $cases, $passed, $failed );
check( '6c after_vote options preserve schema order',
	array_column( $payload_after['poll_after']['options'], 'value' ),
	[ 'website_design', 'wordpress_development', 'maintenance' ], $cases, $passed, $failed );
check( '6c website_design percentage 66.67',
	$payload_after['poll_after']['options'][0]['percentage'] ?? null, 66.67, $cases, $passed, $failed );
check( '6c maintenance percentage 33.33',
	$payload_after['poll_after']['options'][2]['percentage'] ?? null, 33.33, $cases, $passed, $failed );

// 6d. Mixed schema: after_vote + always + never. Only after_vote and
//     always are emitted; never is dropped.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_after' => 'website_design', 'poll_always' => 'maintenance', 'poll_never' => 'website_design' ] ) ],
];
$mixed_schema = [ 'fields' => [
	nf_poll( 'poll_after',  $poll_options, 'after_vote' ),
	nf_poll( 'poll_always', $poll_options, 'always' ),
	nf_poll( 'poll_never',  $poll_options, 'never' ),
] ];
$payload_mixed = $call( 12, $mixed_schema );
check( '6d mixed payload keys (after_vote + always only)',
	array_keys( $payload_mixed ), [ 'poll_after', 'poll_always' ], $cases, $passed, $failed );
check( '6d never poll absent from payload',
	array_key_exists( 'poll_never', $payload_mixed ), false, $cases, $passed, $failed );

// 6e. Unknown values bucket: collapsed into "__other__".
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_after' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_after' => 'removed_option' ] ) ],
];
$payload_other = $call( 12, $schema_after );
$values_other = array_column( $payload_other['poll_after']['options'], 'value' );
check( '6e __other__ present in payload when unknown values exist',
	in_array( '__other__', $values_other, true ), true, $cases, $passed, $failed );

// 6f. Poll inside row + step + step->row->col is reachable.
$schema_deep = [ 'fields' => [
	[
		'id' => 'row_a', 'type' => 'row',
		'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
		'columns' => [ [ 'id' => 'col_a', 'fields' => [ nf_poll( 'poll_in_row', $poll_options, 'after_vote' ) ] ] ],
	],
	[
		'id' => 'step_a', 'type' => 'step', 'title' => 'S',
		'settings' => [ 'button_next' => 'Next', 'button_previous' => 'Back' ],
		'fields' => [ nf_poll( 'poll_in_step', $poll_options, 'after_vote' ) ],
	],
] ];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_in_row' => 'website_design', 'poll_in_step' => 'maintenance' ] ) ],
];
$payload_deep = $call( 12, $schema_deep );
check( '6f payload reaches polls inside row.column AND step',
	array_keys( $payload_deep ), [ 'poll_in_row', 'poll_in_step' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Required / invalid poll validation still works (regression of
//    Phase 1 contracts on a Phase 3 surface).
// ---------------------------------------------------------------------------
$poll_required = nf_poll( 'poll_after', $poll_options, 'after_vote' );
$poll_required['required'] = true;
$res_missing = SubmissionValidator::validate( [ $poll_required ], [] );
check( '7a required poll missing value still fails', is_wp_error( $res_missing ),                                 true,            $cases, $passed, $failed );
$err = $res_missing instanceof WP_Error ? $res_missing->get_error_data() : [];
check( '7b error code remains "required"',           $err['fields']['poll_after'][0]['code'] ?? null,             'required',      $cases, $passed, $failed );

$res_bad = SubmissionValidator::validate( [ $poll_required ], [ 'poll_after' => 'tampered' ] );
check( '7c invalid value still rejected',            is_wp_error( $res_bad ),                                     true,            $cases, $passed, $failed );

$res_ok = SubmissionValidator::validate( [ $poll_required ], [ 'poll_after' => 'website_design' ] );
check( '7d valid option still passes',               $res_ok,                                                     true,            $cases, $passed, $failed );

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
