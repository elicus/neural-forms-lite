<?php
/**
 * URL pre-fill (1.3.0): field.prefill_param → data-prefill on the field
 * wrapper (or on the bare hidden input). Bootstrap copied from
 * ProductPresetRenderTest.php.
 *
 *   php tests/UrlPrefillTest.php
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


function pf_form( array $fields ): array {
	return [ 'id' => 5, 'name' => 'Prefill', 'status' => 'active', 'form_schema' => [ 'fields' => $fields ], 'settings' => [] ];
}
$html = FormRenderer::render_preview_form( pf_form( [
	[ 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'prefill_param' => 'email' ],
	[ 'id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'options' => [ [ 'value' => 'a', 'label' => 'A' ] ], 'prefill_param' => 'plan' ],
	[ 'id' => 'src', 'type' => 'hidden', 'label' => 'Source', 'default_value' => 'direct', 'prefill_param' => 'utm_source' ],
	[ 'id' => 'ok', 'type' => 'acceptance', 'label' => 'I agree', 'prefill_param' => 'ok' ],
	[ 'id' => 'news', 'type' => 'checkbox', 'label' => 'Newsletter', 'prefill_param' => 'news' ],
	[ 'id' => 'bad', 'type' => 'text', 'label' => 'Bad', 'prefill_param' => '"><script>x</script>' ],
	[ 'id' => 'none', 'type' => 'text', 'label' => 'None' ],
] ) );
check( 'visible field wrapper carries data-prefill', preg_match( '/data-neural-forms-field-id="email"[^>]*data-prefill="email"/', $html ) === 1 );
check( 'select carries data-prefill', has( $html, 'data-prefill="plan"' ) );
check( 'hidden input carries it on the input, default kept', preg_match( '/<input type="hidden"[^>]*name="src"[^>]*value="direct"[^>]*data-prefill="utm_source"/', $html ) === 1 );
check( 'consent boxes never pre-fill', lacks( $html, 'data-prefill="ok"', 'data-prefill="news"' ) );
check( 'parameter name is cleaned', has( $html, 'data-prefill="scriptxscript"' ) && lacks( $html, '<script>x' ) );
check( 'no setting, no attribute', preg_match( '/data-neural-forms-field-id="none"[^>]*data-prefill/', $html ) === 0 );
check( 'parameter capped at 64 chars', strlen( FormRenderer::prefill_param( [ 'type' => 'text', 'prefill_param' => str_repeat( 'a', 90 ) ] ) ) === 64 );

$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( 'JS fills in the browser (cache-safe) and on reset', substr_count( $js, 'initPrefill( form );' ) === 2 );
check( 'JS never overwrites what the visitor typed', strpos( $js, "if ( ! hidden && String( el.value ).trim() !== '' ) return;" ) !== false );
check( 'JS remembers only hidden fields for the session', strpos( $js, "if ( isHid && store[ name ] !== value )" ) !== false );
check( 'JS sets values, never HTML', preg_match( '/function applyPrefill[\s\S]*?innerHTML/', substr( $js, strpos( $js, 'function applyPrefill' ), 3000 ) ) === 0 );

echo "\nUrlPrefillTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
