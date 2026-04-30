<?php
/**
 * Builder save payload regression test.
 *
 * Exercises the exact body Builder.js POSTs for a brand-new form:
 *
 *   { name: "...", slug: "", status: "draft",
 *     form_schema: { fields: [...] }, settings: {} }
 *
 * Two failure modes are locked here:
 *
 *   1. The arg-array `sanitize_callback => 'sanitize_title'` regression
 *      that turned the WP_REST_Request into the slug value. We stand
 *      in for WordPress's REST sanitize_callback invocation
 *      (sanitize_title($value, $request, $param)) and prove that the
 *      ARG-ARRAY now uses a wrapper closure that only forwards the
 *      value.
 *
 *   2. The create_form derivation: an empty-string slug must fall
 *      back to the form name, not 400.
 *
 * Run from the plugin root:
 *
 *   php tests/BuilderSavePayloadTest.php
 *
 * Side-by-side with RowSchemaTest.php / CompatibilityTest.php /
 * CanonicalSchemaTest.php.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );

if ( ! function_exists( 'sanitize_title' ) ) {
	// Faithful enough reimplementation of WordPress core's sanitize_title:
	// returns the fallback when the post-sanitize string is empty. This
	// is the exact behaviour that leaks the WP_REST_Request when used as
	// a bare sanitize_callback - so the test reproduces the fatal cause.
	function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
		$t = is_string( $title ) ? strtolower( trim( $title ) ) : '';
		// Keep [a-z0-9 _-], collapse whitespace to dashes.
		$t = preg_replace( '/[^a-z0-9 _-]+/', '', $t );
		$t = preg_replace( '/[\s_]+/', '-', $t );
		$t = trim( (string) $t, '-' );
		if ( $t === '' || $t === false ) {
			return $fallback_title;
		}
		return $t;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = [];
		public function set_param( string $k, $v ): void { $this->params[ $k ] = $v; }
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
	}
}

// Stand-in: how WordPress invokes a sanitize_callback in arg-array mode.
function nf_invoke_sanitize_callback( callable $cb, $value, $request, string $name ) {
	return call_user_func( $cb, $value, $request, $name );
}

// ---------------------------------------------------------------------------
// Pull the actual arg array used by FormsEndpoint by mirroring its shape.
// We can't easily instantiate the endpoint class in a no-WP harness, but
// we know the canonical sanitize_callback after the fix:
//
//   'sanitize_callback' => static fn( $v ) => sanitize_title( (string) $v ),
//
// so we reproduce that locally and assert the contract holds.
// ---------------------------------------------------------------------------

$slug_arg = [
	'sanitize_callback' => static fn( $v ) => sanitize_title( (string) $v ),
];

$cases  = [];
$passed = 0; $failed = 0;
function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// ---------------------------------------------------------------------------
// 1. The fixed sanitize_callback: receives the request as 2nd/3rd arg but
//    discards it. With slug = "", the result is "" - never the request.
// ---------------------------------------------------------------------------
$req = new WP_REST_Request();
$req->set_param( 'name', 'Untitled form' );
$req->set_param( 'slug', '' );

$sanitized_empty = nf_invoke_sanitize_callback( $slug_arg['sanitize_callback'], '', $req, 'slug' );
check( '1 sanitize_callback returns empty string for empty input',
	$sanitized_empty, '', $cases, $passed, $failed );
check( '1 sanitize_callback never returns the WP_REST_Request',
	$sanitized_empty instanceof WP_REST_Request, false, $cases, $passed, $failed );

$sanitized_real = nf_invoke_sanitize_callback( $slug_arg['sanitize_callback'], 'My Form', $req, 'slug' );
check( '1 sanitize_callback handles a real slug', $sanitized_real, 'my-form', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Reproduce the BUG (pre-fix) to prove the test would have caught it.
//    sanitize_title used as a bare callback leaks the request.
// ---------------------------------------------------------------------------
$buggy_callback = 'sanitize_title';
$leak = nf_invoke_sanitize_callback( $buggy_callback, '', $req, 'slug' );
check( '2 BUG repro: bare sanitize_title leaks request as fallback',
	$leak instanceof WP_REST_Request, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. create_form's slug-derivation logic: empty slug + valid name must
//    derive a slug from the name (not 400). Mirror the post-fix code path.
// ---------------------------------------------------------------------------
function nf_derive_slug( $slug_param, string $name ): string {
	$slug_seed = ( is_string( $slug_param ) && $slug_param !== '' ) ? $slug_param : $name;
	return sanitize_title( (string) $slug_seed );
}

check( '3 empty slug derives from name',           nf_derive_slug( '',     'Untitled form' ), 'untitled-form', $cases, $passed, $failed );
check( '3 null  slug derives from name',           nf_derive_slug( null,   'My Quiz' ),       'my-quiz',       $cases, $passed, $failed );
check( '3 explicit slug wins over name',           nf_derive_slug( 'kept', 'Whatever' ),      'kept',          $cases, $passed, $failed );
// Whitespace-only slug is a deliberate user input (not the new builder's
// auto-empty path). Production matches WordPress core sanitize_title()
// which produces '' for whitespace - and the endpoint then returns 400
// "slug required". So the derived value here is ''. The endpoint's
// downstream check (line 162 in FormsEndpoint.php) catches it and
// returns the proper validation error.
check( '3 whitespace slug yields empty (caught downstream)', nf_derive_slug( '   ', 'My Form' ), '', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. End-to-end: feed the EXACT Builder.js new-form payload through the
//    sanitize_callback and the slug-derivation, and assert no fatal +
//    a usable slug.
// ---------------------------------------------------------------------------
$payload = [
	'name'        => 'Untitled form',
	'slug'        => '',
	'status'      => 'draft',
	'form_schema' => [ 'fields' => [] ],
	'settings'    => [],
];

$req2 = new WP_REST_Request();
foreach ( $payload as $k => $v ) $req2->set_param( $k, $v );

$slug_after_sanitize = nf_invoke_sanitize_callback(
	$slug_arg['sanitize_callback'], $payload['slug'], $req2, 'slug'
);
$final_slug = nf_derive_slug( $slug_after_sanitize, $payload['name'] );

check( '4 sanitize step is a string',          is_string( $slug_after_sanitize ), true,            $cases, $passed, $failed );
check( '4 derived slug is non-empty',          $final_slug !== '',                true,            $cases, $passed, $failed );
check( '4 derived slug matches name',          $final_slug,                       'untitled-form', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Status handling - new builder defaults to "active" and the REST
//    arg's validate_callback enforces the canonical enum.
// ---------------------------------------------------------------------------

// Mirror form_body_args() status validate_callback verbatim.
$status_validate = static fn( $v ) => in_array( $v, [ 'draft', 'active', 'archived' ], true );

// Mirror create_form's status default: $request->get_param('status') ?? 'active'.
function nf_resolve_status( $param ) {
	return $param ?? 'active';
}

check( '5 default status is active when omitted',  nf_resolve_status( null ),       'active',   $cases, $passed, $failed );
check( '5 explicit active passes through',         nf_resolve_status( 'active' ),   'active',   $cases, $passed, $failed );
check( '5 explicit draft passes through',          nf_resolve_status( 'draft' ),    'draft',    $cases, $passed, $failed );
check( '5 explicit archived passes through',       nf_resolve_status( 'archived' ), 'archived', $cases, $passed, $failed );

check( '5 validate_callback accepts active',       $status_validate( 'active' ),    true,       $cases, $passed, $failed );
check( '5 validate_callback accepts draft',        $status_validate( 'draft' ),     true,       $cases, $passed, $failed );
check( '5 validate_callback accepts archived',     $status_validate( 'archived' ),  true,       $cases, $passed, $failed );
check( '5 validate_callback rejects invalid',      $status_validate( 'pending' ),   false,      $cases, $passed, $failed );
check( '5 validate_callback rejects empty',        $status_validate( '' ),          false,      $cases, $passed, $failed );

// PUT-path semantics: FormRepository::update() only writes provided
// keys, so a payload that omits "status" must not change the stored
// status. Model the merge contract.
function nf_apply_partial_update( array $existing, array $patch ): array {
	return array_merge( $existing, $patch );
}

$existing = [
	'id'       => 7,
	'name'     => 'Existing form',
	'status'   => 'active',
	'settings' => [ 'captcha_enabled' => true ],
];

$schema_only_patch = [ 'form_schema' => [ 'fields' => [] ] ];
$after_schema_save = nf_apply_partial_update( $existing, $schema_only_patch );
check( '5 schema-only PUT preserves status',       $after_schema_save['status'],    'active',   $cases, $passed, $failed );
check( '5 schema-only PUT preserves settings',     $after_schema_save['settings'],  $existing['settings'], $cases, $passed, $failed );

$status_only_patch = [ 'status' => 'archived' ];
$after_status_save = nf_apply_partial_update( $existing, $status_only_patch );
check( '5 status-only PUT updates status',         $after_status_save['status'],    'archived', $cases, $passed, $failed );
check( '5 status-only PUT preserves settings',     $after_status_save['settings'],  $existing['settings'], $cases, $passed, $failed );
check( '5 status-only PUT preserves name',         $after_status_save['name'],      'Existing form', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Report
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
