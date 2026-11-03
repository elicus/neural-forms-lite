<?php
/**
 * BlockHandler::build_scoped_css() — gradient tabs that used to be ignored.
 *
 * Run from the plugin root:  php tests/BlockGradientCssTest.php
 *
 * The Submit Button panel (BtnControls pfx "btn") and the Matrix panel offer
 * a Gradient tab next to the solid colour; before 2026-11-04 the builder
 * whitelisted btnBgGradient / btnHoverBgGradient and the four matrix
 * gradients but never emitted them. Found by driving every block attribute
 * through the editor renderer on the Pro Payment Form block (same CSS
 * pipeline as Lite's four blocks).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
if ( ! defined( 'NEURAL_FORMS_PATH' ) ) define( 'NEURAL_FORMS_PATH', dirname( __DIR__ ) . '/' );
if ( ! defined( 'NEURAL_FORMS_URL' ) ) define( 'NEURAL_FORMS_URL', 'http://example.test/' );
if ( ! defined( 'NEURAL_FORMS_VERSION' ) ) define( 'NEURAL_FORMS_VERSION', 'test' );
// phpcs:disable -- CLI-only test runner with WordPress stubs.
function sanitize_text_field( $s ) { $s = strip_tags( (string) $s ); return trim( preg_replace( '/[\r\n\t ]+/', ' ', $s ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? $c : ''; }
function esc_url( $u ) { return (string) $u; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function apply_filters( $hook, $value ) { return $value; }
function add_action() {} function add_filter() {}
function current_user_can() { return false; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_kses_post( $s ) { return $s; }

require_once NEURAL_FORMS_PATH . 'modules/core/Capabilities.php';
require_once NEURAL_FORMS_PATH . 'modules/frontend/BlockHandler.php';

$passed = 0; $failed = 0;
function check( string $label, bool $ok ): void {
	global $passed, $failed;
	if ( $ok ) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; }
}
$css = fn( array $a ) => \NeuralForms\Frontend\BlockHandler::build_scoped_css( 'abc12345', $a );

$g1 = 'linear-gradient(90deg,#112233 0%,#445566 100%)';
$g2 = 'linear-gradient(90deg,#121314 0%,#151617 100%)';
$c  = $css( [ 'btnBgGradient' => $g1, 'btnHoverBgGradient' => $g2 ] );
check( 'submit button gradient emitted on the button', (bool) preg_match( '/__submit\{[^}]*background-image:' . preg_quote( $g1, '/' ) . '/', $c ) );
check( 'submit button hover gradient emitted', (bool) preg_match( '/__submit:hover:not\(:disabled\)\{[^}]*background-image:' . preg_quote( $g2, '/' ) . '/', $c ) );
$c = $css( [ 'btnBgGradient' => $g1, 'btnHoverBg' => '#a1b2c3' ] );
check( 'solid hover colour over a normal-state gradient clears the gradient', (bool) preg_match( '/:hover:not\(:disabled\)\{[^}]*background-color:#a1b2c3;background-image:none/', $c ) );
$c = $css( [ 'btnBgColor' => '#aabbcc' ] );
check( 'solid button colour still goes through the CSS variable', strpos( $c, '--neural-forms-btn-bg:#aabbcc' ) !== false && strpos( $c, 'background-image' ) === false );
$c = $css( [ 'btnBgGradient' => 'url(x);}body{color:red' ] );
check( 'gradient value is guarded (no rule breakout)', strpos( $c, 'body{color:red' ) === false );

$m = [
	'matrixCellBgGradient'      => 'linear-gradient(90deg,#010203 0%,#040506 100%)',
	'matrixCellHoverBgGradient' => 'linear-gradient(90deg,#111213 0%,#141516 100%)',
	'matrixRowBgGradient'       => 'linear-gradient(90deg,#070809 0%,#0a0b0c 100%)',
	'matrixAltRowBgGradient'    => 'linear-gradient(90deg,#0b0c0d 0%,#0e0f10 100%)',
];
$c = $css( $m );
check( 'matrix cell gradient', (bool) preg_match( '/__cell\{[^}]*background-image:linear-gradient\(90deg,#010203/', $c ) );
check( 'matrix cell hover gradient', (bool) preg_match( '/__cell:hover\{background-image:linear-gradient\(90deg,#111213/', $c ) );
check( 'matrix row gradient', (bool) preg_match( '/__row \.neural-forms-matrix__cell\{background-image:linear-gradient\(90deg,#070809/', $c ) );
check( 'matrix alternate row gradient', (bool) preg_match( '/nth-child\(even\) \.neural-forms-matrix__cell\{background-image:linear-gradient\(90deg,#0b0c0d/', $c ) );
$c = $css( [ 'matrixCellBg' => '#0d0e0f', 'matrixRowBg' => '#070809' ] );
check( 'matrix solid colours unchanged', strpos( $c, '__cell{background:#0d0e0f' ) !== false && strpos( $c, '__row .neural-forms-matrix__cell{background:#070809' ) !== false );

// render_options(): the switches Lite's blocks hand to FormRenderer, now
// public so Pro's Payment Form card gets the same ones (button icon, repeater
// add-button text, placeholders, welcome screen).
$o = \NeuralForms\Frontend\BlockHandler::render_options( [ 'showLabels' => false, 'btnIconEnabled' => true, 'btnIcon' => 'arrow-right', 'btnIconPosition' => 'before', 'repeaterAddBtnText' => 'More', 'showPlaceholders' => false ] );
check( 'render_options: labels / placeholders switches', $o['show_labels'] === false && $o['show_placeholders'] === false );
check( 'render_options: submit button icon', ( $o['btn_icon'] ?? '' ) === 'arrow-right' && ( $o['btn_icon_position'] ?? '' ) === 'before' );
check( 'render_options: repeater add-button text', ( $o['repeater_add_btn_text'] ?? '' ) === 'More' );
check( 'render_options: no icon without the toggle', ! isset( \NeuralForms\Frontend\BlockHandler::render_options( [ 'btnIcon' => 'send' ] )['btn_icon'] ) );
// Radio inputs: scoped border / colour rules carry appearance:none so a theme's
// input[type="radio"] reset cannot turn them back into native controls.
$c = $css( [ 'radioOptionBorderColor' => '#ec4899', 'radioOptionBorderWidth' => '2px' ] );
check( 'radio scoped rule forces appearance:none', (bool) preg_match( '/__radio\{appearance:none;-webkit-appearance:none;box-sizing:border-box;border:2px solid #ec4899\}/', $c ) );

echo "\nBlockGradientCssTest: $passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
