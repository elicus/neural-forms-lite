<?php
/**
 * Poll results aggregation tests (Phase 2).
 *
 * Exercises:
 *   - EntryRepository::aggregate_poll_votes() — counts votes correctly,
 *     skips trashed rows, ignores malformed JSON, ignores non-string
 *     values, returns zero-shape for empty result sets.
 *   - PollsEndpoint
 *       * permission_callback gated on manage capability
 *       * 404 when form missing
 *       * 404 when field id is not a poll on the form
 *       * percentages computed against the schema's options[]
 *       * unknown-value bucket collapses to "__other__" and is omitted
 *         when its count is zero
 *       * percentages are 0.0 when total is 0 (no /0)
 *
 * Phase 2 does not change the canonical schema or the renderer, so all
 * Phase 1 tests must still pass — they're orchestrated from the same
 * test runner via the standard suite list.
 *
 * Run from the plugin root:
 *
 *   php tests/PollResultsAggregationTest.php
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
if ( ! function_exists( 'esc_html' ) )    { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) )    { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
}
if ( ! function_exists( 'current_time' ) )    { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'current_user_can' ) ) {
	$GLOBALS['__nf_user_can'] = false;
	function current_user_can( $cap ) { return ! empty( $GLOBALS['__nf_user_can'] ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $value ) { return $value; }
}
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() { return true; } }
if ( ! defined( 'NEURAL_FORMS_CAP_MANAGE_FORMS' ) ) {
	define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' );
}

/**
 * Minimal $wpdb shim covering the surfaces aggregate_poll_votes() uses:
 *   $wpdb->get_col() with $wpdb->prepare().
 *
 * Stores fixture rows in $rows; prepare() captures the SQL + args so we
 * can assert "form_id = X AND status != 'trash'" was honoured.
 */
class NF_Poll_WPDB {
	public string $prefix = 'wp_';
	public array  $rows   = [];      // entries (id, form_id, status, data)
	public array  $last_args = [];

	public function prepare( $sql, ...$args ) {
		$this->last_args = $args;
		return [ 'sql' => $sql, 'args' => $args ];
	}
	public function get_col( $query ) {
		// $query is the array returned by prepare().
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
$GLOBALS['wpdb'] = new NF_Poll_WPDB();

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	// EntryRepository imports Logger; provide a no-op stub.
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

// ---------------------------------------------------------------------------
// 1. aggregate_poll_votes counts votes correctly.
// ---------------------------------------------------------------------------
$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new',   'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'read',  'data' => json_encode( [ 'poll_x' => 'wordpress_development' ] ) ],
	[ 'id' => 4, 'form_id' => 12, 'status' => 'spam',  'data' => json_encode( [ 'poll_x' => 'maintenance' ] ) ],
	[ 'id' => 5, 'form_id' => 12, 'status' => 'trash', 'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ], // excluded
	[ 'id' => 6, 'form_id' => 99, 'status' => 'new',   'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ], // wrong form
];

$agg = EntryRepository::aggregate_poll_votes( 12, 'poll_x' );
check( '1a total respects status != trash + form_id filter', $agg['total'], 4,                          $cases, $passed, $failed );
check( '1b website_design count',                            $agg['counts']['website_design']         ?? null, 2,  $cases, $passed, $failed );
check( '1c wordpress_development count',                     $agg['counts']['wordpress_development']  ?? null, 1,  $cases, $passed, $failed );
check( '1d maintenance count (spam not excluded)',           $agg['counts']['maintenance']            ?? null, 1,  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Empty entries return zero shape.
// ---------------------------------------------------------------------------
$wpdb->rows = [];
$agg_empty = EntryRepository::aggregate_poll_votes( 12, 'poll_x' );
check( '2a empty form returns total=0',  $agg_empty['total'],  0,  $cases, $passed, $failed );
check( '2b empty form returns counts=[]', $agg_empty['counts'], [], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Malformed entries do not fatal.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => 'not-json'                              ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => '[]'                                    ], // array, not assoc
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'name' => 'Ada' ] )      ], // missing poll_x
	[ 'id' => 4, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => null ] )     ], // null
	[ 'id' => 5, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => '' ] )       ], // empty string
	[ 'id' => 6, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => [ 1, 2 ] ] ) ], // array (multi)
	[ 'id' => 7, 'form_id' => 12, 'status' => 'new', 'data' => ''                                      ], // empty
	[ 'id' => 8, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ], // valid
];
$agg_mal = EntryRepository::aggregate_poll_votes( 12, 'poll_x' );
check( '3a malformed entries do not fatal',          $agg_mal['total'],                              1, $cases, $passed, $failed );
check( '3b only the valid row is counted',           $agg_mal['counts']['website_design'] ?? null,   1, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Bad inputs short-circuit cleanly.
// ---------------------------------------------------------------------------
$agg_bad_form  = EntryRepository::aggregate_poll_votes( 0, 'poll_x' );
$agg_bad_field = EntryRepository::aggregate_poll_votes( 12, '' );
check( '4a form_id<=0 returns zero shape',  $agg_bad_form,  [ 'total' => 0, 'counts' => [] ], $cases, $passed, $failed );
check( '4b empty field id returns zero',    $agg_bad_field, [ 'total' => 0, 'counts' => [] ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. PollsEndpoint: permission gate.
// ---------------------------------------------------------------------------

// Provide a FormRepository fixture for the endpoint. Built dynamically
// so we can swap the form mid-test.
$GLOBALS['__nf_form_fixture'] = null;
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	eval( 'namespace NeuralForms\\Forms; class FormRepository { public static function find( int $id ): ?array { return $GLOBALS["__nf_form_fixture"]; } }' );
}

require_once $plugin_root . '/modules/rest/RestAPI.php';
require_once $plugin_root . '/modules/rest/endpoints/PollsEndpoint.php';

use NeuralForms\Rest\Endpoints\PollsEndpoint;

$endpoint = new PollsEndpoint();

$GLOBALS['__nf_user_can'] = false;
check( '5a permission denied when user lacks cap', $endpoint->check_permission(), false, $cases, $passed, $failed );
$GLOBALS['__nf_user_can'] = true;
check( '5b permission allowed when user has cap',  $endpoint->check_permission(), true,  $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Endpoint returns 404 when form missing.
// ---------------------------------------------------------------------------
class NF_Test_Request implements ArrayAccess {
	public array $a = [];
	public function offsetExists( $k ): bool   { return array_key_exists( $k, $this->a ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $k )            { return $this->a[ $k ] ?? null; }
	public function offsetSet( $k, $v ): void  { $this->a[ $k ] = $v; }
	public function offsetUnset( $k ): void    { unset( $this->a[ $k ] ); }
}
// Endpoint signature uses WP_REST_Request directly; provide a class
// that satisfies the typehint. WP_REST_Request normally implements
// ArrayAccess, so test the same shape.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request extends NF_Test_Request {}
}
$req = new WP_REST_Request();
$req['id']       = 12;
$req['field_id'] = 'poll_x';

$GLOBALS['__nf_form_fixture'] = null; // form not found
$res_404 = $endpoint->get_poll_results( $req );
check( '6a missing form returns WP_Error',    is_wp_error( $res_404 ),               true,             $cases, $passed, $failed );
check( '6b error code is form_not_found',     $res_404 instanceof WP_Error ? $res_404->get_error_code() : null, 'form_not_found', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Endpoint returns 404 when field id is not a poll on the form.
// ---------------------------------------------------------------------------
$GLOBALS['__nf_form_fixture'] = [
	'id'          => 12,
	'name'        => 'Test',
	'status'      => 'active',
	'form_schema' => [
		'fields' => [
			[ 'id' => 'name',  'type' => 'text',  'label' => 'Name' ],
			[ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ],
		],
	],
	'settings'    => [],
];
$req['field_id'] = 'name';
$res_no_poll = $endpoint->get_poll_results( $req );
check( '7a non-poll field id returns WP_Error', is_wp_error( $res_no_poll ),                                                  true,            $cases, $passed, $failed );
check( '7b error code is poll_not_found',       $res_no_poll instanceof WP_Error ? $res_no_poll->get_error_code() : null,   'poll_not_found', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Endpoint returns the configured options[] in schema order with
//    counts + percentages + total. Selected poll only.
// ---------------------------------------------------------------------------
$poll_field = [
	'id'      => 'poll_x',
	'type'    => 'poll',
	'label'   => 'Service?',
	'options' => [
		[ 'label' => 'Website Design',        'value' => 'website_design' ],
		[ 'label' => 'WordPress Development', 'value' => 'wordpress_development' ],
		[ 'label' => 'Maintenance',           'value' => 'maintenance' ],
	],
	'settings' => [ 'results_visibility' => 'after_vote', 'chart_type' => 'bar' ],
];
$other_poll = [
	'id'      => 'poll_y',
	'type'    => 'poll',
	'label'   => 'Other?',
	'options' => [ [ 'label' => 'Yes', 'value' => 'yes' ] ],
];
$GLOBALS['__nf_form_fixture'] = [
	'id'          => 12,
	'name'        => 'Test',
	'status'      => 'active',
	'form_schema' => [ 'fields' => [ $poll_field, $other_poll ] ],
	'settings'    => [],
];
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'wordpress_development' ] ) ],
	[ 'id' => 4, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_y' => 'yes' ] ) ], // ignored — different poll
];
$req['field_id'] = 'poll_x';
$res_ok = $endpoint->get_poll_results( $req );

check( '8a response form_id',    $res_ok['form_id']     ?? null, 12,                  $cases, $passed, $failed );
check( '8b response field_id',   $res_ok['field_id']    ?? null, 'poll_x',            $cases, $passed, $failed );
check( '8c response label',      $res_ok['label']       ?? null, 'Service?',          $cases, $passed, $failed );
check( '8d total_votes counts only poll_x votes', $res_ok['total_votes'] ?? null, 3,  $cases, $passed, $failed );

$opts = $res_ok['options'] ?? [];
check( '8e response includes 3 schema options', count( $opts ), 3, $cases, $passed, $failed );
check( '8f schema option order preserved',
	array_column( $opts, 'value' ),
	[ 'website_design', 'wordpress_development', 'maintenance' ],
	$cases, $passed, $failed );
check( '8g website_design vote count',                      $opts[0]['votes']      ?? null, 2, $cases, $passed, $failed );
check( '8h website_design percentage (2/3)',                $opts[0]['percentage'] ?? null, 66.67, $cases, $passed, $failed );
check( '8i wordpress_development vote count',               $opts[1]['votes']      ?? null, 1, $cases, $passed, $failed );
check( '8j wordpress_development percentage (1/3)',         $opts[1]['percentage'] ?? null, 33.33, $cases, $passed, $failed );
check( '8k maintenance vote count (zero, still emitted)',   $opts[2]['votes']      ?? null, 0, $cases, $passed, $failed );
check( '8l maintenance percentage (zero)',                  $opts[2]['percentage'] ?? null, 0.0, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. Unknown values collapse into "__other__" and only when > 0.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ],
	[ 'id' => 2, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'removed_option' ] ) ],
	[ 'id' => 3, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'gone_too' ] ) ],
];
$res_other = $endpoint->get_poll_results( $req );
$opts_other = $res_other['options'] ?? [];
$other = array_values( array_filter( $opts_other, static fn ( $o ) => $o['value'] === '__other__' ) );
check( '9a __other__ bucket present',           count( $other ), 1, $cases, $passed, $failed );
check( '9b __other__ collapses unknown values', $other[0]['votes'] ?? null, 2, $cases, $passed, $failed );
check( '9c __other__ percentage (2/3)',         $other[0]['percentage'] ?? null, 66.67, $cases, $passed, $failed );
check( '9d total covers known + unknown',       $res_other['total_votes'] ?? null, 3, $cases, $passed, $failed );

// 9e. Other bucket OMITTED when nothing tampered.
$wpdb->rows = [
	[ 'id' => 1, 'form_id' => 12, 'status' => 'new', 'data' => json_encode( [ 'poll_x' => 'website_design' ] ) ],
];
$res_no_other = $endpoint->get_poll_results( $req );
$values_no_other = array_column( $res_no_other['options'] ?? [], 'value' );
check( '9e __other__ omitted when count is zero',
	in_array( '__other__', $values_no_other, true ), false, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 10. Percentages are 0.0 when total is 0 (no /0).
// ---------------------------------------------------------------------------
$wpdb->rows = [];
$res_zero = $endpoint->get_poll_results( $req );
check( '10a zero total reports total_votes=0', $res_zero['total_votes'] ?? null, 0, $cases, $passed, $failed );
$pct_zero = array_column( $res_zero['options'] ?? [], 'percentage' );
check( '10b every option percentage is 0.0',  $pct_zero, [ 0.0, 0.0, 0.0 ], $cases, $passed, $failed );

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
