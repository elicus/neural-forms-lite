<?php
/**
 * FormSettingsSanitizer tests.
 *
 * Locks the defensive sanitisation contract for the per-form
 * integrations subtree of form.settings.
 *
 * Coverage:
 *   - Clean HubSpot settings round-trip unchanged.
 *   - `enabled` normalises to bool (truthy/falsy/string).
 *   - `hubspot_form_id` rejects path-escape / control chars; valid
 *     GUID-shaped IDs pass.
 *   - `hubspot_form_name` is sanitize_text_field'd and length-capped.
 *   - `field_map` strips invalid keys/values, drops empty values,
 *     rejects array values, length-caps keys + values.
 *   - Delivery telemetry (`last_sent_at`, `last_error`,
 *     `last_status_code`) is stripped from client payloads.
 *   - Forbidden secret/token keys (access_token, client_secret,
 *     private_app_token, token, secret) are stripped.
 *   - Unknown HubSpot keys are stripped.
 *   - Unknown providers under integrations.* are dropped.
 *   - Non-HubSpot top-level settings (process_with_ai, on_success,
 *     security, notifications, captcha, etc.) round-trip unchanged.
 *   - Malformed integrations / hubspot payloads (string, null, int)
 *     do not fatal — they collapse to empty.
 *   - Missing integrations key does NOT add an empty config.
 *   - Disabled-with-mapping config is preserved; entirely-empty
 *     config collapses away.
 *
 * Run from the plugin root:
 *
 *   php tests/FormSettingsSanitizerTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );

if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		$s = (string) $s;
		// Approximation of WP behaviour adequate for this suite: strip
		// tags, normalise whitespace, drop control characters.
		$s = strip_tags( $s );
		$s = preg_replace( '/[\x00-\x1F\x7F]/u', '', $s ) ?? $s;
		$s = preg_replace( '/\s+/u', ' ', $s ) ?? $s;
		return trim( $s );
	}
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FormSettingsSanitizer.php';

use NeuralForms\Forms\FormSettingsSanitizer;

$cases = []; $passed = 0; $failed = 0;
function nf_test( string $name, callable $fn ): void { global $cases; $cases[] = [ 'name' => $name, 'fn' => $fn ]; }
function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) throw new \RuntimeException( $msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true ) );
}
function nf_assert_true( $cond, string $msg ): void { if ( ! $cond ) throw new \RuntimeException( $msg ); }

// ────────── Top-level / pass-through ──────────

nf_test( 'top-level: non-array input → empty array', function () {
	nf_assert_same( [], FormSettingsSanitizer::sanitize( null ),         'null → []' );
	nf_assert_same( [], FormSettingsSanitizer::sanitize( 'string' ),     'string → []' );
	nf_assert_same( [], FormSettingsSanitizer::sanitize( 12345 ),        'int → []' );
} );

nf_test( 'top-level: missing integrations key → no empty config added', function () {
	$in  = [ 'on_success' => [ 'mode' => 'message', 'message' => 'Thanks!' ] ];
	$out = FormSettingsSanitizer::sanitize( $in );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'integrations key not added' );
	nf_assert_same( $in['on_success'], $out['on_success'], 'on_success preserved verbatim' );
} );

nf_test( 'top-level: non-integration settings round-trip unchanged', function () {
	$in = [
		'process_with_ai' => [ 'mode' => 'every_entry' ],
		'security'        => [ 'captcha_enabled' => true ],
		'notifications'   => [ 'admin_email' => 'a@b.test' ],
		'on_success'      => [ 'mode' => 'redirect', 'url' => 'https://x.test' ],
		'on_error'        => [ 'mode' => 'message', 'message' => 'oh no' ],
		'captcha'         => [ 'provider' => 'turnstile' ],
		'random_key'      => [ 'kept' => true ],
	];
	$out = FormSettingsSanitizer::sanitize( $in );
	foreach ( $in as $k => $v ) {
		nf_assert_same( $v, $out[ $k ], "{$k} preserved" );
	}
} );

// ────────── integrations container ──────────

nf_test( 'integrations: non-array container is dropped (no fatal)', function () {
	foreach ( [ null, 'oops', 42, true ] as $bad ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => $bad ] );
		nf_assert_true( ! array_key_exists( 'integrations', $out ), 'integrations dropped on malformed input: ' . var_export( $bad, true ) );
	}
} );

nf_test( 'integrations: unknown providers are dropped', function () {
	$in = [ 'integrations' => [
		'hubspot'         => [ 'enabled' => true, 'hubspot_form_id' => 'abc-123', 'field_map' => [ 'email' => 'email_field' ] ],
		'unknown_vendor'  => [ 'token' => 'secret' ],
	] ];
	$out = FormSettingsSanitizer::sanitize( $in );
	nf_assert_true( array_key_exists( 'hubspot', $out['integrations'] ), 'hubspot kept' );
	nf_assert_true( ! array_key_exists( 'unknown_vendor', $out['integrations'] ), 'unknown provider dropped' );
} );

// ────────── HubSpot: happy path ──────────

nf_test( 'hubspot: clean config round-trips with all four whitelisted keys', function () {
	$in = [ 'integrations' => [ 'hubspot' => [
		'enabled'           => true,
		'hubspot_form_id'   => 'abc-1234-def',
		'hubspot_form_name' => 'Contact Us',
		'field_map'         => [
			'email'     => 'email_field_1',
			'firstname' => 'first_name_field',
		],
	] ] ];
	$out = FormSettingsSanitizer::sanitize( $in );
	$hs  = $out['integrations']['hubspot'];
	nf_assert_same( true,         $hs['enabled'],           'enabled' );
	nf_assert_same( 'abc-1234-def', $hs['hubspot_form_id'], 'form_id round-trips' );
	nf_assert_same( 'Contact Us',  $hs['hubspot_form_name'], 'form_name round-trips' );
	nf_assert_same( 2, count( $hs['field_map'] ),           'field_map has both entries' );
	nf_assert_same( 'email_field_1', $hs['field_map']['email'], 'email mapping preserved' );
} );

// ────────── enabled normalisation ──────────

nf_test( 'enabled: truthy / falsy / strings all coerce to bool', function () {
	$cases = [
		[ 'in' => true,   'expected' => true  ],
		[ 'in' => false,  'expected' => false ],
		[ 'in' => 1,      'expected' => true  ],
		[ 'in' => 0,      'expected' => false ],
		[ 'in' => 'true', 'expected' => true  ],
		[ 'in' => '',     'expected' => false ],
		[ 'in' => null,   'expected' => false ],
	];
	foreach ( $cases as $c ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
			'enabled'         => $c['in'],
			'hubspot_form_id' => 'abc-123',   // keep config non-empty so it survives the collapse
		] ] ] );
		nf_assert_same( $c['expected'], $out['integrations']['hubspot']['enabled'], 'enabled=' . var_export( $c['in'], true ) );
	}
} );

nf_test( 'enabled: missing key defaults to false', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'hubspot_form_id' => 'abc-123',
	] ] ] );
	nf_assert_same( false, $out['integrations']['hubspot']['enabled'], 'enabled default false' );
} );

// ────────── hubspot_form_id sanitisation ──────────

nf_test( 'hubspot_form_id: GUID-shaped IDs accepted', function () {
	foreach ( [ 'abc-1234', '12345678-90ab-cdef-1234-567890abcdef', 'a_b-c' ] as $good ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
			'enabled'         => true,
			'hubspot_form_id' => $good,
		] ] ] );
		nf_assert_same( $good, $out['integrations']['hubspot']['hubspot_form_id'], "accepts: {$good}" );
	}
} );

nf_test( 'hubspot_form_id: path-escape / unsafe chars stripped to empty', function () {
	foreach ( [ '../etc', 'g1/x', 'g1?x=1', 'g1#frag', 'g1 g2', 'g1\\back' ] as $bad ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
			'enabled'         => true,
			'hubspot_form_id' => $bad,
		] ] ] );
		nf_assert_same( '', $out['integrations']['hubspot']['hubspot_form_id'], "rejects: {$bad}" );
	}
} );

nf_test( 'hubspot_form_id: control characters rejected', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'         => true,
		'hubspot_form_id' => "abc\x00def",
	] ] ] );
	nf_assert_same( '', $out['integrations']['hubspot']['hubspot_form_id'], 'NUL byte rejected' );
} );

nf_test( 'hubspot_form_id: non-string value → empty', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'         => true,
		'hubspot_form_id' => [ 'not', 'a', 'string' ],
	] ] ] );
	nf_assert_same( '', $out['integrations']['hubspot']['hubspot_form_id'], 'array value → empty' );
} );

// ────────── hubspot_form_name sanitisation ──────────

nf_test( 'hubspot_form_name: HTML tags stripped via sanitize_text_field', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'           => true,
		'hubspot_form_name' => '<script>alert(1)</script>Contact Us',
	] ] ] );
	$name = $out['integrations']['hubspot']['hubspot_form_name'];
	nf_assert_true( strpos( $name, '<script>' ) === false, 'script tag stripped' );
	nf_assert_true( strpos( $name, 'alert(1)' ) === false || strpos( $name, '<' ) === false, 'inner text or wrapping is sanitised' );
} );

nf_test( 'hubspot_form_name: length-capped at HS_FORM_NAME_MAX', function () {
	$long = str_repeat( 'x', 1000 );
	$out  = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'           => true,
		'hubspot_form_name' => $long,
	] ] ] );
	$name = $out['integrations']['hubspot']['hubspot_form_name'];
	nf_assert_true( strlen( $name ) <= FormSettingsSanitizer::HS_FORM_NAME_MAX, 'name length capped' );
} );

// ────────── field_map sanitisation ──────────

nf_test( 'field_map: array values are rejected (no nested structures)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'   => true,
		'field_map' => [
			'email'     => 'email_field_1',         // valid
			'firstname' => [ 'evil' => 'object' ],  // rejected (array value)
		],
	] ] ] );
	$map = $out['integrations']['hubspot']['field_map'];
	nf_assert_same( 1, count( $map ), 'array-valued mapping dropped' );
	nf_assert_same( 'email_field_1', $map['email'], 'valid mapping preserved' );
} );

nf_test( 'field_map: invalid HubSpot key characters drop the entry', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'   => true,
		'field_map' => [
			'email'        => 'email_field',  // valid
			'evil key!'    => 'x',             // space + bang → reject
			'../../escape' => 'y',             // path-escape → reject
		],
	] ] ] );
	$map = $out['integrations']['hubspot']['field_map'];
	nf_assert_same( 1, count( $map ), 'only the valid key survives' );
	nf_assert_same( 'email_field', $map['email'], 'valid mapping kept' );
} );

nf_test( 'field_map: invalid NF field IDs drop the entry', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'   => true,
		'field_map' => [
			'email'     => 'email_field',
			'firstname' => 'evil id!',
			'lastname'  => 'a/b',
		],
	] ] ] );
	$map = $out['integrations']['hubspot']['field_map'];
	nf_assert_same( 1, count( $map ), 'only the valid mapping survives' );
} );

nf_test( 'field_map: empty values dropped (matches UI "Do not send")', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'   => true,
		'field_map' => [
			'email'     => 'email_field',
			'firstname' => '',           // explicit "do not send"
			'lastname'  => '   ',         // whitespace-only
		],
	] ] ] );
	$map = $out['integrations']['hubspot']['field_map'];
	nf_assert_same( 1, count( $map ), 'empty/whitespace values dropped' );
} );

nf_test( 'field_map: HubSpot dots and dashes in property names are allowed', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'   => true,
		'field_map' => [
			'lifecyclestage'           => 'lifecycle_field',
			'address.line1'            => 'addr_field',
			'utm-source'               => 'utm_field',
		],
	] ] ] );
	$map = $out['integrations']['hubspot']['field_map'];
	nf_assert_same( 3, count( $map ), 'dot and dash property names allowed' );
} );

nf_test( 'field_map: non-array value → empty map (no fatal)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'         => true,
		'hubspot_form_id' => 'abc-123',
		'field_map'       => 'not-an-array',
	] ] ] );
	nf_assert_same( [], $out['integrations']['hubspot']['field_map'], 'string → empty map' );
} );

// ────────── forbidden / telemetry keys ──────────

nf_test( 'forbidden keys: access_token / client_secret / token are stripped', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'           => true,
		'hubspot_form_id'   => 'abc-123',
		'access_token'      => 'pat-evil-token',
		'token'             => 'evil',
		'private_app_token' => 'evil',
		'client_secret'     => 'evil',
		'secret'            => 'evil',
	] ] ] );
	$hs = $out['integrations']['hubspot'];
	foreach ( FormSettingsSanitizer::HS_FORBIDDEN_KEYS as $k ) {
		nf_assert_true( ! array_key_exists( $k, $hs ), "{$k} must be stripped" );
	}
} );

nf_test( 'telemetry keys: last_sent_at / last_error / last_status_code are stripped from client payload', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'           => true,
		'hubspot_form_id'   => 'abc-123',
		'last_sent_at'      => '2099-01-01 00:00:00',
		'last_error'        => 'fake error',
		'last_status_code'  => 200,
	] ] ] );
	$hs = $out['integrations']['hubspot'];
	foreach ( FormSettingsSanitizer::HS_TELEMETRY_KEYS as $k ) {
		nf_assert_true( ! array_key_exists( $k, $hs ), "{$k} must be stripped from client payload" );
	}
} );

nf_test( 'unknown keys: arbitrary extras under hubspot are stripped', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'           => true,
		'hubspot_form_id'   => 'abc-123',
		'random_junk'       => 'should not survive',
		'another_extra'     => [ 'nested' => 'data' ],
	] ] ] );
	$hs = $out['integrations']['hubspot'];
	nf_assert_true( ! array_key_exists( 'random_junk',   $hs ), 'unknown key #1 stripped' );
	nf_assert_true( ! array_key_exists( 'another_extra', $hs ), 'unknown key #2 stripped' );
	$expected_keys = [ 'enabled', 'hubspot_form_id', 'hubspot_form_name', 'field_map' ];
	sort( $expected_keys );
	$actual = array_keys( $hs );
	sort( $actual );
	nf_assert_same( $expected_keys, $actual, 'hubspot keys are exactly the whitelist' );
} );

// ────────── collapse-when-empty ──────────

nf_test( 'collapse: entirely-empty hubspot config drops the integrations key', function () {
	$out = FormSettingsSanitizer::sanitize( [
		'on_success'   => [ 'mode' => 'message', 'message' => 'ok' ],
		'integrations' => [ 'hubspot' => [
			'enabled'         => false,
			'hubspot_form_id' => '',
			'field_map'       => [],
		] ],
	] );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'empty hubspot collapses to no integrations' );
	nf_assert_same( 'message', $out['on_success']['mode'], 'unrelated settings untouched' );
} );

nf_test( 'collapse: disabled-with-mapping is preserved (admin may toggle back on)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => [
		'enabled'         => false,
		'hubspot_form_id' => 'abc-123',
		'field_map'       => [ 'email' => 'email_field' ],
	] ] ] );
	nf_assert_true( array_key_exists( 'hubspot', $out['integrations'] ), 'config retained when there is meaningful data' );
	nf_assert_same( false, $out['integrations']['hubspot']['enabled'], 'enabled remains false' );
	nf_assert_same( 'abc-123', $out['integrations']['hubspot']['hubspot_form_id'], 'form_id preserved' );
} );

// ────────── malformed payload ──────────

nf_test( 'malformed: hubspot value is a string → drops gracefully', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => 'evil' ] ] );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'malformed hubspot → integrations dropped' );
} );

nf_test( 'malformed: hubspot value is null → drops gracefully', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => null ] ] );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'null hubspot → integrations dropped' );
} );

nf_test( 'malformed: hubspot value is an int → drops gracefully', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'hubspot' => 42 ] ] );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'int hubspot → integrations dropped' );
} );

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
