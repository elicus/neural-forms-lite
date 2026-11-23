<?php
/**
 * Entry preview on multi-step forms (1.3.0):
 * form_schema.settings.multistep.entry_preview { enabled, title } renders a
 * hidden review panel that form-submit.js fills on the last step.
 * Bootstrap copied from ProductPresetRenderTest.php.
 *
 *   php tests/EntryPreviewTest.php
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

// ── Entry preview (1.3.0) ──
// Run: php tests/EntryPreviewTest.php
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $a, $b = null ) { return substr( $s, $a, $b ); } }
function ms_form( array $multistep, array $steps ): array {
	return [ 'id' => 9, 'name' => 'Steps', 'status' => 'active', 'form_schema' => [ 'settings' => [ 'multistep' => $multistep ], 'fields' => $steps ], 'settings' => [] ];
}
$steps = [
	[ 'id' => 's1', 'type' => 'step', 'title' => 'One', 'fields' => [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ] ],
	[ 'id' => 's2', 'type' => 'step', 'title' => 'Two', 'fields' => [ [ 'id' => 'msg', 'type' => 'textarea', 'label' => 'Message' ] ] ],
];

$off = FormRenderer::render_preview_form( ms_form( [], $steps ) );
check( 'off by default: no review panel', lacks( $off, 'data-neural-forms-review' ) );

$on = FormRenderer::render_preview_form( ms_form( [ 'entry_preview' => [ 'enabled' => true ] ], $steps ) );
check( 'on: hidden panel with body + default heading', has( $on, 'data-neural-forms-review hidden', 'data-review-body', 'Review your answers', 'aria-labelledby="neural-forms-9-review-title"' ) );
check( 'on: panel sits after the fields, before the step footer', strpos( $on, 'data-neural-forms-review' ) > strpos( $on, 'neural-forms-form__steps-body' ) && strpos( $on, 'data-neural-forms-review' ) < strpos( $on, 'neural-forms-form__steps-footer' ) );
check( 'on: translatable labels for the JS', has( $on, 'data-edit-label="Edit"', 'data-step-label="Step %d"', 'data-empty-label="Nothing entered"' ) );

$custom = FormRenderer::render_preview_form( ms_form( [ 'entry_preview' => [ 'enabled' => true, 'title' => '<b>Check</b> it' ] ], $steps ) );
check( 'custom heading is escaped', has( $custom, '&lt;b&gt;Check&lt;/b&gt; it' ) && lacks( $custom, '<b>Check</b>' ) );

$single = FormRenderer::render_preview_form( [ 'id' => 9, 'name' => 'Flat', 'status' => 'active', 'form_schema' => [ 'settings' => [ 'multistep' => [ 'entry_preview' => [ 'enabled' => true ] ] ], 'fields' => [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ] ], 'settings' => [] ] );
check( 'single-page form never gets the panel', lacks( $single, 'data-neural-forms-review' ) );

$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( 'JS writes values as text, never HTML', strpos( $js, 'dd.textContent = value;' ) !== false && preg_match( '/function renderEntryReview[\s\S]*?innerHTML/', substr( $js, strpos( $js, 'function renderEntryReview' ), 4000 ) ) === 0 );
check( 'JS shows the review only on the last step', strpos( $js, 'refreshReview( isLast );' ) !== false );

echo "\nEntryPreviewTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
