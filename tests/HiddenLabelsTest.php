<?php
/**
 * Hidden labels: accessible name + visible questions.
 *
 * Run from the plugin root:
 *
 *   php tests/HiddenLabelsTest.php
 *
 * Coverage (show_labels=false unless stated):
 *   1. text field → screen-reader-text <label for> carrying the label; no visible label
 *   2. text field, show_labels=true → visible .neural-forms-form__label, no sr label
 *   3. placeholder fallback still label-as-placeholder when hidden
 *   4. radio / checkboxes / rating → legend VISIBLE (__label--block) even when hidden
 *   5. file → visible label even when hidden
 *   6. field with empty label → no label element at all
 *   7. form-submit.js fieldLabelText() queries the sr label + legend
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
function form( array $fields ): array {
	return [ 'id' => 0, 'name' => 'Event registration', 'status' => 'active', 'form_schema' => [ 'fields' => $fields ], 'settings' => [] ];
}
function render( array $fields, bool $show ): string {
	return FormRenderer::render_preview_form( form( $fields ), [ 'show_labels' => $show, 'show_placeholders' => true ] );
}
function wrapper( string $html, string $field_id ): string {
	preg_match( '/<div class="neural-forms-form__field[^"]*" data-neural-forms-field-id="' . preg_quote( $field_id, '/' ) . '".*?<\/div>/s', $html, $m );
	return $m[0] ?? '';
}

$text  = [ 'id' => 'full_name', 'type' => 'text', 'label' => 'Full name', 'placeholder' => 'Jane Cooper', 'required' => true ];
$radio = [ 'id' => 'ticket', 'type' => 'radio', 'label' => 'Ticket type', 'required' => true, 'options' => [ [ 'label' => 'Standard', 'value' => 'std' ], [ 'label' => 'VIP', 'value' => 'vip' ] ] ];
$cbs   = [ 'id' => 'meals', 'type' => 'checkboxes', 'label' => 'Meals', 'options' => [ [ 'label' => 'Veg', 'value' => 'veg' ] ] ];
$rate  = [ 'id' => 'rate', 'type' => 'rating', 'label' => 'Rate us' ];
$file  = [ 'id' => 'cv', 'type' => 'file', 'label' => 'Upload ticket' ];
$nolbl = [ 'id' => 'blank', 'type' => 'text', 'label' => '', 'placeholder' => 'Anything' ];

// 1. text, hidden labels
$w = wrapper( render( [ $text ], false ), 'full_name' );
check( '1a. hidden: screen-reader-text label present with the real label', (bool) preg_match( '/<label class="screen-reader-text" for="[^"]+">Full name<\/label>/', $w ), true, $passed, $failed );
check( '1b. hidden: no visible block label', strpos( $w, 'neural-forms-form__label--block' ) === false, true, $passed, $failed );
check( '1c. hidden: sr label is paired with the input id', (bool) preg_match( '/<label class="screen-reader-text" for="(neural-forms-0-full_name)">.*?<input[^>]*id="\1"/s', $w ), true, $passed, $failed );
// 3. placeholder fallback
check( '3a. hidden: user placeholder kept', strpos( $w, 'placeholder="Jane Cooper"' ) !== false, true, $passed, $failed );
$w2 = wrapper( render( [ array_merge( $text, [ 'placeholder' => '' ] ) ], false ), 'full_name' );
check( '3b. hidden + no placeholder: label-as-placeholder still applied', strpos( $w2, 'placeholder="Full name"' ) !== false, true, $passed, $failed );

// 2. text, visible labels
$w = wrapper( render( [ $text ], true ), 'full_name' );
check( '2a. visible: block label rendered', (bool) preg_match( '/<label class="neural-forms-form__label neural-forms-form__label--block" for="[^"]+">Full name/', $w ), true, $passed, $failed );
check( '2b. visible: no screen-reader-text label', strpos( $w, 'screen-reader-text' ) === false, true, $passed, $failed );

// 4. groups keep their question visible when labels are hidden
foreach ( [ 'ticket' => [ $radio, 'Ticket type' ], 'meals' => [ $cbs, 'Meals' ], 'rate' => [ $rate, 'Rate us' ] ] as $id => [ $f, $lbl ] ) {
	$w = wrapper( render( [ $f ], false ), $id );
	check( "4. {$id}: legend visible when labels hidden", (bool) preg_match( '/<legend class="neural-forms-form__label neural-forms-form__label--block">' . preg_quote( $lbl, '/' ) . '/', $w ), true, $passed, $failed );
	check( "4b. {$id}: legend not screen-reader-only", strpos( $w, '<legend class="screen-reader-text">' ) === false, true, $passed, $failed );
}
$w = wrapper( render( [ $radio ], false ), 'ticket' );
check( '4c. required mark shown on the visible radio legend', strpos( $w, 'neural-forms-form__required' ) !== false, true, $passed, $failed );

// 5. file field label visible when hidden
$w = wrapper( render( [ $file ], false ), 'cv' );
check( '5. file: visible label even with labels hidden', (bool) preg_match( '/<label class="neural-forms-form__label neural-forms-form__label--block" for="[^"]+">Upload ticket/', $w ), true, $passed, $failed );

// 6. empty label → nothing
$w = wrapper( render( [ $nolbl ], false ), 'blank' );
check( '6. empty label: no <label> element at all', strpos( $w, '<label' ) === false, true, $passed, $failed );

// 7. JS reads the sr label / legend
$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( '7. fieldLabelText() selects sr label + legend', strpos( $js, "querySelector( '.neural-forms-form__label, label.screen-reader-text, legend' )" ) !== false, true, $passed, $failed );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
