<?php
/**
 * Matrix display formatter tests (Phase 5).
 *
 * Covers MatrixFormatter (label resolution, CSV expansion) and the
 * NotificationService email-summary branch via the reused helper.
 * The CSV ExportEndpoint integration is exercised through
 * MatrixFormatter::csv_columns + csv_row_value directly, which is
 * how the endpoint composes its rows.
 *
 * Run from the plugin root:
 *
 *   php tests/MatrixFormatterTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op once the line above defines ABSPATH).
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
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/MatrixFormatter.php';

use NeuralForms\Forms\MatrixFormatter;

$cases  = [];
$passed = 0;
$failed = 0;

function nf_test( string $name, callable $fn ): void {
	global $cases;
	$cases[] = [ 'name' => $name, 'fn' => $fn ];
}

function nf_assert_same( $expected, $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			$msg . ' — expected: ' . var_export( $expected, true ) . ' actual: ' . var_export( $actual, true )
		);
	}
}

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

function nf_radio_field(): array {
	return [
		'id'       => 'satisfaction',
		'type'     => 'matrix',
		'label'    => 'How satisfied are you with',
		'settings' => [
			'selection_type' => 'radio',
			'rows' => [
				[ 'label' => 'Purchase',         'value' => 'purchase' ],
				[ 'label' => 'Service',          'value' => 'service'  ],
				[ 'label' => 'Company Overall',  'value' => 'overall'  ],
			],
			'columns' => [
				[ 'label' => 'Very Satisfied', 'value' => 'very_satisfied', 'score' => 5 ],
				[ 'label' => 'Satisfied',      'value' => 'satisfied',      'score' => 3 ],
				[ 'label' => 'Neutral',        'value' => 'neutral',        'score' => 0 ],
			],
		],
	];
}

function nf_checkbox_field(): array {
	return [
		'id'       => 'features',
		'type'     => 'matrix',
		'label'    => 'Features used',
		'settings' => [
			'selection_type' => 'checkbox',
			'rows' => [
				[ 'label' => 'Purchase', 'value' => 'purchase' ],
				[ 'label' => 'Service',  'value' => 'service'  ],
			],
			'columns' => [
				[ 'label' => 'Email',         'value' => 'email',         'score' => 1 ],
				[ 'label' => 'Phone',         'value' => 'phone',         'score' => 1 ],
				[ 'label' => 'Documentation', 'value' => 'documentation', 'score' => 1 ],
			],
		],
	];
}

// ---------------------------------------------------------------------
// Display formatter
// ---------------------------------------------------------------------

nf_test( 'radio_maps_stored_values_to_labels', function () {
	$out = MatrixFormatter::format_value_lines(
		[ 'purchase' => 'very_satisfied', 'service' => 'satisfied' ],
		nf_radio_field()
	);
	nf_assert_same(
		[
			[ 'row_value' => 'purchase', 'row_label' => 'Purchase',        'display' => 'Very Satisfied' ],
			[ 'row_value' => 'service',  'row_label' => 'Service',         'display' => 'Satisfied' ],
			[ 'row_value' => 'overall',  'row_label' => 'Company Overall', 'display' => '' ],
		],
		$out,
		'radio mapping should resolve labels and leave unfilled rows empty'
	);
} );

nf_test( 'checkbox_joins_column_labels_per_row', function () {
	$out = MatrixFormatter::format_value_lines(
		[
			'purchase' => [ 'email', 'phone' ],
			'service'  => [ 'documentation' ],
		],
		nf_checkbox_field()
	);
	nf_assert_same(
		[
			[ 'row_value' => 'purchase', 'row_label' => 'Purchase', 'display' => 'Email, Phone' ],
			[ 'row_value' => 'service',  'row_label' => 'Service',  'display' => 'Documentation' ],
		],
		$out,
		'checkbox mapping should join labels with ", "'
	);
} );

nf_test( 'unknown_row_in_stored_value_is_ignored', function () {
	$out = MatrixFormatter::format_value_lines(
		[
			'purchase'    => 'very_satisfied',
			'unknown_row' => 'satisfied', // not in schema
		],
		nf_radio_field()
	);
	$row_values = array_column( $out, 'row_value' );
	nf_assert_same(
		[ 'purchase', 'service', 'overall' ],
		$row_values,
		'output should follow schema-row order, ignoring unknown stored row'
	);
} );

nf_test( 'unknown_column_falls_back_to_raw_value', function () {
	$out = MatrixFormatter::format_value_lines(
		[ 'purchase' => 'something_off_schema' ],
		nf_radio_field()
	);
	nf_assert_same(
		'something_off_schema',
		$out[0]['display'],
		'unknown column should fall back to the stored value rather than fatal'
	);
} );

nf_test( 'non_array_stored_value_renders_all_rows_empty', function () {
	$out = MatrixFormatter::format_value_lines( 'tampered', nf_radio_field() );
	$displays = array_column( $out, 'display' );
	nf_assert_same( [ '', '', '' ], $displays, 'non-array value should render all rows empty' );
} );

// ---------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------

nf_test( 'csv_columns_emits_one_header_per_row', function () {
	$cols = MatrixFormatter::csv_columns( nf_radio_field() );
	$headers = array_column( $cols, 'header' );
	nf_assert_same(
		[ 'satisfaction__purchase', 'satisfaction__service', 'satisfaction__overall' ],
		$headers,
		'csv headers should be field_id__row_value'
	);
} );

nf_test( 'csv_row_value_radio_returns_column_label', function () {
	$cell = MatrixFormatter::csv_row_value(
		[ 'purchase' => 'very_satisfied' ],
		nf_radio_field(),
		'purchase'
	);
	nf_assert_same( 'Very Satisfied', $cell, 'radio csv cell should be column label' );
} );

nf_test( 'csv_row_value_checkbox_joins_labels', function () {
	$cell = MatrixFormatter::csv_row_value(
		[ 'purchase' => [ 'email', 'phone' ] ],
		nf_checkbox_field(),
		'purchase'
	);
	nf_assert_same( 'Email, Phone', $cell, 'checkbox csv cell should join labels with ", "' );
} );

nf_test( 'csv_row_value_missing_row_returns_empty_string', function () {
	$cell = MatrixFormatter::csv_row_value( [], nf_radio_field(), 'purchase' );
	nf_assert_same( '', $cell, 'missing row should export empty string' );
} );

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------

foreach ( $cases as $case ) {
	try {
		( $case['fn'] )();
		echo "PASS  " . $case['name'] . PHP_EOL;
		$passed++;
	} catch ( \Throwable $e ) {
		echo "FAIL  " . $case['name'] . ' — ' . $e->getMessage() . PHP_EOL;
		$failed++;
	}
}

echo PHP_EOL;
echo $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;

exit( $failed === 0 ? 0 : 1 );
