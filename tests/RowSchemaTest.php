<?php
/**
 * End-to-end verification for the row + column layout system.
 *
 * Loads SchemaHelper + FormValidator + FieldRegistry with minimal WP
 * shims, then runs every checkpoint from the user's request.
 */

declare( strict_types = 1 );

// Minimal WP shims.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op once the line above defines ABSPATH).
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}
function __( $s, $domain = null ) { return $s; }
function _n( $a, $b, $n, $domain = null ) { return $n === 1 ? $a : $b; }
function sprintf_( ...$args ) { return sprintf( ...$args ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

// Load plugin classes (relative to this test file).
$base = dirname( __DIR__ ) . '/modules/forms';
$files = [
	$base . '/FieldRegistry.php',
	$base . '/SchemaHelper.php',
	$base . '/FormValidator.php',
];
foreach ( $files as $f ) {
	if ( ! file_exists( $f ) ) {
		echo "MISSING: $f\n";
		exit( 1 );
	}
	require_once $f;
}

// ---------------------------------------------------------------------------
// Test runner.
// ---------------------------------------------------------------------------
$passed = 0; $failed = 0; $cases = [];
function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\FormValidator;

// Sample fields.
$f_name  = [ 'id' => 'first_name', 'type' => 'text',  'label' => 'First name', 'required' => true ];
$f_email = [ 'id' => 'email',      'type' => 'email', 'label' => 'Email',      'required' => true ];
$f_phone = [ 'id' => 'phone',      'type' => 'phone', 'label' => 'Phone' ];
$f_msg   = [ 'id' => 'message',    'type' => 'textarea', 'label' => 'Message', 'required' => true ];
$f_hidden= [ 'id' => 'utm_src',    'type' => 'hidden', 'default_value' => 'organic' ];
$f_file  = [ 'id' => 'resume',     'type' => 'file' ];

// ---------------------------------------------------------------------------
// 1. Flat (legacy) schema validates and flattens to itself.
// ---------------------------------------------------------------------------
$flat = [ 'fields' => [ $f_name, $f_email, $f_msg ] ];
$res  = FormValidator::validate_schema( $flat );
check( '1a flat schema validates', $res === true, true, $cases, $passed, $failed );
$flat_flat = SchemaHelper::flatten_fields( $flat['fields'] );
check( '1b flatten leaves flat schema unchanged (count)', count( $flat_flat ), 3, $cases, $passed, $failed );
check( '1c flatten preserves order', array_column( $flat_flat, 'id' ), [ 'first_name', 'email', 'message' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Mixed schema: row(2 cols) with name+email + flat textarea.
// ---------------------------------------------------------------------------
$row = [
	'id'       => 'row_1',
	'type'     => 'row',
	'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
	'columns'  => [
		[ 'id' => 'col_1', 'fields' => [ $f_name ] ],
		[ 'id' => 'col_2', 'fields' => [ $f_email ] ],
	],
];
$mixed = [ 'fields' => [ $row, $f_msg ] ];
$res   = FormValidator::validate_schema( $mixed );
check( '2a row schema validates', $res === true, true, $cases, $passed, $failed );

$mixed_flat = SchemaHelper::flatten_fields( $mixed['fields'] );
check( '2b flatten yields 3 leaf fields',     count( $mixed_flat ), 3, $cases, $passed, $failed );
check( '2c flatten preserves source order',   array_column( $mixed_flat, 'id' ), [ 'first_name', 'email', 'message' ], $cases, $passed, $failed );
check( '2d flatten drops row container',      in_array( 'row_1', array_column( $mixed_flat, 'id' ), true ), false, $cases, $passed, $failed );
check( '2e flatten drops column container',   in_array( 'col_1', array_column( $mixed_flat, 'id' ), true ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Required validation inside a column.
// ---------------------------------------------------------------------------
// Required on first_name + email + message; submission only provides email.
$submitted = [ 'email' => 'a@b.com' ];
$flat_for_validation = SchemaHelper::flatten_fields( $mixed['fields'] );
// Required check: any required field whose value is missing is an error.
$missing_required = [];
foreach ( $flat_for_validation as $f ) {
	if ( ! empty( $f['required'] ) && ! array_key_exists( $f['id'], $submitted ) ) {
		$missing_required[] = $f['id'];
	}
}
check( '3 required validation reaches column-nested fields',
	$missing_required, [ 'first_name', 'message' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Nested rows are rejected.
// ---------------------------------------------------------------------------
$nested = [
	'fields' => [
		[
			'id' => 'row_outer', 'type' => 'row',
			'settings' => [ 'columns' => 1 ],
			'columns'  => [
				[ 'id' => 'col_outer', 'fields' => [
					[ 'id' => 'row_inner', 'type' => 'row', 'settings' => [ 'columns' => 1 ], 'columns' => [ [ 'id' => 'col_inner', 'fields' => [] ] ] ],
				] ],
			],
		],
	],
];
$res = FormValidator::validate_schema( $nested );
check( '4 nested rows are rejected', is_wp_error( $res ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Column count out of range rejected.
// ---------------------------------------------------------------------------
$bad_cols = [ 'fields' => [
	[ 'id' => 'r', 'type' => 'row', 'settings' => [ 'columns' => 8 ], 'columns' => [ [ 'id' => 'c', 'fields' => [] ] ] ],
] ];
$res = FormValidator::validate_schema( $bad_cols );
check( '5 8 columns is rejected', is_wp_error( $res ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Duplicate ids across row/column/field rejected.
// ---------------------------------------------------------------------------
$dup = [ 'fields' => [
	[ 'id' => 'shared', 'type' => 'row', 'settings' => [ 'columns' => 1 ], 'columns' => [
		[ 'id' => 'col_x', 'fields' => [ [ 'id' => 'shared', 'type' => 'text' ] ] ],
	] ],
] ];
$res = FormValidator::validate_schema( $dup );
check( '6 duplicate ids rejected', is_wp_error( $res ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Reducing column count never loses fields (PHP normalize_row).
// ---------------------------------------------------------------------------
$big = [
	'id' => 'r', 'type' => 'row', 'settings' => [ 'columns' => 4 ],
	'columns' => [
		[ 'id' => 'c1', 'fields' => [ [ 'id' => 'a', 'type' => 'text' ] ] ],
		[ 'id' => 'c2', 'fields' => [ [ 'id' => 'b', 'type' => 'text' ] ] ],
		[ 'id' => 'c3', 'fields' => [ [ 'id' => 'c', 'type' => 'text' ] ] ],
		[ 'id' => 'c4', 'fields' => [ [ 'id' => 'd', 'type' => 'text' ] ] ],
	],
];
$shrunk = SchemaHelper::normalize_row( $big, 2 );
check( '7a shrink keeps 2 columns',                 count( $shrunk['columns'] ), 2, $cases, $passed, $failed );
$last_fields = array_column( $shrunk['columns'][1]['fields'], 'id' );
check( '7b last column gets the dropped fields',    $last_fields, [ 'b', 'c', 'd' ], $cases, $passed, $failed );
check( '7c settings.columns updated',               $shrunk['settings']['columns'], 2, $cases, $passed, $failed );
$first_fields = array_column( $shrunk['columns'][0]['fields'], 'id' );
check( '7d first column unchanged',                 $first_fields, [ 'a' ], $cases, $passed, $failed );

// Growing.
$grown = SchemaHelper::normalize_row( $big, 4 );
$bigger = SchemaHelper::normalize_row( $shrunk, 4 );
check( '7e grow back to 4 columns',                 count( $bigger['columns'] ), 4, $cases, $passed, $failed );
check( '7f last column retains merged fields after grow (no second merge)',
	array_column( $bigger['columns'][1]['fields'], 'id' ),
	[ 'b', 'c', 'd' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. is_row identifies rows correctly.
// ---------------------------------------------------------------------------
check( '8a is_row on row',   SchemaHelper::is_row( $row ),    true,  $cases, $passed, $failed );
check( '8b is_row on field', SchemaHelper::is_row( $f_name ), false, $cases, $passed, $failed );
check( '8c is_row on null',  SchemaHelper::is_row( null ),    false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. walk() yields fields with row/col context.
// ---------------------------------------------------------------------------
$collected = [];
SchemaHelper::walk( $mixed['fields'], function ( $f, $ctx ) use ( &$collected ) {
	$collected[] = [ $f['id'], $ctx['row_id'], $ctx['col_index'] ];
} );
check( '9 walk emits all fields with context',
	$collected,
	[
		[ 'first_name', 'row_1', 0 ],
		[ 'email',      'row_1', 1 ],
		[ 'message',    '',     -1 ],
	],
	$cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. flatten_fields handles file + hidden inside columns.
// ---------------------------------------------------------------------------
$with_file = [ 'fields' => [
	[ 'id' => 'r', 'type' => 'row', 'settings' => [ 'columns' => 2 ], 'columns' => [
		[ 'id' => 'c1', 'fields' => [ $f_hidden, $f_file ] ],
		[ 'id' => 'c2', 'fields' => [ $f_phone ] ],
	] ],
] ];
$res = FormValidator::validate_schema( $with_file );
check( '10a row with hidden + file validates', $res === true, true, $cases, $passed, $failed );
$flat_with_file = SchemaHelper::flatten_fields( $with_file['fields'] );
check( '10b file field surfaces',     in_array( 'resume',  array_column( $flat_with_file, 'id' ), true ), true, $cases, $passed, $failed );
check( '10c hidden field surfaces',   in_array( 'utm_src', array_column( $flat_with_file, 'id' ), true ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. Empty row rejected.
// ---------------------------------------------------------------------------
$empty_row = [ 'fields' => [ [ 'id' => 'r', 'type' => 'row', 'settings' => [ 'columns' => 2 ], 'columns' => [] ] ] ];
$res = FormValidator::validate_schema( $empty_row );
check( '11 empty columns array rejected', is_wp_error( $res ), true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 12. Empty column ALLOWED (admin just spawned the row).
// ---------------------------------------------------------------------------
$empty_col = [ 'fields' => [
	[ 'id' => 'r', 'type' => 'row', 'settings' => [ 'columns' => 2 ], 'columns' => [
		[ 'id' => 'c1', 'fields' => [] ],
		[ 'id' => 'c2', 'fields' => [ $f_email ] ],
	] ],
] ];
$res = FormValidator::validate_schema( $empty_col );
check( '12 empty column accepted', $res === true, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
echo "\n";
foreach ( $cases as [ $ok, $label, $actual, $expected ] ) {
	$mark = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
	echo "$mark  $label\n";
	if ( ! $ok ) {
		echo "       expected: " . var_export( $expected, true ) . "\n";
		echo "       actual:   " . var_export( $actual, true ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed === 0 ? 0 : 1 );
