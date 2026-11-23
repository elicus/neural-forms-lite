<?php
/**
 * Field help text rendering (builder "Help text" → frontend).
 *
 * Run from the plugin root:
 *
 *   php tests/FieldHelpTextTest.php
 *
 * Coverage:
 *   1. text field with help_text → <p class="neural-forms-form__field-help" id="…-help"> after the input
 *   2. input's aria-describedby lists the help id before the error id
 *   3. radio (fieldset) with help_text → help rendered, fieldset describedby updated
 *   4. file field keeps its own hint AND gets the help text; describedby carries hint + help + error
 *   5. no help_text → no help element, describedby unchanged
 *   6. hidden field → no help element even if set
 *   7. help text is escaped
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( ...$a ) {} } }
if ( ! function_exists( 'is_wp_error' ) )    { function is_wp_error( $t ): bool { return $t instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$a ) { return $value; } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $l = 12, $s = true, $e = false ) { return substr( bin2hex( random_bytes( 8 ) ), 0, $l ); } }
if ( ! function_exists( '__' ) )             { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) )             { function _n( $a, $b, $n, $d = null ) { return $n === 1 ? $a : $b; } }
if ( ! function_exists( 'esc_html' ) )       { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )       { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) )        { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) )    { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) )   { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) )     { function esc_html__( $s, $d = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr__' ) )     { function esc_attr__( $s, $d = null ) { return esc_attr( $s ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return false; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $h ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $h, $s = '', $d = [], $v = false, $f = false ) {} }
if ( ! function_exists( 'is_email' ) )       { function is_email( $s ) { return is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } }
if ( ! function_exists( 'wp_max_upload_size' ) ) { function wp_max_upload_size() { return 0; } }
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) { define( 'NEURAL_FORMS_VERSION', '1.1.0-test' ); }
$GLOBALS['__nf_options'] = [];
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = [] ) { return $GLOBALS['__nf_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__nf_options'][ $k ] = $v; return true; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/forms/SubmissionValidator.php';
require_once $plugin_root . '/modules/core/Config.php';
require_once $plugin_root . '/modules/frontend/FormRenderer.php';

use NeuralForms\Frontend\FormRenderer;

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	if ( $ok ) $passed++; else $failed++;
	$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
	echo $indicator . '  ' . $label . "\n";
	if ( ! $ok ) {
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}
function render_one( array $field ): string {
	$html = FormRenderer::render_preview_form( [ 'id' => 0, 'name' => 'T', 'status' => 'active', 'form_schema' => [ 'fields' => [ $field ] ], 'settings' => [] ], [ 'show_labels' => true ] );
	preg_match( '/<div class="neural-forms-form__field[^"]*" data-neural-forms-field-id="' . preg_quote( $field['id'], '/' ) . '".*?<p class="neural-forms-form__field-error"/s', $html, $m );
	return $m[0] ?? $html;
}

// 1-2. text
$w = render_one( [ 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'help_text' => 'As on your ID.' ] );
check( '1a. help paragraph rendered',               strpos( $w, '<p class="neural-forms-form__field-help" id="neural-forms-0-name-help">As on your ID.</p>' ) !== false, true, $passed, $failed );
check( '1b. help comes after the input',            strpos( $w, '<input' ) < strpos( $w, 'neural-forms-form__field-help' ), true, $passed, $failed );
check( '2. aria-describedby = help then error',     strpos( $w, 'aria-describedby="neural-forms-0-name-help neural-forms-0-name-error"' ) !== false, true, $passed, $failed );

// 3. radio fieldset
$w = render_one( [ 'id' => 'size', 'type' => 'radio', 'label' => 'Size', 'help_text' => 'Pick one.', 'options' => [ [ 'label' => 'S', 'value' => 's' ] ] ] );
check( '3a. radio help rendered',                   strpos( $w, 'neural-forms-form__field-help' ) !== false, true, $passed, $failed );
check( '3b. fieldset describedby updated',          strpos( $w, 'aria-describedby="neural-forms-0-size-help neural-forms-0-size-error"' ) !== false, true, $passed, $failed );

// 4. file keeps its own hint
$w = render_one( [ 'id' => 'cv', 'type' => 'file', 'label' => 'CV', 'help_text' => 'PDF preferred.' ] );
check( '4a. file hint still present',               strpos( $w, 'neural-forms-form__file-hint' ) !== false, true, $passed, $failed );
check( '4b. file help present',                     strpos( $w, '>PDF preferred.</p>' ) !== false, true, $passed, $failed );
check( '4c. describedby = hint, help, error',       strpos( $w, 'aria-describedby="neural-forms-0-cv-hint neural-forms-0-cv-help neural-forms-0-cv-error"' ) !== false, true, $passed, $failed );

// 5. none
$w = render_one( [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] );
check( '5a. no help element without help_text',     strpos( $w, 'field-help' ) === false, true, $passed, $failed );
check( '5b. describedby untouched',                 strpos( $w, 'aria-describedby="neural-forms-0-name-error"' ) !== false, true, $passed, $failed );
$w = render_one( [ 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'help_text' => '   ' ] );
check( '5c. whitespace-only help ignored',          strpos( $w, 'field-help' ) === false, true, $passed, $failed );

// 6. hidden
$html = FormRenderer::render_preview_form( [ 'id' => 0, 'name' => 'T', 'status' => 'active', 'form_schema' => [ 'fields' => [ [ 'id' => 'h', 'type' => 'hidden', 'label' => 'H', 'help_text' => 'secret' ] ] ], 'settings' => [] ] );
check( '6. hidden field renders no help',           strpos( $html, 'field-help' ) === false, true, $passed, $failed );

// 7. escaping
$w = render_one( [ 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'help_text' => '<b>bold</b> & "q"' ] );
check( '7. help text escaped',                      strpos( $w, '&lt;b&gt;bold&lt;/b&gt; &amp; &quot;q&quot;' ) !== false, true, $passed, $failed );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
