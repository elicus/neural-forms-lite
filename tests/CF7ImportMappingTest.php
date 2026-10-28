<?php
/**
 * Contact Form 7 import mapping.
 *
 * Run from the plugin root:
 *
 *   php tests/CF7ImportMappingTest.php
 *
 * Stubs WPCF7_FormTag with the real option semantics (bare flags vs
 * name:value, is_required() = trailing *, get_limit_option() kb/mb) and
 * drives CF7Detector::parse_tags() → CF7FieldMapper::map_form().
 *
 * Coverage (all reported in the 1.1.2 audit):
 *   1. placeholder flag → placeholder, NOT default_value
 *   2. no placeholder flag → first value is the default (CF7 semantics)
 *   3. acceptance required unless `optional`
 *   4. limit:2mb / 500kb / 1048576 → whole MB; absent → unset
 *   5. filetypes → allowed_types groups (array); unknown-only → unset
 *   6. default:1 / default:2 / default:1_3 on radio / select / checkbox
 *   7. dynamic defaults (user_email) left empty
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.
if ( ! defined( 'KB_IN_BYTES' ) ) define( 'KB_IN_BYTES', 1024 );
if ( ! defined( 'MB_IN_BYTES' ) ) define( 'MB_IN_BYTES', 1048576 );
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'class_exists_stub' ) ) { function class_exists_stub() {} }

/** Minimal WPCF7_FormTag with the real option-matching semantics. */
class WPCF7_FormTag {
	public $type, $basetype, $name, $options = [], $values = [], $labels = [], $raw_values = [], $content = '';
	public function __construct( $tag ) {
		foreach ( (array) $tag as $k => $v ) { $this->$k = $v; }
		if ( empty( $this->basetype ) ) { $this->basetype = rtrim( (string) $this->type, '*' ); }
		if ( empty( $this->labels ) ) { $this->labels = $this->values; }
	}
	public function is_required() { return substr( (string) $this->type, -1 ) === '*'; }
	public function has_option( $name ) { return (bool) preg_grep( sprintf( '/^%s(:.+)?$/i', preg_quote( $name, '/' ) ), $this->options ); }
	public function get_option( $name, $pattern = '', $single = false ) {
		$presets = [ 'int' => '[0-9]+', 'signed_int' => '[-]?[0-9]+', 'num' => '(?:[0-9]+|(?:[0-9]+)?[.][0-9]+)', 'signed_num' => '[-]?(?:[0-9]+|(?:[0-9]+)?[.][0-9]+)' ];
		if ( isset( $presets[ $pattern ] ) ) $pattern = $presets[ $pattern ];
		if ( $pattern === '' ) $pattern = '.+';
		$re = sprintf( '/^%s:%s$/i', preg_quote( $name, '/' ), $pattern );
		$out = [];
		foreach ( $this->options as $o ) { if ( preg_match( $re, $o ) ) { $out[] = substr( $o, strlen( $name ) + 1 ); if ( $single ) return $out[0]; } }
		return $out ? $out : false;
	}
	public function get_first_match_option( $pattern ) { foreach ( $this->options as $o ) { if ( preg_match( $pattern, $o, $m ) ) return $m; } return false; }
	public function get_limit_option( $default = MB_IN_BYTES ) {
		$m = $this->get_first_match_option( '/^limit:([1-9][0-9]*)([kKmM]?[bB])?$/' );
		if ( $m ) { $s = (int) $m[1]; $u = strtolower( $m[2] ?? '' ); if ( $u === 'kb' ) $s *= KB_IN_BYTES; elseif ( $u === 'mb' ) $s *= MB_IN_BYTES; return $s; }
		return (int) $default;
	}
}
class CF7_Form_Stub { public $tags; public function __construct( array $t ) { $this->tags = $t; } public function scan_form_tags() { return $this->tags; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/migration/CF7FieldMapper.php';
require_once $plugin_root . '/modules/migration/CF7Detector.php';

use NeuralForms\Migration\CF7Detector;
use NeuralForms\Migration\CF7FieldMapper;

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
/** Map one CF7 tag (array shape of WPCF7_FormTag) → Neural Forms field. */
function nf_map( array $tag ): array {
	$tags = CF7Detector::parse_tags( new CF7_Form_Stub( [ $tag ] ) );
	$out  = CF7FieldMapper::map_form( $tags );
	return array_values( $out['fields'] )[0] ?? [];
}

// 1. placeholder flag
$f = nf_map( [ 'type' => 'text*', 'name' => 'your-name', 'options' => [ 'placeholder' ], 'values' => [ 'Jane Cooper' ] ] );
check( '1a. placeholder flag → placeholder text',      $f['placeholder'] ?? null, 'Jane Cooper', $passed, $failed );
check( '1b. placeholder flag → no default_value',      array_key_exists( 'default_value', $f ), false, $passed, $failed );
check( '1c. required from *',                          $f['required'], true, $passed, $failed );
check( '1d. label from tag name',                      $f['label'], 'Your Name', $passed, $failed );
$f = nf_map( [ 'type' => 'email', 'name' => 'your-email', 'options' => [ 'watermark' ], 'values' => [ 'you@example.com' ] ] );
check( '1e. legacy watermark flag → placeholder',      [ $f['placeholder'], array_key_exists( 'default_value', $f ) ], [ 'you@example.com', false ], $passed, $failed );
$f = nf_map( [ 'type' => 'textarea', 'name' => 'msg', 'options' => [ 'placeholder' ], 'values' => [ 'Your message' ] ] );
check( '1f. textarea placeholder flag → placeholder',  [ $f['placeholder'], array_key_exists( 'default_value', $f ) ], [ 'Your message', false ], $passed, $failed );

// 2. no placeholder flag → value is the default (CF7 behaviour)
$f = nf_map( [ 'type' => 'text', 'name' => 'city', 'options' => [], 'values' => [ 'London' ] ] );
check( '2a. bare value → default_value',               $f['default_value'] ?? null, 'London', $passed, $failed );
check( '2b. bare value → empty placeholder',           $f['placeholder'], '', $passed, $failed );
$f = nf_map( [ 'type' => 'text', 'name' => 'ref', 'options' => [ 'default:ABC' ], 'values' => [] ] );
check( '2c. explicit default:ABC kept',                $f['default_value'] ?? null, 'ABC', $passed, $failed );

// 3. acceptance
$f = nf_map( [ 'type' => 'acceptance', 'name' => 'privacy', 'options' => [], 'values' => [] ] );
check( '3a. [acceptance privacy] → required',          $f['required'], true, $passed, $failed );
$f = nf_map( [ 'type' => 'acceptance', 'name' => 'news', 'options' => [ 'optional' ], 'values' => [] ] );
check( '3b. [acceptance news optional] → not required', $f['required'], false, $passed, $failed );

// 4. limit
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'limit:2mb' ], 'values' => [] ] );
check( '4a. limit:2mb → 2 MB',                         $f['max_file_size'] ?? null, 2, $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'limit:500kb' ], 'values' => [] ] );
check( '4b. limit:500kb → 1 MB (rounded up)',          $f['max_file_size'] ?? null, 1, $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'limit:1048576' ], 'values' => [] ] );
check( '4c. limit:1048576 (bytes) → 1 MB',             $f['max_file_size'] ?? null, 1, $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'limit:3145728' ], 'values' => [] ] );
check( '4d. limit:3145728 → 3 MB',                     $f['max_file_size'] ?? null, 3, $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [], 'values' => [] ] );
check( '4e. no limit → max_file_size unset',           array_key_exists( 'max_file_size', $f ), false, $passed, $failed );

// 5. filetypes
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'filetypes:pdf|docx|jpg' ], 'values' => [] ] );
check( '5a. filetypes → groups array',                 $f['allowed_types'] ?? null, [ 'pdf', 'document', 'image' ], $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'filetypes:.PNG|.Gif' ], 'values' => [] ] );
check( '5b. leading dots + case → image once',         $f['allowed_types'] ?? null, [ 'image' ], $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'filetypes:image/*' ], 'values' => [] ] );
check( '5c. MIME glob image/* → image',                $f['allowed_types'] ?? null, [ 'image' ], $passed, $failed );
$f = nf_map( [ 'type' => 'file', 'name' => 'brief', 'options' => [ 'filetypes:zip|exe' ], 'values' => [] ] );
check( '5d. unsupported extensions only → unset (no restriction)', array_key_exists( 'allowed_types', $f ), false, $passed, $failed );
check( '5e. map_filetypes direct: txt,csv → text',     CF7FieldMapper::map_filetypes( 'txt|csv' ), [ 'text' ], $passed, $failed );

// 6. choice defaults
$f = nf_map( [ 'type' => 'radio', 'name' => 'contact-method', 'options' => [ 'default:1' ], 'values' => [ 'Email', 'Phone' ] ] );
check( '6a. radio default:1 → "Email"',                $f['default_value'] ?? null, 'Email', $passed, $failed );
check( '6b. radio options mapped',                     array_column( $f['options'], 'value' ), [ 'Email', 'Phone' ], $passed, $failed );
$f = nf_map( [ 'type' => 'select', 'name' => 'size', 'options' => [ 'default:2' ], 'values' => [ 'S', 'M', 'L' ] ] );
check( '6c. select default:2 → "M"',                   $f['default_value'] ?? null, 'M', $passed, $failed );
$f = nf_map( [ 'type' => 'checkbox', 'name' => 'topics', 'options' => [ 'default:1_3' ], 'values' => [ 'A', 'B', 'C' ] ] );
check( '6d. checkbox default:1_3 → "A,C"',             $f['default_value'] ?? null, 'A,C', $passed, $failed );
$f = nf_map( [ 'type' => 'radio', 'name' => 'x', 'options' => [ 'default:9' ], 'values' => [ 'Only' ] ] );
check( '6e. out-of-range index → no default',          array_key_exists( 'default_value', $f ), false, $passed, $failed );
$f = nf_map( [ 'type' => 'radio', 'name' => 'x', 'options' => [], 'values' => [ 'Yes', 'No' ] ] );
check( '6f. radio without default → none (first value is NOT a default)', array_key_exists( 'default_value', $f ), false, $passed, $failed );

// 7. dynamic defaults
$f = nf_map( [ 'type' => 'email', 'name' => 'your-email', 'options' => [ 'default:user_email' ], 'values' => [] ] );
check( '7. default:user_email → left empty',           array_key_exists( 'default_value', $f ), false, $passed, $failed );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
