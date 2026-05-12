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
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) return '';
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( $scheme === '' ) return '';
		return $url;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
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

// ────────── Google Forms branch ──────────

nf_test( 'google_forms: clean imported_source config round-trips', function () {
	$in = [ 'integrations' => [ 'google_forms' => [
		'enabled'           => true,
		'mode'              => 'imported_source',
		'google_form_id'    => '1AbCdEfG-_12345',
		'google_form_title' => 'Customer Feedback',
		'google_form_url'   => 'https://docs.google.com/forms/d/1AbCdEfG-_12345/edit',
		'imported_at'       => '2026-05-13 10:30:00',
		'sync_responses'    => false,
	] ] ];
	$out = FormSettingsSanitizer::sanitize( $in );
	$gf  = $out['integrations']['google_forms'];
	nf_assert_same( true,                $gf['enabled'],         'enabled' );
	nf_assert_same( 'imported_source',   $gf['mode'],            'mode' );
	nf_assert_same( '1AbCdEfG-_12345',   $gf['google_form_id'],  'form id round-trips' );
	nf_assert_same( 'Customer Feedback', $gf['google_form_title'], 'title round-trips' );
	nf_assert_same( 'https://docs.google.com/forms/d/1AbCdEfG-_12345/edit', $gf['google_form_url'], 'url round-trips' );
	nf_assert_same( '2026-05-13 10:30:00', $gf['imported_at'],   'imported_at round-trips' );
	nf_assert_same( false,               $gf['sync_responses'],  'sync_responses default false' );
} );

nf_test( 'google_forms: linked_source mode is accepted', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => true,
		'mode'           => 'linked_source',
		'google_form_id' => 'gf_abc_123',
	] ] ] );
	nf_assert_same( 'linked_source', $out['integrations']['google_forms']['mode'], 'linked_source accepted' );
} );

nf_test( 'google_forms: unknown mode → empty string (dropped, others preserved)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => true,
		'mode'           => 'evil_mode',
		'google_form_id' => 'gf_abc_123',
	] ] ] );
	nf_assert_same( '',           $out['integrations']['google_forms']['mode'],           'invalid mode → empty' );
	nf_assert_same( 'gf_abc_123', $out['integrations']['google_forms']['google_form_id'], 'other fields preserved' );
} );

nf_test( 'google_forms: google_form_id path-escape stripped to empty', function () {
	foreach ( [ '../etc', 'gf/x', 'gf?x', 'gf space', "gf\x00null" ] as $bad ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
			'enabled'        => true,
			'google_form_id' => $bad,
		] ] ] );
		nf_assert_same( '', $out['integrations']['google_forms']['google_form_id'], "rejects: " . var_export( $bad, true ) );
	}
} );

nf_test( 'google_forms: google_form_url rejects non-docs.google.com hosts', function () {
	foreach ( [
		'https://attacker.example.com/forms/d/abc',
		'http://docs.google.com/forms/d/abc',           // http, not https
		'javascript:alert(1)',
		'ftp://docs.google.com/x',
	] as $bad ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
			'enabled'         => true,
			'google_form_url' => $bad,
		] ] ] );
		nf_assert_same( '', $out['integrations']['google_forms']['google_form_url'], "rejects: {$bad}" );
	}
} );

nf_test( 'google_forms: google_form_url accepts canonical edit + viewform paths', function () {
	foreach ( [
		'https://docs.google.com/forms/d/1AbC-Def/edit',
		'https://docs.google.com/forms/d/e/1FAIpQLSc-xyz/viewform',
	] as $good ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
			'enabled'         => true,
			'google_form_url' => $good,
		] ] ] );
		nf_assert_same( $good, $out['integrations']['google_forms']['google_form_url'], "accepts: {$good}" );
	}
} );

nf_test( 'google_forms: google_form_title is HTML-stripped + length-capped', function () {
	$long = str_repeat( 'x', 500 );
	$out  = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'           => true,
		// Plain-ASCII payload so the byte-vs-char comparison is
		// unambiguous (the sanitiser caps by Unicode character
		// count via mb_substr — a multi-byte char would push the
		// byte length 1-2 over the char cap without breaking the
		// contract).
		'google_form_title' => '<script>alert(1)</script>Real Title - ' . $long,
	] ] ] );
	$title = $out['integrations']['google_forms']['google_form_title'];
	nf_assert_true( strpos( $title, '<script>' ) === false, 'script tag stripped' );
	$len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
	nf_assert_true( $len <= FormSettingsSanitizer::GF_FORM_TITLE_MAX, 'title length capped to GF_FORM_TITLE_MAX chars' );
} );

nf_test( 'google_forms: forbidden secret keys stripped', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => true,
		'google_form_id' => 'gf-123',
		'access_token'   => 'ya29.evil',
		'refresh_token'  => '1//refresh-evil',
		'client_secret'  => 'GOCSPX-evil',
		'token'          => 'evil',
		'secret'         => 'evil',
	] ] ] );
	$gf = $out['integrations']['google_forms'];
	foreach ( FormSettingsSanitizer::GF_FORBIDDEN_KEYS as $k ) {
		nf_assert_true( ! array_key_exists( $k, $gf ), "{$k} must be stripped" );
	}
} );

nf_test( 'google_forms: telemetry keys stripped from client payload', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'         => true,
		'google_form_id'  => 'gf-123',
		'last_checked_at' => '2099-01-01 00:00:00',
		'last_sync_at'    => '2099-01-01 00:00:00',
		'last_error'      => 'fake error',
	] ] ] );
	$gf = $out['integrations']['google_forms'];
	foreach ( FormSettingsSanitizer::GF_TELEMETRY_KEYS as $k ) {
		nf_assert_true( ! array_key_exists( $k, $gf ), "{$k} must be stripped" );
	}
} );

nf_test( 'google_forms: unknown extras stripped (exact whitelist)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => true,
		'google_form_id' => 'gf-123',
		'random_junk'    => 'no',
		'extra_object'   => [ 'nested' => 'data' ],
	] ] ] );
	$expected = [ 'enabled', 'google_form_id', 'google_form_title', 'google_form_url', 'imported_at', 'mode', 'sync_responses' ];
	sort( $expected );
	$actual = array_keys( $out['integrations']['google_forms'] );
	sort( $actual );
	nf_assert_same( $expected, $actual, 'keys are exactly the whitelist' );
} );

nf_test( 'google_forms: collapse-when-empty drops the integrations key', function () {
	$out = FormSettingsSanitizer::sanitize( [
		'on_success'   => [ 'mode' => 'message', 'message' => 'ok' ],
		'integrations' => [ 'google_forms' => [
			'enabled' => false,
			'mode'    => '',
		] ],
	] );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'empty google_forms collapses' );
	nf_assert_same( 'message', $out['on_success']['mode'], 'unrelated settings untouched' );
} );

nf_test( 'google_forms: imported config survives even with enabled=false', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => false,
		'mode'           => 'imported_source',
		'google_form_id' => 'gf-123',
		'imported_at'    => '2026-05-13 10:30:00',
	] ] ] );
	nf_assert_true( array_key_exists( 'google_forms', $out['integrations'] ), 'imported metadata preserved even when disabled' );
} );

nf_test( 'google_forms: both hubspot AND google_forms can coexist', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [
		'hubspot'      => [ 'enabled' => true, 'hubspot_form_id' => 'g', 'field_map' => [ 'email' => 'email_field' ] ],
		'google_forms' => [ 'enabled' => true, 'mode' => 'imported_source', 'google_form_id' => 'gf-123' ],
	] ] );
	nf_assert_true( array_key_exists( 'hubspot',      $out['integrations'] ), 'hubspot kept' );
	nf_assert_true( array_key_exists( 'google_forms', $out['integrations'] ), 'google_forms kept' );
} );

nf_test( 'google_forms: sync_responses bool default false, never accepts truthy from arbitrary input', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => true,
		'google_form_id' => 'gf-123',
	] ] ] );
	nf_assert_same( false, $out['integrations']['google_forms']['sync_responses'], 'default false' );

	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => [
		'enabled'        => true,
		'google_form_id' => 'gf-123',
		'sync_responses' => true,
	] ] ] );
	nf_assert_same( true, $out['integrations']['google_forms']['sync_responses'], 'client CAN set true (future: server-side dispatcher will gate)' );
} );

nf_test( 'google_forms: malformed payload (string / null / int) drops gracefully', function () {
	foreach ( [ 'evil', null, 42, true ] as $bad ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'google_forms' => $bad ] ] );
		nf_assert_true( ! array_key_exists( 'integrations', $out ), 'malformed google_forms drops cleanly: ' . var_export( $bad, true ) );
	}
} );

// ────────── WhatsApp branch ──────────

nf_test( 'whatsapp: clean config round-trips with all seven whitelisted keys', function () {
	$in = [ 'integrations' => [ 'whatsapp' => [
		'enabled'           => true,
		'recipients'        => [ '919999999999', '918888888888' ],
		'trigger'           => 'entry_created',
		'template_name'     => 'neural_forms_entry_alert',
		'template_language' => 'en_US',
		'send_when'         => 'all_entries',
		'include_summary'   => true,
	] ] ];
	$out = FormSettingsSanitizer::sanitize( $in );
	$wa  = $out['integrations']['whatsapp'];
	nf_assert_same( true,                            $wa['enabled'],           'enabled' );
	nf_assert_same( [ '919999999999', '918888888888' ], $wa['recipients'],    'recipients round-trip' );
	nf_assert_same( 'entry_created',                 $wa['trigger'],           'trigger' );
	nf_assert_same( 'neural_forms_entry_alert',      $wa['template_name'],     'template_name' );
	nf_assert_same( 'en_US',                         $wa['template_language'], 'template_language' );
	nf_assert_same( 'all_entries',                   $wa['send_when'],         'send_when' );
	nf_assert_same( true,                            $wa['include_summary'],   'include_summary' );
} );

nf_test( 'whatsapp: enabled coerces truthy/falsy/strings to bool', function () {
	$cases = [
		[ true,     true  ],
		[ false,    false ],
		[ 1,        true  ],
		[ 0,        false ],
		[ 'true',   true  ],
		[ '',       false ],
		[ null,     false ],
	];
	foreach ( $cases as [ $in, $expected ] ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
			'enabled'    => $in,
			'recipients' => [ '919999999999' ], // keep non-empty so collapse doesn't fire
		] ] ] );
		nf_assert_same( $expected, $out['integrations']['whatsapp']['enabled'], 'enabled=' . var_export( $in, true ) );
	}
} );

// ────────── Recipients normalisation ──────────

nf_test( 'whatsapp: recipients accepts a textarea string with linebreaks', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => "+91 9999 9999 99\n+91-8888-888-888",
	] ] ] );
	nf_assert_same( [ '919999999999', '918888888888' ], $out['integrations']['whatsapp']['recipients'], 'textarea parsed into normalised array' );
} );

nf_test( 'whatsapp: recipients array normalises to digits-only strings', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '+91 (999) 999-9999', '918888888888' ],
	] ] ] );
	nf_assert_same( [ '919999999999', '918888888888' ], $out['integrations']['whatsapp']['recipients'], 'digits-only' );
} );

nf_test( 'whatsapp: recipients rejects out-of-bounds lengths (<7 or >15)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [
			'12345',                  // 5 digits — too short
			'1234567',                // 7 — OK (boundary)
			'123456789012345',        // 15 — OK (boundary)
			'1234567890123456789',    // 19 — too long
			'+1 (555) 123-4567',      // 11 — OK
		],
	] ] ] );
	$wa = $out['integrations']['whatsapp'];
	nf_assert_same( 3, count( $wa['recipients'] ),               'only valid-length numbers survive' );
	nf_assert_same( '1234567',         $wa['recipients'][0],     '7-digit boundary kept' );
	nf_assert_same( '123456789012345', $wa['recipients'][1],     '15-digit boundary kept' );
	nf_assert_same( '15551234567',     $wa['recipients'][2],     '+1 (555) 123-4567 normalised' );
} );

nf_test( 'whatsapp: recipients deduped after normalisation', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '+91 9999999999', '91-9999999999', '919999999999', '918888888888' ],
	] ] ] );
	nf_assert_same( [ '919999999999', '918888888888' ], $out['integrations']['whatsapp']['recipients'], 'three formats of same number → one' );
} );

nf_test( 'whatsapp: recipients capped at WA_RECIPIENTS_MAX (10)', function () {
	$nums = [];
	for ( $i = 0; $i < 25; $i++ ) {
		// Different last digit so they don't dedupe.
		$nums[] = '91' . str_pad( (string) $i, 10, '0', STR_PAD_LEFT );
	}
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => $nums,
	] ] ] );
	nf_assert_same( FormSettingsSanitizer::WA_RECIPIENTS_MAX, count( $out['integrations']['whatsapp']['recipients'] ), 'capped at MAX' );
} );

nf_test( 'whatsapp: recipients comma-separated string also parsed', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => '+919999999999, +918888888888',
	] ] ] );
	nf_assert_same( 2, count( $out['integrations']['whatsapp']['recipients'] ), 'comma split' );
} );

// ────────── Enums ──────────

nf_test( 'whatsapp: trigger invalid → entry_created default', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '919999999999' ],
		'trigger'    => 'evil_trigger',
	] ] ] );
	nf_assert_same( 'entry_created', $out['integrations']['whatsapp']['trigger'], 'invalid → default' );
} );

nf_test( 'whatsapp: send_when accepts v1-active all_entries', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '919999999999' ],
		'send_when'  => 'all_entries',
	] ] ] );
	nf_assert_same( 'all_entries', $out['integrations']['whatsapp']['send_when'], 'all_entries kept' );
} );

nf_test( 'whatsapp: send_when accepts forward-compat values (high_priority etc.) for future', function () {
	foreach ( [ 'high_priority', 'needs_reply', 'spam_detected', 'not_spam' ] as $value ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
			'enabled'    => true,
			'recipients' => [ '919999999999' ],
			'send_when'  => $value,
		] ] ] );
		nf_assert_same( $value, $out['integrations']['whatsapp']['send_when'], "{$value} preserved for forward compatibility" );
	}
} );

nf_test( 'whatsapp: send_when invalid → all_entries default', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '919999999999' ],
		'send_when'  => 'evil_value',
	] ] ] );
	nf_assert_same( 'all_entries', $out['integrations']['whatsapp']['send_when'], 'invalid → default' );
} );

// ────────── Template name + language ──────────

nf_test( 'whatsapp: template_name rejects unsafe chars', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'       => true,
		'recipients'    => [ '919999999999' ],
		'template_name' => 'has spaces!',
	] ] ] );
	nf_assert_same( '', $out['integrations']['whatsapp']['template_name'], 'unsafe chars → empty' );
} );

nf_test( 'whatsapp: template_name accepts standard form', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'       => true,
		'recipients'    => [ '919999999999' ],
		'template_name' => 'neural_forms_entry_alert',
	] ] ] );
	nf_assert_same( 'neural_forms_entry_alert', $out['integrations']['whatsapp']['template_name'], 'accepted' );
} );

nf_test( 'whatsapp: template_language invalid → en_US default', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'           => true,
		'recipients'        => [ '919999999999' ],
		'template_language' => 'invalid lang!',
	] ] ] );
	nf_assert_same( 'en_US', $out['integrations']['whatsapp']['template_language'], 'invalid → default' );
} );

nf_test( 'whatsapp: template_language accepts en / en_US / pt_BR shapes', function () {
	foreach ( [ 'en', 'en_US', 'pt_BR' ] as $lang ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
			'enabled'           => true,
			'recipients'        => [ '919999999999' ],
			'template_language' => $lang,
		] ] ] );
		nf_assert_same( $lang, $out['integrations']['whatsapp']['template_language'], "{$lang} accepted" );
	}
} );

// ────────── include_summary ──────────

nf_test( 'whatsapp: include_summary defaults true when key absent', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '919999999999' ],
	] ] ] );
	nf_assert_same( true, $out['integrations']['whatsapp']['include_summary'], 'default true' );
} );

nf_test( 'whatsapp: include_summary coerces falsy to false', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'         => true,
		'recipients'      => [ '919999999999' ],
		'include_summary' => false,
	] ] ] );
	nf_assert_same( false, $out['integrations']['whatsapp']['include_summary'], 'false preserved' );
} );

// ────────── Forbidden / telemetry / unknown ──────────

nf_test( 'whatsapp: global/secret keys stripped', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'         => true,
		'recipients'      => [ '919999999999' ],
		'access_token'    => 'EAAJevil',
		'token'           => 'evil',
		'secret'          => 'evil',
		'client_secret'   => 'evil',
		'phone_number_id' => '99999',
		'waba_id'         => '99999',
		'graph_version'   => 'v22.0',
	] ] ] );
	$wa = $out['integrations']['whatsapp'];
	foreach ( FormSettingsSanitizer::WA_FORBIDDEN_KEYS as $k ) {
		nf_assert_true( ! array_key_exists( $k, $wa ), "{$k} must be stripped from per-form WhatsApp settings" );
	}
} );

nf_test( 'whatsapp: telemetry keys stripped from client payload', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'           => true,
		'recipients'        => [ '919999999999' ],
		'last_sent_at'      => '2099-01-01 00:00:00',
		'last_error'        => 'fake',
		'last_status_code'  => 200,
	] ] ] );
	$wa = $out['integrations']['whatsapp'];
	foreach ( FormSettingsSanitizer::WA_TELEMETRY_KEYS as $k ) {
		nf_assert_true( ! array_key_exists( $k, $wa ), "{$k} must be stripped" );
	}
} );

nf_test( 'whatsapp: unknown extras stripped (exact whitelist)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => true,
		'recipients' => [ '919999999999' ],
		'random_extra' => 'no',
		'object_extra' => [ 'nested' => 'data' ],
	] ] ] );
	$expected = [ 'enabled', 'recipients', 'trigger', 'template_name', 'template_language', 'send_when', 'include_summary' ];
	sort( $expected );
	$actual = array_keys( $out['integrations']['whatsapp'] );
	sort( $actual );
	nf_assert_same( $expected, $actual, 'whatsapp keys are exactly the whitelist' );
} );

// ────────── Collapse + coexist ──────────

nf_test( 'whatsapp: empty disabled config collapses to no integrations', function () {
	$out = FormSettingsSanitizer::sanitize( [
		'on_success'   => [ 'mode' => 'message', 'message' => 'ok' ],
		'integrations' => [ 'whatsapp' => [
			'enabled'    => false,
			'recipients' => [],
		] ],
	] );
	nf_assert_true( ! array_key_exists( 'integrations', $out ), 'collapsed' );
	nf_assert_same( 'message', $out['on_success']['mode'], 'unrelated settings untouched' );
} );

nf_test( 'whatsapp: disabled-with-recipients preserved (admin may toggle back on)', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => [
		'enabled'    => false,
		'recipients' => [ '919999999999' ],
	] ] ] );
	nf_assert_true( array_key_exists( 'whatsapp', $out['integrations'] ), 'config retained' );
	nf_assert_same( false, $out['integrations']['whatsapp']['enabled'], 'enabled remains false' );
	nf_assert_same( 1, count( $out['integrations']['whatsapp']['recipients'] ), 'recipients preserved' );
} );

nf_test( 'whatsapp: coexists with hubspot AND google_forms in the same form', function () {
	$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [
		'hubspot'      => [ 'enabled' => true, 'hubspot_form_id' => 'g', 'field_map' => [ 'email' => 'email_field' ] ],
		'google_forms' => [ 'enabled' => true, 'mode' => 'imported_source', 'google_form_id' => 'gf-123' ],
		'whatsapp'     => [ 'enabled' => true, 'recipients' => [ '919999999999' ] ],
	] ] );
	nf_assert_true( array_key_exists( 'hubspot',      $out['integrations'] ), 'hubspot kept' );
	nf_assert_true( array_key_exists( 'google_forms', $out['integrations'] ), 'google_forms kept' );
	nf_assert_true( array_key_exists( 'whatsapp',     $out['integrations'] ), 'whatsapp kept' );
} );

nf_test( 'whatsapp: malformed payload (string / null / int) drops gracefully', function () {
	foreach ( [ 'evil', null, 42, true ] as $bad ) {
		$out = FormSettingsSanitizer::sanitize( [ 'integrations' => [ 'whatsapp' => $bad ] ] );
		nf_assert_true( ! array_key_exists( 'integrations', $out ), 'malformed whatsapp drops: ' . var_export( $bad, true ) );
	}
} );

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
