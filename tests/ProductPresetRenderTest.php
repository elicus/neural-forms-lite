<?php
/**
 * Priced Item render presets (1.2.0 part B, 2026-11-04) — the FormRenderer
 * option `product_presets[field_id] = { choice, qty, allow_qty }` that Pro's
 * Payment Form uses to embed the whole form with ONE item preselected, plus
 * the form-currency fix for Priced Item price labels.
 *
 *   php tests/ProductPresetRenderTest.php
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
function form( array $fields, string $currency = 'USD' ): array {
	return [ 'id' => 7, 'name' => 'Presets', 'status' => 'active', 'form_schema' => [ 'fields' => $fields ], 'settings' => [ 'pricing' => [ 'currency' => $currency ] ] ];
}
$book  = [ 'id' => 'book', 'type' => 'product', 'label' => 'Handbook', 'pricing' => [ 'mode' => 'fixed', 'price' => '29.00', 'quantity' => [ 'enabled' => true, 'min' => 1, 'max' => 5, 'default' => 1 ] ] ];
$tier  = [ 'id' => 'tier', 'type' => 'product', 'label' => 'Support plan', 'pricing' => [ 'mode' => 'options', 'display' => 'radio' ], 'options' => [ [ 'value' => 'basic', 'label' => 'Basic', 'price' => '10' ], [ 'value' => 'pro', 'label' => 'Pro', 'price' => '25' ] ] ];
$sel   = $tier; $sel['id'] = 'plan'; $sel['pricing']['display'] = 'select';
$boxes = $tier; $boxes['id'] = 'addons'; $boxes['pricing']['display'] = 'checkboxes';
$mail  = [ 'id' => 'contact', 'type' => 'email', 'label' => 'Email', 'required' => true ];

// No presets: everything renders as usual.
$plain = FormRenderer::render_preview_form( form( [ $book, $tier, $mail ] ) );
check( 'no preset: radios, quantity default 1, email field', has( $plain, 'type="radio"', 'value="basic"', 'value="pro"', 'name="contact"' ) && preg_match( '/name="book\[qty\]"[^>]*value="1"/', $plain ) === 1 );
check( 'no preset: price labels in the form currency (USD)', has( $plain, '$29.00', '$10.00', '$25.00' ) );

// Quantity preset + hidden quantity.
$q = FormRenderer::render_preview_form( form( [ $book ] ), [ 'product_presets' => [ 'book' => [ 'qty' => 3 ] ] ] );
check( 'qty preset becomes the default value', preg_match( '/name="book\[qty\]"[^>]*value="3"/', $q ) === 1 && has( $q, 'neural-forms-form__product-qty-input' ) );
$q = FormRenderer::render_preview_form( form( [ $book ] ), [ 'product_presets' => [ 'book' => [ 'qty' => 99 ] ] ] );
check( 'qty preset is clamped to the field range', preg_match( '/name="book\[qty\]"[^>]*value="5"/', $q ) === 1 );
$q = FormRenderer::render_preview_form( form( [ $book ] ), [ 'product_presets' => [ 'book' => [ 'qty' => 2, 'allow_qty' => false ] ] ] );
check( 'allow_qty false → hidden quantity input', has( $q, 'type="hidden" name="book[qty]" value="2"' ) && lacks( $q, 'neural-forms-form__product-qty-input' ) );

// Pinned option: static line + hidden choice, no inputs to change it.
$l = FormRenderer::render_preview_form( form( [ $tier, $mail ] ), [ 'product_presets' => [ 'tier' => [ 'choice' => 'pro' ] ] ] );
check( 'radio item pinned → locked line + hidden [choice]', has( $l, 'neural-forms-form__product-locked', '>Pro</span>', '$25.00', 'type="hidden" name="tier[choice]" value="pro" data-price="2500"' ) && lacks( $l, 'type="radio"', 'value="basic"' ) );
check( 'other fields still render next to the locked item', has( $l, 'name="contact"', 'data-neural-forms-line-total="tier"' ) );
$l = FormRenderer::render_preview_form( form( [ $sel ] ), [ 'product_presets' => [ 'plan' => [ 'choice' => 'basic' ] ] ] );
check( 'select item pinned → hidden [choice], no <select>', has( $l, 'type="hidden" name="plan[choice]" value="basic"' ) && lacks( $l, '<select' ) );
$l = FormRenderer::render_preview_form( form( [ $boxes ] ), [ 'product_presets' => [ 'addons' => [ 'choice' => 'pro' ] ] ] );
check( 'checkboxes item pinned → hidden [choice][]', has( $l, 'type="hidden" name="addons[choice][]" value="pro"' ) && lacks( $l, 'type="checkbox"' ) );
$l = FormRenderer::render_preview_form( form( [ $tier ] ), [ 'product_presets' => [ 'tier' => [ 'choice' => 'nope' ] ] ] );
check( 'unknown pinned option → normal rendering (visitor chooses)', has( $l, 'type="radio"', 'value="basic"', 'value="pro"' ) && lacks( $l, 'product-locked' ) );
$l = FormRenderer::render_preview_form( form( [ $book ] ), [ 'product_presets' => [ 'book' => [ 'choice' => 'x' ] ] ] );
check( 'choice on a fixed-price item is ignored', has( $l, 'neural-forms-form__product-fixed', '$29.00' ) && lacks( $l, 'product-locked' ) );

// Currency fix: labels follow the form's pricing currency.
$eur = FormRenderer::render_preview_form( form( [ $book, $tier ], 'EUR' ), [ 'product_presets' => [ 'tier' => [ 'choice' => 'pro' ] ] ] );
check( 'EUR form: fixed price, option prices and the locked line say €', has( $eur, '€29.00', '€25.00' ) && lacks( $eur, '$29.00', '$25.00' ) );

echo "\nProductPresetRenderTest: $passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
