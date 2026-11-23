<?php
/**
 * Canonical row/column schema tests (1.1.0).
 *
 * Locks the lenient-read normalizer contract:
 *
 *   - Existing canonical schemas pass through untouched.
 *   - Old flat schemas pass through untouched.
 *   - row.layout.{columns,gap,alignment}, row.gap, and row.columns
 *     as a number are folded into row.settings on normalize.
 *   - Columns with missing id/fields get sane defaults.
 *   - Nested rows inside columns are dropped on normalize.
 *   - Layout containers never produce real-field entries.
 *   - is_real_field / is_column / is_layout_node truth tables.
 *
 * Run from the plugin root:
 *
 *   php tests/CanonicalSchemaTest.php
 *
 * Side-by-side with RowSchemaTest.php and CompatibilityTest.php.
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
if ( ! function_exists( 'is_wp_error' ) )    { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/ConditionEngine.php';
require_once $plugin_root . '/modules/forms/FormValidator.php';

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\FormValidator;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// ---------------------------------------------------------------------------
// Predicates
// ---------------------------------------------------------------------------
$row    = [ 'id' => 'row_x', 'type' => 'row', 'settings' => [ 'columns' => 1 ], 'columns' => [] ];
$col    = [ 'id' => 'col_x', 'fields' => [] ];
$colT   = [ 'id' => 'col_y', 'type' => 'column', 'fields' => [] ];
$field  = [ 'id' => 'first_name', 'type' => 'text', 'label' => 'First name' ];

check( 'P1 is_row on row',                    SchemaHelper::is_row( $row ),         true,  $cases, $passed, $failed );
check( 'P2 is_row on field',                  SchemaHelper::is_row( $field ),       false, $cases, $passed, $failed );
check( 'P3 is_column on shape match',         SchemaHelper::is_column( $col ),      true,  $cases, $passed, $failed );
check( 'P4 is_column on type=column',         SchemaHelper::is_column( $colT ),     true,  $cases, $passed, $failed );
check( 'P5 is_column on real field',          SchemaHelper::is_column( $field ),    false, $cases, $passed, $failed );
check( 'P6 is_layout_node on row',            SchemaHelper::is_layout_node( $row ), true,  $cases, $passed, $failed );
check( 'P7 is_layout_node on column',         SchemaHelper::is_layout_node( $col ), true,  $cases, $passed, $failed );
check( 'P8 is_real_field on field',           SchemaHelper::is_real_field( $field ),true,  $cases, $passed, $failed );
check( 'P9 is_real_field on row',             SchemaHelper::is_real_field( $row ),  false, $cases, $passed, $failed );
check( 'P10 is_real_field on column',         SchemaHelper::is_real_field( $col ),  false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 1. Old flat schema is a no-op through normalize_schema.
// ---------------------------------------------------------------------------
$flat_in = [ 'fields' => [
	[ 'id' => 'first_name', 'type' => 'text',  'label' => 'First' ],
	[ 'id' => 'email',      'type' => 'email', 'label' => 'Email' ],
] ];
$flat_out = SchemaHelper::normalize_schema( $flat_in );
check( '1 flat schema field count unchanged', count( $flat_out['fields'] ), 2, $cases, $passed, $failed );
check( '1 flat schema first field unchanged', $flat_out['fields'][0],       $flat_in['fields'][0],  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Canonical row schema passes through unchanged.
// ---------------------------------------------------------------------------
$canonical = [ 'fields' => [
	[
		'id' => 'row_a', 'type' => 'row',
		'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
		'columns'  => [
			[ 'id' => 'col_1', 'fields' => [ [ 'id' => 'a', 'type' => 'text' ] ] ],
			[ 'id' => 'col_2', 'fields' => [ [ 'id' => 'b', 'type' => 'text' ] ] ],
		],
	],
] ];
$canon_out = SchemaHelper::normalize_schema( $canonical );
check( '2 canonical row settings.columns', $canon_out['fields'][0]['settings']['columns'], 2,        $cases, $passed, $failed );
check( '2 canonical row settings.gap',     $canon_out['fields'][0]['settings']['gap'],     'medium', $cases, $passed, $failed );
check( '2 canonical row column count',     count( $canon_out['fields'][0]['columns'] ),    2,        $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. row.layout.{columns,gap,alignment} folds into settings.
// ---------------------------------------------------------------------------
$with_layout = [ 'fields' => [
	[
		'id' => 'row_l', 'type' => 'row',
		'layout'  => [ 'columns' => 3, 'gap' => 'large', 'alignment' => 'center' ],
		'columns' => [
			[ 'id' => 'c1', 'fields' => [] ],
			[ 'id' => 'c2', 'fields' => [] ],
			[ 'id' => 'c3', 'fields' => [] ],
		],
	],
] ];
$layout_out = SchemaHelper::normalize_schema( $with_layout )['fields'][0];
check( '3 layout.columns folded',     $layout_out['settings']['columns'],   3,        $cases, $passed, $failed );
check( '3 layout.gap folded',         $layout_out['settings']['gap'],       'large',  $cases, $passed, $failed );
check( '3 layout.alignment folded',   $layout_out['settings']['alignment'], 'center', $cases, $passed, $failed );
check( '3 layout key dropped',        array_key_exists( 'layout', $layout_out ),       false,   $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. row.gap top-level folds into settings.gap.
// ---------------------------------------------------------------------------
$with_top_gap = [ 'fields' => [
	[
		'id' => 'row_g', 'type' => 'row',
		'gap' => 'small',
		'settings' => [ 'columns' => 1 ],
		'columns'  => [ [ 'id' => 'c1', 'fields' => [] ] ],
	],
] ];
$top_gap_out = SchemaHelper::normalize_schema( $with_top_gap )['fields'][0];
check( '4 top gap folded into settings', $top_gap_out['settings']['gap'], 'small', $cases, $passed, $failed );
check( '4 top gap key dropped',          array_key_exists( 'gap', $top_gap_out ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. row.columns as a number spawns N empty columns.
// ---------------------------------------------------------------------------
$cols_as_int = [ 'fields' => [
	[ 'id' => 'row_n', 'type' => 'row', 'columns' => 4 ],
] ];
$int_out = SchemaHelper::normalize_schema( $cols_as_int )['fields'][0];
check( '5 numeric columns spawned',  count( $int_out['columns'] ),         4, $cases, $passed, $failed );
check( '5 numeric columns -> settings', $int_out['settings']['columns'],   4, $cases, $passed, $failed );
$col_ids = array_column( $int_out['columns'], 'id' );
$all_have_ids = count( array_filter( $col_ids, 'strlen' ) ) === 4;
check( '5 every spawned column has id', $all_have_ids, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Missing column id is generated; missing fields defaults to [].
// ---------------------------------------------------------------------------
$bare_cols = [ 'fields' => [
	[
		'id' => 'row_b', 'type' => 'row',
		'settings' => [ 'columns' => 2 ],
		'columns'  => [
			[ /* no id, no fields */ ],
			[ 'id' => 'col_keep' /* no fields */ ],
		],
	],
] ];
$bare_out = SchemaHelper::normalize_schema( $bare_cols )['fields'][0]['columns'];
check( '6 bare column fields defaulted',     $bare_out[0]['fields'], [], $cases, $passed, $failed );
check( '6 bare column id generated',         is_string( $bare_out[0]['id'] ) && $bare_out[0]['id'] !== '', true, $cases, $passed, $failed );
check( '6 explicit column id preserved',     $bare_out[1]['id'], 'col_keep', $cases, $passed, $failed );
check( '6 explicit column fields defaulted', $bare_out[1]['fields'], [], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Nested row inside a column is dropped on normalize.
// ---------------------------------------------------------------------------
$nested = [ 'fields' => [
	[
		'id' => 'row_outer', 'type' => 'row',
		'settings' => [ 'columns' => 1 ],
		'columns'  => [ [ 'id' => 'c1', 'fields' => [
			[ 'id' => 'name', 'type' => 'text' ],
			[ 'id' => 'row_inner', 'type' => 'row', 'settings' => [ 'columns' => 1 ], 'columns' => [] ],
		] ] ],
	],
] ];
$nested_out = SchemaHelper::normalize_schema( $nested )['fields'][0]['columns'][0]['fields'];
check( '7 nested row dropped from column', count( $nested_out ), 1, $cases, $passed, $failed );
check( '7 only real field remains',        $nested_out[0]['id'], 'name', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Out-of-range columns clamp to MIN/MAX.
// ---------------------------------------------------------------------------
$too_many = [ 'fields' => [
	[ 'id' => 'row_m', 'type' => 'row', 'settings' => [ 'columns' => 99 ], 'columns' => [
		[ 'id' => 'c1', 'fields' => [] ],
	] ],
] ];
$clamp_out = SchemaHelper::normalize_schema( $too_many )['fields'][0];
check( '8 columns clamped to MAX',     $clamp_out['settings']['columns'],   SchemaHelper::MAX_COLUMNS, $cases, $passed, $failed );
check( '8 columns array padded to MAX', count( $clamp_out['columns'] ),     SchemaHelper::MAX_COLUMNS, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. column.width is preserved as a passthrough.
// ---------------------------------------------------------------------------
$with_width = [ 'fields' => [
	[
		'id' => 'row_w', 'type' => 'row',
		'settings' => [ 'columns' => 2 ],
		'columns'  => [
			[ 'id' => 'c1', 'fields' => [], 'width' => 60 ],
			[ 'id' => 'c2', 'fields' => [], 'width' => 40 ],
		],
	],
] ];
$width_out = SchemaHelper::normalize_schema( $with_width )['fields'][0]['columns'];
check( '9 column.width preserved (1)', $width_out[0]['width'], 60, $cases, $passed, $failed );
check( '9 column.width preserved (2)', $width_out[1]['width'], 40, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. flatten_fields after normalize returns real fields only.
// ---------------------------------------------------------------------------
$mixed = [ 'fields' => [
	[ 'id' => 'a', 'type' => 'text' ],
	[
		'id' => 'row_x', 'type' => 'row',
		'layout'  => [ 'columns' => 2 ],
		'columns' => [
			[ 'id' => 'c1', 'fields' => [ [ 'id' => 'b', 'type' => 'email' ] ] ],
			[ 'id' => 'c2', 'fields' => [ [ 'id' => 'c', 'type' => 'textarea' ] ] ],
		],
	],
] ];
$mixed_norm = SchemaHelper::normalize_schema( $mixed );
$flat       = SchemaHelper::flatten_fields( $mixed_norm['fields'] );
check( '10 flat field count',          count( $flat ), 3, $cases, $passed, $failed );
check( '10 flat ids in source order',  array_column( $flat, 'id' ), [ 'a', 'b', 'c' ], $cases, $passed, $failed );
check( '10 no row id leaks',           in_array( 'row_x', array_column( $flat, 'id' ), true ), false, $cases, $passed, $failed );
check( '10 no column id leaks',        in_array( 'c1',    array_column( $flat, 'id' ), true ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 11. Output of normalize_schema validates.
// ---------------------------------------------------------------------------
$valid = FormValidator::validate_schema( $mixed_norm );
check( '11 normalized schema validates', $valid === true, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 12. Every restored field-palette type is treated as a real field.
//     The sidebar exposes 15 type slugs; each must:
//       - return is_real_field() true
//       - return is_row()/is_column() false
//       - survive flatten_fields() (presence + order)
//       - never be stripped by EntryRepository::strip_layout_keys()
// ---------------------------------------------------------------------------
$palette_slugs = [
	'text', 'email', 'textarea', 'number', 'phone', 'url',
	'select', 'radio', 'checkbox', 'checkboxes',
	'date', 'acceptance', 'range', 'file', 'hidden',
];

$palette_fields = [];
foreach ( $palette_slugs as $i => $slug ) {
	$palette_fields[] = [ 'id' => $slug . '_' . $i, 'type' => $slug, 'label' => $slug ];
}

$all_real     = true;
$none_layout  = true;
foreach ( $palette_fields as $f ) {
	if ( ! SchemaHelper::is_real_field( $f ) )       $all_real    = false;
	if ( SchemaHelper::is_row( $f ) || SchemaHelper::is_column( $f ) ) $none_layout = false;
}
check( '12 every palette type is_real_field',  $all_real,    true, $cases, $passed, $failed );
check( '12 no palette type registers as layout', $none_layout, true, $cases, $passed, $failed );

$flat = SchemaHelper::flatten_fields( $palette_fields );
check( '12 flatten preserves count',           count( $flat ),                 count( $palette_slugs ), $cases, $passed, $failed );
check( '12 flatten preserves slug order',      array_column( $flat, 'type' ),  $palette_slugs,          $cases, $passed, $failed );

// Layout-key strip must NOT touch any of these (none start with row_/col_,
// none have type==='row'). Build a synthetic "submission data" map keyed
// by field id and confirm it round-trips through strip_layout_keys.
require_once $plugin_root . '/modules/storage/EntryRepository.php';
$submission = [];
foreach ( $palette_fields as $f ) $submission[ $f['id'] ] = 'sample';
$strip = new ReflectionMethod( \NeuralForms\Storage\EntryRepository::class, 'strip_layout_keys' );
$strip->setAccessible( true );
$cleaned = $strip->invoke( null, $submission );
check( '12 strip_layout_keys keeps every palette field', $cleaned, $submission, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 13. Schema after the "add field into selected column" Builder flow.
//     Mirrors the JS: clone fields, splice the new field into target
//     column.fields. Locks the invariants the new flow must preserve:
//       - other columns untouched
//       - root fields untouched
//       - row settings untouched
//       - flatten preserves visual order (root, then row's columns
//         left-to-right, fields top-to-bottom)
//       - id namespace is unique across root + columns
// ---------------------------------------------------------------------------
function nf_add_field_to_column( array $fields, string $col_id, array $new_field ): array {
	for ( $i = 0; $i < count( $fields ); $i++ ) {
		$n = $fields[ $i ];
		if ( ( $n['type'] ?? '' ) !== 'row' ) continue;
		$cols = $n['columns'] ?? [];
		for ( $c = 0; $c < count( $cols ); $c++ ) {
			if ( ( $cols[ $c ]['id'] ?? '' ) === $col_id ) {
				$existing = $cols[ $c ]['fields'] ?? [];
				$cols[ $c ]['fields'] = array_merge( $existing, [ $new_field ] );
				$n['columns'] = $cols;
				$fields[ $i ] = $n;
				return $fields;
			}
		}
	}
	return $fields;
}

$before = [
	[ 'id' => 'root_intro', 'type' => 'text', 'label' => 'Intro' ],
	[
		'id' => 'row_main', 'type' => 'row',
		'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
		'columns'  => [
			[ 'id' => 'col_left',  'fields' => [
				[ 'id' => 'first_name', 'type' => 'text', 'label' => 'First name' ],
			] ],
			[ 'id' => 'col_right', 'fields' => [] ],
		],
	],
	[ 'id' => 'root_outro', 'type' => 'textarea', 'label' => 'Notes' ],
];

$new = [ 'id' => 'last_name', 'type' => 'text', 'label' => 'Last name' ];
$after = nf_add_field_to_column( $before, 'col_right', $new );

// Other columns / root fields preserved.
check( '13 root field before row preserved',     $after[0]['id'],                      'root_intro',  $cases, $passed, $failed );
check( '13 root field after row preserved',      $after[2]['id'],                      'root_outro',  $cases, $passed, $failed );
check( '13 left column fields untouched',        $after[1]['columns'][0]['fields'][0]['id'], 'first_name', $cases, $passed, $failed );
check( '13 left column field count unchanged',   count( $after[1]['columns'][0]['fields'] ), 1, $cases, $passed, $failed );

// New field landed in the right column.
check( '13 right column now has the new field',  $after[1]['columns'][1]['fields'][0]['id'], 'last_name', $cases, $passed, $failed );
check( '13 right column field count is 1',       count( $after[1]['columns'][1]['fields'] ), 1, $cases, $passed, $failed );

// Row settings preserved.
check( '13 row.settings.columns preserved',      $after[1]['settings']['columns'],     2,             $cases, $passed, $failed );
check( '13 row.settings.gap preserved',          $after[1]['settings']['gap'],         'medium',      $cases, $passed, $failed );

// Flatten preserves visual order: root_intro, first_name (col_left),
// last_name (col_right), root_outro.
$flat_after = SchemaHelper::flatten_fields( $after );
check( '13 flatten preserves visual order',
	array_column( $flat_after, 'id' ),
	[ 'root_intro', 'first_name', 'last_name', 'root_outro' ],
	$cases, $passed, $failed );

// IDs across root + columns are still unique.
$all_ids = [
	$after[0]['id'],
	$after[1]['id'],
	$after[1]['columns'][0]['id'], $after[1]['columns'][0]['fields'][0]['id'],
	$after[1]['columns'][1]['id'], $after[1]['columns'][1]['fields'][0]['id'],
	$after[2]['id'],
];
check( '13 every id in the form is unique',      count( $all_ids ) === count( array_unique( $all_ids ) ), true, $cases, $passed, $failed );

// Validator accepts the new shape.
$validator_result = FormValidator::validate_schema( [ 'fields' => $after ] );
check( '13 add-to-column result validates',      $validator_result === true, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 14. Conditional Logic schema (v1.0.0 canonical) survives every
//     pipeline stage: validate, flatten, normalize, layout strip.
//     Schema shape:
//       field.conditional_logic = {
//         enabled, field, operator, value
//       }
//     Operators: equals | not_equals | contains | empty | not_empty
// ---------------------------------------------------------------------------
$cl_schema = [ 'fields' => [
	[ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ],
	[
		'id' => 'row_main', 'type' => 'row',
		'settings' => [ 'columns' => 2, 'gap' => 'medium' ],
		'columns'  => [
			[ 'id' => 'col_left', 'fields' => [
				[
					'id' => 'newsletter', 'type' => 'checkbox', 'label' => 'Subscribe?',
				],
			] ],
			[ 'id' => 'col_right', 'fields' => [
				[
					'id'    => 'newsletter_freq',
					'type'  => 'select',
					'label' => 'Frequency',
					'options' => [
						[ 'value' => 'weekly',  'label' => 'Weekly'  ],
						[ 'value' => 'monthly', 'label' => 'Monthly' ],
					],
					'conditional_logic' => [
						'enabled'  => true,
						'field'    => 'newsletter',
						'operator' => 'equals',
						'value'    => '1',
					],
				],
			] ],
		],
	],
] ];

$cl_valid = FormValidator::validate_schema( $cl_schema );
check( '14 schema with CL validates',                   $cl_valid === true, true, $cases, $passed, $failed );

$cl_norm = SchemaHelper::normalize_schema( $cl_schema );
$cl_field_after_norm = $cl_norm['fields'][1]['columns'][1]['fields'][0];
check( '14 normalize preserves CL.enabled',             $cl_field_after_norm['conditional_logic']['enabled'],  true,         $cases, $passed, $failed );
check( '14 normalize preserves CL.field',               $cl_field_after_norm['conditional_logic']['field'],    'newsletter', $cases, $passed, $failed );
check( '14 normalize preserves CL.operator',            $cl_field_after_norm['conditional_logic']['operator'], 'equals',     $cases, $passed, $failed );
check( '14 normalize preserves CL.value',               $cl_field_after_norm['conditional_logic']['value'],    '1',          $cases, $passed, $failed );

$cl_flat = SchemaHelper::flatten_fields( $cl_schema['fields'] );
$cl_flat_ids = array_column( $cl_flat, 'id' );
check( '14 flatten emits the field that has CL',        in_array( 'newsletter_freq', $cl_flat_ids, true ), true, $cases, $passed, $failed );
check( '14 flatten emits the source field too',         in_array( 'newsletter',      $cl_flat_ids, true ), true, $cases, $passed, $failed );

// Source-list filter mirror: builder excludes the current field, hidden
// fields, and rows/columns from the CL source dropdown.
function nf_cl_sources( array $nodes, string $self_id ): array {
	$leaves = SchemaHelper::flatten_fields( $nodes );
	$out = [];
	foreach ( $leaves as $f ) {
		if ( ! is_array( $f ) ) continue;
		if ( ! isset( $f['id'] ) || $f['id'] === '' ) continue;
		if ( $f['id'] === $self_id ) continue;
		if ( ( $f['type'] ?? '' ) === 'hidden' ) continue;
		$out[] = $f['id'];
	}
	return $out;
}

$source_ids = nf_cl_sources( $cl_schema['fields'], 'newsletter_freq' );
check( '14 source list excludes self',                  in_array( 'newsletter_freq', $source_ids, true ), false, $cases, $passed, $failed );
check( '14 source list includes a sibling field',       in_array( 'email',           $source_ids, true ), true,  $cases, $passed, $failed );
check( '14 source list includes nested-in-column field', in_array( 'newsletter',      $source_ids, true ), true,  $cases, $passed, $failed );

// Add a hidden field and confirm it's excluded.
$cl_schema_with_hidden = $cl_schema;
$cl_schema_with_hidden['fields'][] = [ 'id' => 'utm_src', 'type' => 'hidden', 'default_value' => 'organic' ];
$source_ids_h = nf_cl_sources( $cl_schema_with_hidden['fields'], 'newsletter_freq' );
check( '14 source list excludes hidden fields',         in_array( 'utm_src', $source_ids_h, true ), false, $cases, $passed, $failed );

// Layout-key strip never touches conditional_logic state on entries.
// Build a synthetic submission and confirm CL-bearing fields land in the
// stored data (the strip helper only cares about row_/col_ keys).
require_once $plugin_root . '/modules/storage/EntryRepository.php';
$strip2 = new ReflectionMethod( \NeuralForms\Storage\EntryRepository::class, 'strip_layout_keys' );
$strip2->setAccessible( true );
$cl_submission = [
	'email'           => 'a@b.test',
	'newsletter'      => '1',
	'newsletter_freq' => 'weekly',
	'row_main'        => [ 'type' => 'row' ],
	'col_left'        => [ 'fields' => [] ],
];
$cl_cleaned = $strip2->invoke( null, $cl_submission );
check( '14 entry strip keeps CL-bearing field value',   $cl_cleaned['newsletter_freq'] ?? null, 'weekly', $cases, $passed, $failed );
check( '14 entry strip drops row_ key',                 array_key_exists( 'row_main', $cl_cleaned ), false, $cases, $passed, $failed );
check( '14 entry strip drops col_ key',                 array_key_exists( 'col_left', $cl_cleaned ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
echo "\n";
foreach ( $cases as [ $ok, $label, $actual, $expected ] ) {
	$mark = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
	echo "$mark  $label\n";
	if ( ! $ok ) {
		echo "       expected: " . var_export( $expected, true ) . "\n";
		echo "       actual:   " . var_export( $actual,   true ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed === 0 ? 0 : 1 );
