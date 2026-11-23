<?php
/**
 * A3 (1.2.0): lead management storage + endpoint validation.
 *
 *   - Database SQL: entries gain lead columns, ai_responses gains meta,
 *     three workflow tables exist with the idempotency unique keys.
 *   - EntryRepository::build_filter_clauses: overdue view, stage,
 *     assigned_to (incl. unassigned), overdue flag.
 *   - EntryRepository::normalize_datetime.
 *   - LeadStages registry + filter + sanitising.
 *   - EntriesEndpoint::extract_lead_fields / validate_lead_fields.
 *
 *   php tests/LeadFieldsTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	if ( ! defined( 'NEURAL_FORMS_DB_VERSION' ) ) define( 'NEURAL_FORMS_DB_VERSION', '1.2.0' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	$GLOBALS['nf_test_filters'] = array();
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['nf_test_filters'] ) ? $GLOBALS['nf_test_filters'][ $hook ] : $value; } }
	if ( ! function_exists( 'do_action' ) ) { function do_action( ...$a ) { $GLOBALS['nf_actions'][] = $a; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
	if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
	if ( ! function_exists( 'user_can' ) ) { function user_can( $uid, $cap ) { return in_array( (int) $uid, array( 1, 2 ), true ); } }
	if ( ! class_exists( 'WP_REST_Request' ) ) { class WP_REST_Request {} }
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}
	class WPDB_Stub {
		public $prefix = 'wp_';
		public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
		public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	}
	$GLOBALS['wpdb'] = new WPDB_Stub();

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/includes/class-database.php';
	require_once $plugin_root . '/modules/storage/LeadStages.php';
	require_once $plugin_root . '/modules/core/Logger.php';
	require_once $plugin_root . '/modules/storage/EntryRepository.php';
	require_once $plugin_root . '/modules/core/Capabilities.php';
	require_once $plugin_root . '/modules/storage/EntryActivity.php';
	require_once $plugin_root . '/modules/rest/Endpoints/EntriesEndpoint.php';
}

namespace NeuralForms\Tests {

	use ReflectionMethod;
	use NeuralForms\Database;
	use NeuralForms\Storage\EntryRepository;
	use NeuralForms\Storage\LeadStages;
	use NeuralForms\Rest\Endpoints\EntriesEndpoint;

	$passed = 0;
	$failed = 0;
	function check( string $label, $actual, $expected, &$passed, &$failed ): void {
		if ( $actual === $expected ) {
			echo "\033[32mPASS\033[0m  $label\n";
			$passed++;
		} else {
			echo "\033[31mFAIL\033[0m  $label\n";
			echo "       expected: " . var_export( $expected, true ) . "\n";
			echo "       actual:   " . var_export( $actual, true ) . "\n";
			$failed++;
		}
	}

	// ── schema SQL ────────────────────────────────────────────────────
	$sql = static function ( string $method ): string {
		$m = new ReflectionMethod( Database::class, $method );
		$m->setAccessible( true );
		return (string) $m->invoke( null, 'DEFAULT CHARSET=utf8mb4' );
	};
	$entries = $sql( 'sql_entries' );
	foreach ( array( 'assigned_to bigint(20) UNSIGNED DEFAULT NULL', "lead_stage varchar(32) NOT NULL DEFAULT 'new'", 'follow_up_due datetime DEFAULT NULL', 'follow_up_done tinyint(1) NOT NULL DEFAULT 0', 'follow_up_overdue_sent tinyint(1) NOT NULL DEFAULT 0', 'KEY lead_stage (lead_stage)', 'KEY follow_up_due (follow_up_due)' ) as $frag ) {
		check( 'schema entries: ' . $frag, strpos( $entries, $frag ) !== false, true, $passed, $failed );
	}
	check( 'schema ai_responses: meta longtext', strpos( $sql( 'sql_ai_responses' ), 'meta longtext DEFAULT NULL' ) !== false, true, $passed, $failed );
	$wf = $sql( 'sql_workflows' );
	check( 'schema workflows table', strpos( $wf, 'CREATE TABLE wp_neural_forms_workflows' ) !== false && strpos( $wf, 'revision int(11)' ) !== false, true, $passed, $failed );
	$runs = $sql( 'sql_workflow_runs' );
	check( 'schema workflow_runs unique (workflow_id,event_id)', strpos( $runs, 'UNIQUE KEY workflow_event (workflow_id,event_id)' ) !== false, true, $passed, $failed );
	$acts = $sql( 'sql_workflow_actions' );
	check( 'schema workflow_actions unique (run_id,action_key)', strpos( $acts, 'UNIQUE KEY run_action (run_id,action_key)' ) !== false, true, $passed, $failed );
	// Every column line ends with a comma except the last before PRIMARY KEY — dbDelta is picky about "KEY  (id)" two-space form.
	foreach ( array( 'sql_entries', 'sql_workflows', 'sql_workflow_runs', 'sql_workflow_actions', 'sql_ai_responses' ) as $m ) {
		check( "schema $m: dbDelta primary key spacing", strpos( $sql( $m ), 'PRIMARY KEY  (id)' ) !== false, true, $passed, $failed );
	}

	// ── filter clauses ────────────────────────────────────────────────
	$clauses = new ReflectionMethod( EntryRepository::class, 'build_filter_clauses' );
	$clauses->setAccessible( true );
	$c = static function ( array $args, int $form_id = 0 ) use ( $clauses ) { return $clauses->invoke( null, $form_id, $args ); };

	$r = $c( array( 'view' => 'overdue' ) );
	check( 'clauses: overdue view (plain)', $r['plain'], 'WHERE ' . EntryRepository::OVERDUE_WHERE, $passed, $failed );
	check( 'clauses: overdue view (aliased) prefixes every column', $r['aliased'], "WHERE e.follow_up_due IS NOT NULL AND e.follow_up_done = 0 AND e.follow_up_due < UTC_TIMESTAMP() AND e.status IN ('new','read')", $passed, $failed );
	$r = $c( array( 'view' => 'inbox', 'stage' => 'Quote_Sent!' ) );
	check( 'clauses: stage filter sanitised + combined with view', $r['plain'], "WHERE status IN ('new','read') AND lead_stage = %s", $passed, $failed );
	check( 'clauses: stage value bound', $r['values'], array( 'quote_sent' ), $passed, $failed );
	$r = $c( array( 'assigned_to' => 7 ) );
	check( 'clauses: assigned_to bound', array( $r['plain'], $r['values'] ), array( 'WHERE assigned_to = %d', array( 7 ) ), $passed, $failed );
	$r = $c( array( 'assigned_to' => 0 ) );
	check( 'clauses: assigned_to 0 → unassigned', $r['plain'], 'WHERE assigned_to IS NULL', $passed, $failed );
	$r = $c( array( 'view' => 'inbox', 'overdue' => true ) );
	check( 'clauses: overdue flag adds predicate to another view', strpos( $r['plain'], EntryRepository::OVERDUE_WHERE ) !== false && strpos( $r['plain'], "status IN ('new','read') AND" ) === 0 + 6, true, $passed, $failed );
	$r = $c( array( 'view' => 'overdue', 'overdue' => true ) );
	check( 'clauses: overdue view + flag not duplicated', substr_count( $r['plain'], 'follow_up_due IS NOT NULL' ), 1, $passed, $failed );
	$r = $c( array( 'view' => 'inbox' ) );
	check( 'clauses: no lead args → unchanged inbox clause', $r['plain'], "WHERE status IN ('new','read')", $passed, $failed );
	check( 'VALID_VIEWS includes overdue', in_array( 'overdue', EntryRepository::VALID_VIEWS, true ), true, $passed, $failed );

	// ── normalize_datetime ────────────────────────────────────────────
	check( 'datetime: null', EntryRepository::normalize_datetime( null ), null, $passed, $failed );
	check( 'datetime: empty string', EntryRepository::normalize_datetime( '' ), null, $passed, $failed );
	check( 'datetime: ISO date', EntryRepository::normalize_datetime( '2026-11-03T09:30:00Z' ), '2026-11-03 09:30:00', $passed, $failed );
	check( 'datetime: date only', EntryRepository::normalize_datetime( '2026-11-03' ), '2026-11-03 00:00:00', $passed, $failed );
	check( 'datetime: unix', EntryRepository::normalize_datetime( 1767225600 ), '2026-01-01 00:00:00', $passed, $failed );
	check( 'datetime: garbage → null', EntryRepository::normalize_datetime( 'not a date' ), null, $passed, $failed );

	// ── LeadStages ────────────────────────────────────────────────────
	check( 'stages: default ids', LeadStages::ids(), array( 'new', 'contacted', 'quote_sent', 'won', 'lost' ), $passed, $failed );
	check( 'stages: valid', LeadStages::is_valid( 'won' ), true, $passed, $failed );
	check( 'stages: invalid', LeadStages::is_valid( 'maybe' ), false, $passed, $failed );
	check( 'stages: sanitize', LeadStages::sanitize_id( ' Quote-Sent ' ), 'quotesent', $passed, $failed );
	$GLOBALS['nf_test_filters']['neural_forms_lead_stages'] = array( 'Hot Lead' => 'Hot', 'cold' => 'Cold' );
	check( 'stages: filter applied, ids sanitised, default re-added first', LeadStages::ids(), array( 'new', 'hotlead', 'cold' ), $passed, $failed );
	check( 'stages: for_script shape', LeadStages::for_script()[1], array( 'id' => 'hotlead', 'label' => 'Hot' ), $passed, $failed );
	unset( $GLOBALS['nf_test_filters']['neural_forms_lead_stages'] );

	// ── endpoint helpers ──────────────────────────────────────────────
	check( 'extract: picks lead keys only', EntriesEndpoint::extract_lead_fields( array( 'status' => 'read', 'lead_stage' => 'won', 'follow_up_done' => 1, 'id' => 5 ) ), array( 'lead_stage' => 'won', 'follow_up_done' => 1 ), $passed, $failed );
	check( 'extract: empty when none', EntriesEndpoint::extract_lead_fields( array( 'status' => 'read' ) ), array(), $passed, $failed );
	$code = static function ( $r ) { return $r === true ? true : $r->get_error_code(); };
	check( 'validate: good stage', $code( EntriesEndpoint::validate_lead_fields( array( 'lead_stage' => 'contacted' ) ) ), true, $passed, $failed );
	check( 'validate: bad stage', $code( EntriesEndpoint::validate_lead_fields( array( 'lead_stage' => 'nope' ) ) ), 'invalid_lead_stage', $passed, $failed );
	check( 'validate: assignee who can manage', $code( EntriesEndpoint::validate_lead_fields( array( 'assigned_to' => 2 ) ) ), true, $passed, $failed );
	check( 'validate: assignee who cannot manage', $code( EntriesEndpoint::validate_lead_fields( array( 'assigned_to' => 9 ) ) ), 'invalid_assignee', $passed, $failed );
	check( 'validate: unassign (0)', $code( EntriesEndpoint::validate_lead_fields( array( 'assigned_to' => 0 ) ) ), true, $passed, $failed );
	check( 'validate: negative assignee', $code( EntriesEndpoint::validate_lead_fields( array( 'assigned_to' => -1 ) ) ), 'invalid_assignee', $passed, $failed );
	check( 'validate: follow-up date ok', $code( EntriesEndpoint::validate_lead_fields( array( 'follow_up_due' => '2026-12-01 10:00' ) ) ), true, $passed, $failed );
	check( 'validate: follow-up clear (null) ok', $code( EntriesEndpoint::validate_lead_fields( array( 'follow_up_due' => null ) ) ), true, $passed, $failed );
	check( 'validate: follow-up garbage', $code( EntriesEndpoint::validate_lead_fields( array( 'follow_up_due' => 'soon-ish' ) ) ), 'invalid_follow_up', $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
