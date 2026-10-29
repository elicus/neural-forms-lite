<?php
/**
 * SchemaNormalizer + FormGeneratorService (cloud-routing) tests — Lite-owned
 * since 2026-10-30 (ported from Pro's FormGeneratorSchemaTest).
 *
 * Pure unit tests — no real network call, no WordPress at runtime.
 * The cloud client is replaced via reflection / monkey-patched HTTP
 * shim so we can assert the service handles every cloud response shape
 * without spinning up a real backend.
 *
 * Run from the plugin root:
 *
 *   php tests/FormGeneratorTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );

if ( ! function_exists( '__' ) )                      { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) )     { function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; } }
if ( ! function_exists( 'sanitize_title' ) )          { function sanitize_title( $v ) { return strtolower( trim( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $v ), '-' ) ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) )       { function wp_strip_all_tags( $s, $remove_breaks = false ) { $s = preg_replace( '/<[^>]*>/', '', (string) $s ); return $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $s ) : $s; } }
if ( ! function_exists( 'is_wp_error' ) )             { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) )          { function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } }
if ( ! function_exists( 'home_url' ) )                { function home_url( $path = '' ) { return 'https://example.test' . $path; } }
if ( ! function_exists( 'get_locale' ) )              { function get_locale() { return 'en_US'; } }
if ( ! function_exists( 'get_bloginfo' ) )            { function get_bloginfo( $k ) { return ''; } }
if ( ! defined( 'NEURAL_FORMS_VERSION' ) )            { define( 'NEURAL_FORMS_VERSION', '1.2.0-lite-test' ); }

// Tiny apply_filters shim that lets a single test toggle on/off whether
// `neural_forms_cloud_request_headers` adds an extra header.
$GLOBALS['__nf_filter_inject_pro_header'] = false;
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( $tag === 'neural_forms_cloud_request_headers'
			&& ! empty( $GLOBALS['__nf_filter_inject_pro_header'] )
			&& is_array( $value )
		) {
			$value['X-Test-Pro-Filter'] = 'pro-injected';
			$value['Authorization']     = 'Bearer ATTACKER';   // must be ignored by merge
		}
		return $value;
	}
}
function add_filter_shim_ai_form(): void    { $GLOBALS['__nf_filter_inject_pro_header'] = true; }
function remove_filter_shim_ai_form(): void { $GLOBALS['__nf_filter_inject_pro_header'] = false; }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code(): string { return (string) $this->code; }
		public function get_error_message(): string { return (string) $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

// -------------------- HTTP shim: capture the request, return a fixture --------------------
$GLOBALS['__nf_http_response'] = null;
$GLOBALS['__nf_http_last']     = null;
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['__nf_http_last'] = [ 'url' => $url, 'args' => $args ];
		$r = $GLOBALS['__nf_http_response'];
		return $r === null ? new WP_Error( 'no_fixture', 'no http fixture set' ) : $r;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 200; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) )          { function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; } }

// -------------------- Lite Config + FieldRegistry stubs --------------------
$GLOBALS['__nf_config'] = [
	'cloud_api_key'      => 'sk-cloud-fixture',
	// Stale dev URL deliberately set on the fixture — proves the
	// generator IGNORES this option (parity with AiManager's behaviour
	// — see CloudFormGeneratorClient::resolve_base_url() comment).
	'cloud_api_base_url' => 'https://STALE-DEV-HOST.invalid',
	'cloud_ai_enabled'   => true,
];

// Stub Lite's AiManager::resolve_base_url() so the generator can defer
// to the same URL resolver every other AI client uses. The default
// returns the production URL — tests can override via a global to
// simulate a constant override.
$GLOBALS['__nf_ai_manager_base'] = 'https://api.neuralforms.ai';
if ( ! class_exists( 'NeuralForms\\AI\\AiManager' ) ) {
	eval( 'namespace NeuralForms\\AI; class AiManager { public static function resolve_base_url() { return $GLOBALS["__nf_ai_manager_base"]; } }' );
}
if ( ! class_exists( 'NeuralForms\\Core\\Config' ) ) {
	eval( 'namespace NeuralForms\\Core; class Config { public static function get( $key, $default = null ) { return $GLOBALS["__nf_config"][ $key ] ?? $default; } }' );
}
if ( ! class_exists( 'NeuralForms\\Forms\\FieldRegistry' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FieldRegistry { public static function is_registered( string $type ): bool { return in_array( $type, [ "text","email","textarea","select","checkbox","checkboxes","number","phone","url","date","radio","hidden","acceptance","file","rating","poll","quiz_question","matrix","repeater" ], true ); } }' );
}
// Capabilities stub. `field_matrix` is the only cap consulted by the
// normaliser; gate it via a global so individual test cases can flip
// the value mid-run to exercise the drop-when-locked path.
$GLOBALS['__nf_cap_field_matrix']   = true;
$GLOBALS['__nf_cap_field_repeater'] = true;
if ( ! class_exists( 'NeuralForms\\Core\\Capabilities' ) ) {
	eval( 'namespace NeuralForms\\Core; class Capabilities { const FIELD_MATRIX = "field_matrix"; const FIELD_REPEATER = "field_repeater"; public static function can( string $cap ): bool { if ( $cap === self::FIELD_MATRIX )   return (bool) ( $GLOBALS["__nf_cap_field_matrix"]   ?? false ); if ( $cap === self::FIELD_REPEATER ) return (bool) ( $GLOBALS["__nf_cap_field_repeater"] ?? false ); return false; } }' );
}

// PlanManager stub so status() can resolve plan + credits.
$GLOBALS['__nf_plan_payload'] = [
	'plan'         => [ 'id' => 'pro-agency', 'name' => 'Pro Agency' ],
	'balance'      => [ 'credits' => 99 ],
	'subscription' => [ 'status' => 'active' ],
];
if ( ! class_exists( 'NeuralForms\\Plan\\PlanManager' ) ) {
	eval( 'namespace NeuralForms\\Plan; class PlanManager { public static function public_payload() { return $GLOBALS["__nf_plan_payload"]; } }' );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/ai/SchemaNormalizer.php';
require_once $plugin_root . '/modules/ai/CloudFormGeneratorClient.php';
require_once $plugin_root . '/modules/ai/FormGeneratorService.php';

use NeuralForms\AI\SchemaNormalizer;
use NeuralForms\AI\FormGeneratorService;
use NeuralForms\AI\CloudFormGeneratorClient;

$cases = []; $passed = 0; $failed = 0;
function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// =============================================================================
// SECTION 1 — SchemaNormalizer (defence-in-depth, still in plugin)
// =============================================================================
$result = SchemaNormalizer::normalize( [
	'name'   => 'Contact',
	'fields' => [
		[ 'type' => 'text',     'label' => 'Name' ],
		[ 'type' => 'email',    'label' => 'Email' ],
		[ 'type' => 'textarea', 'label' => 'Message' ],
	],
] );
check( '1a normalize happy path produces a form',  is_array( $result['form'] ), true,                          $cases, $passed, $failed );
check( '1b normalize 3 fields',                    count( $result['form']['form_schema']['fields'] ?? [] ), 3,  $cases, $passed, $failed );
check( '1c ids regenerated server-side',           strpos( $result['form']['form_schema']['fields'][0]['id'] ?? '', 'field_' ), 0, $cases, $passed, $failed );

$result = SchemaNormalizer::normalize( [
	'fields' => [ [ 'type' => 'wysiwyg-html', 'label' => 'BAD' ] ],
] );
check( '1d unsupported type drop → empty form → error',
	$result['error'] !== null, true, $cases, $passed, $failed );

$result = SchemaNormalizer::normalize( [
	'fields' => [ [
		'type'    => 'quiz_question',
		'label'   => 'Q',
		'options' => [
			[ 'label' => 'A', 'is_correct' => true ],
			[ 'label' => 'B', 'is_correct' => true ],
		],
	] ],
] );
$opts = $result['form']['form_schema']['fields'][0]['options'] ?? [];
$correct = array_filter( $opts, static fn ( $o ) => ! empty( $o['is_correct'] ) );
check( '1e quiz multi-correct → only first kept',  count( $correct ), 1, $cases, $passed, $failed );

// =============================================================================
// SECTION 2 — CloudFormGeneratorClient
// =============================================================================

// 2a. Cloud disconnected (no key).
$GLOBALS['__nf_config']['cloud_api_key'] = '';
check( '2a is_connected false when no key',  CloudFormGeneratorClient::is_connected(), false, $cases, $passed, $failed );
$res = CloudFormGeneratorClient::generate( 'Hello' );
check( '2b disconnected → cloud_not_connected', $res instanceof WP_Error && $res->get_error_code() === 'cloud_not_connected', true, $cases, $passed, $failed );

$GLOBALS['__nf_config']['cloud_api_key'] = 'sk-cloud-fixture';

// 2c. Successful cloud response → pass-through.
$GLOBALS['__nf_http_response'] = [
	'code' => 200,
	'body' => json_encode( [
		'success'    => true,
		'request_id' => 'gen_abc',
		'form'       => [
			'name'        => 'Contact',
			'description' => 'Get in touch.',
			'form_schema' => [
				'fields'   => [
					[ 'id' => 'field_x', 'type' => 'text', 'label' => 'Name' ],
				],
				'settings' => [],
			],
		],
		'warnings'   => [],
		'usage'      => [ 'credits_used' => 1, 'credits_remaining' => 99 ],
	] ),
];
$res = CloudFormGeneratorClient::generate( 'A short prompt' );
check( '2c happy 200 returns array',          is_array( $res ),                       true, $cases, $passed, $failed );
check( '2d request_id passes through',         $res['request_id'] ?? '',                'gen_abc', $cases, $passed, $failed );
check( '2e usage passes through',              $res['usage']['credits_used'] ?? null,   1, $cases, $passed, $failed );

// 2f. Verify the request body shape (auth header, capabilities, prompt).
$last = $GLOBALS['__nf_http_last'];
check( '2f cloud URL targets /v1/ai/forms/generate',
	strpos( $last['url'] ?? '', '/v1/ai/forms/generate' ) !== false, true, $cases, $passed, $failed );
check( '2g Authorization header is Bearer',
	strpos( $last['args']['headers']['Authorization'] ?? '', 'Bearer ' ) === 0, true, $cases, $passed, $failed );
$body = json_decode( $last['args']['body'] ?? '{}', true );
check( '2h prompt body field set',             $body['prompt'] ?? null, 'A short prompt', $cases, $passed, $failed );
check( '2i capabilities.supported_fields is array',
	is_array( $body['capabilities']['supported_fields'] ?? null ), true, $cases, $passed, $failed );
check( '2j body has no openai/api_key key',
	! array_key_exists( 'openai_api_key', $body ) && ! array_key_exists( 'api_key', $body ), true, $cases, $passed, $failed );

// =============================================================================
// SECTION 3 — Error mapping (cloud → plugin codes)
// =============================================================================

function set_cloud_error( int $code, string $cloud_code, string $msg = 'err' ): void {
	$GLOBALS['__nf_http_response'] = [
		'code' => $code,
		'body' => json_encode( [ 'code' => $cloud_code, 'message' => $msg ] ),
	];
}

set_cloud_error( 401, 'unauthorized' );
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3a 401 unauthorized → cloud_unauthorized',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'cloud_unauthorized', $cases, $passed, $failed );

set_cloud_error( 403, 'feature_locked' );
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3b 403 feature_locked → feature_not_allowed',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'feature_not_allowed', $cases, $passed, $failed );

set_cloud_error( 402, 'quota_exceeded' );
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3c 402 quota_exceeded → insufficient_credits',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'insufficient_credits', $cases, $passed, $failed );

set_cloud_error( 429, 'rate_limited' );
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3d 429 rate_limited → ai_rate_limited',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'ai_rate_limited', $cases, $passed, $failed );

set_cloud_error( 502, 'malformed_ai_response' ); // cloud 5xx → plugin 424
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3e 502 malformed → ai_invalid_response',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'ai_invalid_response', $cases, $passed, $failed );

set_cloud_error( 500, 'provider_error' );
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3f 500 generic → ai_provider_error',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'ai_provider_error', $cases, $passed, $failed );

// 3g. Subscription expired maps to license_inactive.
set_cloud_error( 402, 'subscription_expired' );
$res = CloudFormGeneratorClient::generate( 'p' );
check( '3g subscription_expired → cloud_unauthorized',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'cloud_unauthorized', $cases, $passed, $failed );

// =============================================================================
// SECTION 4 — FormGeneratorService (orchestration)
// =============================================================================

// 4a. Empty prompt rejected before cloud call.
$GLOBALS['__nf_http_response'] = null;
$GLOBALS['__nf_http_last']     = null;
$res = FormGeneratorService::generate( '   ' );
check( '4a empty prompt → invalid_prompt',     $res instanceof WP_Error ? $res->get_error_code() : null, 'invalid_prompt', $cases, $passed, $failed );
check( '4b no cloud call made for empty prompt', $GLOBALS['__nf_http_last'], null, $cases, $passed, $failed );

// 4c. Oversized prompt rejected before cloud call.
$res = FormGeneratorService::generate( str_repeat( 'a', 2000 ) );
check( '4c oversized prompt → invalid_prompt', $res instanceof WP_Error ? $res->get_error_code() : null, 'invalid_prompt', $cases, $passed, $failed );
check( '4d no cloud call for oversized prompt',  $GLOBALS['__nf_http_last'], null, $cases, $passed, $failed );

// 4e. No key → cloud_not_connected before any HTTP call.
$GLOBALS['__nf_config']['cloud_api_key'] = '';
$res = FormGeneratorService::generate( 'Make me a contact form.' );
check( '4e no cloud key → cloud_not_connected', $res instanceof WP_Error ? $res->get_error_code() : null, 'cloud_not_connected', $cases, $passed, $failed );
$GLOBALS['__nf_config']['cloud_api_key'] = 'sk-cloud-fixture';

// 4f. Cloud returns malformed shape → ai_schema_invalid via local re-validation.
$GLOBALS['__nf_http_response'] = [
	'code' => 200,
	'body' => json_encode( [
		'form' => [
			'name'        => 'X',
			'form_schema' => [
				'fields' => [ [ 'type' => 'wysiwyg-html', 'label' => 'BAD' ] ],
			],
		],
	] ),
];
$res = FormGeneratorService::generate( 'A prompt' );
check( '4f bad cloud shape → ai_schema_invalid', $res instanceof WP_Error ? $res->get_error_code() : null, 'ai_schema_invalid', $cases, $passed, $failed );

// 4g. Cloud returns valid shape → success returns form + warnings + request_id + usage.
$GLOBALS['__nf_http_response'] = [
	'code' => 200,
	'body' => json_encode( [
		'request_id' => 'gen_xyz',
		'form'       => [
			'name'        => 'Booking',
			'description' => '',
			'form_schema' => [
				'fields'   => [
					[ 'type' => 'text',  'label' => 'Name'  ],
					[ 'type' => 'email', 'label' => 'Email' ],
				],
				'settings' => [],
			],
		],
		'warnings'   => [ 'note' ],
		// Canonical 0.2.0 cloud-side usage shape: triple-key, decimals,
		// boolean credits_deducted. Verify Pro passes ALL three keys
		// through unchanged so the plugin UI can show the trust message.
		'usage'      => [
			'credits_used'      => 0.30,
			'credits_remaining' => 1993.70,
			'credits_deducted'  => true,
		],
	] ),
];
$res = FormGeneratorService::generate( 'Booking form for clinic' );
check( '4g success returns array',                  is_array( $res ), true, $cases, $passed, $failed );
check( '4h success carries request_id',             is_array( $res ) ? ( $res['request_id'] ?? null ) : null, 'gen_xyz', $cases, $passed, $failed );
check( '4i success carries usage credits_used',     is_array( $res ) ? ( $res['usage']['credits_used'] ?? null ) : null, 0.30, $cases, $passed, $failed );
check( '4i2 success carries usage credits_remaining', is_array( $res ) ? ( $res['usage']['credits_remaining'] ?? null ) : null, 1993.70, $cases, $passed, $failed );
check( '4i3 success carries usage credits_deducted',  is_array( $res ) ? ( $res['usage']['credits_deducted'] ?? null ) : null, true,    $cases, $passed, $failed );
check( '4j success normalised form has 2 fields',
	is_array( $res ) ? count( $res['form']['form_schema']['fields'] ?? [] ) : 0, 2, $cases, $passed, $failed );

// =============================================================================
// SECTION 5 — Status snapshot exposes nothing provider-specific
// =============================================================================
$GLOBALS['__nf_config']['cloud_api_key'] = 'sk-cloud-fixture';
$snap = FormGeneratorService::status();
check( '5a status exposes configured',          isset( $snap['configured'] ),       true, $cases, $passed, $failed );
check( '5b status exposes cloud_connected',     isset( $snap['cloud_connected'] ),  true, $cases, $passed, $failed );
check( '5c status exposes credits',             isset( $snap['credits'] ),           true, $cases, $passed, $failed );
check( '5d status max_prompt_chars surfaced',   $snap['limits']['max_prompt_chars'] ?? null, 1500, $cases, $passed, $failed );

// 5e. Status MUST NOT include any provider/key/model details.
$flat = json_encode( $snap );
foreach ( [ 'openai', 'api_key', 'apiKey', 'gpt-4', 'sk-cloud-fixture', 'model' ] as $banned ) {
	check( '5e status response does not leak "' . $banned . '"',
		stripos( (string) $flat, $banned ) === false, true, $cases, $passed, $failed );
}

// 5f. is_configured aliases to cloud-connected.
check( '5f is_configured matches cloud_connected', FormGeneratorService::is_configured(), true, $cases, $passed, $failed );

$GLOBALS['__nf_config']['cloud_api_key'] = '';
check( '5g is_configured false when key cleared', FormGeneratorService::is_configured(), false, $cases, $passed, $failed );

// =============================================================================
// SECTION 5b — Eligibility (Lite: cloud connected + Cloud AI on + credits)
// =============================================================================
$GLOBALS['__nf_config']['cloud_api_key']    = 'sk-cloud-fixture';
$GLOBALS['__nf_config']['cloud_ai_enabled'] = true;
$GLOBALS['__nf_plan_payload'] = [ 'plan' => [ 'id' => 'lite' ], 'balance' => [ 'credits' => 5 ] ];
$snap = FormGeneratorService::status();
check( '5ba lite plan with key + credits is eligible', $snap['eligible'], true, $cases, $passed, $failed );
check( '5bb status carries the raw plan id',           $snap['plan'],     'lite', $cases, $passed, $failed );
$GLOBALS['__nf_plan_payload'] = [ 'plan' => [ 'id' => 'lite' ], 'balance' => [ 'credits' => 0 ] ];
check( '5bc zero credits → not eligible', FormGeneratorService::status()['eligible'], false, $cases, $passed, $failed );
$GLOBALS['__nf_plan_payload'] = [ 'plan' => [ 'id' => 'pro-agency' ], 'balance' => [ 'credits' => 50 ] ];
$GLOBALS['__nf_config']['cloud_ai_enabled'] = false;
$snap = FormGeneratorService::status();
check( '5bd cloud_ai_enabled=false → ai_enabled false', $snap['ai_enabled'], false, $cases, $passed, $failed );
check( '5be cloud_ai_enabled=false → eligible false',   $snap['eligible'],   false, $cases, $passed, $failed );
$GLOBALS['__nf_http_last']     = null;
$GLOBALS['__nf_http_response'] = [ 'code' => 200, 'body' => '{}' ];
$res = FormGeneratorService::generate( 'A form.' );
check( '5bf ai_disabled returned without cloud call', $res instanceof WP_Error && $res->get_error_code() === 'ai_disabled', true, $cases, $passed, $failed );
check( '5bg no cloud call when AI master toggle off', $GLOBALS['__nf_http_last'], null, $cases, $passed, $failed );
$GLOBALS['__nf_config']['cloud_ai_enabled'] = true;
$GLOBALS['__nf_config']['cloud_api_key']    = '';
check( '5bh no cloud key → not eligible', FormGeneratorService::status()['eligible'], false, $cases, $passed, $failed );
$GLOBALS['__nf_config']['cloud_api_key']    = 'sk-cloud-fixture';
$dbg = FormGeneratorService::debug_status_snapshot();
foreach ( [ 'sk-cloud-fixture', 'api_key', 'license' ] as $banned ) {
	check( '5bi debug does not leak "' . $banned . '"', stripos( json_encode( $dbg ), $banned ) === false, true, $cases, $passed, $failed );
}

// =============================================================================
// SECTION 5d — Cloud HttpExceptionFilter envelope parsing (regression)
// =============================================================================
// The cloud wraps EVERY error response as:
//   { "error": { "code": "invalid_api_key", "message": "...", "status": 401 } }
// An earlier build read $payload['code'] / $payload['message'] directly,
// which silently dropped the cloud's structured code and surfaced the
// generic "Cloud returned HTTP 401." string in the UI. These cases lock
// the corrected envelope-aware parser.

$GLOBALS['__nf_config']['cloud_api_key'] = 'sk-cloud-fixture';

// 5d-a. Wrapped 401 with invalid_api_key → cloud_unauthorized + real message.
$GLOBALS['__nf_http_response'] = [
	'code' => 401,
	'body' => json_encode( [
		'error' => [ 'code' => 'invalid_api_key', 'message' => 'Invalid or revoked API key', 'status' => 401 ],
	] ),
];
$res = CloudFormGeneratorClient::generate( 'p' );
check( '5da wrapped invalid_api_key → cloud_unauthorized',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'cloud_unauthorized', $cases, $passed, $failed );
check( '5db wrapped error message surfaced (not generic HTTP 401 string)',
	$res instanceof WP_Error ? $res->get_error_message() : null, 'Invalid or revoked API key', $cases, $passed, $failed );

// 5d-c. Wrapped 401 with missing_authorization → cloud_unauthorized.
$GLOBALS['__nf_http_response'] = [
	'code' => 401,
	'body' => json_encode( [
		'error' => [ 'code' => 'missing_authorization', 'message' => 'Missing Authorization header', 'status' => 401 ],
	] ),
];
$res = CloudFormGeneratorClient::generate( 'p' );
check( '5dc wrapped missing_authorization → cloud_unauthorized',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'cloud_unauthorized', $cases, $passed, $failed );

// 5d-d. Wrapped 403 plan_not_allowed → plan_not_allowed.
$GLOBALS['__nf_http_response'] = [
	'code' => 403,
	'body' => json_encode( [
		'error' => [ 'code' => 'plan_not_allowed', 'message' => 'Pro plan required', 'status' => 403 ],
	] ),
];
$res = CloudFormGeneratorClient::generate( 'p' );
check( '5dd wrapped plan_not_allowed → plan_not_allowed',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'plan_not_allowed', $cases, $passed, $failed );

// 5d-e. Bare (un-wrapped) 401 still works (back-compat).
$GLOBALS['__nf_http_response'] = [
	'code' => 401,
	'body' => json_encode( [ 'code' => 'invalid_api_key', 'message' => 'bare msg' ] ),
];
$res = CloudFormGeneratorClient::generate( 'p' );
check( '5de bare invalid_api_key still maps',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'cloud_unauthorized', $cases, $passed, $failed );
check( '5df bare error message surfaced',
	$res instanceof WP_Error ? $res->get_error_message() : null, 'bare msg', $cases, $passed, $failed );

// 5d-g. domain_already_claimed → plan_not_allowed (so the UI shows upgrade copy).
$GLOBALS['__nf_http_response'] = [
	'code' => 403,
	'body' => json_encode( [
		'error' => [ 'code' => 'domain_already_claimed', 'message' => 'Domain bound to another account', 'status' => 403 ],
	] ),
];
$res = CloudFormGeneratorClient::generate( 'p' );
check( '5dg wrapped domain_already_claimed → plan_not_allowed',
	$res instanceof WP_Error ? $res->get_error_code() : null, 'plan_not_allowed', $cases, $passed, $failed );

// 5d-parity-base. Base URL defers to AiManager — does NOT read the
// stale `cloud_api_base_url` option. THIS is the regression lock for
// the actual customer bug: form-generate was routing to a different
// host than every other AI client, producing a 401 from the wrong
// server while same-key auth succeeded everywhere else.
$GLOBALS['__nf_ai_manager_base'] = 'https://api.neuralforms.ai';
$GLOBALS['__nf_config']['cloud_api_base_url'] = 'https://STALE-DEV-HOST.invalid';
check( '5d-parity-base ignores stale cloud_api_base_url option (the actual bug)',
	CloudFormGeneratorClient::resolve_base_url(), 'https://api.neuralforms.ai',
	$cases, $passed, $failed );

// AiManager override (constant / staging filter) DOES propagate.
$GLOBALS['__nf_ai_manager_base'] = 'https://staging.neuralforms.ai';
check( '5d-parity-base AiManager override propagates',
	CloudFormGeneratorClient::resolve_base_url(), 'https://staging.neuralforms.ai',
	$cases, $passed, $failed );
$GLOBALS['__nf_ai_manager_base'] = 'https://api.neuralforms.ai';

// Source-level assertion: client source must NOT reference the
// dangerous option name. Catches a future regression that re-adds
// the fallback path.
$client_src = file_get_contents( $plugin_root . '/modules/ai/CloudFormGeneratorClient.php' );
check( '5d-parity-base source no longer reads cloud_api_base_url option in resolution path',
	preg_match( '/Config::get\(\s*[\'"]cloud_api_base_url[\'"]/', $client_src ),
	0, $cases, $passed, $failed );

// 5d-parity-a. Lite-version constant used (matches NeuralFormsCloudProvider).
$h = CloudFormGeneratorClient::headers();
check( '5d-parity-a X-NeuralForms-Version uses NEURAL_FORMS_VERSION (Lite)',
	$h['X-NeuralForms-Version'] ?? null, '1.2.0-lite-test', $cases, $passed, $failed );

// 5d-parity-b. Authorization header is Bearer + raw key (no hashing).
check( '5d-parity-b Authorization is Bearer + raw key',
	$h['Authorization'] ?? null, 'Bearer sk-cloud-fixture', $cases, $passed, $failed );

// 5d-parity-c. trim() in resolve_key strips whitespace.
$old = $GLOBALS['__nf_config']['cloud_api_key'];
$GLOBALS['__nf_config']['cloud_api_key'] = "  sk-padded\n";
$h_pad = CloudFormGeneratorClient::headers();
check( '5d-parity-c key trimmed before Bearer-wrapping',
	$h_pad['Authorization'] ?? null, 'Bearer sk-padded', $cases, $passed, $failed );
$GLOBALS['__nf_config']['cloud_api_key'] = $old;

// 5d-parity-d. Filter pass-through — third-party plugin headers DO reach the request.
add_filter_shim_ai_form();
$h_filt = CloudFormGeneratorClient::headers();
check( '5d-parity-d filter-injected header survives merge',
	$h_filt['X-Test-Pro-Filter'] ?? null, 'pro-injected', $cases, $passed, $failed );
check( '5d-parity-e core Authorization wins over filter (defence)',
	$h_filt['Authorization'] ?? null, 'Bearer sk-cloud-fixture', $cases, $passed, $failed );
remove_filter_shim_ai_form();

// 5d-h. Header-naming regression: site header MUST be X-NeuralForms-Site
// (no inner hyphens), matching what the cloud's ApiKeyGuard reads.
$GLOBALS['__nf_http_response'] = [
	'code' => 200,
	'body' => json_encode( [
		'request_id' => 'gen_h',
		'form'       => [
			'name'        => 'X',
			'description' => '',
			'form_schema' => [ 'fields' => [ [ 'type' => 'text', 'label' => 'Name' ] ], 'settings' => [] ],
		],
		'warnings' => [],
		'usage'    => [],
	] ),
];
$res = CloudFormGeneratorClient::generate( 'A short prompt' );
$last = $GLOBALS['__nf_http_last'];
$headers = $last['args']['headers'] ?? [];
check( '5dh header is X-NeuralForms-Site (no inner hyphens)',
	isset( $headers['X-NeuralForms-Site'] ), true, $cases, $passed, $failed );
check( '5di header is X-NeuralForms-Version (no inner hyphens)',
	isset( $headers['X-NeuralForms-Version'] ), true, $cases, $passed, $failed );
check( '5dj OLD hyphenated header NOT sent (regression guard)',
	! isset( $headers['X-Neural-Forms-Site'] ), true, $cases, $passed, $failed );

// =============================================================================
// SECTION 5e — Field-type aliases + recursive cleanup (Part A + Part C)
// =============================================================================

// 5e-aliases: each alias resolves to its canonical type and survives
// the strict allowed-set check that would otherwise drop it.
$alias_cases = [
	'tel'           => 'phone',
	'telephone'     => 'phone',
	'mobile'        => 'phone',
	'dropdown'      => 'select',
	'long_text'     => 'textarea',
	'paragraph'     => 'textarea',
	'message'       => 'textarea',
	'datepicker'    => 'date',
	'date_picker'   => 'date',
	'integer'       => 'number',
	'file_upload'   => 'file',
	'attachment'    => 'file',
	'star_rating'   => 'rating',
	'consent'       => 'acceptance',
	'gdpr'          => 'acceptance',
	'short_text'    => 'text',
	'full_name'     => 'text',
	'email_address' => 'email',
	'mail'          => 'email',
	'website'       => 'url',
	'multi_select'  => 'checkboxes',
	'yes_no'        => 'checkbox',
	'likert'        => 'matrix',
	'likert_scale'  => 'matrix',
	'rating_matrix' => 'matrix',
	'survey_matrix' => 'matrix',
	'grid'          => 'matrix',
	'scale_matrix'  => 'matrix',
];
foreach ( $alias_cases as $alias => $canonical ) {
	// Most aliased types need a label; choice-likes need 2+ options.
	$node = [ 'type' => $alias, 'label' => 'X' ];
	if ( in_array( $canonical, [ 'select', 'radio', 'checkboxes', 'poll' ], true ) ) {
		$node['options'] = [
			[ 'label' => 'A', 'value' => 'a' ],
			[ 'label' => 'B', 'value' => 'b' ],
		];
	}
	$res = SchemaNormalizer::normalize( [ 'fields' => [ $node ] ] );
	$got = $res['form']['form_schema']['fields'][0]['type'] ?? null;
	check( "5e alias \"{$alias}\" → \"{$canonical}\"", $got, $canonical, $cases, $passed, $failed );
}

// 5e-question-radio: bare "question" with no correct marker → radio.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'    => 'question',
	'label'   => 'Pick',
	'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ],
] ] ] );
check( '5e "question" without correct marker → radio',
	$res['form']['form_schema']['fields'][0]['type'] ?? null, 'radio', $cases, $passed, $failed );

// 5e-question-quiz: "question" with is_correct → quiz_question.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'    => 'question',
	'label'   => 'Capital?',
	'options' => [ [ 'label' => 'A', 'is_correct' => true ], [ 'label' => 'B' ] ],
] ] ] );
check( '5e "question" with correct marker → quiz_question',
	$res['form']['form_schema']['fields'][0]['type'] ?? null, 'quiz_question', $cases, $passed, $failed );

// 5e-cleanup: empty row removed.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'text', 'label' => 'Keep' ],
	[ 'type' => 'row',  'columns' => [ [ 'fields' => [ [ 'type' => 'unknown_xxx' ] ] ] ] ],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5e empty row removed', $types, [ 'text' ], $cases, $passed, $failed );

// 5e-cleanup-step: empty step removed.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'text', 'label' => 'Keep' ],
	[ 'type' => 'step', 'title' => 'Empty', 'fields' => [ [ 'type' => 'unknown' ] ] ],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5e empty step removed', $types, [ 'text' ], $cases, $passed, $failed );

// 5e-cleanup-1col-row: single-column row unwrapped.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[
		'type'    => 'row',
		'columns' => [
			[ 'fields' => [ [ 'type' => 'text', 'label' => 'A' ] ] ],
			[ 'fields' => [ [ 'type' => 'unsupported_xyz' ] ] ],
		],
	],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5e one-column-row unwrapped to bare field', $types, [ 'text' ], $cases, $passed, $failed );

// 5e-cleanup-section-children: unsupported section unwraps its children.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[
		'type'   => 'section',
		'label'  => 'Personal Information',
		'fields' => [
			[ 'type' => 'text',  'label' => 'Name' ],
			[ 'type' => 'email', 'label' => 'Email' ],
		],
	],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5e unsupported section: children preserved', $types, [ 'text', 'email' ], $cases, $passed, $failed );

// 5e-cleanup-section-empty: unsupported section with no children dropped.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'text',    'label' => 'Keep' ],
	[ 'type' => 'section', 'label' => 'Empty', 'fields' => [] ],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5e empty section dropped', $types, [ 'text' ], $cases, $passed, $failed );

// 5e-cleanup-final-empty: every field dropped → error.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'unsupported_a' ],
	[ 'type' => 'row', 'columns' => [ [ 'fields' => [ [ 'type' => 'unsupported_b' ] ] ] ] ],
] ] );
check( '5e all-empty cleanup → form null + error',
	$res['form'] === null && $res['error'] !== null, true, $cases, $passed, $failed );

// 5e-multistep-no-blank: simulates the literal customer bug —
// a multistep form where Step 1 had two select fields with <2 options
// (dropped) and a section (dropped). After cleanup, Step 1 must
// either contain real fields OR be removed entirely. Step 2 with
// real fields must survive intact.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[
		'type'   => 'step',
		'title'  => 'Step 1 (gets cleaned)',
		'fields' => [
			[ 'type' => 'select',  'label' => 'Bad',     'options' => [ [ 'label' => 'Only', 'value' => 'only' ] ] ],
			[ 'type' => 'section', 'label' => 'Empty section' ],
		],
	],
	[
		'type'   => 'step',
		'title'  => 'Step 2',
		'fields' => [
			[ 'type' => 'text',  'label' => 'Name' ],
			[ 'type' => 'email', 'label' => 'Email' ],
		],
	],
] ] );
$top_types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5e multistep cleanup — empty step removed, real step kept',
	$top_types, [ 'step' ], $cases, $passed, $failed );
$kept_step = $res['form']['form_schema']['fields'][0] ?? [];
check( '5e surviving step is "Step 2"',
	$kept_step['title'] ?? null, 'Step 2', $cases, $passed, $failed );
$step_field_types = array_column( $kept_step['fields'] ?? [], 'type' );
check( '5e surviving step has 2 real fields',
	$step_field_types, [ 'text', 'email' ], $cases, $passed, $failed );

// =============================================================================
// SECTION 5g — Matrix normalisation (commit 6/7)
// =============================================================================

// 5g-radio: matrix_radio collapses to matrix + radio selection_type.
$GLOBALS['__nf_cap_field_matrix'] = true;
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'  => 'matrix_radio',
	'label' => 'Satisfaction',
	'rows'    => [ 'Purchase', 'Service' ],
	'columns' => [ 'Bad', 'OK', 'Good' ],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g matrix_radio → type matrix',                $field['type'] ?? null, 'matrix', $cases, $passed, $failed );
check( '5g matrix_radio → selection_type radio',       $field['settings']['selection_type'] ?? null, 'radio', $cases, $passed, $failed );
check( '5g matrix rows as strings → row count',        count( $field['settings']['rows'] ?? [] ), 2, $cases, $passed, $failed );
check( '5g matrix row[0].value derived from label',    $field['settings']['rows'][0]['value'] ?? null, 'purchase', $cases, $passed, $failed );
check( '5g matrix row[0].label preserved',             $field['settings']['rows'][0]['label'] ?? null, 'Purchase', $cases, $passed, $failed );
check( '5g matrix columns as strings → col count',     count( $field['settings']['columns'] ?? [] ), 3, $cases, $passed, $failed );
check( '5g matrix col[0].label preserved',             $field['settings']['columns'][0]['label'] ?? null, 'Bad', $cases, $passed, $failed );
check( '5g matrix col[0].score index-derived = 1',     $field['settings']['columns'][0]['score'] ?? null, 1, $cases, $passed, $failed );
check( '5g matrix col[2].score index-derived = 3',     $field['settings']['columns'][2]['score'] ?? null, 3, $cases, $passed, $failed );

// 5g-checkbox: matrix_checkbox → matrix + checkbox selection_type.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'    => 'matrix_checkbox',
	'label'   => 'Features',
	'rows'    => [ [ 'label' => 'Purchase', 'value' => 'purchase' ] ],
	'columns' => [ [ 'label' => 'Email', 'value' => 'email' ] ],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g matrix_checkbox → selection_type checkbox', $field['settings']['selection_type'] ?? null, 'checkbox', $cases, $passed, $failed );

// 5g-likert: likert alias → matrix with default selection radio.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'    => 'likert',
	'label'   => 'Agreement',
	'rows'    => [ 'Statement A' ],
	'columns' => [ 'Disagree', 'Agree' ],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g likert → matrix',                           $field['type'] ?? null, 'matrix', $cases, $passed, $failed );
check( '5g likert default selection_type → radio',     $field['settings']['selection_type'] ?? null, 'radio', $cases, $passed, $failed );

// 5g-object-columns: object columns preserve supplied score.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'     => 'matrix',
	'label'    => 'Survey',
	'settings' => [
		'selection_type' => 'radio',
		'rows'    => [ [ 'label' => 'Row', 'value' => 'row' ] ],
		'columns' => [
			[ 'label' => 'Low',  'value' => 'low',  'score' => 1 ],
			[ 'label' => 'High', 'value' => 'high', 'score' => 5 ],
		],
	],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g object columns keep supplied score (1)',    $field['settings']['columns'][0]['score'] ?? null, 1, $cases, $passed, $failed );
check( '5g object columns keep supplied score (5)',    $field['settings']['columns'][1]['score'] ?? null, 5, $cases, $passed, $failed );

// 5g-fallback: empty rows / columns → defaults (3 rows, 3 columns).
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'  => 'matrix',
	'label' => 'Empty Matrix',
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g empty matrix → 3 default rows',             count( $field['settings']['rows'] ?? [] ), 3, $cases, $passed, $failed );
check( '5g empty matrix → 3 default cols',             count( $field['settings']['columns'] ?? [] ), 3, $cases, $passed, $failed );

// 5g-input_type: input_type=checkbox synonym → checkbox selection.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'       => 'matrix',
	'label'      => 'M',
	'input_type' => 'checkbox',
	'rows'       => [ 'R' ],
	'columns'    => [ 'A', 'B' ],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g input_type=checkbox synonym → checkbox',    $field['settings']['selection_type'] ?? null, 'checkbox', $cases, $passed, $failed );

// 5g-unique-row-values: duplicate row labels deduplicate via -2 suffix.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'    => 'matrix',
	'label'   => 'M',
	'rows'    => [ 'Item', 'Item' ],
	'columns' => [ 'A', 'B' ],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5g duplicate row[0] value',                    $field['settings']['rows'][0]['value'] ?? null, 'item', $cases, $passed, $failed );
check( '5g duplicate row[1] value disambiguated',      $field['settings']['rows'][1]['value'] ?? null, 'item-2', $cases, $passed, $failed );

// 5g-drop-when-locked: capability false drops matrix entirely.
$GLOBALS['__nf_cap_field_matrix'] = false;
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'matrix', 'label' => 'Survey', 'rows' => [ 'R' ], 'columns' => [ 'A', 'B' ] ],
	[ 'type' => 'text',   'label' => 'Name' ],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5g matrix dropped when capability locked',     $types, [ 'text' ], $cases, $passed, $failed );

// 5g-drop-warning: drop produces a clear warning.
$has_warn = false;
foreach ( $res['warnings'] ?? [] as $w ) {
	if ( strpos( (string) $w, 'matrix' ) !== false && strpos( (string) $w, 'Pro' ) !== false ) {
		$has_warn = true;
		break;
	}
}
check( '5g matrix-drop emits Pro warning',             $has_warn, true, $cases, $passed, $failed );

// 5g-cap-envelope: build_capabilities reflects the cap.
$GLOBALS['__nf_cap_field_matrix'] = false;
$env = CloudFormGeneratorClient::build_capabilities();
check( '5g cap envelope locked → supports_matrix=false', $env['supports_matrix'] ?? null, false, $cases, $passed, $failed );
check( '5g cap envelope locked → matrix not in supported_fields',
	in_array( 'matrix', $env['supported_fields'] ?? [], true ), false, $cases, $passed, $failed );

$GLOBALS['__nf_cap_field_matrix'] = true;
$env = CloudFormGeneratorClient::build_capabilities();
check( '5g cap envelope open → supports_matrix=true',   $env['supports_matrix'] ?? null, true,  $cases, $passed, $failed );
check( '5g cap envelope open → matrix in supported_fields',
	in_array( 'matrix', $env['supported_fields'] ?? [], true ), true,  $cases, $passed, $failed );

// Restore default for subsequent sections.
$GLOBALS['__nf_cap_field_matrix'] = true;

// =============================================================================
// SECTION 5h — Repeater normalisation (commit 6/7)
// =============================================================================

// 5h-canonical: plain "repeater" with valid children + settings.
$GLOBALS['__nf_cap_field_repeater'] = true;
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'  => 'repeater',
	'label' => 'Team Members',
	'settings' => [ 'min_items' => 1, 'max_items' => 4, 'columns' => 2,
		'add_button_label' => 'Add member', 'remove_button_label' => 'Remove member' ],
	'fields' => [
		[ 'type' => 'text', 'label' => 'Name', 'required' => true ],
		[ 'type' => 'date', 'label' => 'Joining Date' ],
		[ 'type' => 'file', 'label' => 'Resume' ],
	],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5h repeater canonical → type repeater',          $field['type'] ?? null, 'repeater', $cases, $passed, $failed );
check( '5h repeater forwards min_items',                 $field['settings']['min_items'] ?? null, 1, $cases, $passed, $failed );
check( '5h repeater forwards max_items',                 $field['settings']['max_items'] ?? null, 4, $cases, $passed, $failed );
check( '5h repeater forwards columns',                   $field['settings']['columns'] ?? null, 2, $cases, $passed, $failed );
check( '5h repeater forwards add_button_label',          $field['settings']['add_button_label'] ?? null, 'Add member', $cases, $passed, $failed );
check( '5h repeater child count after normalisation',    count( $field['fields'] ?? [] ), 3, $cases, $passed, $failed );
check( '5h repeater first child type',                   $field['fields'][0]['type'] ?? null, 'text', $cases, $passed, $failed );
check( '5h repeater child label preserved',              $field['fields'][0]['label'] ?? null, 'Name', $cases, $passed, $failed );

// 5h-clamp: max_items > 20 hard-capped, columns > 4 clamped.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'  => 'repeater',
	'label' => 'R',
	'settings' => [ 'min_items' => 0, 'max_items' => 50, 'columns' => 99 ],
	'fields' => [ [ 'type' => 'text', 'label' => 'Name' ] ],
] ] ] );
$field = $res['form']['form_schema']['fields'][0] ?? null;
check( '5h repeater max_items > 20 clamped to 20',       $field['settings']['max_items'] ?? null, 20, $cases, $passed, $failed );
check( '5h repeater columns > 4 clamped to 4',           $field['settings']['columns']   ?? null, 4,  $cases, $passed, $failed );

// 5h-aliases: each repeater alias resolves to canonical.
$repeater_aliases = [ 'repeatable', 'repeatable_group', 'repeat_group', 'field_group', 'group_list', 'repeating_section' ];
foreach ( $repeater_aliases as $alias ) {
	$res = SchemaNormalizer::normalize( [ 'fields' => [ [
		'type'   => $alias,
		'label'  => 'List',
		'fields' => [ [ 'type' => 'text', 'label' => 'Name' ] ],
	] ] ] );
	check( "5h alias \"{$alias}\" → repeater",
		$res['form']['form_schema']['fields'][0]['type'] ?? null, 'repeater', $cases, $passed, $failed );
}

// 5h-nested-repeater: blocked. Inner repeater is dropped; outer keeps non-repeater siblings.
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'  => 'repeater',
	'label' => 'Outer',
	'fields' => [
		[ 'type' => 'text', 'label' => 'Name' ],
		[ 'type' => 'repeater', 'label' => 'Inner', 'fields' => [ [ 'type' => 'text', 'label' => 'X' ] ] ],
	],
] ] ] );
$child_types = array_column( $res['form']['form_schema']['fields'][0]['fields'] ?? [], 'type' );
check( '5h nested repeater dropped — Name only survives', $child_types, [ 'text' ], $cases, $passed, $failed );

// 5h-child-cap: more than 8 children trimmed.
$too_many = [];
for ( $i = 1; $i <= 12; $i++ ) {
	$too_many[] = [ 'type' => 'text', 'label' => 'F' . $i ];
}
$res = SchemaNormalizer::normalize( [ 'fields' => [ [
	'type'   => 'repeater',
	'label'  => 'Big',
	'fields' => $too_many,
] ] ] );
check( '5h repeater children capped at 8',
	count( $res['form']['form_schema']['fields'][0]['fields'] ?? [] ), 8, $cases, $passed, $failed );

// 5h-locked: capability false → repeater dropped entirely.
$GLOBALS['__nf_cap_field_repeater'] = false;
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'repeater', 'label' => 'TM', 'fields' => [ [ 'type' => 'text', 'label' => 'Name' ] ] ],
	[ 'type' => 'text', 'label' => 'Other' ],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5h repeater dropped when capability locked',     $types, [ 'text' ], $cases, $passed, $failed );

$has_warn = false;
foreach ( $res['warnings'] ?? [] as $w ) {
	if ( strpos( (string) $w, 'repeater' ) !== false && strpos( (string) $w, 'Pro' ) !== false ) { $has_warn = true; break; }
}
check( '5h repeater-drop emits Pro warning',             $has_warn, true, $cases, $passed, $failed );

// 5h-alias-also-blocked: alias path → repeater also dropped when locked.
$res = SchemaNormalizer::normalize( [ 'fields' => [
	[ 'type' => 'repeatable_group', 'label' => 'Members', 'fields' => [ [ 'type' => 'text', 'label' => 'Name' ] ] ],
	[ 'type' => 'text', 'label' => 'Other' ],
] ] );
$types = array_column( $res['form']['form_schema']['fields'] ?? [], 'type' );
check( '5h alias repeater dropped when locked',          $types, [ 'text' ], $cases, $passed, $failed );

// 5h-cap-envelope: build_capabilities reflects the cap (both states).
$GLOBALS['__nf_cap_field_repeater'] = false;
$env = CloudFormGeneratorClient::build_capabilities();
check( '5h cap envelope locked → supports_repeater=false',     $env['supports_repeater'] ?? null, false, $cases, $passed, $failed );
check( '5h cap envelope locked → repeater not in supported_fields',
	in_array( 'repeater', $env['supported_fields'] ?? [], true ), false, $cases, $passed, $failed );

$GLOBALS['__nf_cap_field_repeater'] = true;
$env = CloudFormGeneratorClient::build_capabilities();
check( '5h cap envelope open → supports_repeater=true',        $env['supports_repeater'] ?? null, true, $cases, $passed, $failed );
check( '5h cap envelope open → repeater in supported_fields',
	in_array( 'repeater', $env['supported_fields'] ?? [], true ), true, $cases, $passed, $failed );

// Restore defaults for subsequent sections.
$GLOBALS['__nf_cap_field_matrix']   = true;
$GLOBALS['__nf_cap_field_repeater'] = true;

// =============================================================================
// SECTION 6 — Defense: no direct OpenAI call paths remain
// =============================================================================
// Service must not have an `extract_json` or any OpenAI endpoint string.
$service_src = file_get_contents( $plugin_root . '/modules/ai/FormGeneratorService.php' );
check( '6a FormGeneratorService source has no OpenAI endpoint',
	strpos( $service_src, 'api.openai.com' ), false, $cases, $passed, $failed );
check( '6b FormGeneratorService source has no openai_api_key option',
	strpos( $service_src, 'openai_api_key' ), false, $cases, $passed, $failed );
check( '6c FormGeneratorService source has no NEURAL_FORMS_PRO_OPENAI_API_KEY constant',
	strpos( $service_src, 'NEURAL_FORMS_PRO_OPENAI_API_KEY' ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
foreach ( $cases as $c ) {
	if ( $c[0] ) {
		echo "\033[32mPASS\033[0m  " . $c[1] . "\n";
	} else {
		echo "\033[31mFAIL\033[0m  " . $c[1] . "\n";
		echo "    expected: " . var_export( $c[3], true ) . "\n";
		echo "    actual:   " . var_export( $c[2], true ) . "\n";
	}
}
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
