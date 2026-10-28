<?php
/**
 * File-upload size-limit tests.
 *
 * Run from the plugin root:
 *
 *   php tests/FileSizeLimitTest.php
 *
 * Covers SchemaHelper::file_size_limit() / format_file_size_label()
 * against a stubbed wp_max_upload_size(), plus SubmitEndpoint's
 * is_size_upload_error() / file_too_large_error() (extracted from
 * source, same pattern as the other endpoint tests).
 *
 * Coverage:
 *   1. no server cap → builder value (default 5 MB)
 *   2. builder value wins when below server cap
 *   3. server cap (PHP default 2 MB) wins when below builder value
 *   4. fractional server cap → one-decimal label
 *   5. sub-MB server cap → KB label
 *   6. non-positive / missing max_file_size falls back to 5
 *   7. is_size_upload_error: INI_SIZE + FORM_SIZE only
 *   8. file_too_large_error: code / status / field path / message with label
 *   9. source guard: both file paths route size errors before the generic branch
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

	if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
	$GLOBALS['__nf_server_cap'] = 0;
	function wp_max_upload_size() { return $GLOBALS['__nf_server_cap']; }

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/forms/FieldRegistry.php';
	require_once $plugin_root . '/modules/forms/SchemaHelper.php';

	use NeuralForms\Forms\SchemaHelper;

	$src = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
	$bodies = [];
	foreach ( [ 'is_size_upload_error', 'file_too_large_error' ] as $name ) {
		if ( ! preg_match( '/private (?:static )?function ' . $name . '\(.+?\n\t\}/s', $src, $m ) ) {
			fwrite( STDERR, "Could not extract {$name}() source.\n" );
			exit( 2 );
		}
		$bodies[] = preg_replace( '/^\s*private /', 'public ', $m[0] );
	}
	eval( '
		namespace NeuralForms\\Tests {
			use WP_Error;
			class SubmitEndpointFiles {
				' . implode( "\n\n", $bodies ) . '
			}
		}
	' );
	$ep = new \NeuralForms\Tests\SubmitEndpointFiles();

	$passed = 0;
	$failed = 0;
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

	$MB = 1024 * 1024;

	// 1. no server cap
	$GLOBALS['__nf_server_cap'] = 0;
	check( '1. no server cap → default 5 MB', SchemaHelper::file_size_limit( [] ), [ 'bytes' => 5 * $MB, 'label' => '5 MB' ], $passed, $failed );
	check( '1b. builder 10 MB, no server cap', SchemaHelper::file_size_limit( [ 'max_file_size' => 10 ] ), [ 'bytes' => 10 * $MB, 'label' => '10 MB' ], $passed, $failed );

	// 2. builder below server cap
	$GLOBALS['__nf_server_cap'] = 64 * $MB;
	check( '2. builder 3 MB under 64 MB server cap', SchemaHelper::file_size_limit( [ 'max_file_size' => 3 ] ), [ 'bytes' => 3 * $MB, 'label' => '3 MB' ], $passed, $failed );

	// 3. PHP default 2 MB caps the 5 MB default
	$GLOBALS['__nf_server_cap'] = 2 * $MB;
	check( '3. server 2 MB caps default 5 MB', SchemaHelper::file_size_limit( [] ), [ 'bytes' => 2 * $MB, 'label' => '2 MB' ], $passed, $failed );
	check( '3b. server 2 MB caps builder 8 MB', SchemaHelper::file_size_limit( [ 'max_file_size' => 8 ] ), [ 'bytes' => 2 * $MB, 'label' => '2 MB' ], $passed, $failed );

	// 4. fractional
	$GLOBALS['__nf_server_cap'] = (int) ( 2.5 * $MB );
	check( '4. 2.5 MB server cap → "2.5 MB"', SchemaHelper::file_size_limit( [] )['label'], '2.5 MB', $passed, $failed );

	// 5. sub-MB
	$GLOBALS['__nf_server_cap'] = 512 * 1024;
	check( '5. 512 KB server cap → "512 KB"', SchemaHelper::file_size_limit( [] ), [ 'bytes' => 512 * 1024, 'label' => '512 KB' ], $passed, $failed );
	check( '5b. format 1 MB exactly', SchemaHelper::format_file_size_label( $MB ), '1 MB', $passed, $failed );
	check( '5c. format 1.25 MB rounds to one decimal', SchemaHelper::format_file_size_label( (int) ( 1.25 * $MB ) ), '1.3 MB', $passed, $failed );
	check( '5d. format 1 byte never shows 0 KB', SchemaHelper::format_file_size_label( 1 ), '1 KB', $passed, $failed );

	// 6. bad builder values
	$GLOBALS['__nf_server_cap'] = 0;
	check( '6. max_file_size 0 → 5 MB',   SchemaHelper::file_size_limit( [ 'max_file_size' => 0 ] )['bytes'],   5 * $MB, $passed, $failed );
	check( '6b. max_file_size -1 → 5 MB', SchemaHelper::file_size_limit( [ 'max_file_size' => -1 ] )['bytes'],  5 * $MB, $passed, $failed );
	check( '6c. max_file_size "7" → 7 MB', SchemaHelper::file_size_limit( [ 'max_file_size' => '7' ] )['bytes'], 7 * $MB, $passed, $failed );

	// 7. size error codes
	check( '7. INI_SIZE is a size error',   $ep->is_size_upload_error( UPLOAD_ERR_INI_SIZE ),  true,  $passed, $failed );
	check( '7b. FORM_SIZE is a size error', $ep->is_size_upload_error( UPLOAD_ERR_FORM_SIZE ), true,  $passed, $failed );
	check( '7c. PARTIAL is not',            $ep->is_size_upload_error( UPLOAD_ERR_PARTIAL ),   false, $passed, $failed );
	check( '7d. NO_FILE is not',            $ep->is_size_upload_error( UPLOAD_ERR_NO_FILE ),   false, $passed, $failed );
	check( '7e. OK is not',                 $ep->is_size_upload_error( UPLOAD_ERR_OK ),        false, $passed, $failed );

	// 8. error shape
	$err = $ep->file_too_large_error( [ 'id' => 'cv', 'label' => 'Your CV' ], 'cv', '2 MB' );
	check( '8. code',    $err->get_error_code(), 'invalid_submission', $passed, $failed );
	check( '8b. status', $err->get_error_data()['status'], 422, $passed, $failed );
	check( '8c. field path + code', $err->get_error_data()['fields']['cv'][0]['code'], 'file_too_large', $passed, $failed );
	check( '8d. message uses label + limit', $err->get_error_data()['fields']['cv'][0]['message'], 'Your CV: file exceeds the maximum allowed size of 2 MB.', $passed, $failed );
	$err = $ep->file_too_large_error( [ 'id' => 'cv' ], 'rep[0][cv]', '512 KB' );
	check( '8e. falls back to id, repeater path', [ $err->get_error_data()['fields']['rep[0][cv]'][0]['message'] ], [ 'cv: file exceeds the maximum allowed size of 512 KB.' ], $passed, $failed );

	// 9. source guard — every generic upload-error branch must be preceded by a size-error check
	$generic = substr_count( $src, "'file_upload_error'" );
	$routed  = substr_count( $src, 'self::is_size_upload_error( $file_info[\'error\'] )' );
	check( '9. each generic upload-error branch has a size-error route before it', $routed, $generic, $passed, $failed );
	check( '9b. no bare 5 MB default left in SubmitEndpoint', preg_match( '/max_file_size.*\n.*\n\s*: 5;/', $src ), 0, $passed, $failed );
	check( '9c. no bare 5 MB default left in FormRenderer', preg_match( '/max_file_size.*\n.*\n\s*: 5;/', file_get_contents( $plugin_root . '/modules/frontend/FormRenderer.php' ) ), 0, $passed, $failed );
	check( '9d. post_max_size overflow guard present', strpos( $src, "'file_too_large'" ) !== false && strpos( $src, "'status' => 413" ) !== false, true, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
