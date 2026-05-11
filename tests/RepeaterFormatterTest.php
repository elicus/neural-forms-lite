<?php
/**
 * Repeater display formatter tests (Commit 5).
 *
 * Covers RepeaterFormatter (label resolution, CSV column expansion,
 * cell-value resolution).
 *
 * Run from the plugin root:
 *
 *   php tests/RepeaterFormatterTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/RepeaterFormatter.php';

use NeuralForms\Forms\RepeaterFormatter;

// ─────────────────────── Harness ───────────────────────

$cases = []; $passed = 0; $failed = 0;
function nf_test( string $name, callable $fn ): void { global $cases; $cases[] = [ 'name' => $name, 'fn' => $fn ]; }
function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) throw new \RuntimeException( $msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true ) );
}
function nf_assert_true( $cond, string $msg ): void { if ( ! $cond ) throw new \RuntimeException( $msg ); }

function nf_repeater(): array {
	return [
		'id'       => 'team_members',
		'type'     => 'repeater',
		'label'    => 'Team Members',
		'settings' => [
			'min_items' => 1, 'max_items' => 4, 'columns' => 2,
			'add_button_label' => 'Add member', 'remove_button_label' => 'Remove member',
		],
		'fields' => [
			[ 'id' => 'name', 'type' => 'text',  'label' => 'Name' ],
			[ 'id' => 'role', 'type' => 'radio', 'label' => 'Role',
				'options' => [ [ 'label' => 'Developer', 'value' => 'developer' ], [ 'label' => 'Designer', 'value' => 'designer' ] ] ],
			[ 'id' => 'skills', 'type' => 'checkboxes', 'label' => 'Skills',
				'options' => [ [ 'label' => 'PHP', 'value' => 'php' ], [ 'label' => 'JavaScript', 'value' => 'js' ], [ 'label' => 'Design', 'value' => 'design' ] ] ],
			[ 'id' => 'resume', 'type' => 'file', 'label' => 'Resume' ],
		],
	];
}

// ─────────────────────── normalize_settings ───────────────────────

nf_test( 'normalize_settings: defaults fill in when settings missing', function () {
	$out = RepeaterFormatter::normalize_settings( [ 'id' => 'r', 'type' => 'repeater', 'label' => 'R' ] );
	nf_assert_same( 1, $out['min_items'],  'default min_items=1' );
	nf_assert_same( 5, $out['max_items'],  'default max_items=5' );
	nf_assert_same( 1, $out['columns'],    'default columns=1' );
	nf_assert_same( 'Add item', $out['add_button_label'], 'default add label' );
} );

nf_test( 'normalize_settings: columns clamped to 1..4', function () {
	$out = RepeaterFormatter::normalize_settings( [ 'settings' => [ 'columns' => 99 ] ] );
	nf_assert_same( 4, $out['columns'], 'columns capped at 4' );
} );

// ─────────────────────── format_value_lines ───────────────────────

nf_test( 'format_value_lines: text + radio + checkboxes + file resolve correctly', function () {
	$field = nf_repeater();
	$value = [
		[
			'name'   => 'Arif',
			'role'   => 'developer',
			'skills' => [ 'php', 'js' ],
			'resume' => [ 'attachment_id' => 1, 'url' => 'https://x/cv.pdf', 'filename' => 'cv.pdf', 'mime_type' => 'application/pdf', 'size' => 1234 ],
		],
		[
			'name'   => 'Sara',
			'role'   => 'designer',
			'skills' => [ 'design' ],
		],
	];
	$out = RepeaterFormatter::format_value_lines( $value, $field );
	nf_assert_same( 2, count( $out ), 'two items in, two out' );

	$first = $out[0]['child_lines'];
	nf_assert_same( 'Name',            $first[0]['child_label'], 'first child label is Name' );
	nf_assert_same( 'Arif',            $first[0]['display'],     'text display' );
	nf_assert_same( 'Role',            $first[1]['child_label'], 'second child label is Role' );
	nf_assert_same( 'Developer',       $first[1]['display'],     'radio value resolves to label' );
	nf_assert_same( 'PHP, JavaScript', $first[2]['display'],     'checkboxes joined' );
	nf_assert_same( 'cv.pdf',          $first[3]['display'],     'file shows filename' );

	$second = $out[1]['child_lines'];
	nf_assert_same( 'Designer', $second[1]['display'], 'second iteration radio label resolves' );
	nf_assert_same( 'Design',   $second[2]['display'], 'second iteration checkboxes joins single pick' );
} );

nf_test( 'format_value_lines: unknown child key in wire is ignored', function () {
	$field = nf_repeater();
	$out = RepeaterFormatter::format_value_lines( [ [ 'name' => 'Arif', 'evil' => 'payload' ] ], $field );
	$child_ids = array_column( $out[0]['child_lines'], 'child_id' );
	nf_assert_true( ! in_array( 'evil', $child_ids, true ), 'unknown child must not appear' );
} );

nf_test( 'format_value_lines: child with no stored value gets empty display', function () {
	$field = nf_repeater();
	$out = RepeaterFormatter::format_value_lines( [ [ 'name' => 'Arif' ] ], $field );
	// Find the role line.
	foreach ( $out[0]['child_lines'] as $cl ) {
		if ( $cl['child_id'] === 'role' ) {
			nf_assert_same( '', $cl['display'], 'absent role child renders empty' );
			return;
		}
	}
	throw new \RuntimeException( 'role line missing from output' );
} );

nf_test( 'child_display_value: checkbox truthy → Yes, falsy → No', function () {
	$cb = [ 'id' => 'x', 'type' => 'checkbox', 'label' => 'Agree' ];
	nf_assert_same( 'Yes', RepeaterFormatter::child_display_value( $cb, 1 ), 'checkbox 1 → Yes' );
	nf_assert_same( 'Yes', RepeaterFormatter::child_display_value( $cb, '1' ), 'checkbox "1" → Yes' );
	nf_assert_same( 'No',  RepeaterFormatter::child_display_value( $cb, 0 ), 'checkbox 0 → No' );
	nf_assert_same( 'No',  RepeaterFormatter::child_display_value( $cb, '' ), 'checkbox "" → No' );
} );

nf_test( 'child_display_value: radio unknown value falls back to the value string', function () {
	$child = [ 'id' => 'role', 'type' => 'radio',
		'options' => [ [ 'label' => 'A', 'value' => 'a' ], [ 'label' => 'B', 'value' => 'b' ] ],
	];
	nf_assert_same( 'unknown_value', RepeaterFormatter::child_display_value( $child, 'unknown_value' ), 'radio falls back to raw value when no option matches' );
} );

// ─────────────────────── csv_columns ───────────────────────

nf_test( 'csv_columns: emits one column per (item, child) up to max_items', function () {
	$field = nf_repeater();
	$cols  = RepeaterFormatter::csv_columns( $field );
	// 4 items × (3 non-file children + 2 file sub-columns) = 4 × 5 = 20 cols.
	nf_assert_same( 20, count( $cols ), 'expected 4 items × 5 sub-columns = 20' );
} );

nf_test( 'csv_columns: file children produce __filename + __url sub-columns', function () {
	$field = nf_repeater();
	$cols  = RepeaterFormatter::csv_columns( $field );
	$headers = array_column( $cols, 'header' );
	nf_assert_true( in_array( 'team_members__1__resume_filename', $headers, true ),
		'file __filename header must appear' );
	nf_assert_true( in_array( 'team_members__1__resume_url', $headers, true ),
		'file __url header must appear' );
} );

nf_test( 'csv_columns: max_items beyond 20 is hard-capped', function () {
	$field = nf_repeater();
	$field['settings']['max_items'] = 100;
	$cols = RepeaterFormatter::csv_columns( $field );
	// 20 items × 5 sub-columns = 100 columns at the cap.
	nf_assert_same( 100, count( $cols ), 'max_items > 20 must clamp to 20' );
} );

// ─────────────────────── csv_row_value ───────────────────────

nf_test( 'csv_row_value: text/radio/checkboxes resolve labels', function () {
	$field = nf_repeater();
	$value = [
		[ 'name' => 'Arif', 'role' => 'developer', 'skills' => [ 'php', 'js' ] ],
	];
	nf_assert_same( 'Arif',            RepeaterFormatter::csv_row_value( $value, 0, $field['fields'][0], 'value' ), 'text csv cell' );
	nf_assert_same( 'Developer',       RepeaterFormatter::csv_row_value( $value, 0, $field['fields'][1], 'value' ), 'radio csv cell' );
	nf_assert_same( 'PHP, JavaScript', RepeaterFormatter::csv_row_value( $value, 0, $field['fields'][2], 'value' ), 'checkboxes csv cell' );
} );

nf_test( 'csv_row_value: file filename / url split into sub-columns', function () {
	$field = nf_repeater();
	$file_child = $field['fields'][3];
	$value = [
		[ 'resume' => [ 'attachment_id' => 1, 'url' => 'https://x/cv.pdf', 'filename' => 'cv.pdf', 'mime_type' => 'application/pdf', 'size' => 1234 ] ],
	];
	nf_assert_same( 'cv.pdf',             RepeaterFormatter::csv_row_value( $value, 0, $file_child, 'filename' ), 'file filename sub-cell' );
	nf_assert_same( 'https://x/cv.pdf',   RepeaterFormatter::csv_row_value( $value, 0, $file_child, 'url' ), 'file url sub-cell' );
} );

nf_test( 'csv_row_value: missing item returns empty string', function () {
	$field = nf_repeater();
	nf_assert_same( '', RepeaterFormatter::csv_row_value( [], 0, $field['fields'][0], 'value' ), 'empty value list → empty cell' );
	nf_assert_same( '', RepeaterFormatter::csv_row_value( [ [ 'name' => 'A' ] ], 3, $field['fields'][0], 'value' ),
		'item index past data returns empty' );
} );

// ─────────────────────── Run ───────────────────────

foreach ( $cases as $case ) {
	try { ( $case['fn'] )(); echo "PASS  " . $case['name'] . PHP_EOL; $passed++; }
	catch ( \Throwable $e ) { echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL; $failed++; }
}
echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit( $failed === 0 ? 0 : 1 );
