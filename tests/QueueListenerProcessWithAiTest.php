<?php
/**
 * Integration smoke for the per-form Process-with-AI gate inside
 * QueueListener::handle(). The unit tests in
 * AiProcessingResolverTest.php cover the resolver exhaustively;
 * this file just proves the wire-up — that handle() actually CALLS
 * the resolver and SHORT-CIRCUITS without inserting a queue row when
 * the decision is anything other than ALLOW_AUTO.
 *
 * Strategy: stub the minimum WP/Lite surface required to instantiate
 * the handler in-process, capture wpdb->insert calls + Logger lines,
 * and assert the gate fires for every non-ALLOW_AUTO decision.
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	if ( ! function_exists( '__' ) )                  { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; } }
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $GLOBALS['__nf_filter_returns'][ $tag ] ?? $value;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) { return json_encode( $data ); }
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) { return '2026-05-05 00:00:00'; }
	}
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		function wp_schedule_single_event( $time, $hook ) { $GLOBALS['__nf_cron_scheduled'][] = [ 'time' => $time, 'hook' => $hook ]; }
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error { public function __construct( $code = '', $message = '', $data = [] ) {} }
	}

	// Stub wpdb that captures inserts.
	class WPDB_Stub {
		public $prefix = 'wp_';
		public $last_error = '';
		public $insert_calls = [];
		public function insert( $table, $data, $formats ) {
			$this->insert_calls[] = compact( 'table', 'data', 'formats' );
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new WPDB_Stub();
}

namespace NeuralForms\Core {
	class Config {
		public static function get( string $key, $default = null ) {
			return $GLOBALS['__nf_config'][ $key ] ?? $default;
		}
	}
	class Logger {
		public static function file( $channel, $level, $message ) {
			$GLOBALS['__nf_log'][] = compact( 'channel', 'level', 'message' );
		}
		public static function both( ...$args ) { /* unused in this test */ }
	}
}

namespace NeuralForms\Forms {
	class FormRepository {
		public static function find( int $id ): ?array {
			return $GLOBALS['__nf_forms'][ $id ] ?? null;
		}
	}
}

namespace NeuralForms\Plan {
	class PlanManager {
		public static function can( $cap ) {
			return $GLOBALS['__nf_plan_can'][ $cap ] ?? true;
		}
	}
}

namespace NeuralForms\AI {
	class AIRepository {
		public static function update_status( $entry_id, $status, $error = null ) {
			$GLOBALS['__nf_status_updates'][] = compact( 'entry_id', 'status', 'error' );
		}
	}
	class AIQueueProcessor {
		const CRON_HOOK = 'neural_forms_process_queue';
	}
}

namespace {
	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/forms/FieldRegistry.php';
	require_once $plugin_root . '/modules/forms/SchemaHelper.php';
	require_once $plugin_root . '/modules/core/Capabilities.php';
	require_once $plugin_root . '/modules/ai/AiProcessingResolver.php';
	require_once $plugin_root . '/modules/ai/QueueListener.php';

	use NeuralForms\AI\QueueListener;

	$cases = [];
	$passed = 0;
	$failed = 0;

	function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
		$ok = $actual === $expected;
		$cases[] = compact( 'label', 'actual', 'expected', 'ok' );
		if ( $ok ) $passed++; else $failed++;
		$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
		echo $indicator . '  ' . $label . "\n";
		if ( ! $ok ) {
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	function fixture_form( int $id, string $mode ): void {
		$GLOBALS['__nf_forms'][ $id ] = [
			'id'          => $id,
			'name'        => 'Test',
			'form_schema' => [ 'fields' => [], 'settings' => [ 'ai_processing' => [ 'mode' => $mode ] ] ],
		];
	}

	function reset_capture(): void {
		$GLOBALS['wpdb']->insert_calls = [];
		$GLOBALS['__nf_status_updates'] = [];
		$GLOBALS['__nf_cron_scheduled'] = [];
		$GLOBALS['__nf_log'] = [];
	}

	function set_pro_active(): void {
		$GLOBALS['__nf_filter_returns']['neural_forms_can_ai_process_entries'] = true;
		$GLOBALS['__nf_config'] = [
			'ai_enabled'       => true,
			'cloud_ai_enabled' => true,
			'cloud_api_key'    => 'nf_live_x',
			'ai_auto_process'  => true,
		];
	}

	function set_lite_only(): void {
		$GLOBALS['__nf_filter_returns']['neural_forms_can_ai_process_entries'] = false;
		$GLOBALS['__nf_config'] = [
			'ai_enabled'       => true,
			'cloud_ai_enabled' => true,
			'cloud_api_key'    => 'nf_live_x',
			'ai_auto_process'  => true,
		];
	}

	// ─── 1. Lite-only install never queues ───────────────────────────────
	set_lite_only();
	fixture_form( 100, 'every_entry' );
	reset_capture();
	QueueListener::handle( 1001, 100 );
	check( '1a Lite-only + every_entry: no queue insert',
		count( $GLOBALS['wpdb']->insert_calls ), 0, $cases, $passed, $failed );
	check( '1b Lite-only: structured skip log emitted',
		count( $GLOBALS['__nf_log'] ) >= 1 && strpos( $GLOBALS['__nf_log'][0]['message'], 'decision=unavailable' ) !== false,
		true, $cases, $passed, $failed );

	// ─── 2. Pro active — every per-form mode ─────────────────────────────
	set_pro_active();

	fixture_form( 200, 'disabled' );
	reset_capture();
	QueueListener::handle( 2001, 200 );
	check( '2a disabled mode: no queue insert',
		count( $GLOBALS['wpdb']->insert_calls ), 0, $cases, $passed, $failed );
	check( '2b disabled mode: skip log mentions decision=skip',
		strpos( $GLOBALS['__nf_log'][0]['message'] ?? '', 'decision=skip' ) !== false,
		true, $cases, $passed, $failed );

	fixture_form( 201, 'manual' );
	reset_capture();
	QueueListener::handle( 2011, 201 );
	check( '2c manual mode: no queue insert',
		count( $GLOBALS['wpdb']->insert_calls ), 0, $cases, $passed, $failed );
	check( '2d manual mode: skip log mentions decision=allow_manual_only',
		strpos( $GLOBALS['__nf_log'][0]['message'] ?? '', 'decision=allow_manual_only' ) !== false,
		true, $cases, $passed, $failed );

	fixture_form( 202, 'final_results' );
	reset_capture();
	QueueListener::handle( 2021, 202 );
	check( '2e final_results mode: no queue insert',
		count( $GLOBALS['wpdb']->insert_calls ), 0, $cases, $passed, $failed );

	fixture_form( 203, 'every_entry' );
	reset_capture();
	QueueListener::handle( 2031, 203 );
	check( '2f every_entry mode + Pro active: queue insert HAPPENS',
		count( $GLOBALS['wpdb']->insert_calls ), 1, $cases, $passed, $failed );
	check( '2g every_entry mode: cron scheduled',
		count( $GLOBALS['__nf_cron_scheduled'] ), 1, $cases, $passed, $failed );
	check( '2h every_entry mode: entry status updated to queued',
		( $GLOBALS['__nf_status_updates'][0]['status'] ?? '' ) === 'queued',
		true, $cases, $passed, $failed );

	fixture_form( 204, 'inherit' );
	reset_capture();
	QueueListener::handle( 2041, 204 );
	check( '2i inherit + global ai_auto_process ON: queue insert HAPPENS',
		count( $GLOBALS['wpdb']->insert_calls ), 1, $cases, $passed, $failed );

	$GLOBALS['__nf_config']['ai_auto_process'] = false;
	$GLOBALS['__nf_config']['ai_spam_enabled'] = false;
	reset_capture();
	QueueListener::handle( 2042, 204 );
	check( '2j inherit + global auto-process OFF: no queue insert (resolver returns ALLOW_MANUAL_ONLY)',
		count( $GLOBALS['wpdb']->insert_calls ), 0, $cases, $passed, $failed );

	// ─── 3. Cloud disconnect short-circuits ───────────────────────────────
	set_pro_active();
	$GLOBALS['__nf_config']['cloud_api_key'] = '';
	fixture_form( 300, 'every_entry' );
	reset_capture();
	QueueListener::handle( 3001, 300 );
	check( '3a Pro + every_entry but cloud key missing: no queue insert',
		count( $GLOBALS['wpdb']->insert_calls ), 0, $cases, $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
