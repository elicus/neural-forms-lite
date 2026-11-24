<?php
/**
 * Form views + conversion (1.3.0): tracking marker on public renders,
 * conversion maths, table schema, cleanup and the browser counter.
 *
 *   php tests/FormViewsTest.php
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



require_once $plugin_root . '/modules/analytics/FormViews.php';
use NeuralForms\Analytics\FormViews;

$form = [ 'id' => 9, 'name' => 'Views', 'status' => 'active', 'form_schema' => [ 'fields' => [ [ 'id' => 'e', 'type' => 'email', 'label' => 'Email' ] ] ], 'settings' => [] ];
check( 'preview render is never tracked', lacks( FormRenderer::render_preview_form( $form ), 'data-neural-forms-track-view' ) );
check( 'track_views option marks the form', has( FormRenderer::render_preview_form( $form, [ 'track_views' => true ] ), 'data-neural-forms-track-view="1"' ) );

check( 'no views -> no rate', FormViews::rate( 3, 0 ) === null );
check( '1 of 4 -> 25%', FormViews::rate( 1, 4 ) === 25.0 );
check( 'one decimal', FormViews::rate( 1, 3 ) === 33.3 );
check( 'capped at 100', FormViews::rate( 9, 4 ) === 100.0 );

$db = file_get_contents( $plugin_root . '/includes/class-database.php' );
check( 'table: one row per form per day', strpos( $db, 'PRIMARY KEY  (form_id,view_date)' ) !== false && strpos( $db, 'self::sql_form_views( $charset )' ) !== false );
check( 'table stores no visitor data', ! preg_match( '/neural_forms_form_views[\s\S]{0,300}(ip_address|user_agent|page_url)/', $db ) );
$main = file_get_contents( $plugin_root . '/neural-forms-lite.php' );
check( 'DB version is 1.3.0 or later', preg_match( "/define\\( 'NEURAL_FORMS_DB_VERSION', '([0-9.]+)' \\);/", $main, $m ) === 1 && version_compare( $m[1], '1.3.0', '>=' ) );
check( 'uninstall drops the table', strpos( file_get_contents( $plugin_root . '/uninstall.php' ), "'neural_forms_form_views'" ) !== false );
check( 'hard delete removes counts', strpos( file_get_contents( $plugin_root . '/modules/forms/FormRepository.php' ), 'FormViews::delete_for_form( $id )' ) !== false );

$r = file_get_contents( $plugin_root . '/modules/frontend/FormRenderer.php' );
check( 'managers and previews not counted', strpos( $r, "! is_preview() && ! current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS )" ) !== false );
$ep = file_get_contents( $plugin_root . '/modules/rest/Endpoints/FormViewsEndpoint.php' );
check( 'only active forms count', strpos( $ep, "( \$form['status'] ?? '' ) !== 'active'" ) !== false );
check( 'per-IP cap', strpos( $ep, 'self::over_cap(' ) !== false );
check( 'summary is admin-only', strpos( $ep, 'current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS )' ) !== false );

$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( 'JS counts once per session per form', strpos( $js, "'neural_forms_viewed_' + formId" ) !== false );
check( 'JS waits until the form is on screen', strpos( $js, 'en.intersectionRatio >= 0.4' ) !== false );
check( 'JS sends no cookies', strpos( $js, "credentials: 'omit'" ) !== false );

echo "\nFormViewsTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
