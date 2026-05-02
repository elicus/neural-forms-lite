<?php
/**
 * Quiz aggregation tests (Phase 2).
 *
 * Targets EntryRepository::aggregate_quiz_answers() — the data-layer
 * helper used by GET /forms/{id}/quiz to compute per-option answer
 * counts and per-entry score input.
 *
 * Run from the plugin root:
 *
 *   php tests/QuizResultsAggregationTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );

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
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) )    { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )    { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } }
if ( ! function_exists( 'current_time' ) )    { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }

class NF_Quiz_Agg_WPDB {
	public string $prefix = 'wp_';
	public array  $rows   = [];
	public function prepare( $sql, ...$args ) { return [ 'sql' => $sql, 'args' => $args ]; }
	public function get_col( $query ) {
		[ , $form_id, $status_filter ] = $query['args'];
		$out = [];
		foreach ( $this->rows as $r ) {
			if ( (int) $r['form_id'] !== (int) $form_id ) continue;
			if ( $r['status'] === $status_filter ) continue;
			$out[] = $r['data'];
		}
		return $out;
	}
}
$GLOBALS['wpdb'] = new NF_Quiz_Agg_WPDB();

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';

use NeuralForms\Storage\EntryRepository;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

$wpdb = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Bad inputs short-circuit cleanly.
// ---------------------------------------------------------------------------
check( '1a form_id<=0 returns zero shape',
	EntryRepository::aggregate_quiz_answers( 0, [ 'q1' ] ),
	[ 'rows' => [], 'counts' => [], 'totals' => [], 'entry_count' => 0 ],
	$cases, $passed, $failed );

check( '1b empty field list returns zero',
	EntryRepository::aggregate_quiz_answers( 12, [] ),
	[ 'rows' => [], 'counts' => [], 'totals' => [], 'entry_count' => 0 ],
	$cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Counts answers per option, excludes trash.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'q1' => 'a', 'q2' => 'x' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'read',  'data' => json_encode( [ 'q1' => 'b', 'q2' => 'x' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'q1' => 'a' ] ) ],            // q2 missing
	[ 'id' => 4, 'form_id' => 12, 'status' => 'trash', 'data' => json_encode( [ 'q1' => 'a', 'q2' => 'x' ] ) ], // excluded
	[ 'id' => 5, 'form_id' => 99, 'status' => 'new',   'data' => json_encode( [ 'q1' => 'a' ] ) ],            // wrong form
];

$agg = EntryRepository::aggregate_quiz_answers( 12, [ 'q1', 'q2' ] );
check( '2a entry_count excludes trash + wrong form', $agg['entry_count'], 3, $cases, $passed, $failed );
check( '2b q1 count "a" = 2',                        $agg['counts']['q1']['a'] ?? null, 2, $cases, $passed, $failed );
check( '2c q1 count "b" = 1',                        $agg['counts']['q1']['b'] ?? null, 1, $cases, $passed, $failed );
check( '2d q2 count "x" = 2',                        $agg['counts']['q2']['x'] ?? null, 2, $cases, $passed, $failed );
check( '2e q1 totals',                               $agg['totals']['q1'], 3, $cases, $passed, $failed );
check( '2f q2 totals (missing skipped)',             $agg['totals']['q2'], 2, $cases, $passed, $failed );
check( '2g rows decoded',                            count( $agg['rows'] ), 3, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Malformed entries do not fatal.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => 'not-json' ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => '[]' ],                                  // array, not assoc
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'q1' => null ] ) ],       // null
	[ 'id' => 4, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'q1' => '' ] ) ],         // empty
	[ 'id' => 5, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'q1' => [ 1, 2 ] ] ) ],   // array (multi)
	[ 'id' => 6, 'form_id' => 12, 'status' => 'new', 'data' => '' ],                                    // empty
	[ 'id' => 7, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'q1' => 'a' ] ) ],        // valid
];
$agg_mal = EntryRepository::aggregate_quiz_answers( 12, [ 'q1' ] );
// entry_count counts EVERY decoded entry, regardless of whether
// q1 was answered. The "[]" case decodes to array but isn't
// associative — json_decode([]) returns []; is_array() is true
// so it counts. Other malformed rows are skipped at the json_decode
// step because they yield null/non-arrays.
check( '3a malformed entries do not fatal', is_array( $agg_mal['counts'] ), true, $cases, $passed, $failed );
check( '3b only the valid row is counted',  $agg_mal['counts']['q1']['a'] ?? null, 1, $cases, $passed, $failed );
check( '3c q1 totals ignores null/empty/array', $agg_mal['totals']['q1'], 1, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Counts initialise to empty for fields that never appear.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'q1' => 'a' ] ) ],
];
$agg_init = EntryRepository::aggregate_quiz_answers( 12, [ 'q1', 'q_unused' ] );
check( '4a q_unused counts initialised empty', $agg_init['counts']['q_unused'], [], $cases, $passed, $failed );
check( '4b q_unused totals 0',                 $agg_init['totals']['q_unused'], 0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
foreach ( $cases as $row ) {
	[ $ok, $label, $actual, $expected ] = $row;
	if ( $ok ) {
		echo "\033[32mPASS\033[0m  $label\n";
	} else {
		echo "\033[31mFAIL\033[0m  $label\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed === 0 ? 0 : 1 );
