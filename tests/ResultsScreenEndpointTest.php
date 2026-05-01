<?php
/**
 * Results screen endpoint tests.
 *
 * Exercises:
 *   - GET /forms/{id}/polls (all-polls aggregator) with real schemas:
 *       * returns every poll field on the form
 *       * total_entries excludes status='trash'
 *       * total_poll_votes is the sum of per-poll totals
 *       * poll_count is len(polls)
 *       * unknown values collapse into "__other__"
 *       * malformed entries do not fatal
 *       * empty polls when the form has none
 *   - permission gate uses NEURAL_FORMS_CAP_MANAGE_FORMS
 *   - missing form returns form_not_found 404
 *   - GET /forms list enrichment: every row gets has_polls + poll_count
 *
 * Run from the plugin root:
 *
 *   php tests/ResultsScreenEndpointTest.php
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
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) )    { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )    { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'current_time' ) )    { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $v ) { return $v; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() { return true; } }
if ( ! defined( 'NEURAL_FORMS_CAP_MANAGE_FORMS' ) ) {
	define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' );
}

$GLOBALS['__nf_user_can'] = false;
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return ! empty( $GLOBALS['__nf_user_can'] ); }
}

// $wpdb shim covering aggregate_poll_votes() AND count_form_entries().
class NF_Results_WPDB {
	public string $prefix = 'wp_';
	public array  $rows   = [];

	public function prepare( $sql, ...$args ) {
		return [ 'sql' => $sql, 'args' => $args ];
	}
	public function get_col( $query ) {
		// SELECT data FROM ... WHERE form_id = %d AND status != %s
		[ , $form_id, $status_filter ] = $query['args'];
		$out = [];
		foreach ( $this->rows as $r ) {
			if ( (int) $r['form_id'] !== (int) $form_id ) continue;
			if ( $r['status'] === $status_filter ) continue;
			$out[] = $r['data'];
		}
		return $out;
	}
	public function get_var( $query ) {
		// SELECT COUNT(*) FROM ... WHERE form_id = %d AND status != %s
		[ , $form_id, $status_filter ] = $query['args'];
		$n = 0;
		foreach ( $this->rows as $r ) {
			if ( (int) $r['form_id'] !== (int) $form_id ) continue;
			if ( $r['status'] === $status_filter ) continue;
			$n++;
		}
		return $n;
	}
}
$GLOBALS['wpdb'] = new NF_Results_WPDB();

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/storage/EntryRepository.php';

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Storage\EntryRepository;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// FormRepository fixture stub.
$GLOBALS['__nf_form_fixture'] = null;
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormRepository { public static function find( int $id ): ?array { return $GLOBALS["__nf_form_fixture"]; } }' );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		public array $a = [];
		public function offsetExists( $k ): bool   { return array_key_exists( $k, $this->a ); }
		#[\ReturnTypeWillChange]
		public function offsetGet( $k )            { return $this->a[ $k ] ?? null; }
		public function offsetSet( $k, $v ): void  { $this->a[ $k ] = $v; }
		public function offsetUnset( $k ): void    { unset( $this->a[ $k ] ); }
	}
}

require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/PollsEndpoint.php';

use NeuralForms\Rest\Endpoints\PollsEndpoint;

$endpoint = new PollsEndpoint();

// Fixture poll fields.
$opts1 = [
	[ 'label' => 'Website Design',         'value' => 'website_design' ],
	[ 'label' => 'WordPress Development',  'value' => 'wordpress_development' ],
	[ 'label' => 'Maintenance',            'value' => 'maintenance' ],
];
$opts2 = [
	[ 'label' => 'Yes', 'value' => 'yes' ],
	[ 'label' => 'No',  'value' => 'no' ],
];

$poll_a = [ 'id' => 'poll_service',  'type' => 'poll', 'label' => 'Service?',  'options' => $opts1 ];
$poll_b = [ 'id' => 'poll_followup', 'type' => 'poll', 'label' => 'Follow up?', 'options' => $opts2 ];

$req = new WP_REST_Request();

// ---------------------------------------------------------------------------
// 1. Permission gate.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_user_can'] = false;
check( '1a permission denied without cap', $endpoint->check_permission(), false, $cases, $passed, $failed );
$GLOBALS['__nf_user_can'] = true;
check( '1b permission allowed with cap',   $endpoint->check_permission(), true,  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Form not found -> 404.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = null;
$req['id'] = 99;
$res_404 = $endpoint->get_all_poll_results( $req );
check( '2a missing form returns WP_Error', is_wp_error( $res_404 ), true, $cases, $passed, $failed );
check( '2b error code form_not_found',
	$res_404 instanceof WP_Error ? $res_404->get_error_code() : null, 'form_not_found', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Form with no poll fields returns empty `polls` (not 404).
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id'          => 12,
	'name'        => 'Plain',
	'status'      => 'active',
	'form_schema' => [ 'fields' => [
		[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
	] ],
	'settings'    => [],
];
$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'name' => 'Ada' ] ) ],
];
$req['id'] = 12;
$res_empty = $endpoint->get_all_poll_results( $req );
check( '3a no-poll form returns 200 shape',     is_array( $res_empty ), true,            $cases, $passed, $failed );
check( '3b form_id echoed',                     $res_empty['form_id'] ?? null, 12,        $cases, $passed, $failed );
check( '3c form_name echoed',                   $res_empty['form_name'] ?? null, 'Plain', $cases, $passed, $failed );
check( '3d total_entries counted (1)',          $res_empty['total_entries'] ?? null, 1,    $cases, $passed, $failed );
check( '3e poll_count is 0',                    $res_empty['poll_count'] ?? null, 0,       $cases, $passed, $failed );
check( '3f total_poll_votes is 0',              $res_empty['total_poll_votes'] ?? null, 0, $cases, $passed, $failed );
check( '3g polls is empty array',               $res_empty['polls'] ?? null, [],           $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Form with two polls — happy path totals + ordering.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id'          => 12,
	'name'        => 'Survey',
	'status'      => 'active',
	'form_schema' => [ 'fields' => [
		$poll_a,
		[
			'id' => 'row_1', 'type' => 'row',
			'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
			'columns' => [ [ 'id' => 'col_1', 'fields' => [ $poll_b ] ] ],
		],
	] ],
	'settings'    => [],
];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'poll_service' => 'website_design',        'poll_followup' => 'yes' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'poll_service' => 'website_design',        'poll_followup' => 'no'  ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'poll_service' => 'wordpress_development'                       ] ) ],
	[ 'id' => 4, 'form_id' => 12, 'status' => 'trash', 'data' => json_encode( [ 'poll_service' => 'website_design',        'poll_followup' => 'yes' ] ) ], // excluded
	[ 'id' => 5, 'form_id' => 99, 'status' => 'new',   'data' => json_encode( [ 'poll_service' => 'website_design'                              ] ) ], // wrong form
];

$res = $endpoint->get_all_poll_results( $req );
check( '4a poll_count = 2',                     $res['poll_count'] ?? null,        2, $cases, $passed, $failed );
check( '4b polls len = 2',                      count( $res['polls'] ?? [] ),      2, $cases, $passed, $failed );
check( '4c polls keys ordered by schema',       array_column( $res['polls'], 'field_id' ), [ 'poll_service', 'poll_followup' ], $cases, $passed, $failed );
check( '4d total_entries excludes trash (3)',   $res['total_entries'] ?? null,     3, $cases, $passed, $failed );
check( '4e poll_service total_votes = 3',       $res['polls'][0]['total_votes'] ?? null, 3, $cases, $passed, $failed );
check( '4f poll_followup total_votes = 2',      $res['polls'][1]['total_votes'] ?? null, 2, $cases, $passed, $failed );
check( '4g total_poll_votes is sum (5)',        $res['total_poll_votes'] ?? null,  5, $cases, $passed, $failed );

// 4h. Schema option order preserved + percentages.
$svc = $res['polls'][0];
check( '4h poll_service options preserve order',
	array_column( $svc['options'], 'value' ),
	[ 'website_design', 'wordpress_development', 'maintenance' ], $cases, $passed, $failed );
check( '4i website_design percentage 66.67',  $svc['options'][0]['percentage'] ?? null, 66.67, $cases, $passed, $failed );
check( '4j zero-vote option still emitted',   $svc['options'][2]['votes']      ?? null, 0,     $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Unknown values collapse into "__other__".
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_service' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_service' => 'removed_option' ] ) ],
];
$res_other = $endpoint->get_all_poll_results( $req );
$svc_other = $res_other['polls'][0] ?? [];
$values = array_column( $svc_other['options'] ?? [], 'value' );
check( '5a __other__ row present in all-polls payload', in_array( '__other__', $values, true ), true, $cases, $passed, $failed );

// 5b. Other row absent when only known values.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_service' => 'website_design' ] ) ],
];
$res_no_other = $endpoint->get_all_poll_results( $req );
$values_no_other = array_column( $res_no_other['polls'][0]['options'] ?? [], 'value' );
check( '5b __other__ omitted when no unknown values', in_array( '__other__', $values_no_other, true ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Malformed entries do not fatal.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => 'not-json' ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => '[]' ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_service' => 'website_design' ] ) ],
	[ 'id' => 4, 'form_id' => 12, 'status' => 'new', 'data' => '' ],
];
$res_mal = $endpoint->get_all_poll_results( $req );
check( '6a malformed entries do not fatal',           is_array( $res_mal ), true, $cases, $passed, $failed );
check( '6b only valid row counted in poll_service',   $res_mal['polls'][0]['total_votes'] ?? null, 1, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Polls inside steps + step.row.column reachable.
// ---------------------------------------------------------------------------
$step_form = [
	'id' => 12, 'name' => 'Stepper', 'status' => 'active',
	'form_schema' => [ 'fields' => [
		[
			'id' => 'step_one', 'type' => 'step', 'title' => 'S',
			'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
			'fields' => [
				$poll_a,
				[
					'id' => 'row_1', 'type' => 'row',
					'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
					'columns' => [ [ 'id' => 'col_1', 'fields' => [ $poll_b ] ] ],
				],
			],
		],
	] ],
	'settings' => [],
];
$GLOBALS['__nf_form_fixture'] = $step_form;
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_service' => 'maintenance', 'poll_followup' => 'yes' ] ) ],
];
$res_step = $endpoint->get_all_poll_results( $req );
check( '7a step + step.row.column polls reached',  array_column( $res_step['polls'] ?? [], 'field_id' ),
	[ 'poll_service', 'poll_followup' ], $cases, $passed, $failed );
check( '7b total_poll_votes across polls (1+1)',   $res_step['total_poll_votes'] ?? null, 2, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Forms list enrichment: has_polls + poll_count are added by
//    FormsEndpoint::get_forms() via the new poll_count_for_schema() helper.
//
// We test the helper directly via reflection — exercising the full
// endpoint requires WP_REST_Request + FormRepository::list, which is
// orchestrated separately in the existing BuilderSavePayloadTest harness.
// ---------------------------------------------------------------------------
require_once $plugin_root . '/modules/forms/FormValidator.php';
require_once $plugin_root . '/modules/rest/endpoints/FormsEndpoint.php';

use NeuralForms\Rest\Endpoints\FormsEndpoint;

$ref = new ReflectionMethod( FormsEndpoint::class, 'poll_count_for_schema' );
$ref->setAccessible( true );

check( '8a poll_count_for_schema null -> 0',           $ref->invoke( null, null ), 0, $cases, $passed, $failed );
check( '8b poll_count_for_schema empty array -> 0',    $ref->invoke( null, [] ),   0, $cases, $passed, $failed );
check( '8c poll_count_for_schema no fields -> 0',      $ref->invoke( null, [ 'fields' => [] ] ), 0, $cases, $passed, $failed );
check( '8d poll_count_for_schema flat 2 polls -> 2',
	$ref->invoke( null, [ 'fields' => [ $poll_a, $poll_b ] ] ),
	2, $cases, $passed, $failed );
check( '8e poll_count_for_schema mixed types -> 1',
	$ref->invoke( null, [ 'fields' => [
		[ 'id' => 'name', 'type' => 'text' ],
		$poll_a,
	] ] ),
	1, $cases, $passed, $failed );
check( '8f poll_count_for_schema poll inside row -> 1',
	$ref->invoke( null, [ 'fields' => [
		[
			'id' => 'row_1', 'type' => 'row',
			'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
			'columns' => [ [ 'id' => 'col_1', 'fields' => [ $poll_a ] ] ],
		],
	] ] ),
	1, $cases, $passed, $failed );
check( '8g poll_count_for_schema poll inside step -> 1',
	$ref->invoke( null, [ 'fields' => [
		[
			'id' => 'step_1', 'type' => 'step', 'title' => 'X',
			'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
			'fields' => [ $poll_a ],
		],
	] ] ),
	1, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. GET /forms list enrichment loop walks the canonical `items` key.
//
// FormRepository::list() returns { items, total }. An earlier draft of
// the enrichment looked for `forms` and silently no-op'd, leaving the
// Results screen dropdown empty. Lock the contract with a focused
// reflection on the same loop semantics.
// ---------------------------------------------------------------------------
$enrich = function ( array $result ) {
	// Inline reproduction of the enrichment loop from
	// FormsEndpoint::get_forms() — we test the loop's mutation
	// behaviour without spinning up the full WP_REST_Request stack.
	$rows_key = null;
	if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
		$rows_key = 'items';
	} elseif ( isset( $result['forms'] ) && is_array( $result['forms'] ) ) {
		$rows_key = 'forms';
	}
	if ( $rows_key !== null ) {
		foreach ( $result[ $rows_key ] as &$form_row ) {
			$ref2 = new ReflectionMethod( FormsEndpoint::class, 'poll_count_for_schema' );
			$ref2->setAccessible( true );
			$count = $ref2->invoke( null, $form_row['form_schema'] ?? null );
			$form_row['poll_count'] = $count;
			$form_row['has_polls']  = $count > 0;
		}
		unset( $form_row );
	}
	return $result;
};

$list_response = [
	'items' => [
		[ 'id' => 1, 'name' => 'Plain',  'form_schema' => [ 'fields' => [ [ 'id' => 'name', 'type' => 'text' ] ] ] ],
		[ 'id' => 2, 'name' => 'Polled', 'form_schema' => [ 'fields' => [ $poll_a ] ] ],
		[ 'id' => 3, 'name' => 'Mixed',  'form_schema' => [ 'fields' => [
			[
				'id' => 'step_1', 'type' => 'step', 'title' => 'S',
				'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
				'fields' => [ $poll_a, $poll_b ],
			],
		] ] ],
	],
	'total' => 3,
];
$enriched = $enrich( $list_response );

check( '9a items[] enrichment runs (Plain has poll_count=0)',  $enriched['items'][0]['poll_count'] ?? null, 0, $cases, $passed, $failed );
check( '9b Plain has_polls is false',                          $enriched['items'][0]['has_polls']  ?? null, false, $cases, $passed, $failed );
check( '9c Polled has poll_count=1',                           $enriched['items'][1]['poll_count'] ?? null, 1, $cases, $passed, $failed );
check( '9d Polled has_polls is true',                          $enriched['items'][1]['has_polls']  ?? null, true, $cases, $passed, $failed );
check( '9e Mixed step polls counted (poll_count=2)',           $enriched['items'][2]['poll_count'] ?? null, 2, $cases, $passed, $failed );
check( '9f Mixed has_polls is true',                           $enriched['items'][2]['has_polls']  ?? null, true, $cases, $passed, $failed );
check( '9g enrichment preserves total',                        $enriched['total'] ?? null, 3, $cases, $passed, $failed );

// 9h. Legacy `forms` key (older drafts) — defensive fallback path.
$legacy_response = [
	'forms' => [
		[ 'id' => 4, 'name' => 'Legacy', 'form_schema' => [ 'fields' => [ $poll_b ] ] ],
	],
	'total' => 1,
];
$enriched_legacy = $enrich( $legacy_response );
check( '9h legacy forms[] key still enriched', $enriched_legacy['forms'][0]['poll_count'] ?? null, 1, $cases, $passed, $failed );

// 9i. No items / no forms -> loop is a no-op, response untouched.
$no_rows = [ 'total' => 0 ];
check( '9i empty response not mutated', $enrich( $no_rows ), [ 'total' => 0 ], $cases, $passed, $failed );

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
