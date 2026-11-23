<?php
/**
 * Name, Address, Time, Password, Content block and Signature fields (1.3.0):
 * ExtraFields rules (checks, cleaning, display, signature decoding), the
 * renderer markup, and the submit / browser wiring.
 *
 *   php tests/NewFieldTypesTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) )    { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
// Calculations unlocked (Pro present); every other filter passes the value through.
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$args ) { return $tag === 'neural_forms_can_calculations' ? true : $value; } }
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'esc_html' ) )    { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )    { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) )     { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ){ function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) )  { function esc_html__( $s, $domain = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr__' ) )  { function esc_attr__( $s, $domain = null ) { return esc_attr( $s ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return false; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $h ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $h, $src = '', $deps = [], $ver = false, $in_footer = false ) {} }
if ( ! function_exists( 'is_email' ) ) { function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) { define( 'NEURAL_FORMS_VERSION', '1.2.0-test' ); }
$GLOBALS['__nf_options'] = [];
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = [] ) { return $GLOBALS['__nf_options'][ $key ] ?? $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $key, $value, $autoload = null ) { $GLOBALS['__nf_options'][ $key ] = $value; return true; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/core/Capabilities.php';
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/ConditionEngine.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';
require_once $plugin_root . '/modules/core/Config.php';
require_once $plugin_root . '/modules/pricing/Money.php';
require_once $plugin_root . '/modules/pricing/Expression.php';
require_once $plugin_root . '/modules/pricing/PriceEngine.php';
require_once $plugin_root . '/modules/frontend/FormRenderer.php';

use NeuralForms\Frontend\FormRenderer;

$passed = 0; $failed = 0;
function check( string $label, bool $ok ): void {
	global $passed, $failed;
	if ( $ok ) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; }
}
function has( string $html, string ...$needles ): bool {
	foreach ( $needles as $n ) { if ( strpos( $html, $n ) === false ) { echo "       missing: $n\n"; return false; } }
	return true;
}
function lacks( string $html, string ...$needles ): bool {
	foreach ( $needles as $n ) { if ( strpos( $html, $n ) !== false ) { echo "       unexpected: $n\n"; return false; } }
	return true;
}



if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $h ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $h ); } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $h ) { return (string) $h; } }
require_once $plugin_root . '/modules/forms/ExtraFields.php';
use NeuralForms\Forms\ExtraFields;
use NeuralForms\Forms\FieldRegistry;

foreach ( ExtraFields::TYPES as $t ) {
	check( "registered: $t", FieldRegistry::is_registered( $t ) );
}

$name = [ 'id' => 'n', 'type' => 'name', 'label' => 'Name', 'required' => true ];
check( 'name: last missing -> required', ( ExtraFields::validate( $name, [ 'first' => 'Jo' ] )['code'] ?? '' ) === 'required' );
check( 'name: ok', ExtraFields::validate( $name, [ 'first' => 'Jo', 'last' => 'Smith' ] ) === null );
check( 'name: string refused', ( ExtraFields::validate( $name, 'Jo' )['code'] ?? '' ) === 'invalid_value' );
check( 'name: nested array refused', ( ExtraFields::validate( $name, [ 'first' => [ 'x' ] ] )['code'] ?? '' ) === 'invalid_value' );
check( 'name: middle optional', ExtraFields::validate( $name + [ 'settings' => [ 'format' => 'first_middle_last' ] ], [ 'first' => 'A', 'last' => 'B' ] ) === null );
check( 'name: unknown parts dropped, tags stripped', ExtraFields::sanitize( $name, [ 'first' => '<b>Jo</b>', 'last' => 'Smith', 'evil' => 'x' ] ) === [ 'first' => 'Jo', 'last' => 'Smith' ] );
check( 'name: display', ExtraFields::display( $name, [ 'first' => 'Jo', 'last' => 'Smith' ] ) === 'Jo Smith' );
check( 'name: empty -> nothing stored', ExtraFields::sanitize( $name, [ 'first' => '', 'last' => '' ] ) === null );

$addr = [ 'id' => 'a', 'type' => 'address', 'label' => 'Address', 'required' => true ];
check( 'address: city required', ( ExtraFields::validate( $addr, [ 'line1' => '1 St', 'country' => 'United Kingdom' ] )['code'] ?? '' ) === 'required' );
check( 'address: country not required when hidden', ExtraFields::validate( $addr + [ 'settings' => [ 'show_country' => false ] ], [ 'line1' => '1 St', 'city' => 'Leeds' ] ) === null );
check( 'address: display order', ExtraFields::display( $addr, [ 'country' => 'UK', 'city' => 'Leeds', 'line1' => '1 St' ] ) === '1 St, Leeds, UK' );
check( 'address: hidden line2 not stored', ! isset( ExtraFields::sanitize( $addr + [ 'settings' => [ 'show_line2' => false ] ], [ 'line1' => '1', 'line2' => 'x', 'city' => 'L' ] )['line2'] ) );

$time = [ 'id' => 't', 'type' => 'time', 'label' => 'Time', 'min' => '09:00', 'max' => '17:00' ];
check( 'time: clean 9:05', ExtraFields::clean_time( '9:05' ) === '09:05' );
check( 'time: seconds dropped', ExtraFields::clean_time( '14:30:59' ) === '14:30' );
check( 'time: 25:00 invalid', ExtraFields::clean_time( '25:00' ) === '' );
check( 'time: before min', ( ExtraFields::validate( $time, '08:30' )['code'] ?? '' ) === 'out_of_range' );
check( 'time: in range', ExtraFields::validate( $time, '14:30' ) === null );
check( 'time: optional empty ok', ExtraFields::validate( $time, '' ) === null );

$pw = [ 'id' => 'p', 'type' => 'password', 'label' => 'Password', 'required' => true, 'settings' => [ 'min_length' => 8, 'confirm' => true ] ];
check( 'password: too short', ( ExtraFields::validate( $pw, [ 'password' => 'abc', 'confirm' => 'abc' ] )['code'] ?? '' ) === 'too_short' );
check( 'password: mismatch', ( ExtraFields::validate( $pw, [ 'password' => 'abcdefgh', 'confirm' => 'abcdefgX' ] )['code'] ?? '' ) === 'mismatch' );
check( 'password: ok', ExtraFields::validate( $pw, [ 'password' => 'abcdefgh', 'confirm' => 'abcdefgh' ] ) === null );
check( 'password: never stored', ExtraFields::sanitize( $pw, [ 'password' => 'abcdefgh' ] ) === null );
check( 'password: min clamped', ExtraFields::password_min( [ 'settings' => [ 'min_length' => 500 ] ] ) === 64 );

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
$sig = [ 'id' => 's', 'type' => 'signature', 'label' => 'Signature', 'required' => true ];
check( 'signature: real PNG accepted', ExtraFields::decode_signature( 'data:image/png;base64,' . base64_encode( $png ) ) === $png );
check( 'signature: GIF refused', ExtraFields::decode_signature( 'data:image/png;base64,' . base64_encode( 'GIF89a' ) ) === null );
check( 'signature: SVG refused', ExtraFields::decode_signature( 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=' ) === null );
check( 'signature: oversized refused', ExtraFields::decode_signature( 'data:image/png;base64,' . str_repeat( 'A', ExtraFields::SIGNATURE_MAX_CHARS ) ) === null );
check( 'signature: required', ( ExtraFields::validate( $sig, '' )['code'] ?? '' ) === 'required' );
check( 'signature: display', ExtraFields::display( $sig, [ 'url' => 'https://x/s.png' ] ) === 'Signed' );

$html = FormRenderer::render_preview_form( [ 'id' => 7, 'name' => 'T', 'status' => 'active', 'form_schema' => [ 'fields' => [
	[ 'id' => 'intro', 'type' => 'html', 'label' => 'Content', 'content' => '<p>Hi <strong>there</strong></p><script>alert(1)</script>' ],
	$name, $addr + [ 'settings' => [ 'default_country' => 'United Kingdom' ] ], $time, $pw, $sig,
] ], 'settings' => [] ] );
check( 'content block rendered, script removed', has( $html, 'neural-forms-form__content-block', '<strong>there</strong>' ) && lacks( $html, '<script>alert' ) );
check( 'content block is not a field (no input, no error slot)', lacks( $html, 'neural-forms-7-intro-error' ) );
check( 'name posts parts with autocomplete', has( $html, 'name="n[first]"', 'autocomplete="given-name"', 'name="n[last]"' ) );
check( 'address country is a dropdown', preg_match( '/<select id="neural-forms-7-a-country" name="a\[country\]"/', $html ) === 1 );
check( 'legacy default name pre-selects', has( $html, '<option value="United Kingdom" data-code="GB" selected>' ) );
$html_code = FormRenderer::render_preview_form( [ 'id' => 8, 'name' => 'T', 'status' => 'active', 'form_schema' => [ 'fields' => [ $addr + [ 'settings' => [ 'default_country' => 'de' ] ] ] ], 'settings' => [] ] );
check( 'ISO default pre-selects', has( $html_code, '<option value="Germany" data-code="DE" selected>' ) );
check( 'full country list', count( \NeuralForms\Forms\Countries::all() ) >= 245 );
check( 'unknown country refused', ( ExtraFields::validate( $addr, [ 'line1' => '1', 'city' => 'X', 'country' => 'Narnia' ] )['code'] ?? '' ) === 'invalid_country' );
check( 'country stored with listed spelling', ( ExtraFields::sanitize( $addr, [ 'line1' => '1', 'city' => 'X', 'country' => 'united kingdom' ] )['country'] ?? '' ) === 'United Kingdom' );
check( 'time input with min/max', has( $html, 'type="time"', 'min="09:00"', 'max="17:00"' ) );
check( 'password + confirm, new-password', has( $html, 'name="p[password]"', 'name="p[confirm]"', 'autocomplete="new-password"', 'minlength="8"' ) );
check( 'signature pad + hidden input', has( $html, 'data-neural-forms-signature', '<canvas', 'type="hidden" id="neural-forms-7-s"' ) );

$submit = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
check( 'submit: content block never accepted', strpos( $submit, "array( 'calculated', 'html' )" ) !== false );
check( 'submit: signature saved as a private attachment', preg_match( "/function store_signature[\s\S]*?'post_status'    => 'private'/", $submit ) === 1 );
check( 'submit: password hook after save, not for spam', strpos( $submit, "do_action( 'neural_forms_password_submitted'" ) > strpos( $submit, '$entry_id = EntryRepository::create(' ) );
check( 'validator: routes new types to ExtraFields', strpos( file_get_contents( $plugin_root . '/modules/forms/SubmissionValidator.php' ), 'ExtraFields::validate( $field, $value )' ) !== false );
$export = file_get_contents( $plugin_root . '/modules/rest/Endpoints/ExportEndpoint.php' );
check( 'CSV: password / content skipped', strpos( $export, "if ( \$type === 'password' || \$type === 'html' ) {" ) !== false );

$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( 'JS: signature pad wired + cleared on reset', strpos( $js, 'initSignatures( form );' ) !== false && strpos( $js, "form.addEventListener( 'reset'" ) !== false );
check( 'JS: review masks the password', strpos( $js, "'••••••••'" ) !== false );
check( 'JS: checks every required part', strpos( $js, "w.querySelectorAll( 'input[required], select[required]' )" ) !== false );

echo "\nNewFieldTypesTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
