<?php
/**
 * Conversational forms (1.3.0, Pro cap conversational_forms): one question
 * per screen built from the Step engine. Bootstrap copied from EntryPreviewTest.php.
 *
 *   php tests/ConversationalFormsTest.php
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

use NeuralForms\Frontend\Conversational;
use NeuralForms\Forms\FormSettingsSanitizer;
use NeuralForms\Forms\FormValidator;

if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return '<p>' . $s . '</p>'; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return strip_tags( (string) $s, '<p><a><strong><em><br>' ); } }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $a, $b = null ) { return substr( $s, $a, $b ); } }

function cv_form( array $fields, array $cv = [ 'enabled' => true ] ): array {
	return [ 'id' => 7, 'name' => 'Chat', 'status' => 'active', 'form_schema' => [ 'fields' => $fields ], 'settings' => [ 'conversational' => $cv ] ];
}
function step_count( string $html ): int {
	return preg_match_all( '/<div class="neural-forms-form__step"/', $html );
}

$flat = [
	[ 'id' => 'utm', 'type' => 'hidden' ],
	[ 'id' => 'intro', 'type' => 'section', 'title' => 'About you' ],
	[ 'id' => 'r1', 'type' => 'row', 'settings' => [ 'columns' => 2 ], 'columns' => [
		[ 'id' => 'c1', 'fields' => [ [ 'id' => 'first', 'type' => 'text', 'label' => 'First name' ] ] ],
		[ 'id' => 'c2', 'fields' => [ [ 'id' => 'last', 'type' => 'text', 'label' => 'Last name' ] ] ],
	] ],
	[ 'id' => 'size', 'type' => 'radio', 'label' => 'Team size', 'options' => [ [ 'label' => 'Small', 'value' => 's' ], [ 'label' => 'Big', 'value' => 'b' ] ] ],
	[ 'id' => 'note', 'type' => 'html', 'content' => 'Almost done' ],
	[ 'id' => 'msg', 'type' => 'textarea', 'label' => 'Message' ],
	[ 'id' => 'ref', 'type' => 'hidden' ],
];

// ── expand() ──
$screens = Conversational::expand( $flat );
$kinds   = array_map( function ( $s ) { return $s['_cv_kind']; }, $screens );
check( 'one screen per question, row split, section + content as statements', $kinds === [ 'statement', 'question', 'question', 'question', 'statement', 'question' ] );
check( 'every screen is a step node with a prefixed id', count( array_filter( $screens, function ( $s ) { return $s['type'] === 'step' && strpos( $s['id'], 'nfcv_' ) === 0; } ) ) === 6 );
check( 'leading hidden field rides on the first screen', $screens[0]['fields'][0]['id'] === 'utm' );
check( 'trailing hidden field goes on the last screen', end( $screens[5]['fields'] )['id'] === 'ref' );
$ids = [];
foreach ( $screens as $s ) foreach ( $s['fields'] as $f ) $ids[] = $f['id'];
check( 'no field lost or duplicated', $ids === [ 'utm', 'intro', 'first', 'last', 'size', 'note', 'msg', 'ref' ] );

$withReview = Conversational::expand( $flat, true );
check( 'review adds an empty final screen', count( $withReview ) === 7 && $withReview[6]['_cv_kind'] === 'review' && $withReview[6]['fields'] === [] );

$logic = [ 'enabled' => true, 'version' => 2, 'action' => 'show', 'match' => 'all', 'rules' => [ [ 'field' => 'size', 'operator' => 'equals', 'value' => 'b' ] ] ];
$stepped = [
	[ 'id' => 's1', 'type' => 'step', 'title' => 'Basics', 'description' => 'Two quick ones', 'fields' => [
		[ 'id' => 'size', 'type' => 'radio', 'label' => 'Team size', 'options' => [ [ 'label' => 'Small', 'value' => 's' ], [ 'label' => 'Big', 'value' => 'b' ] ] ],
		[ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ],
	] ],
	[ 'id' => 's2', 'type' => 'step', 'title' => 'Big teams', 'conditional_logic' => $logic, 'fields' => [
		[ 'id' => 'seats', 'type' => 'number', 'label' => 'Seats' ],
		[ 'id' => 'sso', 'type' => 'text', 'label' => 'SSO provider' ],
	] ],
];
$sx = Conversational::expand( $stepped );
check( 'steps are split into screens', count( $sx ) === 4 );
check( 'step title + description go on its first screen only', $sx[0]['title'] === 'Basics' && $sx[0]['description'] === 'Two quick ones' && $sx[1]['title'] === '' && $sx[2]['title'] === 'Big teams' && $sx[3]['title'] === '' );
check( 'screens inherit their step logic', ( $sx[2]['conditional_logic'] ?? null ) === $logic && ( $sx[3]['conditional_logic'] ?? null ) === $logic && ! isset( $sx[0]['conditional_logic'] ) );

// ── sanitize ──
check( 'all defaults + off → dropped', Conversational::sanitize_settings( [ 'enabled' => false ] ) === [] && Conversational::sanitize_settings( 'x' ) === [] );
$clean = Conversational::sanitize_settings( [ 'enabled' => '1', 'auto_advance' => 0, 'progress' => 'numbers', 'next_label' => '<b>Go</b> on and on and on and on and on and on and on', 'evil' => 1 ] );
check( 'sanitize: booleans, unknown progress → bar, label stripped + capped, unknown keys gone',
	$clean['enabled'] === true && $clean['auto_advance'] === false && $clean['progress'] === 'bar'
	&& strpos( $clean['next_label'], '<' ) === false && strlen( $clean['next_label'] ) <= 40 && ! isset( $clean['evil'] ) );
$whole = FormSettingsSanitizer::sanitize( [ 'conversational' => [ 'enabled' => true, 'progress' => 'none' ] ] );
check( 'FormSettingsSanitizer runs it', ( $whole['conversational']['progress'] ?? '' ) === 'none' && $whole['conversational']['enabled'] === true );
check( 'FormSettingsSanitizer drops an off block', ! isset( FormSettingsSanitizer::sanitize( [ 'conversational' => [ 'enabled' => false ] ] )['conversational'] ) );

// ── locked (Lite only) ──
$GLOBALS['__nf_cv_unlocked'] = false;
$locked = FormRenderer::render_preview_form( cv_form( $flat ) );
check( 'locked: normal single-page layout', lacks( $locked, 'neural-forms-form--conversational', 'data-neural-forms-multistep', 'data-cv-kind' ) );
$err = FormValidator::validate_settings_logic( [ 'conversational' => [ 'enabled' => true ] ], [ 'fields' => $flat ] );
check( 'locked: saving it on → 403 pro_feature_locked', is_wp_error( $err ) && $err->get_error_code() === 'pro_feature_locked' );
check( 'locked: saving it off is fine', FormValidator::validate_settings_logic( [ 'conversational' => [ 'enabled' => false ] ], [ 'fields' => $flat ] ) === true );

// ── unlocked ──
$GLOBALS['__nf_cv_unlocked'] = true;
check( 'unlocked: validator accepts it', FormValidator::validate_settings_logic( [ 'conversational' => [ 'enabled' => true ] ], [ 'fields' => $flat ] ) === true );
$html = FormRenderer::render_preview_form( cv_form( $flat ) );
check( 'renders as a multi-step form with a conversational flag', has( $html, 'neural-forms-form neural-forms-form--conversational', 'data-neural-forms-multistep="1"', 'data-neural-forms-conversational="1"', 'data-cv-auto-advance="1"', 'data-cv-numbers="1"' ) );
check( 'one step per screen', step_count( $html ) === 6 && has( $html, 'data-neural-forms-step-count="6"' ) );
check( 'screen kinds + number slot on questions only', substr_count( $html, 'data-cv-kind="question"' ) === 4 && substr_count( $html, 'data-cv-kind="statement"' ) === 2 && substr_count( $html, 'neural-forms-form__cv-num' ) === 4 );
check( 'progress is a bar with "x of y"', has( $html, 'neural-forms-form__progress--bar', 'data-progress-format="%1$d of %2$d"', '1 of 6' ) && lacks( $html, 'progress--numbers' ) );
check( 'OK button, Enter hint, default fade transition', has( $html, 'data-nav-default-next="OK"', 'data-cv-hint', '<kbd>Enter ↵</kbd>', 'data-step-transition="fade"', 'data-step-transition-dir="up"' ) );
check( 'every field still rendered once', substr_count( $html, 'data-neural-forms-field-id="first"' ) === 1 && substr_count( $html, 'name="utm"' ) === 1 && substr_count( $html, 'name="ref"' ) === 1 );

$custom = FormRenderer::render_preview_form( cv_form( $flat, [ 'enabled' => true, 'auto_advance' => false, 'show_numbers' => false, 'progress' => 'none', 'next_label' => 'Continue', 'review' => true ] ) );
check( 'options: no progress, custom label, flags off', lacks( $custom, 'neural-forms-form__progress' ) && has( $custom, 'data-nav-default-next="Continue"', 'data-cv-auto-advance="0"', 'data-cv-numbers="0"' ) );
check( 'review: final screen + flat review panel', has( $custom, 'data-cv-kind="review"', 'data-neural-forms-review hidden data-review-flat' ) && step_count( $custom ) === 7 );

$block = FormRenderer::render_preview_form( cv_form( $flat ), [ 'next_button_text' => 'Onward', 'progress_indicator_override' => 'numbers', 'step_transition' => 'slide' ] );
check( 'block settings still win (button text, transition); numbers become a bar', has( $block, 'data-nav-default-next="Onward"', 'data-step-transition="slide"', 'progress--bar' ) && lacks( $block, 'progress--numbers' ) );

$stepHtml = FormRenderer::render_preview_form( cv_form( $stepped ) );
check( 'step logic carried onto each screen of that step', substr_count( $stepHtml, 'data-conditional-step=' ) === 2 && has( $stepHtml, '<h3 class="neural-forms-form__step-title">Basics</h3>' ) );

$one = FormRenderer::render_preview_form( cv_form( [ [ 'id' => 'only', 'type' => 'email', 'label' => 'Email' ] ] ) );
check( 'a one-question form stays a normal form', lacks( $one, 'data-neural-forms-multistep', 'neural-forms-form--conversational' ) );

$stored = cv_form( $stepped );
FormRenderer::render_preview_form( $stored );
check( 'stored schema is never touched (render-time only)', $stored['form_schema']['fields'] === $stepped );

// ── JS contract ──
$js = file_get_contents( $plugin_root . '/modules/frontend/assets/js/form-submit.js' );
check( 'JS: conversational flag read in initMultiStep', strpos( $js, "data-neural-forms-conversational" ) !== false && strpos( $js, 'var hasStepLogic = isCv;' ) !== false );
check( 'JS: empty screens skipped without dropping their hidden inputs', strpos( $js, 'function cvScreenEmpty' ) !== false && strpos( $js, "eligible.push( byLogic[ i ] && ! empty );" ) !== false );
check( 'JS: auto-advance ignores untrusted (pre-fill) change events', strpos( $js, 'if ( e.isTrusted ) cvMaybeAutoAdvance( e.target );' ) !== false );
check( 'JS: plain Enter in a textarea is a new line', strpos( $js, "if ( ! ( e.metaKey || e.ctrlKey ) ) return; // plain Enter = new line" ) !== false );
check( 'JS: progress text comes from the translated format', strpos( $js, "progress.getAttribute( 'data-progress-format' )" ) !== false && strpos( $js, "'Step ' + pos" ) === false );
$review_fn = substr( $js, strpos( $js, 'function renderEntryReview' ), 6000 );
check( 'JS: flat review writes text only', strpos( $review_fn, 'data-review-flat' ) !== false && strpos( $review_fn, 'innerHTML' ) === false );

echo "\nConversationalFormsTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
