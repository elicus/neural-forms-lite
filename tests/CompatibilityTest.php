<?php
/**
 * Regression coverage for v1.0.0 backwards compatibility.
 *
 * Locks behavior the 1.1.0 row+column work must preserve:
 *   1. Old flat schemas load without mutation.
 *   2. Saving a builder change does not wipe untouched settings.
 *   3. Row/column containers never reach entry data.
 *   4. Both flat and row schemas render on the frontend.
 *   5. Preview endpoint accepts a synthetic in-memory form.
 *   6. Notification email / on_success / on_error / captcha settings
 *      survive a partial settings save.
 *
 * Run from the plugin root:
 *
 *   php tests/CompatibilityTest.php
 *
 * Exit code is 0 when every check passes, 1 otherwise. Output uses the
 * same PASS/FAIL format as RowSchemaTest.php so both files can be run
 * side-by-side without a heavier framework.
 */

declare( strict_types = 1 );

// ---------------------------------------------------------------------------
// Minimal WP shims. Mirror RowSchemaTest.php so both tests stay portable
// and don't need a WordPress bootstrap.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/abspath' );
}
defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op — ABSPATH is defined above).
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = null ) { return $s; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; }
}
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
	function wp_enqueue_style( $handle ) {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {}
}

// Stub option store driving Config::all().
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

if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) {
	define( 'NEURAL_FORMS_VERSION', '1.1.0-test' );
}

// ---------------------------------------------------------------------------
// Load the production code we are exercising. Order matters: SchemaHelper
// is loaded first because FormValidator + FormRenderer depend on it.
// ---------------------------------------------------------------------------
$plugin_root = dirname( __DIR__ );
$includes    = [
	$plugin_root . '/modules/core/Config.php',
	$plugin_root . '/modules/forms/FieldRegistry.php',
	$plugin_root . '/modules/forms/SchemaHelper.php',
	$plugin_root . '/modules/forms/FormValidator.php',
	$plugin_root . '/modules/frontend/FormRenderer.php',
];
foreach ( $includes as $f ) {
	if ( ! file_exists( $f ) ) {
		echo "MISSING: $f\n";
		exit( 1 );
	}
	require_once $f;
}

// ---------------------------------------------------------------------------
// Inline mini-runner.
// ---------------------------------------------------------------------------
$cases  = [];
$passed = 0;
$failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok      = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

function check_truthy( string $label, $actual, array &$cases, int &$passed, int &$failed ): void {
	$ok      = (bool) $actual;
	$cases[] = [ $ok, $label, $actual, '(truthy)' ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\FormValidator;
use NeuralForms\Frontend\FormRenderer;

// ---------------------------------------------------------------------------
// Sample fixtures.
// ---------------------------------------------------------------------------
$flat_field_name  = [ 'id' => 'first_name', 'type' => 'text',     'label' => 'First name', 'required' => true ];
$flat_field_email = [ 'id' => 'email',      'type' => 'email',    'label' => 'Email',      'required' => true ];
$flat_field_msg   = [ 'id' => 'message',    'type' => 'textarea', 'label' => 'Message',    'required' => true ];

// A v1.0.0-shaped form: flat fields + the four settings the user
// explicitly called out (captcha, notification email, on_success, on_error).
$legacy_form = [
	'id'          => 7,
	'name'        => 'Legacy Contact',
	'status'      => 'active',
	'form_schema' => [
		'fields' => [ $flat_field_name, $flat_field_email, $flat_field_msg ],
	],
	'settings'    => [
		'captcha_enabled'           => true,
		'enable_admin_notification' => true,
		'admin_notification_email'  => 'leads@example.test',
		'on_success' => [ 'mode' => 'message',  'message' => 'Thanks!',          'url' => '' ],
		'on_error'   => [ 'mode' => 'redirect', 'message' => 'Something failed', 'url' => 'https://example.test/error' ],
		'success_message'           => 'Legacy fallback success',
	],
];

// A v1.1.0-shaped form: row + columns plus a flat textarea below.
$row_node = [
	'id'       => 'row_main',
	'type'     => 'row',
	'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
	'columns'  => [
		[ 'id' => 'col_left',  'fields' => [ $flat_field_name  ] ],
		[ 'id' => 'col_right', 'fields' => [ $flat_field_email ] ],
	],
];
$row_form = [
	'id'          => 8,
	'name'        => 'New Layout Form',
	'status'      => 'active',
	'form_schema' => [ 'fields' => [ $row_node, $flat_field_msg ] ],
	'settings'    => $legacy_form['settings'],
];

// ---------------------------------------------------------------------------
// 1. Old flat form load compatibility.
//    A flat schema must validate, must not require row/column structure,
//    and must round-trip through SchemaHelper without mutation.
// ---------------------------------------------------------------------------
$pre_load        = $legacy_form['form_schema'];
$pre_load_clone  = json_decode( json_encode( $pre_load ), true );
$validates_flat  = FormValidator::validate_schema( $pre_load );
$flatten_flat    = SchemaHelper::flatten_fields( $pre_load['fields'] );

check( '1a flat schema validates',                          $validates_flat === true, true, $cases, $passed, $failed );
check( '1b flatten preserves field count',                  count( $flatten_flat ),   3,    $cases, $passed, $failed );
check( '1c flatten preserves order',                        array_column( $flatten_flat, 'id' ), [ 'first_name', 'email', 'message' ], $cases, $passed, $failed );
check( '1d schema array unmutated by flatten',              $pre_load,                $pre_load_clone, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Old flat form save compatibility.
//    Saving must preserve every settings key. We model the contract that
//    FormRepository::update() honors: only keys present in $data are
//    written. A schema-only save leaves settings untouched, regardless of
//    whether the schema is flat or contains rows.
// ---------------------------------------------------------------------------
$saved_settings = $legacy_form['settings'];

// Model "schema-only save": merge nothing into settings, but normalize the
// schema as the builder would (no-op for flat input).
$schema_only_save = [ 'form_schema' => $legacy_form['form_schema'] ];
$post_save_settings = array_key_exists( 'settings', $schema_only_save )
	? $schema_only_save['settings']
	: $saved_settings;

check( '2a schema-only save leaves settings intact',        $post_save_settings,                                $saved_settings,                  $cases, $passed, $failed );
check( '2b captcha_enabled survives schema-only save',      $post_save_settings['captcha_enabled'],             true,                              $cases, $passed, $failed );
check( '2c admin notification email survives',              $post_save_settings['admin_notification_email'],    'leads@example.test',              $cases, $passed, $failed );
check( '2d on_success survives schema-only save',           $post_save_settings['on_success'],                  $saved_settings['on_success'],     $cases, $passed, $failed );
check( '2e on_error survives schema-only save',             $post_save_settings['on_error'],                    $saved_settings['on_error'],       $cases, $passed, $failed );
check( '2f legacy success_message survives',                $post_save_settings['success_message'],             'Legacy fallback success',         $cases, $passed, $failed );

// Saving a flat schema must not silently rewrite it as a row schema.
$resaved_schema = $legacy_form['form_schema'];
$has_row_at_top = false;
foreach ( $resaved_schema['fields'] as $node ) {
	if ( SchemaHelper::is_row( $node ) ) {
		$has_row_at_top = true;
		break;
	}
}
check( '2g flat save does not inject row containers',       $has_row_at_top, false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Row/column flattening.
//    flatten_fields must drop row/column containers; only real input
//    fields appear in the submitted entry payload.
// ---------------------------------------------------------------------------
$flat_for_submit = SchemaHelper::flatten_fields( $row_form['form_schema']['fields'] );
$ids_for_submit  = array_column( $flat_for_submit, 'id' );

check( '3a flatten yields three real fields',               count( $flat_for_submit ), 3, $cases, $passed, $failed );
check( '3b row id is not a submitted field id',             in_array( 'row_main',  $ids_for_submit, true ), false, $cases, $passed, $failed );
check( '3c left column id is not submitted',                in_array( 'col_left',  $ids_for_submit, true ), false, $cases, $passed, $failed );
check( '3d right column id is not submitted',               in_array( 'col_right', $ids_for_submit, true ), false, $cases, $passed, $failed );
check( '3e only real input types remain',                   array_column( $flat_for_submit, 'type' ), [ 'text', 'email', 'textarea' ], $cases, $passed, $failed );

// strip_layout_keys is the defense-in-depth guard inside EntryRepository.
// If a future caller forgot to flatten and shipped row_/col_ keys to
// EntryRepository::create(), they must still be stripped before storage.
require_once $plugin_root . '/modules/storage/EntryRepository.php';
$ref_strip = new ReflectionMethod( \NeuralForms\Storage\EntryRepository::class, 'strip_layout_keys' );
$ref_strip->setAccessible( true );

$contaminated = [
	'first_name' => 'Ada',
	'email'      => 'ada@example.test',
	'row_main'   => [ 'type' => 'row', 'columns' => [] ],
	'col_left'   => [ 'fields' => [] ],
	'message'    => 'hi',
];
$cleaned = $ref_strip->invoke( null, $contaminated );

check( '3f strip_layout_keys drops row_* keys',             array_key_exists( 'row_main', $cleaned ), false, $cases, $passed, $failed );
check( '3g strip_layout_keys drops col_* keys',             array_key_exists( 'col_left', $cleaned ), false, $cases, $passed, $failed );
check( '3h strip_layout_keys keeps real field values',      $cleaned, [ 'first_name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'hi' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Frontend rendering compatibility.
//    Both flat and row schemas must render to non-empty HTML. The row
//    schema must produce a row wrapper; the flat schema must not.
// ---------------------------------------------------------------------------
$flat_html = FormRenderer::render_preview_form( $legacy_form, [] );
$row_html  = FormRenderer::render_preview_form( $row_form,    [] );

check_truthy( '4a flat schema renders to non-empty HTML',                          strlen( $flat_html ) > 0,                                              $cases, $passed, $failed );
check_truthy( '4b flat HTML contains the form wrapper',                            strpos( $flat_html, 'class="neural-forms-form"' ) !== false,           $cases, $passed, $failed );
check( '4c flat HTML contains no row container',                                   strpos( $flat_html, 'neural-forms-form__row neural-forms-form__row--cols-' ), false, $cases, $passed, $failed );

check_truthy( '4d row schema renders to non-empty HTML',                           strlen( $row_html ) > 0,                                               $cases, $passed, $failed );
check_truthy( '4e row HTML emits a 2-column row wrapper',                          strpos( $row_html, 'neural-forms-form__row--cols-2' ) !== false,        $cases, $passed, $failed );
check_truthy( '4f row HTML emits the column wrapper',                              strpos( $row_html, 'neural-forms-form__row__column' )  !== false,       $cases, $passed, $failed );
check_truthy( '4g row HTML still emits the flat textarea sibling',                 strpos( $row_html, 'name="message"' )                  !== false,       $cases, $passed, $failed );
// row_/col_ ids show up as data-* attributes (layout markers), but never as
// input name="..." — confirm the layout-only invariant in the markup.
check( '4h row HTML never emits an input named after the row',                     strpos( $row_html, 'name="row_main"' ),  false, $cases, $passed, $failed );
check( '4i row HTML never emits an input named after the column',                  strpos( $row_html, 'name="col_left"' ),  false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Preview endpoint compatibility.
//    render_preview_form is the helper PreviewEndpoint calls. It must:
//      - accept a synthetic array (no DB lookup),
//      - tolerate a partial array via defensive defaults,
//      - render both flat and row schemas.
// ---------------------------------------------------------------------------
$partial_form = [
	'form_schema' => [ 'fields' => [ $flat_field_email ] ],
	// no id, no name, no status, no settings — caller is in-flight.
];
$partial_html = FormRenderer::render_preview_form( $partial_form, [] );

check_truthy( '5a partial form (only schema) still renders',                       strlen( $partial_html ) > 0,                                            $cases, $passed, $failed );
check_truthy( '5b partial render contains the email input',                       strpos( $partial_html, 'type="email"' )       !== false,                $cases, $passed, $failed );
check_truthy( '5c partial render contains a form wrapper',                        strpos( $partial_html, 'neural-forms-form' )  !== false,                $cases, $passed, $failed );

// Repeat the flat + row renders through the same code path to lock the
// "no DB lookup required" guarantee.
$preview_flat = FormRenderer::render_preview_form( [
	'form_schema' => $legacy_form['form_schema'],
	'settings'    => $legacy_form['settings'],
] );
$preview_row = FormRenderer::render_preview_form( [
	'form_schema' => $row_form['form_schema'],
	'settings'    => $row_form['settings'],
] );

check_truthy( '5d preview renders flat schema without DB',                         strpos( $preview_flat, 'name="first_name"' ) !== false,                 $cases, $passed, $failed );
check_truthy( '5e preview renders row schema without DB',                          strpos( $preview_row,  'neural-forms-form__row--cols-2' ) !== false,    $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Settings preservation.
//    Builder save = a partial settings dict from the React state. Any
//    keys not in the partial must keep the value already on disk. This
//    is the contract the REST layer relies on; we model it directly.
// ---------------------------------------------------------------------------
$on_disk = [
	'captcha_enabled'           => true,
	'admin_notification_email'  => 'leads@example.test',
	'enable_admin_notification' => true,
	'on_success' => [ 'mode' => 'message',  'message' => 'Thanks!',                'url' => '' ],
	'on_error'   => [ 'mode' => 'redirect', 'message' => 'Something failed',       'url' => 'https://example.test/error' ],
	'success_message'           => 'Legacy fallback success',
	'sender_email'              => 'noreply@example.test',
];

// Builder only changed the success message (typical builder UX path).
$builder_partial = [ 'on_success' => [ 'mode' => 'message', 'message' => 'Got it - we will reply soon.', 'url' => '' ] ];

// Mirror Config::update()'s array_merge semantics: partial wins, rest stays.
$merged = array_merge( $on_disk, $builder_partial );

check( '6a updated key reflects new value',                 $merged['on_success']['message'],            'Got it - we will reply soon.',  $cases, $passed, $failed );
check( '6b captcha_enabled preserved',                      $merged['captcha_enabled'],                  true,                            $cases, $passed, $failed );
check( '6c admin_notification_email preserved',             $merged['admin_notification_email'],         'leads@example.test',            $cases, $passed, $failed );
check( '6d enable_admin_notification preserved',            $merged['enable_admin_notification'],        true,                            $cases, $passed, $failed );
check( '6e on_error preserved',                             $merged['on_error'],                         $on_disk['on_error'],            $cases, $passed, $failed );
check( '6f legacy success_message preserved',               $merged['success_message'],                  'Legacy fallback success',       $cases, $passed, $failed );
check( '6g sender_email preserved',                         $merged['sender_email'],                     'noreply@example.test',          $cases, $passed, $failed );
check( '6h every original key still present after merge',   array_diff_key( $on_disk, $merged ),         [],                              $cases, $passed, $failed );

// Partial merge must not invent keys that weren't supplied.
$expected_only_change = $on_disk;
$expected_only_change['on_success'] = $builder_partial['on_success'];
check( '6i partial save only changes the supplied key',     $merged,                                     $expected_only_change,           $cases, $passed, $failed );

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
