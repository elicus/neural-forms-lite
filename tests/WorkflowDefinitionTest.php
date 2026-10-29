<?php
/**
 * A4 (1.2.0): workflow definition + pure engine helpers.
 *
 *   php tests/WorkflowDefinitionTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	$GLOBALS['nf_test_filters'] = array();
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['nf_test_filters'] ) ? $GLOBALS['nf_test_filters'][ $hook ] : $value; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	if ( ! function_exists( 'user_can' ) ) { function user_can( $uid, $cap ) { return in_array( (int) $uid, array( 1, 2 ), true ); } }
	if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $uid ) { $o = new stdClass(); $o->display_name = 'User ' . $uid; return $o; } }
	if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } }
	if ( ! function_exists( 'sanitize_email' ) ) { function sanitize_email( $e ) { return trim( (string) $e ); } }
	if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k ) { return 'Site'; } }
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}
	$plugin_root = dirname( __DIR__ );
	foreach ( array(
		'/modules/forms/ConditionEngine.php', '/modules/forms/SchemaHelper.php', '/modules/storage/LeadStages.php',
		'/modules/core/Logger.php', '/modules/core/Config.php', '/modules/storage/EntryActivity.php', '/modules/storage/EntryRepository.php',
		'/modules/notifications/NotificationService.php',
		'/modules/workflows/Actions/WorkflowAction.php', '/modules/workflows/Actions/ActionResult.php', '/modules/workflows/Actions/AbstractAction.php',
		'/modules/workflows/Actions/AssignUserAction.php', '/modules/workflows/Actions/SetStageAction.php', '/modules/workflows/Actions/CreateFollowUpAction.php',
		'/modules/workflows/Actions/NotifyInternalAction.php', '/modules/workflows/Actions/SendAcknowledgementAction.php', '/modules/workflows/Actions/PrepareAiDraftAction.php',
		'/modules/workflows/Actions/ActionRegistry.php', '/modules/workflows/WorkflowDefinition.php',
	) as $f ) {
		require_once $plugin_root . $f;
	}
}

namespace NeuralForms\Tests {

	use NeuralForms\Workflows\WorkflowDefinition as W;
	use NeuralForms\Workflows\Actions\ActionRegistry;
	use NeuralForms\Workflows\Actions\ActionResult;

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
	$code = static function ( $r ) { return $r === true ? true : $r->get_error_code(); };

	$schema = array( 'fields' => array(
		array( 'id' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => array( array( 'value' => 'web', 'label' => 'Web' ), array( 'value' => 'seo', 'label' => 'SEO' ) ) ),
		array( 'id' => 'budget', 'type' => 'number', 'label' => 'Budget' ),
		array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ),
	) );
	$form = array( 'id' => 7, 'name' => 'Enquiry', 'form_schema' => $schema );

	// ── registry ──────────────────────────────────────────────────────
	$types = array_keys( ActionRegistry::all() );
	check( 'registry: six built-ins', $types, array( 'assign_user', 'set_stage', 'create_follow_up', 'notify_internal', 'send_acknowledgement', 'prepare_ai_draft' ), $passed, $failed );
	$script = ActionRegistry::for_script();
	check( 'registry: for_script carries settings schema', isset( $script[3]['settings'][0]['key'] ) && $script[3]['settings'][0]['key'] === 'recipients', true, $passed, $failed );

	// ── normalize ─────────────────────────────────────────────────────
	$raw = array(
		'form_id' => 7, 'name' => '  Qualified web leads ', 'trigger' => 'submission_received',
		'conditions' => array( 'enabled' => true, 'match' => 'all', 'rules' => array( array( 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ), array( 'field' => 'budget', 'operator' => 'gte', 'value' => '5000' ) ) ),
		'actions' => array(
			array( 'type' => 'assign_user', 'settings' => array( 'user_id' => 2 ) ),
			array( 'type' => 'Notify_Internal', 'settings' => array( 'recipients' => 'sales@example.com', 'subject' => 'S', 'body' => 'B' ), 'continue_on_failure' => 1 ),
			'garbage',
			array( 'settings' => array() ),
		),
		'settings' => array( 'on_reanalysis' => '1', 'stage_to' => 'Won!' ),
	);
	$def = W::normalize( $raw );
	check( 'normalize: name trimmed', $def['name'], 'Qualified web leads', $passed, $failed );
	check( 'normalize: trigger kept', $def['trigger'], 'submission_received', $passed, $failed );
	check( 'normalize: conditions enabled + 2 rules', $def['conditions']['enabled'] === true && count( $def['conditions']['rules'] ) === 2, true, $passed, $failed );
	check( 'normalize: actions keyed a1/a2, junk dropped, type lowercased', array_map( static function ( $a ) { return $a['key'] . ':' . $a['type']; }, $def['actions'] ), array( 'a1:assign_user', 'a2:notify_internal' ), $passed, $failed );
	check( 'normalize: continue_on_failure bool', array( $def['actions'][0]['continue_on_failure'], $def['actions'][1]['continue_on_failure'] ), array( false, true ), $passed, $failed );
	check( 'normalize: settings coerced', $def['settings'], array( 'on_reanalysis' => true, 'stage_from' => '', 'stage_to' => 'won' ), $passed, $failed );
	check( 'normalize: enabled defaults true', $def['enabled'], true, $passed, $failed );
	check( 'normalize: unknown trigger → submission', W::normalize( array( 'trigger' => 'nope' ) )['trigger'], 'submission_received', $passed, $failed );
	check( 'normalize: empty conditions → null', W::normalize( array( 'conditions' => array( 'enabled' => true, 'rules' => array() ) ) )['conditions'], null, $passed, $failed );
	$many = array( 'actions' => array_fill( 0, 15, array( 'type' => 'set_stage' ) ) );
	check( 'normalize: actions capped', count( W::normalize( $many )['actions'] ), W::MAX_ACTIONS, $passed, $failed );

	// ── validate ──────────────────────────────────────────────────────
	check( 'validate: good definition', $code( W::validate( $def, $schema ) ), true, $passed, $failed );
	$bad = $def; $bad['name'] = '';
	check( 'validate: name required', $code( W::validate( $bad, $schema ) ), 'invalid_workflow', $passed, $failed );
	$bad = $def; $bad['actions'] = array();
	check( 'validate: at least one action', $code( W::validate( $bad, $schema ) ), 'invalid_workflow', $passed, $failed );
	$bad = $def; $bad['conditions']['rules'][0]['field'] = 'ghost';
	check( 'validate: condition on unknown field', $code( W::validate( $bad, $schema ) ), 'invalid_form_data', $passed, $failed );
	$meta = $def; $meta['conditions']['rules'][0] = array( 'field' => 'ai_priority', 'operator' => 'equals', 'value' => 'high' );
	check( 'validate: condition on meta source ok', $code( W::validate( $meta, $schema ) ), true, $passed, $failed );
	$bad = $def; $bad['actions'][0]['type'] = 'teleport';
	check( 'validate: unknown action type', $code( W::validate( $bad, $schema ) ), 'invalid_workflow', $passed, $failed );
	$bad = $def; $bad['actions'][0]['settings']['user_id'] = 9;
	$r = W::validate( $bad, $schema );
	check( 'validate: assignee who cannot manage entries', $code( $r ), 'invalid_workflow', $passed, $failed );
	check( 'validate: message names the action', strpos( $r->get_error_message(), 'Action 1' ) === 0, true, $passed, $failed );
	$bad = $def; $bad['actions'][1]['settings']['recipients'] = '{email}';
	check( 'validate: recipients may not be tokens', $code( W::validate( $bad, $schema ) ), 'invalid_workflow', $passed, $failed );
	$bad = $def; $bad['actions'][1]['settings']['recipients'] = 'not-an-email';
	check( 'validate: recipients must be emails', $code( W::validate( $bad, $schema ) ), 'invalid_workflow', $passed, $failed );
	$stage = $def; $stage['trigger'] = 'stage_changed'; $stage['settings']['stage_from'] = 'won'; $stage['settings']['stage_to'] = 'won';
	check( 'validate: stage trigger from == to rejected', $code( W::validate( $stage, $schema ) ), 'invalid_workflow', $passed, $failed );
	$stage['settings']['stage_from'] = '';
	check( 'validate: stage trigger with any → won ok', $code( W::validate( $stage, $schema ) ), true, $passed, $failed );
	$fu = $def; $fu['actions'] = array( array( 'key' => 'a1', 'type' => 'create_follow_up', 'settings' => array( 'hours' => 0 ), 'continue_on_failure' => false ) );
	check( 'validate: follow-up hours range', $code( W::validate( $fu, $schema ) ), 'invalid_workflow', $passed, $failed );
	$fu['actions'][0]['settings']['hours'] = 24;
	check( 'validate: follow-up ok', $code( W::validate( $fu, $schema ) ), true, $passed, $failed );
	$ss = $def; $ss['actions'] = array( array( 'key' => 'a1', 'type' => 'set_stage', 'settings' => array( 'stage' => 'maybe' ), 'continue_on_failure' => false ) );
	check( 'validate: set_stage unknown stage', $code( W::validate( $ss, $schema ) ), 'invalid_workflow', $passed, $failed );

	// ── evaluation context ────────────────────────────────────────────
	$entry = array( 'id' => 11, 'form_id' => 7, 'data' => array( 'service' => 'web', 'budget' => '9000', 'email' => 'a@b.c' ), 'status' => 'new', 'lead_stage' => 'new', 'assigned_to' => null, 'ai_category' => null, 'ai_priority' => null, 'ai_score' => null, 'ai_is_spam' => 0 );
	$ctx = W::evaluation_context( $entry, $form );
	check( 'context: meta keys always present (empty when unknown)', array( $ctx['data']['ai_category'], $ctx['data']['ai_score'], $ctx['data']['assigned_to'] ), array( '', '', '0' ), $passed, $failed );
	check( 'context: types include fields + meta', array( $ctx['types']['budget'], $ctx['types']['ai_priority'], $ctx['types']['ai_is_spam'] ), array( 'number', 'select', 'checkbox' ), $passed, $failed );
	check( 'context: equals on missing AI category fails (no lenient fallback)', \NeuralForms\Forms\ConditionEngine::evaluate( array( 'enabled' => true, 'rules' => array( array( 'field' => 'ai_category', 'operator' => 'equals', 'value' => 'sales' ) ) ), $ctx['data'], $ctx['types'] ), false, $passed, $failed );
	check( 'context: field + budget rule matches', \NeuralForms\Forms\ConditionEngine::evaluate( $def['conditions'], $ctx['data'], $ctx['types'] ), true, $passed, $failed );

	// ── event ids / lineage / backoff / stage filter ──────────────────
	check( 'event_id: created', W::event_id( 'submission_received', 11 ), 'created:11', $passed, $failed );
	check( 'event_id: first analysis', W::event_id( 'analysis_completed', 11, array( 'occurrence' => 1 ) ), 'analysis:11:1', $passed, $failed );
	check( 'event_id: stage', W::event_id( 'stage_changed', 11, array( 'from' => 'new', 'to' => 'won', 'at' => 5 ) ), 'stage:11:new>won:5', $passed, $failed );
	check( 'event_id: overdue', W::event_id( 'follow_up_overdue', 11, array( 'due' => '2026-11-01 10:00:00' ) ), 'overdue:11:2026-11-01 10:00:00', $passed, $failed );
	$l = W::lineage_append( '', 4, 100 );
	$l = W::lineage_append( $l, 5, 101 );
	check( 'lineage: append', $l, 'W4:R100,W5:R101', $passed, $failed );
	check( 'lineage: depth', W::lineage_depth( $l ), 2, $passed, $failed );
	check( 'lineage: contains workflow 4', W::lineage_contains( $l, 4 ), true, $passed, $failed );
	check( 'lineage: does not confuse W4 with W40', W::lineage_contains( $l, 40 ), false, $passed, $failed );
	check( 'may_start: fresh event', W::may_start( '', 4 ), true, $passed, $failed );
	check( 'may_start: same workflow in lineage → blocked (loop)', W::may_start( $l, 4 ), false, $passed, $failed );
	check( 'may_start: other workflow, depth 2 → ok', W::may_start( $l, 6 ), true, $passed, $failed );
	$deep = W::lineage_append( $l, 6, 102 );
	check( 'may_start: depth 3 → blocked', W::may_start( $deep, 7 ), false, $passed, $failed );
	check( 'backoff: 1st failure → 1 min', W::backoff_minutes( 1 ), 1, $passed, $failed );
	check( 'backoff: 2nd → 5 min', W::backoff_minutes( 2 ), 5, $passed, $failed );
	check( 'backoff: 3rd+ → 30 min', W::backoff_minutes( 9 ), 30, $passed, $failed );
	check( 'stage_matches: any → won', W::stage_matches( array( 'settings' => array( 'stage_from' => '', 'stage_to' => 'won' ) ), 'new', 'won' ), true, $passed, $failed );
	check( 'stage_matches: from mismatch', W::stage_matches( array( 'settings' => array( 'stage_from' => 'contacted', 'stage_to' => '' ) ), 'new', 'won' ), false, $passed, $failed );

	// ── ActionResult ──────────────────────────────────────────────────
	check( 'result: done ok', ActionResult::done( 'x' )->is_ok(), true, $passed, $failed );
	check( 'result: skipped ok', ActionResult::skipped( 'x' )->is_ok(), true, $passed, $failed );
	check( 'result: retry not ok', ActionResult::retry( 'x' )->is_ok(), false, $passed, $failed );
	check( 'result: uncertain carries ref', ActionResult::uncertain( 'x', 'msg:1' )->external_ref, 'msg:1', $passed, $failed );

	// ── describe (dry-run text) is side-effect free ───────────────────
	$ctx_dry = array( 'run_id' => 0, 'workflow_id' => 1, 'workflow_name' => 'T', 'lineage' => '', 'entry' => $entry, 'form' => $form, 'trigger' => 'submission_received', 'dry_run' => true );
	check( 'describe: assign', ActionRegistry::get( 'assign_user' )->describe( array( 'user_id' => 2 ), $ctx_dry ), 'Assign the entry to User 2', $passed, $failed );
	check( 'describe: acknowledgement finds submitter', ActionRegistry::get( 'send_acknowledgement' )->describe( array(), $ctx_dry ), 'Send the fixed acknowledgement to a@b.c', $passed, $failed );
	check( 'describe: set_stage', ActionRegistry::get( 'set_stage' )->describe( array( 'stage' => 'won' ), $ctx_dry ), 'Move the lead to "Won"', $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
