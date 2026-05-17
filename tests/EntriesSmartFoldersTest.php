<?php
/**
 * Smart-folder filtering tests for FormRepository::get_form_ids_by_detected_type.
 *
 * Run from the plugin root:
 *
 *   php tests/EntriesSmartFoldersTest.php
 *
 * These cover the type-resolution helper, which is the foundation
 * EntryRepository / EntriesEndpoint use to decide which form IDs
 * belong in each smart folder. The list/count layers downstream are
 * thin SQL — once the id resolution is correct, the predicates
 * (form_id IN / NOT IN) are mechanical.
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op once the line above defines ABSPATH).
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.
	if ( ! function_exists( '__' ) )                  { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; } }

	// In-memory wpdb stub. Captures the SELECT issued by
	// get_form_ids_by_detected_type() and returns the fixture rows.
	class WPDB_Stub {
		public $prefix = 'wp_';
		public $rows   = [];   // array of ['id'=>int, 'form_schema'=>json string, 'status'=>string]
		public function get_results( $sql, $output = ARRAY_A ) {
			// Filter by status (the helper appends `AND status != 'archived'`)
			return array_values( array_filter(
				$this->rows,
				static fn ( $r ) => ( $r['status'] ?? 'active' ) !== 'archived'
			) );
		}
		public function prepare( $sql, ...$args ) { return $sql; }
		public function esc_like( $s ) { return $s; }
	}
	$GLOBALS['wpdb'] = new WPDB_Stub();

	if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/forms/FieldRegistry.php';
	require_once $plugin_root . '/modules/forms/SchemaHelper.php';
	require_once $plugin_root . '/modules/forms/FormTypeDetector.php';
	require_once $plugin_root . '/modules/forms/FormRepository.php';

	use NeuralForms\Forms\FormRepository;

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

	function load_fixture( array $forms ): void {
		$rows = [];
		foreach ( $forms as $f ) {
			$rows[] = [
				'id'          => $f['id'],
				'form_schema' => json_encode( [ 'fields' => $f['fields'] ] ),
				'status'      => $f['status'] ?? 'active',
			];
		}
		$GLOBALS['wpdb']->rows = $rows;
	}

	// Field shape helpers — keep test bodies readable.
	function f_text( string $id = 'name' )       : array { return [ 'id' => $id, 'type' => 'text', 'label' => 'Name' ]; }
	function f_email( string $id = 'email' )      : array { return [ 'id' => $id, 'type' => 'email', 'label' => 'Email' ]; }
	function f_poll()                             : array { return [ 'id' => 'poll1', 'type' => 'poll', 'label' => 'Vote' ]; }
	function f_quiz()                             : array { return [ 'id' => 'q1', 'type' => 'quiz_question', 'label' => 'Q1' ]; }
	function f_step( array $children )            : array { return [ 'id' => 's1', 'type' => 'step', 'fields' => $children ]; }
	function f_row_quiz()                         : array {
		return [ 'id' => 'r1', 'type' => 'row', 'columns' => [
			[ 'id' => 'c1', 'fields' => [ f_quiz() ] ],
		] ];
	}

	// ─── 1. Simple form → ['simple'] ─────────────────────────────────────
	load_fixture( [
		[ 'id' => 1, 'fields' => [ f_text(), f_email() ] ],
	] );
	check( '1a simple form is detected as simple',
		FormRepository::get_form_ids_by_detected_type( [ 'simple' ] ),
		[ 1 ], $cases, $passed, $failed );
	check( '1b simple form is NOT in poll set',
		FormRepository::get_form_ids_by_detected_type( [ 'poll' ] ),
		[], $cases, $passed, $failed );
	check( '1c simple form is NOT in quiz set',
		FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] ),
		[], $cases, $passed, $failed );

	// ─── 2. Poll form ────────────────────────────────────────────────────
	load_fixture( [
		[ 'id' => 10, 'fields' => [ f_text(), f_poll() ] ],
	] );
	check( '2a poll form → poll set',
		FormRepository::get_form_ids_by_detected_type( [ 'poll' ] ),
		[ 10 ], $cases, $passed, $failed );
	check( '2b poll form is NOT in quiz set',
		FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] ),
		[], $cases, $passed, $failed );

	// ─── 3. Quiz form (root-level quiz_question) ─────────────────────────
	load_fixture( [
		[ 'id' => 20, 'fields' => [ f_text(), f_quiz() ] ],
	] );
	check( '3a quiz form → quiz set',
		FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] ),
		[ 20 ], $cases, $passed, $failed );
	check( '3b quiz form is NOT in poll set',
		FormRepository::get_form_ids_by_detected_type( [ 'poll' ] ),
		[], $cases, $passed, $failed );

	// ─── 4. Mixed form (quiz + poll) → routes to quiz, NOT poll ──────────
	load_fixture( [
		[ 'id' => 30, 'fields' => [ f_text(), f_quiz(), f_poll() ] ],
	] );
	check( '4a mixed form → quiz set (mixed→quiz routing rule)',
		FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] ),
		[ 30 ], $cases, $passed, $failed );
	check( '4b mixed form is NOT in poll set (per brief)',
		FormRepository::get_form_ids_by_detected_type( [ 'poll' ] ),
		[], $cases, $passed, $failed );

	// ─── 5. Nested quiz inside step → still quiz ─────────────────────────
	load_fixture( [
		[ 'id' => 40, 'fields' => [ f_step( [ f_quiz() ] ) ] ],
	] );
	check( '5a quiz nested in step → quiz set',
		FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] ),
		[ 40 ], $cases, $passed, $failed );

	// ─── 6. Quiz inside row→column inside step ───────────────────────────
	load_fixture( [
		[ 'id' => 50, 'fields' => [ f_step( [ f_row_quiz() ] ) ] ],
	] );
	check( '6a quiz nested in step→row→col → quiz set',
		FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] ),
		[ 50 ], $cases, $passed, $failed );

	// ─── 7. Multi-form fixture: each ends up in the right bucket ─────────
	load_fixture( [
		[ 'id' => 100, 'fields' => [ f_text() ] ],                   // simple
		[ 'id' => 200, 'fields' => [ f_poll() ] ],                   // poll
		[ 'id' => 300, 'fields' => [ f_quiz() ] ],                   // quiz
		[ 'id' => 400, 'fields' => [ f_quiz(), f_poll() ] ],         // mixed
		[ 'id' => 500, 'fields' => [ f_text() ], 'status' => 'archived' ], // archived → never resolved
	] );

	check( '7a poll set has only the poll-only form',
		FormRepository::get_form_ids_by_detected_type( [ 'poll' ] ),
		[ 200 ], $cases, $passed, $failed );

	$quiz_set = FormRepository::get_form_ids_by_detected_type( [ 'quiz', 'mixed' ] );
	sort( $quiz_set );
	check( '7b quiz set has both quiz and mixed forms',
		$quiz_set,
		[ 300, 400 ], $cases, $passed, $failed );

	$simple_set = FormRepository::get_form_ids_by_detected_type( [ 'simple' ] );
	check( '7c simple set has only the simple form (archived excluded)',
		$simple_set,
		[ 100 ], $cases, $passed, $failed );

	// 7d covers the explicit folder=normal_inbox path (the rich UI's
	// default Inbox does NOT auto-exclude — only an explicit
	// folder=normal_inbox request triggers the exclusion).
	$normal_inbox_excludes = FormRepository::get_form_ids_by_detected_type( [ 'poll', 'quiz', 'mixed' ] );
	sort( $normal_inbox_excludes );
	check( '7d folder=normal_inbox excludes poll, quiz, AND mixed (explicit opt-in)',
		$normal_inbox_excludes,
		[ 200, 300, 400 ], $cases, $passed, $failed );

	// ─── 8. Empty types arg → empty result (no full table dump) ──────────
	check( '8a empty types arg returns empty list (defensive)',
		FormRepository::get_form_ids_by_detected_type( [] ),
		[], $cases, $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
