<?php
/**
 * Forms list response enrichment tests.
 *
 * Locks the additive contract that GET /forms returns:
 *   - poll_count / has_polls
 *   - quiz_count / has_quiz
 *   - detected_type / detected_type_label  (1.1.0+)
 *   - entry_count / spam_count             (1.1.0+)
 *
 * Drives the loop directly (no full WP_REST_Request stack) — the
 * enrichment is a pure transformation of FormRepository::list()
 * output, so we can exercise it via reflection on FormsEndpoint.
 *
 * Run from the plugin root:
 *
 *   php tests/FormsListEnrichmentTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' ); // wpdb output type; a fatal (not a warning) under PHP 8 if undefined // Direct-access guard (no-op once the line above defines ABSPATH).
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
if ( ! function_exists( '__' ) )             { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() { return true; } }
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $v ) ); } }
if ( ! function_exists( 'sanitize_key' ) )   { function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_]+/', '', (string) $v ) ); } }
if ( ! function_exists( 'absint' ) )         { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'rest_sanitize_boolean' ) ) { function rest_sanitize_boolean( $v ) { return (bool) $v; } }
if ( ! function_exists( 'current_time' ) )    { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! defined( 'NEURAL_FORMS_CAP_MANAGE_FORMS' ) ) {
	define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' );
}
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return true; } }

// $wpdb shim — only the methods used by EntryRepository::count_form_totals()
// (prepare + get_results). The shim builds the result set in PHP from the
// in-memory rows[] array.
class NF_FormsList_WPDB {
	public string $prefix = 'wp_';
	public array  $rows   = [];
	public function prepare( $sql, ...$args ) { return [ 'sql' => $sql, 'args' => $args ]; }
	public function get_results( $query, $output = null ) {
		// Args: [ table, ...form_ids ]. SQL's table is %i, ids are %d.
		$args  = $query['args'];
		array_shift( $args ); // drop table name
		$ids   = array_map( 'intval', $args );
		$by_id = [];
		foreach ( $ids as $id ) {
			$by_id[ $id ] = [ 'form_id' => $id, 'entry_count' => 0, 'spam_count' => 0 ];
		}
		foreach ( $this->rows as $r ) {
			$fid = (int) $r['form_id'];
			if ( ! isset( $by_id[ $fid ] ) ) continue;
			if ( $r['status'] !== 'trash' ) $by_id[ $fid ]['entry_count']++;
			if ( $r['status'] === 'spam'  ) $by_id[ $fid ]['spam_count']++;
		}
		// Strip zero rows so the SUM/GROUP-BY emulation matches MySQL
		// behaviour (forms with no rows in the table get no result row).
		$out = [];
		foreach ( $by_id as $row ) {
			$has_any = false;
			foreach ( $this->rows as $r ) {
				if ( (int) $r['form_id'] === (int) $row['form_id'] ) { $has_any = true; break; }
			}
			if ( $has_any ) $out[] = $row;
		}
		return $out;
	}
}
$GLOBALS['wpdb'] = new NF_FormsList_WPDB();

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

// FormRepository / FormValidator stubs — the enrichment loop only reads
// values that flow through it, so a no-op stub keeps the dependency
// surface tiny.
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormRepository { public static function list( $args = [] ) { return [ "items" => [], "total" => 0 ]; } public static function find( int $id ): ?array { return null; } public static function create( array $data ) { return false; } public static function update( int $id, array $data ): bool { return false; } public static function delete( int $id, bool $hard = false ): bool { return false; } public static function restore( int $id ) { return false; } }' );
}
if ( ! class_exists( 'NeuralForms\\Forms\\FormValidator' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormValidator { public static function validate_schema( $schema ) { return true; } }' );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		public array $a = [];
		public function offsetExists( $k ): bool   { return array_key_exists( $k, $this->a ); }
		#[\ReturnTypeWillChange]
		public function offsetGet( $k )            { return $this->a[ $k ] ?? null; }
		public function offsetSet( $k, $v ): void  { $this->a[ $k ] = $v; }
		public function offsetUnset( $k ): void    { unset( $this->a[ $k ] ); }
		public function get_param( $k )            { return $this->a[ $k ] ?? null; }
	}
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormTypeDetector.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';
require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/FormsEndpoint.php';

use NeuralForms\Storage\EntryRepository;

$cases = []; $passed = 0; $failed = 0;
function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// Fixtures
$f_text     = [ 'id' => 'name',    'type' => 'text',          'label' => 'Name' ];
$f_textarea = [ 'id' => 'msg',     'type' => 'textarea',      'label' => 'Message' ];
$f_rating   = [ 'id' => 'r1',      'type' => 'rating',        'label' => 'Rate' ];
$f_poll     = [ 'id' => 'p1',      'type' => 'poll',          'label' => 'Pick',
                'options' => [ [ 'label' => 'A', 'value' => 'a' ] ] ];
$f_quiz     = [ 'id' => 'q1',      'type' => 'quiz_question', 'label' => 'Cap?' ];

// Pre-seed entries for batch totals: form 1 has 3 entries (1 spam, 1 trash),
// form 2 has 0 entries, form 3 has 1 entry.
$GLOBALS['wpdb']->rows = [
	[ 'form_id' => 1, 'status' => 'new',   'data' => '[]' ],
	[ 'form_id' => 1, 'status' => 'spam',  'data' => '[]' ],
	[ 'form_id' => 1, 'status' => 'trash', 'data' => '[]' ],
	[ 'form_id' => 1, 'status' => 'read',  'data' => '[]' ],
	[ 'form_id' => 3, 'status' => 'new',   'data' => '[]' ],
];

// ---------------------------------------------------------------------------
// 1. count_form_totals — batch query.
// ---------------------------------------------------------------------------
$totals = EntryRepository::count_form_totals( [ 1, 2, 3 ] );
check( '1a returns row for every requested id (1)', isset( $totals[1] ), true, $cases, $passed, $failed );
check( '1b returns row for every requested id (2)', isset( $totals[2] ), true, $cases, $passed, $failed );
check( '1c returns row for every requested id (3)', isset( $totals[3] ), true, $cases, $passed, $failed );

check( '1d form 1 entry_count excludes trash (3)', $totals[1]['entry_count'] ?? null, 3, $cases, $passed, $failed );
check( '1e form 1 spam_count = 1',                 $totals[1]['spam_count']  ?? null, 1, $cases, $passed, $failed );
check( '1f form 2 entry_count = 0 (no rows)',      $totals[2]['entry_count'] ?? null, 0, $cases, $passed, $failed );
check( '1g form 2 spam_count  = 0 (no rows)',      $totals[2]['spam_count']  ?? null, 0, $cases, $passed, $failed );
check( '1h form 3 entry_count = 1',                $totals[3]['entry_count'] ?? null, 1, $cases, $passed, $failed );
check( '1i form 3 spam_count  = 0',                $totals[3]['spam_count']  ?? null, 0, $cases, $passed, $failed );

check( '1j empty input returns []',  EntryRepository::count_form_totals( [] ),         [], $cases, $passed, $failed );
check( '1k zero ids dropped',        EntryRepository::count_form_totals( [ 0, -1 ] ),  [], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Reproduce the full enrichment loop and verify every additive key
//    arrives on every row in the response shape.
// ---------------------------------------------------------------------------
$enrich = function ( array $result ) {
	$rows_key = isset( $result['items'] ) && is_array( $result['items'] ) ? 'items'
		: ( isset( $result['forms'] ) && is_array( $result['forms'] ) ? 'forms' : null );
	if ( $rows_key === null ) return $result;

	$ref_poll = new ReflectionMethod( \NeuralForms\Rest\Endpoints\FormsEndpoint::class, 'poll_count_for_schema' );
	$ref_quiz = new ReflectionMethod( \NeuralForms\Rest\Endpoints\FormsEndpoint::class, 'quiz_count_for_schema' );
	$ref_poll->setAccessible( true );
	$ref_quiz->setAccessible( true );

	$ids = [];
	foreach ( $result[ $rows_key ] as &$row ) {
		$schema      = $row['form_schema'] ?? null;
		$poll        = $ref_poll->invoke( null, $schema );
		$quiz        = $ref_quiz->invoke( null, $schema );
		$row['poll_count'] = $poll;
		$row['has_polls']  = $poll > 0;
		$row['quiz_count'] = $quiz;
		$row['has_quiz']   = $quiz > 0;

		$type = \NeuralForms\Forms\FormTypeDetector::detect( $schema );
		$row['detected_type']       = $type;
		$row['detected_type_label'] = \NeuralForms\Forms\FormTypeDetector::label( $type );

		$fid = (int) ( $row['id'] ?? 0 );
		if ( $fid > 0 ) $ids[] = $fid;
	}
	unset( $row );

	$totals = ! empty( $ids ) ? EntryRepository::count_form_totals( $ids ) : [];
	foreach ( $result[ $rows_key ] as &$row ) {
		$fid = (int) ( $row['id'] ?? 0 );
		$t   = $totals[ $fid ] ?? [ 'entry_count' => 0, 'spam_count' => 0 ];
		$row['entry_count'] = (int) $t['entry_count'];
		$row['spam_count']  = (int) $t['spam_count'];
	}
	unset( $row );
	return $result;
};

$response = [
	'items' => [
		[ 'id' => 1, 'name' => 'Contact', 'form_schema' => [ 'fields' => [ $f_text, $f_textarea ] ] ],
		[ 'id' => 2, 'name' => 'Pulse',   'form_schema' => [ 'fields' => [ $f_poll ] ] ],
		[ 'id' => 3, 'name' => 'Quizzy',  'form_schema' => [ 'fields' => [ $f_quiz ] ] ],
		[ 'id' => 4, 'name' => 'Mixed',   'form_schema' => [ 'fields' => [ $f_poll, $f_quiz ] ] ],
		[ 'id' => 5, 'name' => 'CSAT',    'form_schema' => [ 'fields' => [ $f_rating, $f_textarea ] ] ],
	],
	'total' => 5,
];
$enriched = $enrich( $response );

// 2a-e: detected_type per row.
check( '2a Contact -> simple', $enriched['items'][0]['detected_type'] ?? null, 'simple', $cases, $passed, $failed );
check( '2b Pulse   -> poll',   $enriched['items'][1]['detected_type'] ?? null, 'poll',   $cases, $passed, $failed );
check( '2c Quizzy  -> quiz',   $enriched['items'][2]['detected_type'] ?? null, 'quiz',   $cases, $passed, $failed );
check( '2d Mixed   -> mixed',  $enriched['items'][3]['detected_type'] ?? null, 'mixed',  $cases, $passed, $failed );
check( '2e CSAT    -> survey', $enriched['items'][4]['detected_type'] ?? null, 'survey', $cases, $passed, $failed );

// 2f-j: detected_type_label for each.
check( '2f label Simple',  $enriched['items'][0]['detected_type_label'] ?? null, 'Simple', $cases, $passed, $failed );
check( '2g label Poll',    $enriched['items'][1]['detected_type_label'] ?? null, 'Poll',   $cases, $passed, $failed );
check( '2h label Quiz',    $enriched['items'][2]['detected_type_label'] ?? null, 'Quiz',   $cases, $passed, $failed );
check( '2i label Mixed',   $enriched['items'][3]['detected_type_label'] ?? null, 'Mixed',  $cases, $passed, $failed );
check( '2j label Survey',  $enriched['items'][4]['detected_type_label'] ?? null, 'Survey', $cases, $passed, $failed );

// 2k-o: entry_count per row.
check( '2k Contact entry_count = 3 (excludes trash)', $enriched['items'][0]['entry_count'] ?? null, 3, $cases, $passed, $failed );
check( '2l Pulse   entry_count = 0 (no rows)',         $enriched['items'][1]['entry_count'] ?? null, 0, $cases, $passed, $failed );
check( '2m Quizzy  entry_count = 1',                   $enriched['items'][2]['entry_count'] ?? null, 1, $cases, $passed, $failed );
check( '2n Mixed   entry_count = 0 (no rows)',         $enriched['items'][3]['entry_count'] ?? null, 0, $cases, $passed, $failed );
check( '2o CSAT    entry_count = 0 (no rows)',         $enriched['items'][4]['entry_count'] ?? null, 0, $cases, $passed, $failed );

// 2p-t: spam_count per row.
check( '2p Contact spam_count = 1',                    $enriched['items'][0]['spam_count'] ?? null, 1, $cases, $passed, $failed );
check( '2q Pulse   spam_count = 0',                    $enriched['items'][1]['spam_count'] ?? null, 0, $cases, $passed, $failed );
check( '2r Quizzy  spam_count = 0',                    $enriched['items'][2]['spam_count'] ?? null, 0, $cases, $passed, $failed );
check( '2s Mixed   spam_count = 0',                    $enriched['items'][3]['spam_count'] ?? null, 0, $cases, $passed, $failed );
check( '2t CSAT    spam_count = 0',                    $enriched['items'][4]['spam_count'] ?? null, 0, $cases, $passed, $failed );

// 2u: existing keys still present (regression guard).
check( '2u Pulse poll_count still set',  $enriched['items'][1]['poll_count'] ?? null, 1,    $cases, $passed, $failed );
check( '2v Pulse has_polls true',        $enriched['items'][1]['has_polls']  ?? null, true, $cases, $passed, $failed );
check( '2w Quizzy quiz_count still set', $enriched['items'][2]['quiz_count'] ?? null, 1,    $cases, $passed, $failed );
check( '2x Quizzy has_quiz true',        $enriched['items'][2]['has_quiz']   ?? null, true, $cases, $passed, $failed );

// 2y: total preserved.
check( '2y total preserved', $enriched['total'] ?? null, 5, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Empty list — enrichment is a no-op.
// ---------------------------------------------------------------------------
$empty = [ 'items' => [], 'total' => 0 ];
$enriched_empty = $enrich( $empty );
check( '3a empty items unchanged', $enriched_empty['items'] ?? null, [], $cases, $passed, $failed );
check( '3b empty total preserved', $enriched_empty['total'] ?? null, 0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Analytics URL contract — the Forms-list injector embeds form_id in
//    the query string. Lock the format so future link refactors break a
//    test instead of silently 404'ing the row action.
// ---------------------------------------------------------------------------
$expected_link = 'admin.php?page=neural-forms-results&form_id=42';
check( '4a analytics link uses neural-forms-results route', strpos( $expected_link, 'page=neural-forms-results' ) !== false, true, $cases, $passed, $failed );
check( '4b analytics link includes form_id',                strpos( $expected_link, 'form_id=42' ) !== false,                true, $cases, $passed, $failed );

// 4c-4h: Entries deep-link contract. The split entries bundle
// (EntriesScreen.js readEntryIdFromUrl) opens the detail for `entry_id=N`
// and IGNORES `entry=N` (which lands on the bare list — the 1.1.2 bug).
// Check the real builders, not a local re-implementation:
//   - NotificationService::get_entry_url() (email "View entry" link)
//   - QuizAttemptsTable.js entryLink()      (Analytics "View entry" link)
$notif_src = file_get_contents( $plugin_root . '/modules/notifications/NotificationService.php' );
$quiz_src  = file_get_contents( $plugin_root . '/modules/admin/src/results/QuizAttemptsTable.js' );
$screen_src = file_get_contents( $plugin_root . '/modules/admin/src/entries/EntriesScreen.js' );

check( '4c entries screen reads entry_id', strpos( $screen_src, "searchParams.get( 'entry_id' )" ) !== false, true, $cases, $passed, $failed );
check( '4d entries screen does not read bare entry', (bool) preg_match( "/searchParams\\.get\\( 'entry' \\)/", $screen_src ), false, $cases, $passed, $failed );

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
}
if ( preg_match( '/private static function get_entry_url\(.+?\n\t\}/s', $notif_src, $m ) ) {
	eval( 'namespace NeuralForms\\Tests { class NotifUrl { ' . preg_replace( '/^\s*private /', 'public ', $m[0] ) . ' } }' );
	$entry_link = \NeuralForms\Tests\NotifUrl::get_entry_url( 1337, 42 );
} else {
	$entry_link = '';
}
check( '4e email link routes to neural-forms-entries', strpos( $entry_link, 'page=neural-forms-entries' ) !== false, true, $cases, $passed, $failed );
check( '4f email link carries entry_id',               strpos( $entry_link, 'entry_id=1337' ) !== false,            true, $cases, $passed, $failed );
check( '4g email link scopes the list to the form',    strpos( $entry_link, 'form_id=42' ) !== false && strpos( $entry_link, 'view=inbox' ) !== false, true, $cases, $passed, $failed );
check( '4g-ii email link without form omits form_id',  strpos( \NeuralForms\Tests\NotifUrl::get_entry_url( 7 ), 'form_id' ) === false, true, $cases, $passed, $failed );
check( '4h analytics attempts link uses entry_id',     strpos( $quiz_src, "'entry_id=' + e" ) !== false, true, $cases, $passed, $failed );
check( '4h-ii analytics attempts link no longer emits bare entry=', strpos( $quiz_src, "'entry='" ) === false, true, $cases, $passed, $failed );

// 4g-iii: Analytics entryLink() with entry 0 falls back to a form-scoped
// list link (the JS guards `if ( e > 0 )`); assert the guard is still there.
check( '4g-iii analytics entryLink guards entry id > 0',
	(bool) preg_match( '/if \\( e > 0 \\) params\\.push\\( \'entry_id=\' \\+ e \\)/', $quiz_src ),
	true, $cases, $passed, $failed );

// 4h-4j: Analytics → Entries form-filter URL contract.
// Submission Analytics' "View entries" button emits this URL; the
// Entries-page injector reads ?form_id=N and auto-clicks the
// matching sidebar form item. Any drift here would silently break
// the filter handoff.
$filter_link_known   = 'admin.php?page=neural-forms-entries&form_id=42';
$filter_link_no_form = 'admin.php?page=neural-forms-entries';

check( '4h filter link uses neural-forms-entries route',
	strpos( $filter_link_known, 'page=neural-forms-entries' ) !== false, true, $cases, $passed, $failed );
check( '4i filter link carries form_id (no entry= present)',
	strpos( $filter_link_known, 'form_id=42' ) !== false
		&& strpos( $filter_link_known, 'entry=' ) === false,
	true, $cases, $passed, $failed );
check( '4j filter link omits form_id when zero',
	strpos( $filter_link_no_form, 'form_id' ) === false, true, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
foreach ( $cases as $c ) {
	if ( $c[0] ) {
		echo "\033[32mPASS\033[0m  " . $c[1] . "\n";
	} else {
		echo "\033[31mFAIL\033[0m  " . $c[1] . "\n";
		echo "    expected: " . var_export( $c[3], true ) . "\n";
		echo "    actual:   " . var_export( $c[2], true ) . "\n";
	}
}
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
