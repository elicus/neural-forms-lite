<?php
/**
 * B4 live harness (1.2.0 part B) — payment_confirmed workflow trigger,
 * payment tokens in actions, create_quote_draft action, quote pay links,
 * inbox payment views. Fake gateway as in B3PaymentsHarness.php.
 * Same bootstrap as A6LiveHarness.php.
 */
$_SERVER['HTTP_HOST'] = 'd5.local';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
define( 'WP_USE_THEMES', false );
require '/Users/ALI/Local Sites/diviplus/app/public/wp-load.php';

use NeuralForms\Forms\FormRepository;
use NeuralForms\Payments\PaymentProviderInterface;
use NeuralForms\Payments\PaymentProviderRegistry;
use NeuralForms\Payments\PaymentRepository;
use NeuralForms\Payments\PaymentService;
use NeuralForms\Quotes\QuoteRepository;
use NeuralForms\Quotes\QuoteService;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Workflows\WorkflowDefinition;
use NeuralForms\Workflows\WorkflowQueueProcessor;
use NeuralForms\Workflows\WorkflowRepository;
use NeuralForms\Workflows\WorkflowRunRepository;

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected ): void {
	global $passed, $failed;
	if ( $actual === $expected ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
}
final class NF_FakeGateway2 implements PaymentProviderInterface {
	public $state = array();
	public function id(): string { return 'fakepay'; }
	public function label(): string { return 'FakePay'; }
	public function is_configured(): bool { return true; }
	public function is_live(): bool { return false; }
	public function public_status(): array { return array(); }
	public function create_checkout( array $payment, array $entry, array $form, array $urls, array $details ) { $sid = 'fs_' . $payment['id']; $this->state[ $sid ] = array( 'state' => 'open', 'paid' => false, 'processing' => false, 'failed' => false, 'amount_minor' => $payment['amount_due_minor'], 'currency' => $payment['currency'], 'provider_payment_id' => 'fp_' . $payment['id'], 'account_id' => 'acct_fake', 'livemode' => false, 'amount_refunded_minor' => 0 ); return array( 'session_id' => $sid, 'url' => 'https://fakepay.test/checkout/' . $sid, 'account_id' => 'acct_fake', 'livemode' => false ); }
	public function fetch_checkout( array $payment ) { return $this->state[ $payment['session_id'] ] ?? new WP_Error( 'nf', 'unknown' ); }
	public function capture( array $payment ) { return $this->fetch_checkout( $payment ); }
	public function expire_checkout( array $payment ) { return true; }
	public $refunds = array();
	public function refund( array $payment, int $amount_minor, string $reason ) { $this->refunds[] = array( $payment['id'], $amount_minor, $reason ); if ( $this->fail_refund ?? false ) { return new WP_Error( 'fake_refund', 'Gateway refused the refund', array( 'status' => 424 ) ); } $sid = (string) $payment['session_id']; if ( isset( $this->state[ $sid ] ) ) { $this->state[ $sid ]['amount_refunded_minor'] = (int) $payment['amount_refunded_minor'] + $amount_minor; } return array( 'refund_id' => 'rf_' . count( $this->refunds ), 'amount_minor' => $amount_minor, 'total_refunded_minor' => (int) $payment['amount_refunded_minor'] + $amount_minor, 'status' => 'succeeded' ); }
	public function verify_webhook( WP_REST_Request $request ) { return json_decode( (string) $request->get_body(), true ); }
}
$gw = new NF_FakeGateway2();
add_filter( 'neural_forms_payment_providers', static function ( $p ) use ( $gw ) { $p['fakepay'] = $gw; return $p; } );
PaymentProviderRegistry::reset();
$mails = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
global $wpdb;
$created = array( 'forms' => array(), 'entries' => array(), 'workflows' => array() );
$settings_before = get_option( \NeuralForms\Quotes\QuoteSettings::OPTION, null );
\NeuralForms\Quotes\QuoteSettings::update( array( 'business_name' => 'Harness Studio', 'prefix' => 'HQ' ) );

try {
	$schema = array( 'fields' => array(
		array( 'id' => 'full_name', 'type' => 'text', 'label' => 'Name' ),
		array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'id' => 'service', 'type' => 'product', 'label' => 'Service', 'pricing' => array( 'mode' => 'fixed', 'price' => '1000.00' ) ),
	) );
	$settings = \NeuralForms\Forms\FormSettingsSanitizer::sanitize( array( 'pricing' => array( 'currency' => 'USD', 'deposit' => array( 'type' => 'percent', 'value' => '30' ) ), 'payment' => array( 'enabled' => true ) ) );
	$form_id  = (int) FormRepository::create( array( 'name' => 'B4 form', 'slug' => 'b4-' . time(), 'status' => 'active', 'form_schema' => $schema, 'settings' => $settings ) );
	$created['forms'][] = $form_id;
	$form = FormRepository::find( $form_id );

	// ── 1. meta exposes the trigger, the action and the payment meta sources ──
	$server = rest_get_server();
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$meta = $server->dispatch( new WP_REST_Request( 'GET', '/neural-forms/v1/workflows/meta' ) )->get_data();
	check( 'meta: payment_confirmed trigger listed', in_array( 'payment_confirmed', array_column( $meta['triggers'], 'id' ), true ), true );
	check( 'meta: create_quote_draft action listed', in_array( 'create_quote_draft', array_column( $meta['actions'], 'type' ), true ), true );
	check( 'meta: payment_status + payment_kind meta sources', in_array( 'payment_status', array_column( $meta['meta_sources'], 'id' ), true ) && in_array( 'payment_kind', array_column( $meta['meta_sources'], 'id' ), true ), true );

	// ── 2. workflows ────────────────────────────────────────────────
	// A: on payment confirmed (deposit only) → notify with payment tokens + set stage.
	$defA = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'B4 paid', 'trigger' => 'payment_confirmed',
		'conditions' => array( 'enabled' => true, 'version' => 2, 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'payment_kind', 'operator' => 'equals', 'value' => 'deposit' ) ) ),
		'actions' => array(
			array( 'type' => 'notify_internal', 'settings' => array( 'recipients' => 'team@harness.test', 'subject' => 'Paid {payment_amount} ({payment_kind})', 'body' => "Status: {payment_status}\nRemaining: {payment_remaining}\n{entry_url}" ) ),
			array( 'type' => 'set_stage', 'settings' => array( 'stage' => 'won' ) ),
		) ) );
	$wfA = WorkflowRepository::create( $defA ); $created['workflows'][] = $wfA;
	// B: on submission → create quote draft (no issue).
	$defB = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'B4 draft', 'trigger' => 'submission_received', 'actions' => array( array( 'type' => 'create_quote_draft', 'settings' => array( 'issue' => false, 'send' => false ) ) ) ) );
	$wfB = WorkflowRepository::create( $defB ); $created['workflows'][] = $wfB;
	check( 'workflows stored', $wfA > 0 && $wfB > 0, true );
	$bad = WorkflowDefinition::validate( WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'x', 'trigger' => 'submission_received', 'actions' => array( array( 'type' => 'create_quote_draft', 'settings' => array( 'issue' => false, 'send' => true ) ) ) ) ), $form );
	check( 'validation: send without issue rejected', is_wp_error( $bad ), true );

	// ── 3. submit → draft quote via workflow; payment pending ────────
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $form_id . '/submit' ); $req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'full_name' => 'Ada', 'email' => 'ada@harness.test', 'service' => array( 'choice' => '' ), 'neural_forms_website' => '' ) ) );
	$b = $server->dispatch( $req )->get_data(); $entry_id = (int) $b['entry_id']; $created['entries'][] = $entry_id; $pid = (int) $b['payment']['id'];
	for ( $i = 0; $i < 3; $i++ ) { WorkflowQueueProcessor::run(); }
	$runsB = WorkflowRunRepository::list_runs( $wfB );
	check( 'B ran on submission and finished', count( $runsB ) === 1 && $runsB[0]['status'] === 'done', true );
	$quotes = QuoteRepository::list_for_entry( $entry_id );
	check( 'quote draft created by the action (not issued, not sent)', count( $quotes ) === 1 && $quotes[0]['status'] === 'draft' && $quotes[0]['number'] === null && $quotes[0]['sent_at'] === null && count( array_filter( $mails, static function ( $m ) { return strpos( (string) $m['subject'], 'Quote' ) === 0; } ) ) === 0, true );
	check( 'A did NOT run on submission', count( WorkflowRunRepository::list_runs( $wfA ) ), 0 );
	$act = array_column( EntryActivity::list( $entry_id ), 'event_type' );
	check( 'activity: workflow.quote_created', in_array( 'workflow.quote_created', $act, true ), true );

	// ── 4. payment confirmed → A runs with tokens, stage → won ──────
	$hook = static function ( array $event ) use ( $server ) { $req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/webhook/fakepay' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( $event ) ); return $server->dispatch( $req ); };
	$evt = array( 'event_id' => 'b4_paid_' . $pid, 'type' => 'fake', 'raw_type' => 'fake', 'livemode' => false, 'account_id' => 'acct_fake', 'kind' => 'paid', 'session_id' => 'fs_' . $pid, 'provider_payment_id' => 'fp_' . $pid, 'amount_minor' => 30000, 'currency' => 'USD', 'amount_refunded_minor' => 0 );
	$res = $hook( $evt );
	check( 'webhook applied', $res->get_data()['status'] ?? '', 'paid' );
	for ( $i = 0; $i < 3; $i++ ) { WorkflowQueueProcessor::run(); }
	$runsA = WorkflowRunRepository::list_runs( $wfA );
	check( 'A ran once on payment_confirmed and finished', count( $runsA ) === 1 && $runsA[0]['status'] === 'done', true );
	check( 'event id is per payment attempt', $runsA[0]['event_id'] ?? '', 'payment:' . $entry_id . ':' . $pid );
	$last = end( $mails );
	check( 'tokens: subject has amount + kind', (string) $last['subject'], 'Paid $300.00 (deposit)' );
	check( 'tokens: body has status + remaining', strpos( (string) $last['message'], 'Status: Deposit paid' ) !== false && strpos( (string) $last['message'], 'Remaining: $700.00' ) !== false, true );
	check( 'set_stage action moved the lead to won', EntryRepository::get( $entry_id )['lead_stage'], 'won' );
	$res = $hook( $evt );
	check( 'redelivered webhook: no second run', $res->get_data()['result'] === 'duplicate' && count( WorkflowRunRepository::list_runs( $wfA ) ) === 1, true );

	// Condition on payment_kind: a FULL payment on another entry must be skipped by A.
	$req2 = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $form_id . '/submit' ); $req2->set_header( 'Content-Type', 'application/json' );
	$req2->set_body( wp_json_encode( array( 'full_name' => 'Bob', 'email' => 'bob@harness.test', 'service' => array( 'choice' => '' ), 'neural_forms_website' => '' ) ) );
	$b2 = $server->dispatch( $req2 )->get_data(); $e2 = (int) $b2['entry_id']; $created['entries'][] = $e2; $p2 = (int) $b2['payment']['id'];
	// Simulate a full payment: mark the attempt kind full before the webhook.
	$wpdb->update( $wpdb->prefix . 'neural_forms_payments', array( 'kind' => 'full', 'amount_due_minor' => 100000 ), array( 'id' => $p2 ) );
	$hook( array( 'event_id' => 'b4_paid_' . $p2, 'livemode' => false, 'account_id' => 'acct_fake', 'kind' => 'paid', 'session_id' => 'fs_' . $p2, 'amount_minor' => 100000, 'currency' => 'USD', 'raw_type' => 'fake' ) );
	for ( $i = 0; $i < 3; $i++ ) { WorkflowQueueProcessor::run(); }
	$runsA = WorkflowRunRepository::list_runs( $wfA );
	$skipped = array_filter( $runsA, static function ( $r ) use ( $e2 ) { return (int) $r['entry_id'] === $e2; } );
	check( 'condition payment_kind=deposit skips the full payment run', count( $skipped ) === 1 && reset( $skipped )['status'] === 'skipped', true );
	check( 'full payment entry aggregate = paid', EntryRepository::get( $e2 )['payment_status'], 'paid' );

	// ── 5. create_quote_draft with issue + send, then quote pay link ──
	$req3 = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $form_id . '/submit' ); $req3->set_header( 'Content-Type', 'application/json' );
	$req3->set_body( wp_json_encode( array( 'full_name' => 'Cy', 'email' => 'cy@harness.test', 'service' => array( 'choice' => '' ), 'neural_forms_website' => '' ) ) );
	$b3 = $server->dispatch( $req3 )->get_data(); $e3 = (int) $b3['entry_id']; $created['entries'][] = $e3;
	for ( $i = 0; $i < 3; $i++ ) { WorkflowQueueProcessor::run(); } // B creates the draft
	QuoteRepository::delete_for_entry( $e3 ); // make room for the issue+send variant
	$defC = WorkflowDefinition::normalize( array( 'form_id' => $form_id, 'name' => 'B4 send', 'trigger' => 'stage_changed', 'settings' => array( 'stage_to' => 'quote_sent' ), 'actions' => array( array( 'type' => 'create_quote_draft', 'settings' => array( 'issue' => true, 'send' => true, 'message' => 'Hi {full_name}, your quote is ready.' ) ) ) ) );
	$wfC = WorkflowRepository::create( $defC ); $created['workflows'][] = $wfC;
	$mails_before = count( $mails );
	EntryRepository::update( $e3, array( 'lead_stage' => 'quote_sent' ) );
	for ( $i = 0; $i < 3; $i++ ) { WorkflowQueueProcessor::run(); }
	$q3 = QuoteRepository::list_for_entry( $e3 );
	check( 'issue+send: quote issued with a number and sent', count( $q3 ) === 1 && $q3[0]['status'] === 'issued' && preg_match( '/^HQ-\d{4}-\d{4,}$/', (string) $q3[0]['number'] ) === 1 && $q3[0]['sent_at'] !== null, true );
	$qm = end( $mails );
	check( 'quote email: note rendered with tokens + pay link', count( $mails ) === $mails_before + 1 && strpos( (string) $qm['message'], 'Hi Cy, your quote is ready.' ) !== false && strpos( (string) $qm['message'], '/quotes/' . $q3[0]['id'] . '/pay?' ) !== false, true );
	$links = QuoteService::links( $q3[0] );
	check( 'links: pay link present for a payable quote', isset( $links['pay'] ), true );
	parse_str( (string) parse_url( $links['pay'], PHP_URL_QUERY ), $qs ); $token = (string) $qs['token'];
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $q3[0]['id'] . '/view' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'quote page shows the Pay button', strpos( (string) $res->get_data(), 'nf-quote__pay-btn' ) !== false && strpos( (string) $res->get_data(), 'Pay $300.00' ) !== false, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $q3[0]['id'] . '/pay' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'pay link → 302 to the gateway', $res->get_status() === 302 && strpos( (string) ( $res->get_headers()['Location'] ?? '' ), 'https://fakepay.test/checkout/' ) === 0, true );
	$pq = array_filter( PaymentRepository::list_for_entry( $e3 ), static function ( $p ) use ( $q3 ) { return (int) ( $p['quote_id'] ?? 0 ) === (int) $q3[0]['id']; } );
	$pq = array_values( $pq );
	check( 'CQ11: attempt bound to the quote with the quote amount (deposit 300.00)', count( $pq ) === 1 && $pq[0]['amount_due_minor'] === 30000 && $pq[0]['kind'] === 'deposit', true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $q3[0]['id'] . '/pay' ); $req->set_query_params( array( 'token' => $token ) ); $res2 = $server->dispatch( $req );
	check( 'second click reuses the same checkout (PAY8)', $res2->get_headers()['Location'] === $res->get_headers()['Location'] && count( PaymentRepository::list_for_entry( $e3 ) ) === count( $pq ) + 1, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $q3[0]['id'] . '/pay' ); $req->set_query_params( array( 'token' => 'nope' ) ); $res = $server->dispatch( $req );
	check( 'pay link with a bad token → 404 (CQ10)', $res->get_status(), 404 );
	$hook( array( 'event_id' => 'b4_paid_q', 'livemode' => false, 'account_id' => 'acct_fake', 'kind' => 'paid', 'session_id' => 'fs_' . $pq[0]['id'], 'amount_minor' => 30000, 'currency' => 'USD', 'raw_type' => 'fake' ) );
	check( 'after paying: quote no longer payable, no pay link', QuoteService::is_payable( QuoteRepository::get( (int) $q3[0]['id'] ) ) === false && ! isset( QuoteService::links( QuoteRepository::get( (int) $q3[0]['id'] ) )['pay'] ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $q3[0]['id'] . '/pay' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'pay link after payment → 409 already paid page', $res->get_status() === 409 && strpos( (string) $res->get_data(), 'already been paid' ) !== false, true );
	QuoteRepository::update( (int) $q3[0]['id'], array( 'status' => 'expired' ) );
	check( 'CQ9: expired quote not payable', QuoteService::is_payable( QuoteRepository::get( (int) $q3[0]['id'] ) ), false );

	// ── 6. inbox views + counts ─────────────────────────────────────
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries' ); $req->set_query_params( array( 'form_id' => $form_id, 'view' => 'paid' ) ); $res = $server->dispatch( $req );
	check( 'view=paid lists the deposit-paid, paid and quote-paid entries', count( $res->get_data() ), 3 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries' ); $req->set_query_params( array( 'form_id' => $form_id, 'view' => 'awaiting_payment' ) ); $res = $server->dispatch( $req );
	check( 'view=awaiting_payment lists none here', count( $res->get_data() ), 0 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/counts' ); $req->set_query_params( array( 'form_id' => $form_id ) ); $res = $server->dispatch( $req );
	check( 'counts include paid / awaiting_payment', ( $res->get_data()['paid'] ?? null ) === 3 && array_key_exists( 'awaiting_payment', $res->get_data() ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries' ); $req->set_query_params( array( 'form_id' => $form_id, 'view' => 'paid', 'search' => 'Ada' ) ); $res = $server->dispatch( $req );
	check( 'aliased view predicate works with search', $res->get_status() === 200 && count( $res->get_data() ) === 1, true );

	// ── 7. dry run describes the quote action ───────────────────────
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/workflows/' . $wfC . '/test' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( array( 'entry_id' => $e3 ) ) ); $res = $server->dispatch( $req );
	check( 'dry run: no quote created, action described', $res->get_status() === 200 && strpos( wp_json_encode( $res->get_data() ), 'issue and email a quote' ) !== false && count( QuoteRepository::list_for_entry( $e3 ) ) === 1, true );
} catch ( \Throwable $e ) {
	$failed++;
	echo "FATAL ", get_class( $e ), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n";
}

// ── cleanup ───────────────────────────────────────────────────────
foreach ( $created['workflows'] as $id ) { WorkflowRepository::delete( (int) $id ); $wpdb->query( $wpdb->prepare( "DELETE a FROM {$wpdb->prefix}neural_forms_workflow_actions a JOIN {$wpdb->prefix}neural_forms_workflow_runs r ON r.id = a.run_id WHERE r.workflow_id = %d", (int) $id ) ); $wpdb->delete( $wpdb->prefix . 'neural_forms_workflow_runs', array( 'workflow_id' => (int) $id ), array( '%d' ) ); }
foreach ( $created['entries'] as $id ) { if ( $id > 0 ) { EntryActivity::delete_for_entry( (int) $id ); EntryMessageRepository::delete_by_entry( (int) $id ); QuoteRepository::delete_for_entry( (int) $id ); foreach ( PaymentRepository::list_for_entry( (int) $id ) as $pp ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_payment_events', array( 'payment_id' => (int) $pp['id'] ), array( '%d' ) ); } PaymentRepository::delete_for_entry( (int) $id ); $wpdb->delete( $wpdb->prefix . 'neural_forms_logs', array( 'entry_id' => (int) $id ), array( '%d' ) ); EntryRepository::delete( (int) $id ); } }
$wpdb->query( "DELETE FROM {$wpdb->prefix}neural_forms_payment_events WHERE provider = 'fakepay'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}neural_forms_queue WHERE job_type = 'workflow' AND status IN ('completed','failed','pending')" );
foreach ( $created['forms'] as $id ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_forms', array( 'id' => (int) $id ), array( '%d' ) ); }
if ( null === $settings_before ) { delete_option( \NeuralForms\Quotes\QuoteSettings::OPTION ); } else { update_option( \NeuralForms\Quotes\QuoteSettings::OPTION, $settings_before, false ); }
echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
