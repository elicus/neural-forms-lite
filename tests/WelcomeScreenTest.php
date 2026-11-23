<?php
/**
 * Welcome Screen layout block tests.
 *
 * Locks the contract:
 *   - SchemaHelper recognises welcome_screen as a layout node, NOT a
 *     real field, NOT a section/row/step.
 *   - normalize_schema preserves welcome_screen with canonical settings
 *     shape and clamps alignment to known values.
 *   - normalize_schema drops a second welcome_screen (one per form).
 *   - flatten_fields skips welcome_screen — submission code never sees
 *     a welcome_screen id as user-facing data.
 *   - find_welcome_screen returns the node or null.
 *   - FormValidator accepts a top-level welcome_screen and rejects a
 *     nested one (welcome screens are top-level only).
 *   - FormValidator rejects a second welcome_screen with a clear error.
 *
 * Run from the plugin root:
 *
 *   php tests/WelcomeScreenTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) )   { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( '__' ) )            { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
	}
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\FormValidator;

$passed = 0;
$failed = 0;
function check( string $label, $actual, $expected, &$passed, &$failed ): void {
	$ok = $actual === $expected;
	if ( $ok ) {
		echo "PASS  $label\n";
		$passed += 1;
	} else {
		echo "FAIL  $label\n";
		echo "       expected: " . var_export( $expected, true ) . "\n";
		echo "       actual:   " . var_export( $actual,   true ) . "\n";
		$failed += 1;
	}
}

// ─── is_* truth table ───────────────────────────────────────────────

$ws = array(
	'id'          => 'welcome_screen_1',
	'type'        => 'welcome_screen',
	'title'       => "Let's go",
	'description' => 'desc',
	'buttonText'  => 'Start',
	'settings'    => array( 'alignment' => 'center' ),
);

check( '1a is_welcome_screen true',
	SchemaHelper::is_welcome_screen( $ws ), true, $passed, $failed );
check( '1b is_layout_node true for ws',
	SchemaHelper::is_layout_node( $ws ), true, $passed, $failed );
check( '1c is_real_field false for ws',
	SchemaHelper::is_real_field( $ws ), false, $passed, $failed );
check( '1d is_step false',
	SchemaHelper::is_step( $ws ), false, $passed, $failed );
check( '1e is_section false',
	SchemaHelper::is_section( $ws ), false, $passed, $failed );
check( '1f is_row false',
	SchemaHelper::is_row( $ws ), false, $passed, $failed );

// ─── find_welcome_screen ────────────────────────────────────────────

check( '2a find returns null for empty',
	SchemaHelper::find_welcome_screen( array() ), null, $passed, $failed );
check( '2b find returns null when none',
	SchemaHelper::find_welcome_screen( array( array( 'id' => 'name', 'type' => 'text' ) ) ),
	null, $passed, $failed );
$found = SchemaHelper::find_welcome_screen( array( $ws, array( 'id' => 'x', 'type' => 'text' ) ) );
check( '2c find returns the ws node',
	is_array( $found ) && $found['id'] === 'welcome_screen_1', true, $passed, $failed );

// ─── flatten_fields skips welcome_screen ────────────────────────────

$with_ws = array(
	$ws,
	array( 'id' => 'name',  'type' => 'text' ),
	array( 'id' => 'email', 'type' => 'email' ),
);
$flat = SchemaHelper::flatten_fields( $with_ws );
check( '3a flatten_fields skips welcome screen — count',
	count( $flat ), 2, $passed, $failed );
$flat_ids = array_map( static function ( $f ) { return $f['id']; }, $flat );
check( '3b flatten_fields preserves order',
	$flat_ids, array( 'name', 'email' ), $passed, $failed );

// ─── normalize_schema ───────────────────────────────────────────────

$schema_in = array( 'fields' => $with_ws );
$out       = SchemaHelper::normalize_schema( $schema_in );
check( '4a normalize keeps welcome_screen',
	$out['fields'][0]['type'] ?? '', 'welcome_screen', $passed, $failed );
check( '4b normalize keeps fields after',
	$out['fields'][1]['type'] ?? '', 'text', $passed, $failed );

// Alignment clamp
$bad_align = array(
	'fields' => array( array_merge( $ws, array( 'settings' => array( 'alignment' => 'wonky' ) ) ) ),
);
$normalised = SchemaHelper::normalize_schema( $bad_align );
check( '4c alignment clamps to center',
	$normalised['fields'][0]['settings']['alignment'] ?? '', 'center', $passed, $failed );

// buttonText survives + button_text aliases through
$alias = array(
	'fields' => array( array_merge( $ws, array( 'button_text' => 'Begin', 'buttonText' => '' ) ) ),
);
$norm_alias = SchemaHelper::normalize_schema( $alias );
check( '4d button_text aliased into buttonText',
	$norm_alias['fields'][0]['buttonText'] ?? '__missing__', 'Begin', $passed, $failed );

// One per form — second welcome screen dropped on normalize
$ws2 = array_merge( $ws, array( 'id' => 'welcome_screen_2' ) );
$dup_in = array( 'fields' => array( $ws, $ws2, array( 'id' => 'a', 'type' => 'text' ) ) );
$dup_out = SchemaHelper::normalize_schema( $dup_in );
$dup_ws_count = 0;
foreach ( $dup_out['fields'] as $node ) {
	if ( SchemaHelper::is_welcome_screen( $node ) ) {
		$dup_ws_count += 1;
	}
}
check( '4e normalize drops duplicate welcome screen',
	$dup_ws_count, 1, $passed, $failed );

// ─── FormValidator: top-level welcome screen accepted ───────────────

$ok_schema = array(
	'fields' => array(
		$ws,
		array( 'id' => 'name', 'type' => 'text', 'label' => 'Name' ),
	),
);
$res_ok = FormValidator::validate_schema( $ok_schema );
check( '5a validator accepts top-level welcome screen',
	$res_ok, true, $passed, $failed );

// Second welcome screen → rejected
$dup_schema = array(
	'fields' => array( $ws, $ws2 ),
);
$res_dup = FormValidator::validate_schema( $dup_schema );
check( '5b validator rejects second welcome screen',
	is_wp_error( $res_dup ), true, $passed, $failed );

// Nested welcome screen inside step → rejected
$nested_schema = array(
	'fields' => array(
		array(
			'id'     => 'step_1',
			'type'   => 'step',
			'title'  => 'S1',
			'fields' => array( $ws ),
		),
	),
);
$res_nested = FormValidator::validate_schema( $nested_schema );
check( '5c validator rejects nested welcome screen',
	is_wp_error( $res_nested ), true, $passed, $failed );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
