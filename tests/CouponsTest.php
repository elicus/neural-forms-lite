<?php
/**
 * Coupon codes (1.3.0, Pro via calculations): normalise, check, engine maths,
 * client config, render. Writes tests/fixtures/coupons.client.json for
 * tests/CouponsTest.js. Bootstrap copied from ConversationalFormsTest.php.
 *
 *   php tests/CouponsTest.php
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
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$args ) { if ( $tag === 'neural_forms_can_conversational_forms' ) { return ! empty( $GLOBALS['__nf_cv_unlocked'] ); } return $tag === 'neural_forms_can_calculations' ? true : $value; } }
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
require_once $plugin_root . '/modules/frontend/Conversational.php';
require_once $plugin_root . '/modules/frontend/FormRenderer.php';
require_once $plugin_root . '/modules/forms/FormSettingsSanitizer.php';

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


use NeuralForms\Pricing\Coupons;
use NeuralForms\Pricing\PriceEngine;

if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return '<p>' . $s . '</p>'; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $a, $b = null ) { return substr( $s, $a, $b ); } }

function cp_form( array $pricing, array $extra_fields = [] ): array {
	return [ 'id' => 5, 'name' => 'Shop', 'status' => 'active', 'settings' => [ 'pricing' => $pricing ], 'form_schema' => [ 'fields' => array_merge( [
		[ 'id' => 'plan', 'type' => 'product', 'label' => 'Plan', 'pricing' => [ 'mode' => 'fixed', 'price' => '100.00', 'quantity' => [ 'enabled' => true, 'min' => 1, 'max' => 10 ] ] ],
	], $extra_fields ) ] ];
}
$coupons = [
	[ 'id' => 'c1', 'code' => 'save10', 'type' => 'percent', 'value' => '10' ],
	[ 'id' => 'c2', 'code' => 'FIVE', 'label' => 'Five off', 'type' => 'fixed', 'value' => '5' ],
	[ 'id' => 'c3', 'code' => 'BIG', 'type' => 'fixed', 'value' => '500' ],
	[ 'id' => 'c4', 'code' => 'CAPPED', 'type' => 'percent', 'value' => '50', 'max' => '20' ],
	[ 'id' => 'c5', 'code' => 'MIN200', 'type' => 'percent', 'value' => '10', 'min' => '200' ],
	[ 'id' => 'c6', 'code' => 'OLD', 'type' => 'percent', 'value' => '10', 'expires' => '2000-01-31' ],
	[ 'id' => 'c7', 'code' => 'OFF', 'type' => 'percent', 'value' => '10', 'enabled' => false ],
	[ 'id' => 'c8', 'code' => 'LIMIT2', 'type' => 'percent', 'value' => '10', 'limit' => 2 ],
];
$rule = [ [ 'id' => 'r1', 'label' => 'Launch', 'type' => 'percent', 'value' => '10' ] ];

// ── normalize ──
$n = Coupons::normalize( array_merge( $coupons, [ [ 'code' => 'save10' ], [ 'code' => 'ab' ], [ 'code' => 'we ird!*code', 'label' => '<b>x</b>' ], 'junk' ] ) );
$codes = array_column( $n, 'code' );
check( 'codes upper-cased, cleaned; duplicate + too short dropped', $codes === [ 'SAVE10', 'FIVE', 'BIG', 'CAPPED', 'MIN200', 'OLD', 'OFF', 'LIMIT2', 'WEIRDCODE' ] );
check( 'label tags stripped; enabled defaults to true; limit int', $n[8]['label'] === 'x' && $n[0]['enabled'] === true && $n[6]['enabled'] === false && $n[7]['limit'] === 2 );
check( 'bad expiry dropped', Coupons::normalize( [ [ 'code' => 'ABC', 'expires' => 'tomorrow' ] ] )[0]['expires'] === '' );
$many = [];
for ( $i = 0; $i < 70; $i++ ) $many[] = [ 'code' => 'CODE' . $i ];
check( 'max 50 coupons', count( Coupons::normalize( $many ) ) === 50 );
check( 'settings carry coupons', count( PriceEngine::settings( cp_form( [ 'coupons' => $coupons ] ) )['coupons'] ) === 8 );

// ── find / check ──
$f = cp_form( [ 'coupons' => $coupons ] );
check( 'find is case-insensitive', ( Coupons::find( $f, ' Save10 ' )['id'] ?? '' ) === 'c1' );
check( 'switched-off code is not found', Coupons::find( $f, 'OFF' ) === null );
$e = Coupons::check( $f, 'NOPE', function () { return 0; } );
check( 'unknown → coupon_invalid 422', is_wp_error( $e ) && $e->get_error_code() === 'coupon_invalid' && $e->get_error_data()['status'] === 422 );
$e = Coupons::check( $f, 'OFF', function () { return 0; } );
check( 'switched off looks the same as unknown', is_wp_error( $e ) && $e->get_error_code() === 'coupon_invalid' );
$e = Coupons::check( $f, 'old', function () { return 0; } );
check( 'expired → coupon_expired', is_wp_error( $e ) && $e->get_error_code() === 'coupon_expired' );
check( 'valid through the expiry day', ! Coupons::is_expired( [ 'expires' => '2026-11-24' ], '2026-11-24' ) && Coupons::is_expired( [ 'expires' => '2026-11-24' ], '2026-11-25' ) );
$e = Coupons::check( $f, 'LIMIT2', function () { return 2; } );
check( 'limit reached → coupon_used_up', is_wp_error( $e ) && $e->get_error_code() === 'coupon_used_up' );
check( 'under the limit → coupon', ( Coupons::check( $f, 'LIMIT2', function () { return 1; } )['id'] ?? '' ) === 'c8' );
check( 'no limit never counts uses', ( Coupons::check( $f, 'SAVE10', function () { throw new RuntimeException( 'counted' ); } )['id'] ?? '' ) === 'c1' );

// ── evaluate ──
$data = [ 'plan' => [ 'qty' => '1' ] ];
$cp   = function ( string $code, array $pricing ) { return Coupons::find( cp_form( $pricing ), $code ); };
$base = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), $data );
check( 'no coupon: unchanged snapshot (no coupon keys)', $base['total'] === 10000 && $base['discount'] === null && ! isset( $base['coupon'] ) && ! isset( $base['coupon_error'] ) );

$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons, 'discounts' => $rule ] ), $data, [], $cp( 'SAVE10', [ 'coupons' => $coupons ] ) );
check( 'rule 10% then coupon 10% of the rest: 100 → 90 → 81', $r['total'] === 8100 && $r['discount']['amount'] === 1900 );
check( 'combined discount: label Discounts, fixed, value = amount, two parts', $r['discount']['label'] === 'Discounts' && $r['discount']['type'] === 'fixed' && $r['discount']['value'] === '19.00' && count( $r['discount']['parts'] ) === 2 && $r['discount']['parts'][0]['kind'] === 'rule' && $r['discount']['parts'][1]['amount'] === 900 );
check( 'snapshot.coupon for usage counting', $r['coupon'] === [ 'id' => 'c1', 'code' => 'SAVE10', 'amount' => 900 ] );
check( 'usage LIKE pattern matches the stored JSON', strpos( (string) wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), '"coupon":{"id":"c1"' ) !== false );

$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), $data, [], $cp( 'FIVE', [ 'coupons' => $coupons ] ) );
check( 'coupon alone: its own label, type and value', $r['total'] === 9500 && $r['discount']['label'] === 'Five off' && $r['discount']['type'] === 'fixed' && $r['discount']['value'] === '5' );
$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), $data, [], $cp( 'SAVE10', [ 'coupons' => $coupons ] ) );
check( 'default label "Coupon CODE"', $r['discount']['label'] === 'Coupon SAVE10' );
$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), $data, [], $cp( 'BIG', [ 'coupons' => $coupons ] ) );
check( 'never below zero', $r['total'] === 0 && $r['discount']['amount'] === 10000 );
$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), [ 'plan' => [ 'qty' => '3' ] ], [], $cp( 'CAPPED', [ 'coupons' => $coupons ] ) );
check( 'max caps a percent coupon (50% of 300 capped at 20)', $r['total'] === 28000 );
$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), $data, [], $cp( 'MIN200', [ 'coupons' => $coupons ] ) );
check( 'minimum not met → coupon_error, nothing taken off', $r['total'] === 10000 && $r['coupon_error'] === [ 'code' => 'coupon_min', 'min' => 20000 ] && ! isset( $r['coupon'] ) );
$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons ] ), [ 'plan' => [ 'qty' => '2' ] ], [], $cp( 'MIN200', [ 'coupons' => $coupons ] ) );
check( 'minimum met (200) → applies', $r['total'] === 18000 );
$r = PriceEngine::evaluate( cp_form( [ 'coupons' => $coupons, 'deposit' => [ 'type' => 'percent', 'value' => '50' ] ] ), $data, [], $cp( 'SAVE10', [ 'coupons' => $coupons ] ) );
check( 'deposit is taken from the discounted total', $r['due_now'] === 4500 && $r['remaining'] === 4500 );

// ── client config ──
$cfg = PriceEngine::client_config( cp_form( [ 'coupons' => $coupons ] ) );
check( 'client config: only a coupons flag, never the codes', $cfg['coupons'] === true && strpos( (string) wp_json_encode( $cfg ), 'SAVE10' ) === false );
check( 'no switched-on coupon → flag off', PriceEngine::client_config( cp_form( [ 'coupons' => [ $coupons[6] ] ] ) )['coupons'] === false );
$shape = Coupons::client_shape( Coupons::find( $f, 'CAPPED' ), 'USD' );
check( 'client shape: basis points + minor units', $shape === [ 'id' => 'c4', 'code' => 'CAPPED', 'label' => 'Coupon CAPPED', 'type' => 'percent', 'bp' => 5000, 'amount' => 0, 'max' => 2000, 'min' => null ] );

// ── sanitizer ──
$clean = \NeuralForms\Forms\FormSettingsSanitizer::sanitize( [ 'pricing' => [ 'coupons' => [ [ 'code' => 'abc' ] ] ] ] );
check( 'a coupon alone keeps the pricing block', ( $clean['pricing']['coupons'][0]['code'] ?? '' ) === 'ABC' );

// ── render ──
$html = FormRenderer::render_preview_form( cp_form( [ 'coupons' => $coupons ] ) );
check( 'coupon box rendered with an unnamed text box + hidden nf_coupon', has( $html, 'data-neural-forms-coupon', 'data-neural-forms-field-id="nf_coupon"', 'name="nf_coupon" value=""', 'data-coupon-input', 'data-coupon-apply', 'neural-forms-form__totals-row--coupon' ) && preg_match( '/<input[^>]*data-coupon-input[^>]*name=/', $html ) === 0 );
check( 'no code ever in the page', lacks( $html, 'SAVE10', 'LIMIT2' ) );
check( 'no switched-on coupon → no box', lacks( FormRenderer::render_preview_form( cp_form( [ 'coupons' => [ $coupons[6] ] ] ) ), 'data-neural-forms-coupon' ) );

// ── wiring (source) ──
$submit = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
check( 'submit re-checks the code and passes it to the engine', strpos( $submit, "Coupons::check( \$form, \$_coupon_code )" ) !== false && strpos( $submit, 'PriceEngine::evaluate( $form, $sanitized_data, $_hidden_ids, $_coupon )' ) !== false && strpos( $submit, "'nf_coupon' => array(" ) !== false );
$ep = file_get_contents( $plugin_root . '/modules/rest/Endpoints/CouponsEndpoint.php' );
check( 'public check is rate limited and active-form only', strpos( $ep, 'Coupons::over_try_cap(' ) !== false && strpos( $ep, "'active'" ) !== false );

// ── fixtures for the JS mirror (tests/CouponsTest.js) ──
$cases = [];
foreach ( [ [ 'SAVE10', 1, true ], [ 'FIVE', 1, false ], [ 'BIG', 1, false ], [ 'CAPPED', 3, false ], [ 'MIN200', 1, false ], [ 'MIN200', 2, true ], [ 'SAVE10', 7, true ] ] as [ $code, $qty, $withRule ] ) {
	$pricing = [ 'coupons' => $coupons, 'deposit' => [ 'type' => 'percent', 'value' => '30' ] ];
	if ( $withRule ) $pricing['discounts'] = $rule;
	$form = cp_form( $pricing );
	$c    = Coupons::find( $form, $code );
	$r    = PriceEngine::evaluate( $form, [ 'plan' => [ 'qty' => (string) $qty ] ], [], $c );
	$cfg  = PriceEngine::client_config( $form );
	$cfg['coupon'] = Coupons::client_shape( $c, 'USD' );
	$cases[] = [ 'name' => "$code x$qty" . ( $withRule ? ' + rule' : '' ), 'cfg' => $cfg, 'qty' => (string) $qty,
		'expected' => [ 'subtotal' => $r['subtotal'], 'discount' => (int) ( $r['discount']['amount'] ?? 0 ), 'coupon' => (int) ( $r['coupon']['amount'] ?? 0 ), 'coupon_error' => isset( $r['coupon_error'] ), 'total' => $r['total'], 'due_now' => $r['due_now'] ] ];
}
file_put_contents( __DIR__ . '/fixtures/coupons.client.json', json_encode( $cases, JSON_PRETTY_PRINT ) . "\n" );
check( 'JS fixtures written', count( $cases ) === 7 );

echo "\nCouponsTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
