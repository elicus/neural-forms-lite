<?php
/**
 * A5 (1.2.0): plugin side of business-aware drafts.
 *
 *   - NeuralFormsCloudProvider::strip_ai_excluded() drops ai_exclude fields
 *   - attach_ai_behaviour() sends ai_behaviour only with the Pro capability
 *   - normalize_business_extras() keeps knowledge_refs / missing_info /
 *     reply_language / knowledge_revision and drops junk
 *   - FormSettingsSanitizer::sanitize_ai_behaviour()
 *   - AIResponseRepository::build_meta() / decode_row()
 *
 *   php tests/BusinessContextPluginTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	$GLOBALS['nf_test_filters'] = array();
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['nf_test_filters'] ) ? $GLOBALS['nf_test_filters'][ $hook ] : $value; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
	if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return preg_match( '#^https?://#i', (string) $u ) ? (string) $u : ''; } }
	if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return strip_tags( (string) $s, '<p><a><strong><em><br>' ); } }
	if ( ! function_exists( 'site_url' ) ) { function site_url() { return 'https://example.test'; } }
	if ( ! class_exists( 'WP_REST_Request' ) ) { class WP_REST_Request {} }
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
		}
	}
	$plugin_root = dirname( __DIR__ );
	foreach ( array( '/modules/core/Capabilities.php', '/modules/forms/ConditionEngine.php', '/modules/forms/SchemaHelper.php', '/modules/forms/FormSettingsSanitizer.php', '/modules/ai/Contracts/AIAnalysisProviderInterface.php', '/modules/ai/AIRepository.php', '/modules/ai/Exceptions/CloudPlanException.php', '/modules/ai/Exceptions/CloudSafetyException.php', '/modules/ai/Providers/NeuralFormsCloudProvider.php', '/modules/storage/AIResponseRepository.php' ) as $f ) {
		if ( file_exists( $plugin_root . $f ) ) require_once $plugin_root . $f;
	}
}

namespace NeuralForms\Tests {

	use ReflectionMethod;
	use NeuralForms\AI\Providers\NeuralFormsCloudProvider;
	use NeuralForms\Forms\FormSettingsSanitizer;
	use NeuralForms\Storage\AIResponseRepository;

	$passed = 0;
	$failed = 0;
	function check( string $label, $actual, $expected, &$passed, &$failed ): void {
		if ( $actual === $expected ) {
			echo "\033[32mPASS\033[0m  $label\n";
			$passed++;
		} else {
			echo "\033[31mFAIL\033[0m  $label\n";
			echo "       expected: " . var_export( $expected, true ) . "\n";
			echo "       actual:   " . var_export( $actual, true ) . "\n";
			$failed++;
		}
	}

	$form = array( 'id' => 3, 'name' => 'Enquiry', 'form_schema' => array( 'fields' => array(
		array( 'id' => 'name', 'type' => 'text' ),
		array( 'id' => 'ssn', 'type' => 'text', 'ai_exclude' => true ),
		array( 'type' => 'step', 'id' => 's1', 'fields' => array( array( 'id' => 'card', 'type' => 'text', 'ai_exclude' => true ), array( 'id' => 'msg', 'type' => 'textarea' ) ) ),
	) ), 'settings' => array( 'ai_behaviour' => array( 'tone' => 'friendly', 'language_mode' => 'fixed', 'language' => 'de', 'knowledge_ids' => array( 'hours', 'Refund Policy', '' ) ) ) );
	$data = array( 'name' => 'A', 'ssn' => '123', 'card' => '4111', 'msg' => 'hello' );

	// ── strip_ai_excluded ─────────────────────────────────────────────
	check( 'ai_exclude: excluded fields removed (top level + inside step)', NeuralFormsCloudProvider::strip_ai_excluded( $data, $form ), array( 'name' => 'A', 'msg' => 'hello' ), $passed, $failed );
	check( 'ai_exclude: no schema → untouched', NeuralFormsCloudProvider::strip_ai_excluded( $data, array() ), $data, $passed, $failed );

	// format_entry goes through strip_ai_excluded
	$provider = new NeuralFormsCloudProvider( 'key' );
	$fe = new ReflectionMethod( NeuralFormsCloudProvider::class, 'format_entry' );
	$fe->setAccessible( true );
	$formatted = $fe->invoke( $provider, array( 'id' => 9, 'data' => $data, 'created_at' => 'x' ), $form );
	check( 'format_entry: excluded fields never leave WordPress', array_keys( $formatted['fields'] ), array( 'name', 'msg' ), $passed, $failed );

	// ── attach_ai_behaviour ───────────────────────────────────────────
	$ab = new ReflectionMethod( NeuralFormsCloudProvider::class, 'attach_ai_behaviour' );
	$ab->setAccessible( true );
	$payload = array( 'x' => 1 );
	$ab->invokeArgs( $provider, array( &$payload, $form ) );
	check( 'ai_behaviour: NOT sent without the Pro capability', isset( $payload['ai_behaviour'] ), false, $passed, $failed );
	$GLOBALS['nf_test_filters']['neural_forms_can_ai_business_context'] = true;
	$payload = array( 'x' => 1 );
	$ab->invokeArgs( $provider, array( &$payload, $form ) );
	check( 'ai_behaviour: sent with the capability, sanitised', $payload['ai_behaviour'], array( 'tone' => 'friendly', 'language_mode' => 'fixed', 'language' => 'de', 'knowledge_ids' => array( 'hours', 'refundpolicy' ) ), $passed, $failed );
	$payload = array();
	$ab->invokeArgs( $provider, array( &$payload, array( 'settings' => array( 'ai_behaviour' => array( 'language_mode' => 'detect' ) ) ) ) );
	check( 'ai_behaviour: default-only settings send nothing', isset( $payload['ai_behaviour'] ), false, $passed, $failed );
	unset( $GLOBALS['nf_test_filters']['neural_forms_can_ai_business_context'] );

	// ── normalize_business_extras ─────────────────────────────────────
	$nb = new ReflectionMethod( NeuralFormsCloudProvider::class, 'normalize_business_extras' );
	$nb->setAccessible( true );
	$extras = $nb->invoke( $provider, array( 'knowledge_refs' => array( 'hours', '<b>x</b>', '' ), 'missing_info' => array( 'delivery time' ), 'reply_language' => 'de', 'knowledge_revision' => '4' ) );
	check( 'extras: refs sanitised, empties dropped', $extras['knowledge_refs'], array( 'hours', 'x' ), $passed, $failed );
	check( 'extras: missing_info kept', $extras['missing_info'], array( 'delivery time' ), $passed, $failed );
	check( 'extras: language + revision', array( $extras['reply_language'], $extras['knowledge_revision'] ), array( 'de', 4 ), $passed, $failed );
	check( 'extras: bad language dropped', isset( $nb->invoke( $provider, array( 'reply_language' => 'DROP TABLE' ) )['reply_language'] ), false, $passed, $failed );
	check( 'extras: absent → empty', $nb->invoke( $provider, array( 'reply_draft' => 'x' ) ), array(), $passed, $failed );

	// normalize_analysis carries extras through
	$na = new ReflectionMethod( NeuralFormsCloudProvider::class, 'normalize_analysis' );
	$na->setAccessible( true );
	$norm = $na->invoke( $provider, array( 'summary' => 's', 'category' => 'general', 'priority' => 'medium', 'reply_draft' => 'r', 'score' => 50, 'knowledge_refs' => array( 'hours' ), 'reply_language' => 'en' ) );
	check( 'normalize_analysis: extras present', array( $norm['knowledge_refs'], $norm['reply_language'] ), array( array( 'hours' ), 'en' ), $passed, $failed );
	check( 'normalize_analysis: legacy body has no extras keys', isset( $na->invoke( $provider, array( 'summary' => 's' ) )['knowledge_refs'] ), false, $passed, $failed );

	// ── sanitizer ─────────────────────────────────────────────────────
	check( 'sanitize ai_behaviour: full', FormSettingsSanitizer::sanitize_ai_behaviour( array( 'tone' => ' Warm <b>x</b> ', 'language_mode' => 'fallback', 'language' => 'fr-CA', 'knowledge_ids' => array( 'A', 'a', 'b!' ) ) ), array( 'tone' => 'Warm x', 'language_mode' => 'fallback', 'language' => 'fr-CA', 'knowledge_ids' => array( 'a', 'b' ) ), $passed, $failed );
	check( 'sanitize ai_behaviour: mode without code dropped', FormSettingsSanitizer::sanitize_ai_behaviour( array( 'language_mode' => 'fixed' ) ), array(), $passed, $failed );
	check( 'sanitize ai_behaviour: detect mode is the default (dropped)', FormSettingsSanitizer::sanitize_ai_behaviour( array( 'language_mode' => 'detect', 'language' => 'en' ) ), array(), $passed, $failed );
	$san = FormSettingsSanitizer::sanitize( array( 'ai_behaviour' => array( 'tone' => '' ), 'on_success' => array( 'mode' => 'message' ) ) );
	check( 'sanitize: empty ai_behaviour key removed', isset( $san['ai_behaviour'] ), false, $passed, $failed );
	$san = FormSettingsSanitizer::sanitize( array( 'ai_behaviour' => array( 'tone' => 'formal' ) ) );
	check( 'sanitize: ai_behaviour kept when meaningful', $san['ai_behaviour'], array( 'tone' => 'formal' ), $passed, $failed );

	// ── AIResponseRepository meta ─────────────────────────────────────
	check( 'build_meta: null when nothing', AIResponseRepository::build_meta( array( 'reply_draft' => 'x' ) ), null, $passed, $failed );
	$meta = json_decode( (string) AIResponseRepository::build_meta( array( 'knowledge_refs' => array( 'hours' ), 'missing_info' => array(), 'reply_language' => 'de', 'knowledge_revision' => 3, 'meta' => array( 'translated_to' => 'de' ) ) ), true );
	check( 'build_meta: merges extras + explicit meta, drops empty lists', $meta, array( 'translated_to' => 'de', 'knowledge_refs' => array( 'hours' ), 'reply_language' => 'de', 'knowledge_revision' => 3 ), $passed, $failed );
	check( 'decode_row: JSON string → array', AIResponseRepository::decode_row( array( 'meta' => '{"a":1}' ) )['meta'], array( 'a' => 1 ), $passed, $failed );
	check( 'decode_row: null → []', AIResponseRepository::decode_row( array( 'meta' => null ) )['meta'], array(), $passed, $failed );
	check( 'decode_row: pre-1.2 row without meta → []', AIResponseRepository::decode_row( array( 'id' => 1 ) )['meta'], array(), $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
