<?php
/**
 * AiProcessingResolver + ai_processing schema normalization tests.
 *
 * Run from the plugin root:
 *
 *   php tests/AiProcessingResolverTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	if ( ! function_exists( '__' ) )                   { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'sanitize_text_field' ) )  { function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; } }
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return isset( $GLOBALS['__nf_filter_returns'][ $tag ] )
				? $GLOBALS['__nf_filter_returns'][ $tag ]
				: $value;
		}
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( $code = '', $message = '', $data = [] ) {}
		}
	}
}

namespace NeuralForms\Core {
	class Config {
		public static function get( string $key, $default = null ) {
			return $GLOBALS['__nf_config'][ $key ] ?? $default;
		}
	}
}

namespace NeuralForms\Forms {
	class FormRepository {
		public static function find( int $id ): ?array {
			return $GLOBALS['__nf_forms'][ $id ] ?? null;
		}
	}
}

namespace {
	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/forms/FieldRegistry.php';
	require_once $plugin_root . '/modules/forms/SchemaHelper.php';
	require_once $plugin_root . '/modules/core/Capabilities.php';
	require_once $plugin_root . '/modules/ai/AiProcessingResolver.php';

	use NeuralForms\AI\AiProcessingResolver;
	use NeuralForms\Forms\SchemaHelper;

	$cases = [];
	$passed = 0;
	$failed = 0;

	function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
		$ok = $actual === $expected;
		$cases[] = compact( 'label', 'actual', 'expected', 'ok' );
		if ( $ok ) $passed++; else $failed++;
		$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
		$msg = $indicator . '  ' . $label;
		if ( ! $ok ) {
			$msg .= "\n      expected: " . var_export( $expected, true );
			$msg .= "\n      actual:   " . var_export( $actual, true );
		}
		echo $msg . "\n";
	}

	function set_cap( bool $allowed ): void {
		$GLOBALS['__nf_filter_returns']['neural_forms_can_ai_process_entries'] = $allowed;
	}

	function set_cloud( bool $ai_enabled, bool $cloud_enabled, string $key, ?bool $auto_process = null ): void {
		$GLOBALS['__nf_config'] = [
			'ai_enabled'       => $ai_enabled,
			'cloud_ai_enabled' => $cloud_enabled,
			'cloud_api_key'    => $key,
		];
		if ( $auto_process !== null ) {
			$GLOBALS['__nf_config']['ai_auto_process'] = $auto_process;
		}
	}

	function set_form( int $id, ?string $mode ): void {
		$ai_processing = $mode === null ? null : [ 'mode' => $mode ];
		$schema = [ 'fields' => [] ];
		if ( $mode !== null ) {
			$schema['settings'] = [ 'ai_processing' => $ai_processing ];
		}
		$GLOBALS['__nf_forms'][ $id ] = [
			'id'          => $id,
			'name'        => 'Test',
			'form_schema' => $schema,
		];
	}

	// ─── 1. Schema normalization ─────────────────────────────────────────
	$valid_modes = SchemaHelper::AI_PROCESSING_MODES;
	foreach ( $valid_modes as $mode ) {
		$out = SchemaHelper::normalize_ai_processing_settings( [ 'mode' => $mode ] );
		check( "1a normalize preserves valid mode: $mode", $out['mode'], $mode, $cases, $passed, $failed );
	}

	check( '1b unknown mode clamps to inherit',
		SchemaHelper::normalize_ai_processing_settings( [ 'mode' => 'bogus' ] )['mode'],
		'inherit', $cases, $passed, $failed );

	check( '1c missing input → inherit',
		SchemaHelper::normalize_ai_processing_settings( null )['mode'],
		'inherit', $cases, $passed, $failed );

	check( '1d non-array → inherit',
		SchemaHelper::normalize_ai_processing_settings( 'foo' )['mode'],
		'inherit', $cases, $passed, $failed );

	check( '1e default returns inherit',
		SchemaHelper::default_ai_processing_settings()['mode'],
		'inherit', $cases, $passed, $failed );

	// ─── 2. Resolver capability gate ─────────────────────────────────────
	set_cap( false );
	set_cloud( true, true, 'nf_live_xxx', true );
	set_form( 1, 'every_entry' );
	check( '2a cap=false → unavailable regardless of form mode',
		AiProcessingResolver::resolve( 1 ),
		AiProcessingResolver::RESULT_UNAVAILABLE, $cases, $passed, $failed );

	// ─── 3. Resolver cloud-connection gate ───────────────────────────────
	set_cap( true );
	set_cloud( false, true, 'nf_live_xxx', true );
	check( '3a ai_enabled=false → unavailable',
		AiProcessingResolver::resolve( 1 ),
		AiProcessingResolver::RESULT_UNAVAILABLE, $cases, $passed, $failed );

	set_cloud( true, false, 'nf_live_xxx', true );
	check( '3b cloud_ai_enabled=false → unavailable',
		AiProcessingResolver::resolve( 1 ),
		AiProcessingResolver::RESULT_UNAVAILABLE, $cases, $passed, $failed );

	set_cloud( true, true, '', true );
	check( '3c cloud_api_key empty → unavailable',
		AiProcessingResolver::resolve( 1 ),
		AiProcessingResolver::RESULT_UNAVAILABLE, $cases, $passed, $failed );

	// ─── 4. Per-form mode behaviour (cap on, cloud connected) ────────────
	set_cap( true );
	set_cloud( true, true, 'nf_live_xxx', true );

	set_form( 10, 'disabled' );
	check( '4a disabled → SKIP',
		AiProcessingResolver::resolve( 10 ),
		AiProcessingResolver::RESULT_SKIP, $cases, $passed, $failed );

	set_form( 11, 'every_entry' );
	check( '4b every_entry → ALLOW_AUTO',
		AiProcessingResolver::resolve( 11 ),
		AiProcessingResolver::RESULT_ALLOW_AUTO, $cases, $passed, $failed );

	set_form( 12, 'manual' );
	check( '4c manual → ALLOW_MANUAL_ONLY',
		AiProcessingResolver::resolve( 12 ),
		AiProcessingResolver::RESULT_ALLOW_MANUAL_ONLY, $cases, $passed, $failed );

	set_form( 13, 'final_results' );
	check( '4d final_results → ALLOW_MANUAL_ONLY',
		AiProcessingResolver::resolve( 13 ),
		AiProcessingResolver::RESULT_ALLOW_MANUAL_ONLY, $cases, $passed, $failed );

	set_form( 14, 'inherit' );
	check( '4e inherit + global auto-process ON → ALLOW_AUTO',
		AiProcessingResolver::resolve( 14 ),
		AiProcessingResolver::RESULT_ALLOW_AUTO, $cases, $passed, $failed );

	set_cloud( true, true, 'nf_live_xxx', false );
	check( '4f inherit + global auto-process OFF → ALLOW_MANUAL_ONLY',
		AiProcessingResolver::resolve( 14 ),
		AiProcessingResolver::RESULT_ALLOW_MANUAL_ONLY, $cases, $passed, $failed );

	// ─── 5. Form-mode read fallback ──────────────────────────────────────
	set_cap( true );
	set_cloud( true, true, 'nf_live_xxx', true );
	set_form( 20, null );
	check( '5a missing ai_processing on schema → inherit fallback',
		AiProcessingResolver::form_mode( 20 ),
		'inherit', $cases, $passed, $failed );

	check( '5b unknown form_id → inherit (default)',
		AiProcessingResolver::form_mode( 9999 ),
		'inherit', $cases, $passed, $failed );

	check( '5c invalid form_id (0) → inherit (default)',
		AiProcessingResolver::form_mode( 0 ),
		'inherit', $cases, $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
