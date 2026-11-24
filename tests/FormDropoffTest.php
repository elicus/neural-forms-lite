<?php
/**
 * Drop-off analytics (1.3.0, report gated by form_dropoff_analytics):
 * known ids, layouts (steps, conversational screens, single page), report
 * maths, beacon parsing, schema / cleanup wiring and the browser tracker.
 * Bootstrap copied from ConversationalFormsTest.php.
 *
 *   php tests/FormDropoffTest.php
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
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
require_once $plugin_root . '/modules/core/Capabilities.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/ConditionEngine.php';
require_once $plugin_root . '/modules/frontend/Conversational.php';
require_once $plugin_root . '/modules/analytics/FormViews.php';
require_once $plugin_root . '/modules/analytics/FormFunnel.php';
require_once $plugin_root . '/modules/rest/Endpoints/FormFunnelEndpoint.php';

use NeuralForms\Analytics\FormFunnel;
use NeuralForms\Rest\Endpoints\FormFunnelEndpoint;

$passed = 0; $failed = 0;
function check( string $label, bool $ok ): void {
	global $passed, $failed;
	if ( $ok ) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; }
}

$single = [ 'id' => 1, 'settings' => [], 'form_schema' => [ 'fields' => [
	[ 'id' => 'welcome', 'type' => 'welcome_screen', 'title' => 'Hi' ],
	[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
	[ 'id' => 'r1', 'type' => 'row', 'columns' => [ [ 'fields' => [ [ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ] ] ], [ 'fields' => [ [ 'id' => 'phone', 'type' => 'phone', 'label' => '' ] ] ] ] ],
	[ 'id' => 'utm', 'type' => 'hidden' ],
	[ 'id' => 'sec', 'type' => 'section', 'title' => 'More' ],
] ] ];
$known = FormFunnel::known_nodes( $single );
check( 'fields in rows are known', isset( $known['email'], $known['phone'], $known['name'] ) );
check( 'label falls back to id', $known['phone'] === 'phone' );
check( 'hidden fields are not known', ! isset( $known['utm'] ) );
check( 'welcome screen is not known', ! isset( $known['welcome'] ) );
check( 'sections are known (conversational statement screens)', isset( $known['sec'] ) );
check( 'is_known accepts a real id', FormFunnel::is_known( $single, 'email' ) );
check( 'is_known rejects junk', ! FormFunnel::is_known( $single, 'nope' ) && ! FormFunnel::is_known( $single, "em'ail" ) && ! FormFunnel::is_known( $single, str_repeat( 'a', 65 ) ) );
check( 'single-page form has no steps', FormFunnel::layout( $single, false ) === [] );

$multi = [ 'id' => 2, 'settings' => [], 'form_schema' => [ 'fields' => [
	[ 'id' => 'st1', 'type' => 'step', 'title' => 'About you', 'fields' => [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ] ],
	[ 'id' => 'st2', 'type' => 'step', 'title' => '', 'conditional_logic' => [ 'enabled' => true, 'field' => 'name', 'operator' => 'is_not_empty', 'value' => '' ], 'fields' => [ [ 'id' => 'budget', 'type' => 'number', 'label' => 'Budget' ] ] ],
	[ 'id' => 'st3', 'type' => 'step', 'title' => 'Done', 'fields' => [ [ 'id' => 'msg', 'type' => 'textarea', 'label' => 'Message' ] ] ],
] ] ];
$lay = FormFunnel::layout( $multi, false );
check( 'three steps in order', array_column( $lay, 'node' ) === [ 'st1', 'st2', 'st3' ] );
check( 'untitled step gets "Step 2"', $lay[1]['label'] === 'Step 2' );
check( 'step logic marks the step conditional', $lay[1]['conditional'] === true && $lay[0]['conditional'] === false );
check( 'step ids are known', FormFunnel::is_known( $multi, 'st2' ) && FormFunnel::is_known( $multi, 'budget' ) );

$cv = FormFunnel::layout( $single, true );
check( 'conversational layout = one screen per field, ids without nfcv_', array_column( $cv, 'node' ) === [ 'name', 'email', 'phone', 'sec' ] );
check( 'conversational screen label = field label', $cv[1]['label'] === 'Email' && $cv[2]['label'] === 'Screen 3' );

// Report maths.
$labels = FormFunnel::known_nodes( $multi );
$r = FormFunnel::build( $lay, $labels, [ 'started' => 100, 'step' => [ 'st1' => 100, 'st2' => 60, 'st3' => 50 ], 'quit_field' => [ 'budget' => 30, 'name' => 10, 'gone' => 5 ] ], 400, 40 );
check( 'funnel = viewed, started, 3 steps, submitted', array_column( $r['funnel'], 'key' ) === [ 'viewed', 'started', 'step:st1', 'step:st2', 'step:st3', 'submitted' ] );
check( 'drop from viewed to started is 75%', $r['funnel'][1]['drop'] === 75.0 );
check( 'drop into st3 is 16.7%', $r['funnel'][4]['drop'] === 16.7 );
check( 'biggest drop skips conditional step, names the step before', $r['biggest']['key'] === 'step:st3' && $r['biggest']['drop'] === 20.0 && $r['biggest']['at'] === 'Done' );
check( 'quit fields sorted, top first', $r['quit_fields'][0]['node'] === 'budget' && $r['quit_fields'][0]['share'] === round( 30 / 45 * 100, 1 ) );
check( 'deleted field shows as Removed field', $r['quit_fields'][2]['label'] === 'Removed field' && $r['quit_fields'][2]['removed'] === true );
check( 'quits total', $r['quits'] === 45 && $r['multi_step'] === true );

$small = FormFunnel::build( [], FormFunnel::known_nodes( $single ), [ 'started' => 5, 'quit_field' => [ 'email' => 3 ] ], 9, 2 );
check( 'single page funnel = viewed, started, submitted', array_column( $small['funnel'], 'key' ) === [ 'viewed', 'started', 'submitted' ] );
check( 'under MIN_SAMPLE: no percentages, no callout', $small['enough_data'] === false && $small['biggest'] === null && $small['funnel'][2]['drop'] === null && $small['quit_fields'][0]['share'] === null );
check( 'under MIN_SAMPLE: counts still shown', $small['quit_fields'][0]['count'] === 3 && $small['multi_step'] === false );

$first = FormFunnel::build( [], [], [ 'started' => 50 ], 60, 20 );
check( 'single page biggest drop is "the first question"', $first['biggest']['at'] === 'the first question' && $first['biggest']['drop'] === 60.0 );
$over = FormFunnel::build( [], [], [ 'started' => 20 ], 10, 30 );
check( 'more submits than starts never goes negative', $over['funnel'][2]['drop'] === 0.0 && $over['biggest'] === null );

check( 'period clamps to 7/30/90', FormFunnel::period( 7 ) === 7 && FormFunnel::period( 90 ) === 90 && FormFunnel::period( 365 ) === 30 && FormFunnel::period( 'x' ) === 30 );

// Beacon parsing.
check( 'started', FormFunnelEndpoint::parse( $multi, 'started', '', '' ) === [ 'kind' => 'started', 'node' => '', 'delta' => 1 ] );
check( 'known step', FormFunnelEndpoint::parse( $multi, 'step', 'st2', '' )['node'] === 'st2' );
check( 'unknown step dropped', FormFunnelEndpoint::parse( $multi, 'step', 'st9', '' ) === null );
check( 'quit counts the field', FormFunnelEndpoint::parse( $multi, 'quit', '', 'budget' ) === [ 'kind' => 'quit_field', 'node' => 'budget', 'delta' => 1 ] );
check( 'unquit takes it back', FormFunnelEndpoint::parse( $multi, 'unquit', '', 'budget' )['delta'] === -1 );
check( 'quit on unknown field dropped', FormFunnelEndpoint::parse( $multi, 'quit', '', 'x' ) === null );
check( 'unknown kind dropped', FormFunnelEndpoint::parse( $multi, 'quit_field', '', 'budget' ) === null );

// Wiring.
$db = file_get_contents( $plugin_root . '/includes/class-database.php' );
check( 'table in create_tables + primary key', strpos( $db, 'self::sql_form_funnel( $charset )' ) !== false && strpos( $db, 'PRIMARY KEY  (form_id,day,kind,node)' ) !== false );
check( 'DB version bumped', strpos( file_get_contents( $plugin_root . '/neural-forms-lite.php' ), "define( 'NEURAL_FORMS_DB_VERSION', '1.3.1' )" ) !== false );
$un = file_get_contents( $plugin_root . '/uninstall.php' );
check( 'uninstall drops table + rate-limit transients', strpos( $un, "'neural_forms_form_funnel'" ) !== false && strpos( $un, '_transient_neural_forms_funnel_rl_' ) !== false );
check( 'hard delete removes counters', strpos( file_get_contents( $plugin_root . '/modules/forms/FormRepository.php' ), 'FormFunnel::delete_for_form( $id )' ) !== false );
$ep = file_get_contents( $plugin_root . '/modules/rest/Endpoints/FormFunnelEndpoint.php' );
check( 'report is gated', strpos( $ep, 'Capabilities::locked_error( Capabilities::FORM_DROPOFF_ANALYTICS )' ) !== false );
check( 'recording is rate-limited', strpos( $ep, 'FormViewsEndpoint::over_cap(' ) !== false );
check( 'routes registered', strpos( file_get_contents( $plugin_root . '/modules/rest/RestAPI.php' ), 'FormFunnelEndpoint()' ) !== false );
check( 'capability in snapshot', array_key_exists( 'form_dropoff_analytics', \NeuralForms\Core\Capabilities::all() ) );
$pro = $plugin_root . '/../neural-forms-pro/modules/capabilities/Unlocker.php';
if ( is_file( $pro ) ) {
	check( 'Pro Unlocker flips it', strpos( file_get_contents( $pro ), "neural_forms_can_form_dropoff_analytics" ) !== false );
}

// Browser tracker.
$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( 'tracker only on tracked forms', (bool) preg_match( "/function initDropoffTracking\\( form \\) \\{\\s*if \\( form.getAttribute\\( 'data-neural-forms-track-view' \\) !== '1' \\) return;/", $js ) );
check( 'synthetic events ignored', strpos( $js, 'e.isTrusted === false' ) !== false );
check( 'conversational prefix stripped', strpos( $js, "id.indexOf( 'nfcv_' ) === 0 ? id.slice( 5 ) : id" ) !== false );
check( 'steps reported from afterShow', strpos( $js, 'if ( form._nfFunnelStep ) form._nfFunnelStep( steps[ index ] );' ) !== false );
check( 'success marks done before redirect', strpos( $js, "case 201:\n\t\t\t\t\t\t// Drop-off" ) !== false && strpos( $js, 'if ( form._nfFunnelDone ) form._nfFunnelDone();' ) !== false );
check( 'quit on pagehide / hidden', strpos( $js, "window.addEventListener( 'pagehide', onLeave )" ) !== false && strpos( $js, "document.visibilityState === 'hidden'" ) !== false );
check( 'coupon box is not a field', strpos( $js, "found === 'nf_coupon' ? '' : found" ) !== false );

echo "\nFormDropoffTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
