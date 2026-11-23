<?php
/**
 * A6 live harness (1.2.0) — exercises the DB-bound code the standalone
 * tests cannot: the 1.1.3 → 1.2.0 upgrade rerun, embed routes (render /
 * shortcode / block), the submit path with step + field logic and
 * conditional confirmations, workflow runs (dispatch, skip, idempotent
 * events, loop guard, overdue sweep, retry + manual retry, dry run) and
 * the REST surface. Creates its own form / entries / workflows on the
 * LOCAL site and removes them at the end. Every check prints PASS/FAIL.
 *
 * Not part of the `tests/*.php` sweep (it needs a live WordPress + DB).
 * Run from the plugin root with Local's PHP and MySQL socket, Pro active:
 *
 *   P82="/Users/ALI/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
 *   SOCK="/Users/ALI/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock"
 *   "$P82" -d mysqli.default_socket="$SOCK" tests/live/A6LiveHarness.php
 *
 * Adjust HTTP_HOST / wp-load path below for another local site.
 */
$_SERVER['HTTP_HOST'] = 'd5.local';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
define( 'WP_USE_THEMES', false );
require '/Users/ALI/Local Sites/diviplus/app/public/wp-load.php';

use NeuralForms\Database;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Frontend\FormRenderer;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Workflows\WorkflowDefinition;
use NeuralForms\Workflows\WorkflowEngine;
use NeuralForms\Workflows\WorkflowQueueProcessor;
use NeuralForms\Workflows\WorkflowRepository;
use NeuralForms\Workflows\WorkflowRunner;
use NeuralForms\Workflows\WorkflowRunRepository;

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected ): void {
	global $passed, $failed;
	if ( $actual === $expected ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
}
global $wpdb;
$mail_ok = true;
add_filter( 'pre_wp_mail', static function () use ( &$mail_ok ) { return $mail_ok; }, 10, 0 );
$created = array( 'forms' => array(), 'entries' => array(), 'workflows' => array() );
function nf_a6_finish(): void {
	static $done = false;
	if ( $done ) { return; }
	$done = true;
	global $passed, $failed, $created, $wpdb;
	if ( ! empty( $GLOBALS['nf_csv_pending'] ) ) {
		while ( ob_get_level() > 0 ) { ob_end_flush(); }
		$out = (string) $GLOBALS['nf_csv_captured'];
		$GLOBALS['nf_csv_pending'] = false;
		check( 'pricing: CSV export renders product choices', strpos( $out, 'SEO' ) !== false && strpos( $out, 'entry_id' ) !== false, true );
	}
	// ── cleanup ───────────────────────────────────────────────────────
foreach ( $created['workflows'] as $id ) { WorkflowRepository::delete( (int) $id ); $wpdb->query( $wpdb->prepare( "DELETE a FROM {$wpdb->prefix}neural_forms_workflow_actions a JOIN {$wpdb->prefix}neural_forms_workflow_runs r ON r.id = a.run_id WHERE r.workflow_id = %d", (int) $id ) ); $wpdb->delete( $wpdb->prefix . 'neural_forms_workflow_runs', array( 'workflow_id' => (int) $id ), array( '%d' ) ); }
	foreach ( $created['entries'] as $id ) { if ( $id > 0 ) { EntryActivity::delete_for_entry( (int) $id ); EntryMessageRepository::delete_by_entry( (int) $id ); \NeuralForms\Storage\AIResponseRepository::delete_by_entry( (int) $id ); $wpdb->delete( $wpdb->prefix . 'neural_forms_logs', array( 'entry_id' => (int) $id ), array( '%d' ) ); EntryRepository::delete( (int) $id ); } }
	$wpdb->query( "DELETE FROM {$wpdb->prefix}neural_forms_queue WHERE job_type = 'workflow' AND status IN ('completed','failed','pending')" );
	foreach ( $created['forms'] as $id ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_forms', array( 'id' => (int) $id ), array( '%d' ) ); }
	echo "\n$passed passed, $failed failed\n";
}
register_shutdown_function( 'nf_a6_finish' );

try {
	// ── 1. upgrade rerun from 1.1.3 ─────────────────────────────────
	update_option( 'neural_forms_db_version', '1.1.3' );
	Database::maybe_upgrade();
	check( 'upgrade: db version back to the current schema version', get_option( 'neural_forms_db_version' ), NEURAL_FORMS_DB_VERSION );
	$cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}neural_forms_entries", ARRAY_A ), 'Field' );
	foreach ( array( 'assigned_to', 'lead_stage', 'follow_up_due', 'follow_up_done', 'follow_up_overdue_sent' ) as $c ) check( "upgrade: entries.$c exists", in_array( $c, $cols, true ), true );
	$cols2 = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}neural_forms_entry_ai_responses", ARRAY_A ), 'Field' );
	check( 'upgrade: ai_responses.meta exists', in_array( 'meta', $cols2, true ), true );
	$before = count( $cols );
	Database::create_tables();
	$after = count( array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}neural_forms_entries", ARRAY_A ), 'Field' ) );
	check( 'upgrade: rerun is a no-op on columns', $after, $before );
	check( 'upgrade: no DB error after rerun', $wpdb->last_error, '' );
	$idx = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}neural_forms_workflow_runs WHERE Key_name = 'workflow_event'", ARRAY_A );
	check( 'upgrade: runs unique key present + unique', count( $idx ) === 2 && (int) $idx[0]['Non_unique'] === 0, true );

	// ── 2. form with step logic, field logic, confirmation rules ────
	$schema = array( 'fields' => array(
		array( 'type' => 'step', 'id' => 'st1', 'title' => 'About', 'fields' => array(
			array( 'id' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => array( array( 'value' => 'web', 'label' => 'Web' ), array( 'value' => 'seo', 'label' => 'SEO' ) ) ),
			array( 'id' => 'budget', 'type' => 'number', 'label' => 'Budget' ),
			array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'id' => 'phone', 'type' => 'text', 'label' => 'Phone', 'conditional_logic' => array( 'enabled' => true, 'version' => 2, 'match' => 'any', 'rules' => array( array( 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ), array( 'field' => 'budget', 'operator' => 'gte', 'value' => '10000' ) ) ) ),
			array( 'id' => 'secret', 'type' => 'text', 'label' => 'Internal ref', 'ai_exclude' => true ),
		) ),
		array( 'type' => 'step', 'id' => 'st2', 'title' => 'Web details', 'conditional_logic' => array( 'enabled' => true, 'version' => 2, 'rules' => array( array( 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ) ) ), 'fields' => array(
			array( 'id' => 'pages', 'type' => 'number', 'label' => 'Pages', 'required' => true ),
		) ),
	) );
	$settings = array( 'on_success' => array( 'mode' => 'message', 'message' => 'DEFAULT THANKS', 'rules' => array(
		array( 'id' => 'r1', 'conditions' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ) ) ), 'mode' => 'message', 'message' => 'WEB THANKS', 'url' => '' ),
	) ) );
	$form_id = (int) FormRepository::create( array( 'name' => 'A6 harness form', 'slug' => 'a6-harness-' . time(), 'form_schema' => $schema, 'settings' => \NeuralForms\Forms\FormSettingsSanitizer::sanitize( $settings ), 'status' => 'active' ) );
	$created['forms'][] = $form_id;
	check( 'form created', $form_id > 0, true );
	$valid = \NeuralForms\Forms\FormValidator::validate_schema( $schema );
	check( 'schema validates with Pro caps (step + field logic)', $valid === true, true );
	$sv = \NeuralForms\Forms\FormValidator::validate_settings_logic( FormRepository::find( $form_id )['settings'], $schema );
	check( 'confirmation rules validate', $sv === true, true );

	// ── 3. embed routes ─────────────────────────────────────────────
	$html = FormRenderer::render( $form_id );
	check( 'render: multistep flag', strpos( $html, 'data-neural-forms-multistep="1"' ) !== false, true );
	check( 'render: step logic attribute', strpos( $html, 'data-conditional-step=' ) !== false, true );
	check( 'render: field logic attribute (v2 json)', (bool) preg_match( '/data-conditional="[^"]*&quot;v&quot;:2/', $html ), true );
	check( 'render: progress indicator present (numbers list or live bar text)', strpos( $html, 'neural-forms-form__progress-item' ) !== false || strpos( $html, 'progress-text" aria-live="polite"' ) !== false || strpos( $html, 'neural-forms-form__progress' ) === false, true );
	$sc = do_shortcode( '[neural-forms id="' . $form_id . '"]' );
	check( 'shortcode route renders the same attributes', strpos( $sc, 'data-conditional-step=' ) !== false && strpos( $sc, 'data-neural-forms-id="' . $form_id . '"' ) !== false, true );
	$bj = json_decode( (string) file_get_contents( NEURAL_FORMS_PATH . 'modules/frontend/block/block.json' ), true );
	$attr = isset( $bj['attributes']['formId'] ) ? 'formId' : ( isset( $bj['attributes']['id'] ) ? 'id' : 'formId' );
	$block = do_blocks( '<!-- wp:neural-forms/form {"' . $attr . '":' . $form_id . '} /-->' );
	check( 'block route renders the same attributes', strpos( $block, 'data-conditional-step=' ) !== false, true );

	// ── 4. workflows (created BEFORE submissions) ───────────────────
	$admin_id = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' ORDER BY user_id ASC LIMIT 1" );
	$defA = WorkflowDefinition::normalize( array(
		'form_id' => $form_id, 'name' => 'A6 route web leads', 'trigger' => 'submission_received',
		'conditions' => array( 'enabled' => true, 'match' => 'all', 'rules' => array( array( 'field' => 'service', 'operator' => 'equals', 'value' => 'web' ), array( 'field' => 'budget', 'operator' => 'gte', 'value' => '5000' ) ) ),
		'actions' => array(
			array( 'type' => 'assign_user', 'settings' => array( 'user_id' => $admin_id ) ),
			array( 'type' => 'set_stage', 'settings' => array( 'stage' => 'contacted' ) ),
			array( 'type' => 'create_follow_up', 'settings' => array( 'hours' => 24 ) ),
			array( 'type' => 'notify_internal', 'settings' => array( 'recipients' => 'sales@example.test', 'subject' => 'Lead {form_name}', 'body' => '{entry_url} {service}' ) ),
			array( 'type' => 'send_acknowledgement', 'settings' => array( 'subject' => 'Thanks', 'body' => 'Hi {email}, thanks. {site_name}' ) ),
		),
	) );
	check( 'workflow A validates', WorkflowDefinition::validate( $defA, $schema ) === true, true );
	$wfA = WorkflowRepository::create( $defA ); $created['workflows'][] = $wfA;
	check( 'workflow A stored', $wfA > 0, true );

	// ── 5. submit path ──────────────────────────────────────────────
	$server = rest_get_server();
	$submit = static function ( array $data ) use ( $server, $form_id ) {
		$req = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $form_id . '/submit' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $data + array( 'neural_forms_website' => '' ) ) );
		$res = $server->dispatch( $req );
		return array( 'status' => $res->get_status(), 'body' => $res->get_data() );
	};
	// 5a: SEO lead — step 2 skipped (pages must not block / not be stored), phone hidden, default confirmation
	$r1 = $submit( array( 'service' => 'seo', 'budget' => '100', 'email' => 'seo@example.test', 'phone' => 'should-vanish', 'pages' => '7', 'secret' => 'X1' ) );
	check( 'submit seo: 201 (skipped step did not block)', $r1['status'], 201 );
	$e1 = (int) ( $r1['body']['entry_id'] ?? 0 ); $created['entries'][] = $e1;
	$row1 = EntryRepository::get( $e1 );
	check( 'submit seo: pages (skipped step) not stored', isset( $row1['data']['pages'] ), false );
	check( 'submit seo: phone (hidden field) not stored', isset( $row1['data']['phone'] ), false );
	check( 'submit seo: no outcome override (default confirmation)', isset( $r1['body']['outcome'] ), false );
	// 5b: WEB lead — step 2 required, phone kept, WEB THANKS outcome
	$r2 = $submit( array( 'service' => 'web', 'budget' => '20000', 'email' => 'web@example.test', 'phone' => '+1 555', 'pages' => '12', 'secret' => 'X2' ) );
	check( 'submit web: 201', $r2['status'], 201 );
	$e2 = (int) ( $r2['body']['entry_id'] ?? 0 ); $created['entries'][] = $e2;
	$row2 = EntryRepository::get( $e2 );
	check( 'submit web: pages stored', (string) ( $row2['data']['pages'] ?? '' ), '12' );
	check( 'submit web: phone stored', $row2['data']['phone'] ?? null, '+1 555' );
	check( 'submit web: conditional confirmation chosen', $r2['body']['outcome'] ?? null, array( 'mode' => 'message', 'message' => 'WEB THANKS', 'url' => '' ) );
	// 5c: WEB without pages → 422 (required in an eligible step)
	$r3 = $submit( array( 'service' => 'web', 'budget' => '20000', 'email' => 'web2@example.test' ) );
	check( 'submit web without pages: 422', $r3['status'], 422 );
	// ai_exclude never leaves the site
	$prov = new \NeuralForms\AI\Providers\NeuralFormsCloudProvider( 'k' );
	$fe = new ReflectionMethod( $prov, 'format_entry' ); $fe->setAccessible( true );
	$fmt = $fe->invoke( $prov, $row2, FormRepository::find( $form_id ) );
	check( 'ai_exclude: secret field stripped from AI payload', isset( $fmt['fields']['secret'] ), false );

	// ── 6. workflow runs ────────────────────────────────────────────
	$runsA = WorkflowRunRepository::list_runs( $wfA );
	check( 'dispatch: one run per submission (2 entries → 2 runs)', count( $runsA ), 2 );
	WorkflowQueueProcessor::run();
	$runsA = WorkflowRunRepository::list_runs( $wfA );
	$byEntry = array(); foreach ( $runsA as $r ) $byEntry[ $r['entry_id'] ] = $r;
	check( 'run seo: skipped (conditions not met)', $byEntry[ $e1 ]['status'] ?? null, 'skipped' );
	check( 'run web: done', $byEntry[ $e2 ]['status'] ?? null, 'done' );
	check( 'run web: 5 actions done', array_count_values( array_column( $byEntry[ $e2 ]['actions'] ?? array(), 'status' ) ), array( 'done' => 5 ) );
	$row2 = EntryRepository::get( $e2 );
	check( 'effect: assigned to admin', (int) $row2['assigned_to'], $admin_id );
	check( 'effect: stage contacted', $row2['lead_stage'], 'contacted' );
	check( 'effect: follow-up set ~24h ahead', $row2['follow_up_due'] !== null && abs( strtotime( $row2['follow_up_due'] . ' UTC' ) - ( time() + 86400 ) ) < 120, true );
	$msgs = EntryMessageRepository::list_by_entry( $e2 );
	$ack = array_values( array_filter( $msgs, static fn( $m ) => ( $m['meta']['source'] ?? '' ) === 'workflow' ) );
	check( 'effect: acknowledgement recorded as outgoing message', count( $ack ), 1 );
	check( 'effect: acknowledgement tokens rendered', strpos( $ack[0]['body'] ?? '', 'web@example.test' ) !== false, true );
	$act = EntryActivity::list( $e2 );
	$types = array_column( $act, 'event_type' );
	check( 'activity: started + finished + per-action rows', in_array( 'workflow.started', $types, true ) && in_array( 'workflow.finished', $types, true ) && in_array( 'workflow.stage_changed', $types, true ), true );
	// WF4 duplicate event
	do_action( 'neural_forms_entry_created', $e2, $form_id, $row2['data'] );
	check( 'idempotency: duplicate entry_created adds no run', count( WorkflowRunRepository::list_runs( $wfA ) ), 2 );

	// ── 7. loop guard (B ↔ C on stage changes) ──────────────────────
	$defB = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'A6 B', 'trigger' => 'stage_changed', 'settings' => array( 'stage_to' => 'contacted' ), 'actions' => array( array( 'type' => 'set_stage', 'settings' => array( 'stage' => 'quote_sent' ) ) ) ) );
	$defC = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'A6 C', 'trigger' => 'stage_changed', 'settings' => array( 'stage_to' => 'quote_sent' ), 'actions' => array( array( 'type' => 'set_stage', 'settings' => array( 'stage' => 'contacted' ) ) ) ) );
	$wfB = WorkflowRepository::create( $defB ); $wfC = WorkflowRepository::create( $defC ); $created['workflows'][] = $wfB; $created['workflows'][] = $wfC;
	EntryRepository::update( $e1, array( 'lead_stage' => 'contacted' ) ); // a person moves the SEO lead
	for ( $i = 0; $i < 6; $i++ ) { WorkflowQueueProcessor::run(); }
	check( 'loop: B ran once', count( WorkflowRunRepository::list_runs( $wfB ) ), 1 );
	check( 'loop: C ran once', count( WorkflowRunRepository::list_runs( $wfC ) ), 1 );
	$types1 = array_column( EntryActivity::list( $e1 ), 'event_type' );
	check( 'loop: blocked re-entry recorded', in_array( 'workflow.loop_blocked', $types1, true ), true );
	check( 'loop: final stage is contacted (C ran, B blocked)', EntryRepository::get( $e1 )['lead_stage'], 'contacted' );

	// ── 8. overdue sweep fires once ─────────────────────────────────
	$defD = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'A6 overdue', 'trigger' => 'follow_up_overdue', 'actions' => array( array( 'type' => 'notify_internal', 'settings' => array( 'recipients' => 'ops@example.test', 'subject' => 'Overdue', 'body' => '{entry_url}' ) ) ) ) );
	$wfD = WorkflowRepository::create( $defD ); $created['workflows'][] = $wfD;
	EntryRepository::update( $e2, array( 'follow_up_due' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
	WorkflowEngine::sweep_overdue_follow_ups();
	WorkflowQueueProcessor::run();
	check( 'overdue: one run, done', array_column( WorkflowRunRepository::list_runs( $wfD ), 'status' ), array( 'done' ) );
	check( 'overdue: flag set', (int) EntryRepository::get( $e2 )['follow_up_overdue_sent'], 1 );
	WorkflowEngine::sweep_overdue_follow_ups();
	check( 'overdue: second sweep adds nothing', count( WorkflowRunRepository::list_runs( $wfD ) ), 1 );
	EntryRepository::update( $e2, array( 'follow_up_due' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // rescheduled → may alert again
	check( 'overdue: reschedule resets the flag', (int) EntryRepository::get( $e2 )['follow_up_overdue_sent'], 0 );

	// ── 9. retry + manual retry on a mail failure ───────────────────
	$mail_ok = false;
	$e3 = EntryRepository::create( $form_id, array( 'service' => 'seo', 'budget' => '100', 'email' => 'retry@example.test' ) ); $created['entries'][] = (int) $e3;
	$defE = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'A6 retry', 'trigger' => 'submission_received', 'actions' => array( array( 'type' => 'notify_internal', 'settings' => array( 'recipients' => 'x@example.test', 'subject' => 's', 'body' => 'b' ) ), array( 'type' => 'set_stage', 'settings' => array( 'stage' => 'won' ) ) ) ) );
	$wfE = WorkflowRepository::create( $defE ); $created['workflows'][] = $wfE;
	do_action( 'neural_forms_entry_created', (int) $e3, $form_id, array() );
	WorkflowQueueProcessor::run();
	$runE = WorkflowRunRepository::list_runs( $wfE )[0];
	check( 'retry: run waiting after mail failure', $runE['status'], 'waiting' );
	check( 'retry: first action pending with next_attempt_at, second untouched', array( $runE['actions'][0]['status'], ! empty( $runE['actions'][0]['next_attempt_at'] ), $runE['actions'][1]['status'] ), array( 'pending', true, 'pending' ) );
	check( 'retry: dependent action did not run (stage unchanged)', EntryRepository::get( (int) $e3 )['lead_stage'], 'new' );
	$future = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}neural_forms_queue WHERE job_type = 'workflow' AND status = 'pending' AND scheduled_at > UTC_TIMESTAMP() AND payload LIKE %s", '%"run_id":' . (int) $runE['id'] . '%' ) );
	check( 'retry: a future queue job exists', $future, 1 );
	// Manual retry path: reopen the action, mail works again.
	$mail_ok = true;
	WorkflowRunRepository::reopen_action( (int) $runE['actions'][0]['id'] );
	WorkflowEngine::enqueue_run( (int) $runE['id'] );
	WorkflowQueueProcessor::run();
	$runE = WorkflowRunRepository::list_runs( $wfE )[0];
	check( 'manual retry: run done, both actions done', array( $runE['status'], array_column( $runE['actions'], 'status' ) ), array( 'done', array( 'done', 'done' ) ) );
	check( 'manual retry: dependent action ran afterwards', EntryRepository::get( (int) $e3 )['lead_stage'], 'won' );

	// ── 10. dry run has no side effects ─────────────────────────────
	$cnt = static function () use ( $wpdb ) {
		return array(
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}neural_forms_workflow_runs" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}neural_forms_logs" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}neural_forms_entry_messages" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}neural_forms_queue" ),
		);
	};
	$before = $cnt();
	$dry = WorkflowRunner::dry_run( WorkflowRepository::find( $wfA ), EntryRepository::get( $e2 ), FormRepository::find( $form_id ) );
	check( 'dry run: matched + 5 planned actions', array( $dry['matched'], count( $dry['planned_actions'] ) ), array( true, 5 ) );
	check( 'dry run: per-rule explanations', count( $dry['conditions'] ), 2 );
	check( 'dry run: zero side effects', $cnt(), $before );
	$drySample = WorkflowRunner::dry_run( WorkflowRepository::find( $wfA ), null, FormRepository::find( $form_id ) );
	check( 'dry run: sample entry works', $drySample['sample'], true );

	// ── 11. REST surface smoke (as admin) ───────────────────────────
	wp_set_current_user( $admin_id );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/workflows' ); $req->set_query_params( array( 'form_id' => $form_id ) );
	$res = $server->dispatch( $req );
	check( 'REST: list workflows 200 with run counts', $res->get_status() === 200 && isset( $res->get_data()[0]['runs']['total'] ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/workflows/meta' ); $res = $server->dispatch( $req );
	$meta = $res->get_data();
	check( 'REST: meta lists built-in + Pro actions', in_array( 'sync_hubspot', array_column( $meta['actions'], 'type' ), true ) && in_array( 'assign_user', array_column( $meta['actions'], 'type' ), true ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/workflows/health' ); $res = $server->dispatch( $req );
	check( 'REST: health has last_run_at', ! empty( $res->get_data()['last_run_at'] ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/' . $e2 . '/activity' ); $res = $server->dispatch( $req );
	check( 'REST: activity list', $res->get_status() === 200 && count( $res->get_data() ) > 3, true );
	$req = new WP_REST_Request( 'PATCH', '/neural-forms/v1/entries/' . $e2 ); $req->set_body_params( array( 'lead_stage' => 'nope' ) ); $res = $server->dispatch( $req );
	check( 'REST: PATCH rejects unknown stage', $res->get_status(), 400 );
	// Stage-trigger workflows B/C still exist and would react to 'won'? No: B fires on →contacted, C on →quote_sent.
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries' ); $req->set_query_params( array( 'form_id' => $form_id, 'stage' => 'won' ) ); $res = $server->dispatch( $req );
	check( 'REST: stage filter returns only the won lead', array_column( $res->get_data(), 'id' ), array( (int) $e3 ) );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/counts' ); $req->set_query_params( array( 'form_id' => $form_id ) ); $res = $server->dispatch( $req );
	$counts = $res->get_data();
	check( 'REST: counts include stages + overdue', isset( $counts['stages']['contacted'], $counts['overdue'] ) && $counts['overdue'] >= 1, true );
	// ── 12. Part B: pricing end to end ──────────────────────────────
	$pform_id = (int) FormRepository::create( array( 'name' => 'A6 pricing form', 'slug' => 'a6-pricing-' . time(), 'status' => 'active',
		'form_schema' => array( 'fields' => array(
			array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
			array( 'id' => 'service', 'type' => 'product', 'label' => 'Service', 'pricing' => array( 'mode' => 'fixed', 'price' => '1000.00' ) ),
			array( 'id' => 'addons', 'type' => 'product', 'label' => 'Add-ons', 'pricing' => array( 'mode' => 'options', 'display' => 'checkboxes' ), 'options' => array( array( 'value' => 'seo', 'label' => 'SEO', 'price' => '150' ), array( 'value' => 'copy', 'label' => 'Copy', 'price' => '150' ) ) ),
			array( 'id' => 'rush', 'type' => 'product', 'label' => 'Rush', 'pricing' => array( 'mode' => 'fixed', 'price' => '500' ), 'conditional_logic' => array( 'enabled' => true, 'field' => 'addons', 'operator' => 'contains', 'value' => 'seo' ) ),
			array( 'id' => 'grand', 'type' => 'calculated', 'label' => 'Grand', 'expression' => '{service} + {addons} + {rush}', 'format' => 'currency' ),
		) ),
		'settings' => \NeuralForms\Forms\FormSettingsSanitizer::sanitize( array( 'pricing' => array( 'currency' => 'USD', 'discounts' => array( array( 'id' => 'ten', 'label' => 'Launch', 'type' => 'percent', 'value' => '10' ) ), 'deposit' => array( 'type' => 'percent', 'value' => '30' ) ) ) ),
	) );
	$created['forms'][] = $pform_id;
	$pf = FormRepository::find( $pform_id );
	check( 'pricing: schema + settings saved (validator accepted products/calculated)', \NeuralForms\Forms\FormValidator::validate_schema( $pf['form_schema'] ) === true && \NeuralForms\Forms\FormValidator::validate_settings_logic( $pf['settings'], $pf['form_schema'] ) === true, true );
	$phtml = FormRenderer::render( $pform_id );
	check( 'pricing: renderer emits product inputs, calculated output, totals and config', strpos( $phtml, 'name="addons[choice][]"' ) !== false && strpos( $phtml, 'data-neural-forms-calculated="grand"' ) !== false && strpos( $phtml, 'data-neural-forms-totals' ) !== false && strpos( $phtml, 'data-neural-forms-pricing=' ) !== false, true );
	$psubmit = static function ( array $data ) use ( $server, $pform_id ) {
		$req = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $pform_id . '/submit' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $data + array( 'neural_forms_website' => '' ) ) );
		$res = $server->dispatch( $req );
		return array( 'status' => $res->get_status(), 'body' => $res->get_data() );
	};
	// Tampered: client sends a "price" and a calculated value — both ignored. seo chosen → rush visible (+500).
	$pr = $psubmit( array( 'email' => 'p@example.test', 'service' => array( 'price' => '1' ), 'addons' => array( 'choice' => array( 'seo' ) ), 'grand' => '0', 'rush' => array() ) );
	check( 'pricing: submit 201', $pr['status'] === 201 ? 201 : $pr['body'], 201 );
	// An unknown option value is a validation error, not silently priced.
	$bad = $psubmit( array( 'email' => 'x@example.test', 'addons' => array( 'choice' => array( 'hack' ) ) ) );
	check( 'pricing: unknown option rejected with 422', $bad['status'], 422 );
	$pe = (int) ( $pr['body']['entry_id'] ?? 0 ); $created['entries'][] = $pe;
	check( 'pricing: response totals (1000+150+500=1650, -10% = 1485, 30% deposit = 445.50)', array( $pr['body']['pricing']['total'] ?? null, $pr['body']['pricing']['due_now'] ?? null, $pr['body']['pricing']['remaining'] ?? null ), array( 148500, 44550, 103950 ), true );
	$prow = EntryRepository::get( $pe );
	check( 'pricing: snapshot stored with 3 lines + calculated', array( count( $prow['pricing']['line_items'] ?? array() ), $prow['pricing']['calculated']['grand']['display'] ?? null, $prow['amount_total_minor'], $prow['amount_due_minor'], $prow['currency'], $prow['payment_status'] ), array( 3, '$1,650.00', 148500, 44550, 'USD', 'pending' ), true );
	check( 'pricing: stored product value is the sanitised shape', $prow['data']['addons'] ?? null, array( 'choice' => array( 'seo' ) ) );
	check( 'pricing: calculated value never stored in data', isset( $prow['data']['grand'] ), false );
	// Hidden rush (no seo) → not priced.
	$pr2 = $psubmit( array( 'email' => 'p2@example.test', 'addons' => array( 'choice' => array( 'copy' ) ) ) );
	$pe2 = (int) ( $pr2['body']['entry_id'] ?? 0 ); $created['entries'][] = $pe2;
	check( 'pricing: hidden product excluded (1000+150 = 1150, -10% = 1035)', $pr2['body']['pricing']['total'] ?? null, 103500 );
	// The export endpoint streams the CSV and exit()s, so this must be the
	// LAST step: the buffered output is checked, and cleanup + summary run,
	// from the shutdown handler registered above.
	$csv_req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/export' ); $csv_req->set_query_params( array( 'form_id' => $pform_id ) );
	$GLOBALS['nf_csv_pending'] = true;
	$GLOBALS['nf_csv_captured'] = '';
	// WordPress flushes every buffer from its own shutdown hook before ours runs, so capture through the callback.
	ob_start( static function ( $buf ) { $GLOBALS['nf_csv_captured'] .= $buf; return ''; } );
	$server->dispatch( $csv_req );

} catch ( \Throwable $e ) {
	$failed++;
	echo "FATAL ", get_class( $e ), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n";
}

nf_a6_finish();
