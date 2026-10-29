<?php
/**
 * Matrix analyze-payload enrichment tests.
 *
 * Asserts that NeuralFormsCloudProvider::format_entry replaces raw
 * matrix values with the structured envelope the cloud's
 * matrix-signals module expects:
 *
 *   {
 *     _matrix: true,
 *     label: "...",
 *     selection_type: "radio" | "checkbox",
 *     rows: [...], columns: [...],
 *     value: <stored>,
 *   }
 *
 * Non-matrix fields must pass through untouched so other field types
 * keep their existing wire shape.
 *
 * Run from the plugin root:
 *
 *   php tests/MatrixAnalyzePayloadTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op once the line above defines ABSPATH).
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'site_url' ) ) { function site_url( $path = '' ) { return 'https://example.com' . $path; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return is_string( $s ) ? $s : ''; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return is_scalar( $s ) ? (string) $s : ''; } }
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) { return new \WP_Error( 'no_http', 'http disabled in tests' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) )         { function wp_remote_retrieve_body( $r ) { return ''; } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return 0; } }
if ( ! function_exists( 'is_wp_error' ) )                      { function is_wp_error( $r ) { return $r instanceof \WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) )                   { function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) define( 'NEURAL_FORMS_VERSION', '1.1.0-test' );
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = '' ) { return $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { return true; } }

// AIRepository stub — provider's constructor reads from Config, not
// the repository directly. Stubbed so unused references don't error.
if ( ! class_exists( 'NeuralForms\\Core\\Config' ) ) {
	eval( 'namespace NeuralForms\\Core; class Config { public static function get( $k, $d = "" ) { return $d; } public static function set( $k, $v ) {} }' );
}
if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}
if ( ! class_exists( 'NeuralForms\\Plan\\PlanManager' ) ) {
	eval( 'namespace NeuralForms\\Plan; class PlanManager { public static function apply_usage( $u ) {} }' );
}
if ( ! class_exists( 'NeuralForms\\AI\\AIRepository' ) ) {
	eval( 'namespace NeuralForms\\AI; class AIRepository {}' );
}
if ( ! interface_exists( 'NeuralForms\\AI\\Contracts\\AIAnalysisProviderInterface' ) ) {
	eval( 'namespace NeuralForms\\AI\\Contracts; interface AIAnalysisProviderInterface { public function analyze( array $entry, array $form ): array; public function regenerate_reply( array $entry, array $form ): array; public function polish_reply( string $draft, array $entry, array $form ): array; public function provider_id(): string; }' );
}
if ( ! class_exists( 'NeuralForms\\AI\\Exceptions\\CloudPlanException' ) ) {
	eval( 'namespace NeuralForms\\AI\\Exceptions; class CloudPlanException extends \\Exception {}' );
}
if ( ! class_exists( 'NeuralForms\\AI\\Exceptions\\CloudSafetyException' ) ) {
	eval( 'namespace NeuralForms\\AI\\Exceptions; class CloudSafetyException extends \\Exception {}' );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/ai/Providers/NeuralFormsCloudProvider.php';

use NeuralForms\AI\Providers\NeuralFormsCloudProvider;

// Reflect into the private format_entry so we can exercise it in
// isolation without hitting the wire.
function nf_format_entry( array $entry, array $form ): array {
	static $method = null;
	if ( $method === null ) {
		$class = new \ReflectionClass( NeuralFormsCloudProvider::class );
		$method = $class->getMethod( 'format_entry' );
		$method->setAccessible( true );
	}
	$class = new \ReflectionClass( NeuralFormsCloudProvider::class );
	$instance = $class->newInstanceWithoutConstructor();
	return $method->invoke( $instance, $entry, $form );
}

// ─────────────────────── Test harness ───────────────────────

$cases = []; $passed = 0; $failed = 0;
function nf_test( string $name, callable $fn ): void { global $cases; $cases[] = [ 'name' => $name, 'fn' => $fn ]; }
function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException( $msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true ) );
	}
}
function nf_assert_true( $cond, string $msg ): void { if ( ! $cond ) throw new \RuntimeException( $msg ); }

// ─────────────────────── Fixtures ───────────────────────

function nf_matrix_form( string $selection = 'radio', int $matrix_score_max = 5 ): array {
	$cols = [
		[ 'label' => 'Very Unsatisfied', 'value' => 'very_unsatisfied', 'score' => 1 ],
		[ 'label' => 'Unsatisfied',      'value' => 'unsatisfied',      'score' => 2 ],
		[ 'label' => 'Neutral',          'value' => 'neutral',          'score' => 3 ],
		[ 'label' => 'Satisfied',        'value' => 'satisfied',        'score' => 4 ],
		[ 'label' => 'Very Satisfied',   'value' => 'very_satisfied',   'score' => 5 ],
	];
	return [
		'id'    => 7,
		'title' => 'Feedback',
		'form_schema' => [
			'fields' => [
				[ 'id' => 'name',  'type' => 'text',  'label' => 'Name' ],
				[
					'id'    => 'satisfaction',
					'type'  => 'matrix',
					'label' => 'How satisfied are you with',
					'settings' => [
						'selection_type' => $selection,
						'rows' => [
							[ 'label' => 'Purchase',        'value' => 'purchase' ],
							[ 'label' => 'Service',         'value' => 'service' ],
							[ 'label' => 'Company Overall', 'value' => 'company_overall' ],
						],
						'columns' => $cols,
					],
				],
			],
		],
	];
}

// ─────────────────────── Tests ───────────────────────

nf_test( 'matrix radio entry → value replaced with _matrix envelope', function () {
	$entry = [
		'id'         => 42,
		'data'       => [
			'name'         => 'Alice',
			'satisfaction' => [
				'purchase'        => 'very_unsatisfied',
				'service'         => 'unsatisfied',
				'company_overall' => 'neutral',
			],
		],
		'created_at' => '2026-05-11 12:00:00',
	];
	$out = nf_format_entry( $entry, nf_matrix_form( 'radio' ) );

	// Non-matrix field untouched.
	nf_assert_same( 'Alice', $out['fields']['name'], 'name field must pass through unchanged' );

	$wrap = $out['fields']['satisfaction'];
	nf_assert_true( is_array( $wrap ), 'matrix field must be enriched to an array' );
	nf_assert_same( true,           $wrap['_matrix']        ?? null, '_matrix:true discriminator must be present' );
	nf_assert_same( 'radio',        $wrap['selection_type'] ?? null, 'selection_type forwarded from schema' );
	nf_assert_same( 'How satisfied are you with', $wrap['label'] ?? null, 'label forwarded from schema' );
	nf_assert_same( 3, count( $wrap['rows'] ?? [] ), 'three rows forwarded' );
	nf_assert_same( 5, count( $wrap['columns'] ?? [] ), 'five columns forwarded' );
	nf_assert_same( 'Very Unsatisfied', $wrap['columns'][0]['label'] ?? null, 'first column label preserved' );
	nf_assert_same( 1,                  $wrap['columns'][0]['score'] ?? null, 'first column score preserved as int' );
	nf_assert_same(
		[ 'purchase' => 'very_unsatisfied', 'service' => 'unsatisfied', 'company_overall' => 'neutral' ],
		$wrap['value'] ?? null,
		'original stored value carried under .value'
	);
} );

nf_test( 'matrix checkbox entry → value replaced with _matrix envelope', function () {
	$entry = [
		'id'   => 43,
		'data' => [
			'satisfaction' => [
				'purchase' => [ 'unsatisfied' ],
			],
		],
		'created_at' => '2026-05-11 12:00:00',
	];
	$out = nf_format_entry( $entry, nf_matrix_form( 'checkbox' ) );
	$wrap = $out['fields']['satisfaction'];
	nf_assert_same( 'checkbox', $wrap['selection_type'] ?? null, 'checkbox selection_type forwarded' );
	nf_assert_same( [ 'unsatisfied' ], $wrap['value']['purchase'] ?? null, 'checkbox stored value forwarded as array' );
} );

nf_test( 'entry without form schema → format_entry leaves matrix untouched', function () {
	$entry = [
		'id' => 44,
		'data' => [ 'satisfaction' => [ 'purchase' => 'very_unsatisfied' ] ],
	];
	// No form passed → enrichment skipped, stored array survives as-is.
	$out = nf_format_entry( $entry, [] );
	nf_assert_same(
		[ 'purchase' => 'very_unsatisfied' ],
		$out['fields']['satisfaction'] ?? null,
		'with no schema the provider cannot enrich — raw value preserved'
	);
} );

nf_test( 'matrix field with no stored data → not enriched, not invented', function () {
	$entry = [ 'id' => 45, 'data' => [ 'name' => 'Bob' ] ];
	$out = nf_format_entry( $entry, nf_matrix_form() );
	nf_assert_true( ! array_key_exists( 'satisfaction', $out['fields'] ),
		'matrix without a stored entry value must not appear in payload' );
} );

nf_test( 'matrix nested inside step + row + column is still discovered', function () {
	$form = [
		'id'    => 7,
		'title' => 'Wizard',
		'form_schema' => [
			'fields' => [
				[
					'id'   => 'step_1',
					'type' => 'step',
					'fields' => [
						[
							'id'      => 'row_1',
							'type'    => 'row',
							'columns' => [
								[
									'id'     => 'col_1',
									'fields' => [
										[
											'id'    => 'satisfaction',
											'type'  => 'matrix',
											'label' => 'Rate us',
											'settings' => [
												'selection_type' => 'radio',
												'rows'    => [ [ 'label' => 'Purchase', 'value' => 'purchase' ] ],
												'columns' => [
													[ 'label' => 'Bad',  'value' => 'bad',  'score' => 1 ],
													[ 'label' => 'Good', 'value' => 'good', 'score' => 5 ],
												],
											],
										],
									],
								],
							],
						],
					],
				],
			],
		],
	];
	$entry = [ 'id' => 46, 'data' => [ 'satisfaction' => [ 'purchase' => 'bad' ] ] ];
	$out = nf_format_entry( $entry, $form );
	nf_assert_same( true, $out['fields']['satisfaction']['_matrix'] ?? null,
		'matrix nested under step→row→column must still be enriched' );
} );

nf_test( 'columns without a numeric score → score key omitted, label kept', function () {
	$form = [
		'id'    => 7,
		'title' => 'Likert without scores',
		'form_schema' => [
			'fields' => [
				[
					'id'    => 'opinion',
					'type'  => 'matrix',
					'label' => 'Agreement',
					'settings' => [
						'selection_type' => 'radio',
						'rows'    => [ [ 'label' => 'Statement A', 'value' => 'a' ] ],
						'columns' => [
							[ 'label' => 'Disagree', 'value' => 'disagree' ],
							[ 'label' => 'Agree',    'value' => 'agree'    ],
						],
					],
				],
			],
		],
	];
	$entry = [ 'id' => 47, 'data' => [ 'opinion' => [ 'a' => 'disagree' ] ] ];
	$out   = nf_format_entry( $entry, $form );
	$cols  = $out['fields']['opinion']['columns'] ?? [];
	nf_assert_true( ! array_key_exists( 'score', $cols[0] ),
		'absent score must not be invented' );
	nf_assert_same( 'Disagree', $cols[0]['label'] ?? null, 'label preserved when no score' );
} );

// ─────────────────────── Run ───────────────────────

foreach ( $cases as $case ) {
	try {
		( $case['fn'] )();
		echo "PASS  " . $case['name'] . PHP_EOL;
		$passed++;
	} catch ( \Throwable $e ) {
		echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL;
		$failed++;
	}
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
