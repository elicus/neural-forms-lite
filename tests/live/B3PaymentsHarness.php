<?php
/**
 * B3 live harness (1.2.0 part B) — payments against the LOCAL site with a
 * FAKE gateway registered through `neural_forms_payment_providers`, so the
 * whole path (submit → attempt → redirect, webhooks, reconciliation,
 * return page, aggregate status, workflow action) runs without network
 * access or real keys. Same bootstrap as A6LiveHarness.php.
 */
$_SERVER['HTTP_HOST'] = 'd5.local';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
define( 'WP_USE_THEMES', false );
require '/Users/ALI/Local Sites/diviplus/app/public/wp-load.php';

use NeuralForms\Database;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Payments\PaymentProviderInterface;
use NeuralForms\Payments\PaymentProviderRegistry;
use NeuralForms\Payments\PaymentRepository;
use NeuralForms\Payments\PaymentService;
use NeuralForms\Payments\PaymentTokens;
use NeuralForms\Quotes\QuoteRepository;
use NeuralForms\Quotes\QuoteService;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Storage\EntryRepository;

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected ): void {
	global $passed, $failed;
	if ( $actual === $expected ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
}

/** Fake gateway: records calls, returns scripted states. */
final class NF_FakeGateway implements PaymentProviderInterface {
	public $calls = array(); public $state = array(); public $fail_create = false; public $live = false;
	public function id(): string { return 'fakepay'; }
	public function label(): string { return 'FakePay'; }
	public function is_configured(): bool { return true; }
	public function is_live(): bool { return $this->live; }
	public function public_status(): array { return array( 'mode' => 'test' ); }
	public function create_checkout( array $payment, array $entry, array $form, array $urls, array $details ) {
		$this->calls[] = array( 'create', $payment['idempotency_key'], $details, $urls );
		if ( $this->fail_create ) { return new WP_Error( 'fake_down', 'Gateway down', array( 'status' => 424 ) ); }
		$sid = 'fs_' . $payment['id'];
		$this->state[ $sid ] = array( 'state' => 'open', 'paid' => false, 'processing' => false, 'failed' => false, 'amount_minor' => $payment['amount_due_minor'], 'currency' => $payment['currency'], 'provider_payment_id' => 'fp_' . $payment['id'], 'account_id' => 'acct_fake', 'livemode' => $this->live, 'amount_refunded_minor' => 0 );
		return array( 'session_id' => $sid, 'url' => 'https://fakepay.test/checkout/' . $sid, 'account_id' => 'acct_fake', 'livemode' => $this->live, 'provider_payment_id' => 'fp_' . $payment['id'] );
	}
	public function fetch_checkout( array $payment ) { $this->calls[] = array( 'fetch', $payment['session_id'] ); return $this->state[ $payment['session_id'] ] ?? new WP_Error( 'nf', 'unknown' ); }
	public function capture( array $payment ) { $this->calls[] = array( 'capture', $payment['session_id'] ); return $this->fetch_checkout( $payment ); }
	public function expire_checkout( array $payment ) { $this->calls[] = array( 'expire', $payment['session_id'] ); if ( isset( $this->state[ $payment['session_id'] ] ) ) { $this->state[ $payment['session_id'] ]['state'] = 'expired'; } return true; }
	public $refunds = array();
	public function refund( array $payment, int $amount_minor, string $reason ) { $this->refunds[] = array( $payment['id'], $amount_minor, $reason ); if ( $this->fail_refund ?? false ) { return new WP_Error( 'fake_refund', 'Gateway refused the refund', array( 'status' => 424 ) ); } $sid = (string) $payment['session_id']; if ( isset( $this->state[ $sid ] ) ) { $this->state[ $sid ]['amount_refunded_minor'] = (int) $payment['amount_refunded_minor'] + $amount_minor; } return array( 'refund_id' => 'rf_' . count( $this->refunds ), 'amount_minor' => $amount_minor, 'total_refunded_minor' => (int) $payment['amount_refunded_minor'] + $amount_minor, 'status' => 'succeeded' ); }
	public function verify_webhook( WP_REST_Request $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		if ( (string) $request->get_header( 'x_fake_sig' ) !== 'good' ) { return new WP_Error( 'fake_sig', 'bad signature', array( 'status' => 400 ) ); }
		return $body;
	}
}
$gw = new NF_FakeGateway();
add_filter( 'neural_forms_payment_providers', static function ( $p ) use ( $gw ) { $p['fakepay'] = $gw; return $p; } );
PaymentProviderRegistry::reset();
$confirmed = array();
add_action( 'neural_forms_payment_confirmed', static function ( $entry_id, $payment, $agg ) use ( &$confirmed ) { $confirmed[] = array( $entry_id, $payment['id'], $agg['status'] ); }, 10, 3 );
$mails = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
global $wpdb;
$created = array( 'forms' => array(), 'entries' => array() );
$mk_event = static function ( array $o ) { return $o + array( 'event_id' => 'evt_' . wp_generate_password( 8, false ), 'type' => 'fake', 'raw_type' => 'fake', 'livemode' => false, 'account_id' => 'acct_fake', 'kind' => 'paid', 'session_id' => '', 'provider_payment_id' => '', 'amount_minor' => null, 'currency' => 'USD', 'amount_refunded_minor' => 0 ); };

try {
	update_option( 'neural_forms_db_version', '1.2.2' );
	Database::maybe_upgrade();
	check( 'upgrade: payments tables exist', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'neural_forms_payment_events' ) ) !== null, true );
	check( 'cron: reconcile job scheduled', wp_next_scheduled( 'neural_forms_payment_reconcile' ) !== false, true );
	check( 'registry: fake provider registered + configured', isset( PaymentProviderRegistry::configured()['fakepay'] ), true );

	$schema  = array( 'fields' => array(
		array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'id' => 'service', 'type' => 'product', 'label' => 'Service', 'pricing' => array( 'mode' => 'fixed', 'price' => '1000.00' ) ),
		array( 'id' => 'addons', 'type' => 'product', 'label' => 'Add-ons', 'pricing' => array( 'mode' => 'options', 'display' => 'checkboxes' ), 'options' => array( array( 'value' => 'seo', 'label' => 'SEO', 'price' => '150' ), array( 'value' => 'copy', 'label' => 'Copy', 'price' => '150' ) ) ),
	) );
	$settings = \NeuralForms\Forms\FormSettingsSanitizer::sanitize( array(
		'pricing' => array( 'currency' => 'USD', 'discounts' => array( array( 'id' => 'ten', 'label' => 'Launch', 'type' => 'percent', 'value' => '10' ) ), 'deposit' => array( 'type' => 'percent', 'value' => '30' ) ),
		'payment' => array( 'enabled' => true, 'provider' => 'auto', 'submit_label' => 'Pay deposit' ),
	) );
	check( 'settings: payment block kept', $settings['payment']['enabled'] ?? null, true );
	check( 'validator: payment settings accepted (Pro caps)', \NeuralForms\Forms\FormValidator::validate_settings_logic( $settings, $schema ), true );
	$form_id = (int) FormRepository::create( array( 'name' => 'B3 payments form', 'slug' => 'b3-pay-' . time(), 'status' => 'active', 'form_schema' => $schema, 'settings' => $settings ) );
	$created['forms'][] = $form_id;
	$form = FormRepository::find( $form_id );
	check( 'renderer: submit label says Pay deposit', strpos( \NeuralForms\Frontend\FormRenderer::render( $form_id ), 'Pay deposit' ) !== false, true );

	// ── submit → redirect ─────────────────────────────────────────
	$server = rest_get_server();
	$submit = static function () use ( $server, $form_id ) {
		$req = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $form_id . '/submit' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'email' => 'buyer@harness.test', 'service' => array( 'choice' => '' ), 'addons' => array( 'choice' => array( 'seo', 'copy' ) ), 'neural_forms_website' => '' ) ) );
		return $server->dispatch( $req );
	};
	$res = $submit();
	$b   = $res->get_data();
	check( 'submit: 201 with redirect outcome to the gateway', $res->get_status() === 201 && ( $b['outcome']['mode'] ?? '' ) === 'redirect' && strpos( (string) ( $b['outcome']['url'] ?? '' ), 'https://fakepay.test/checkout/' ) === 0, true );
	$entry_id = (int) $b['entry_id']; $created['entries'][] = $entry_id;
	$pid      = (int) $b['payment']['id'];
	$p        = PaymentRepository::get( $pid );
	check( 'attempt: deposit 351.00 pending with session + idempotency key', array( $p['kind'], $p['amount_due_minor'], $p['status'], $p['session_id'], $p['idempotency_key'] ), array( 'deposit', 35100, 'pending', 'fs_' . $pid, 'nf-' . $entry_id . '-0-1' ) );
	check( 'entry aggregate pending', EntryRepository::get( $entry_id )['payment_status'], 'pending' );
	$urls = $gw->calls[0][3];
	check( 'urls: return + cancel carry a token, webhook route is Lite-owned', strpos( $urls['success'], '/payments/' . $pid . '/return' ) !== false && strpos( $urls['success'], 'token=' ) !== false && strpos( $urls['cancel'], 'result=cancel' ) !== false && strpos( $urls['webhook'], '/neural-forms/v1/payments/webhook/fakepay' ) !== false, true );
	check( 'details: single deposit line, customer email', count( $gw->calls[0][2]['line_items'] ) === 1 && $gw->calls[0][2]['line_items'][0]['amount_minor'] === 35100 && $gw->calls[0][2]['customer_email'] === 'buyer@harness.test', true );
	check( 'no secrets/cards in entry data', ! isset( EntryRepository::get( $entry_id )['data']['nf_payment_provider'] ), true );

	// PAY8: a second start for the same entry reuses the open attempt.
	$again = PaymentService::start_checkout( EntryRepository::get( $entry_id ), $form );
	check( 'PAY8: retry reuses the open attempt (no second create)', $again['reused'] === true && (int) $again['payment']['id'] === $pid && count( array_filter( $gw->calls, static function ( $c ) { return $c[0] === 'create'; } ) ) === 1, true );

	// PAY3: forged return cannot mark paid.
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/return' ); $req->set_query_params( array( 'token' => 'forged', 'result' => 'success' ) ); $res = $server->dispatch( $req );
	check( 'PAY3: forged return token → 404', $res->get_status(), 404 );
	$token = PaymentTokens::derive( $pid, (string) $p['token_seed'] );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/return' ); $req->set_query_params( array( 'token' => $token, 'result' => 'success' ) ); $res = $server->dispatch( $req );
	check( 'PAY3: valid return before the gateway confirms → still pending, page says waiting', PaymentRepository::get( $pid )['status'] === 'pending' && strpos( (string) $res->get_data(), 'Waiting for confirmation' ) !== false, true );

	// PAY4: bad signature / mismatches never mark paid.
	$hook = static function ( array $event, string $sig = 'good' ) use ( $server ) { $req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/webhook/fakepay' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_header( 'X-Fake-Sig', $sig ); $req->set_body( wp_json_encode( $event ) ); return $server->dispatch( $req ); };
	$res = $hook( $mk_event( array( 'session_id' => 'fs_' . $pid, 'amount_minor' => 35100 ) ), 'bad' );
	check( 'PAY4: invalid signature → 400, still pending', $res->get_status() === 400 && PaymentRepository::get( $pid )['status'] === 'pending', true );
	$res = $hook( $mk_event( array( 'session_id' => 'fs_' . $pid, 'amount_minor' => 100 ) ) );
	check( 'PAY4: amount mismatch ignored + flagged', $res->get_data()['result'] === 'ignored' && PaymentRepository::get( $pid )['status'] === 'pending' && ! empty( PaymentRepository::get( $pid )['meta']['needs_reconciliation'] ), true );
	$res = $hook( $mk_event( array( 'session_id' => 'fs_' . $pid, 'amount_minor' => 35100, 'livemode' => true ) ) );
	check( 'PAY10: live event on test attempt ignored', $res->get_data()['reason'] ?? '', 'mode_mismatch' );
	$res = $hook( $mk_event( array( 'session_id' => 'fs_' . $pid, 'amount_minor' => 35100, 'currency' => 'EUR' ) ) );
	check( 'PAY4: currency mismatch ignored', $res->get_data()['reason'] ?? '', 'currency_mismatch' );
	$res = $hook( $mk_event( array( 'session_id' => 'fs_nope', 'amount_minor' => 35100 ) ) );
	check( 'unknown session → unmatched', $res->get_data()['result'], 'unmatched' );
	$res = $hook( $mk_event( array( 'event_id' => 'evt_unknown_type', 'kind' => 'ignored', 'session_id' => 'fs_' . $pid ) ) );
	check( 'ignored event kind', $res->get_data()['result'], 'ignored' );

	// PAY1/PAY6: the verified paid webhook marks the deposit paid, fires the action once.
	$paid_evt = $mk_event( array( 'event_id' => 'evt_paid_1', 'session_id' => 'fs_' . $pid, 'amount_minor' => 35100, 'provider_payment_id' => 'fp_' . $pid ) );
	$res = $hook( $paid_evt );
	check( 'PAY1: paid webhook applied', array( $res->get_data()['result'], $res->get_data()['status'] ), array( 'applied', 'paid' ) );
	check( 'PAY2: entry aggregate = deposit_paid (never "paid in full")', EntryRepository::get( $entry_id )['payment_status'], 'deposit_paid' );
	$sum = PaymentService::entry_summary( $entry_id );
	check( 'PAY2: paid 351.00, remaining 819.00', array( $sum['formatted']['paid'], $sum['formatted']['remaining'] ), array( '$351.00', '$819.00' ) );
	check( 'payment_confirmed fired once', count( $confirmed ) === 1 && $confirmed[0][0] === $entry_id && $confirmed[0][2] === 'deposit_paid', true );
	$res = $hook( $paid_evt );
	check( 'PAY5: redelivered event → duplicate, no second action', $res->get_data()['result'] === 'duplicate' && count( $confirmed ) === 1, true );
	$res = $hook( $mk_event( array( 'kind' => 'failed', 'session_id' => 'fs_' . $pid ) ) );
	check( 'PAY5: late failed event cannot regress paid', $res->get_data()['reason'] === 'regression_blocked' && PaymentRepository::get( $pid )['status'] === 'paid', true );
	$res = $hook( $mk_event( array( 'kind' => 'processing', 'session_id' => 'fs_' . $pid ) ) );
	check( 'PAY5: out-of-order processing after paid ignored', PaymentRepository::get( $pid )['status'], 'paid' );
	$act = array_column( EntryActivity::list( $entry_id ), 'event_type' );
	check( 'activity: started + paid + mismatch warning', in_array( 'payment.started', $act, true ) && in_array( 'payment.paid', $act, true ) && in_array( 'payment.mismatch', $act, true ), true );
	check( 'PAY11: lead stage untouched', EntryRepository::get( $entry_id )['lead_stage'], 'new' );

	// Return page after payment: reads stored state.
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/return' ); $req->set_query_params( array( 'token' => $token, 'result' => 'success' ) ); $res = $server->dispatch( $req );
	check( 'return page: deposit received + remaining balance', strpos( (string) $res->get_data(), 'Deposit received' ) !== false && strpos( (string) $res->get_data(), '$819.00' ) !== false, true );

	// ── Invoices (auto + manual) and refunds from the admin ────────
	$adm_mail = array_values( array_filter( $mails, static function ( $m ) { return strpos( (string) $m['subject'], 'Deposit received:' ) === 0; } ) );
	check( 'site owner emailed on confirmed payment (amount, remaining, entry link)', count( $adm_mail ) === 1 && strpos( (string) $adm_mail[0]['message'], '$351.00' ) !== false && strpos( (string) $adm_mail[0]['message'], 'Remaining balance: $819.00' ) !== false && strpos( (string) $adm_mail[0]['message'], 'entry_id=' . $entry_id ) !== false, true );
	$inv_mail = array_values( array_filter( $mails, static function ( $m ) { return strpos( (string) $m['subject'], 'Invoice INV-' ) === 0; } ) );
	check( 'auto invoice emailed on confirmed payment (default on)', count( $inv_mail ) === 1 && strpos( (string) $inv_mail[0]['message'], '/payments/' . $pid . '/invoice?' ) !== false && is_array( $inv_mail[0]['attachments'] ) && count( $inv_mail[0]['attachments'] ) === 1, true );
	$pinv = PaymentRepository::get( $pid );
	check( 'invoice number assigned once (INV-YYYY-NNNN) + sent_to recorded', preg_match( '/^INV-' . gmdate( 'Y' ) . '-\d{4,}$/', (string) ( $pinv['meta']['invoice_number'] ?? '' ) ) === 1 && ( $pinv['meta']['invoice_sent_to'] ?? '' ) === 'buyer@harness.test', true );
	$inv_no = (string) $pinv['meta']['invoice_number'];
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/invoice' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'public invoice: html with number, PAID stamp, amount and remaining balance', $res->get_status() === 200 && strpos( (string) $res->get_data(), $inv_no ) !== false && strpos( (string) $res->get_data(), '>PAID<' ) !== false && strpos( (string) $res->get_data(), '$351.00' ) !== false && strpos( (string) $res->get_data(), '$819.00' ) !== false, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/invoice' ); $req->set_query_params( array( 'token' => $token, 'format' => 'pdf' ) ); $res = $server->dispatch( $req );
	check( 'public invoice pdf', $res->get_status() === 200 && strncmp( (string) $res->get_data(), '%PDF', 4 ) === 0, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/invoice' ); $req->set_query_params( array( 'token' => 'nope' ) ); $res = $server->dispatch( $req );
	check( 'invoice with bad token → 404', $res->get_status(), 404 );
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/invoice' ); $res = $server->dispatch( $req );
	check( 'admin invoice preview without token', $res->get_status(), 200 );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $pid . '/invoice/send' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( array( 'to' => 'accounts@harness.test', 'message' => 'Copy for your records.' ) ) ); $res = $server->dispatch( $req );
	$last = end( $mails );
	check( 'manual invoice send to another address with note', $res->get_status() === 200 && (string) $last['to'] === 'accounts@harness.test' && strpos( (string) $last['message'], 'Copy for your records.' ) !== false && strpos( (string) $last['subject'], $inv_no ) !== false, true );
	check( 'invoice number stable across sends', PaymentRepository::get( $pid )['meta']['invoice_number'], $inv_no );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $pid . '/refund' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( array( 'amount_minor' => 99999 ) ) ); $res = $server->dispatch( $req );
	check( 'refund above the paid amount → 422', $res->get_status(), 422 );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $pid . '/refund' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( array( 'amount_minor' => 10000, 'reason' => 'Goodwill' ) ) ); $res = $server->dispatch( $req );
	check( 'partial refund via the gateway → partially_refunded', $res->get_status() === 200 && $res->get_data()['payment']['status'] === 'partially_refunded' && $res->get_data()['payment']['amount_refunded_minor'] === 10000 && $gw->refunds[0][1] === 10000 && $gw->refunds[0][2] === 'Goodwill', true );
	check( 'refund: entry aggregate partially_refunded, refundable 251.00 left', EntryRepository::get( $entry_id )['payment_status'] === 'partially_refunded' && $res->get_data()['payment']['refundable_minor'] === 25100, true );
	$gw->fail_refund = true;
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $pid . '/refund' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( '{}' ); $res = $server->dispatch( $req );
	check( 'gateway refuses → 424, nothing recorded', $res->get_status() === 424 && PaymentRepository::get( $pid )['amount_refunded_minor'] === 10000, true );
	$gw->fail_refund = false;
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $pid . '/refund' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( '{}' ); $res = $server->dispatch( $req );
	check( 'refund the rest → refunded', $res->get_status() === 200 && $res->get_data()['payment']['status'] === 'refunded' && $res->get_data()['payment']['amount_refunded_minor'] === 35100, true );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $pid . '/refund' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( '{}' ); $res = $server->dispatch( $req );
	check( 'nothing left to refund → 409', $res->get_status(), 409 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $pid . '/invoice' ); $res = $server->dispatch( $req );
	check( 'invoice shows REFUNDED stamp afterwards', strpos( (string) $res->get_data(), '>REFUNDED<' ) !== false, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments' ); $req->set_query_params( array( 'status' => 'refunded,paid', 'search' => (string) $entry_id ) ); $res = $server->dispatch( $req );
	$d = $res->get_data();
	check( 'payments list: filtered row with customer, entry url, invoice links; totals per currency', $res->get_status() === 200 && $d['total'] === 1 && $d['payments'][0]['meta']['customer_email'] === 'buyer@harness.test' && strpos( $d['payments'][0]['entry_url'], 'entry_id=' . $entry_id ) !== false && isset( $d['payments'][0]['invoice_links']['view'] ) && is_array( $d['totals'] ) && ! isset( $d['payments'][0]['token_seed'] ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments' ); $req->set_query_params( array( 'provider' => 'fakepay', 'form_id' => $form_id ) ); $res = $server->dispatch( $req );
	$tot = array_values( array_filter( $res->get_data()['totals'], static function ( $t ) { return $t['currency'] === 'USD' && ! $t['livemode']; } ) );
	check( 'totals: paid 351.00, refunded 351.00, net 0', $tot && $tot[0]['paid'] === 35100 && $tot[0]['refunded'] === 35100 && $tot[0]['net'] === 0, true );
	wp_set_current_user( 0 );

	// Refund (PAY11): financial status only.
	$res = $hook( $mk_event( array( 'event_id' => 'evt_refund_1', 'kind' => 'refunded', 'provider_payment_id' => 'fp_' . $pid, 'amount_refunded_minor' => 35100 ) ) );
	check( 'refund webhook after full refund: cumulative total keeps the row refunded', array( PaymentRepository::get( $pid )['status'], EntryRepository::get( $entry_id )['payment_status'] ), array( 'refunded', 'refunded' ) );
	check( 'PAY11: refund never changes lead stage', EntryRepository::get( $entry_id )['lead_stage'], 'new' );

	// ── second entry: cancel, replacement, reconciliation, admin REST ──
	$res2 = $submit(); $b2 = $res2->get_data(); $e2 = (int) $b2['entry_id']; $created['entries'][] = $e2; $p2 = (int) $b2['payment']['id'];
	$tok2 = PaymentTokens::derive( $p2, (string) PaymentRepository::get( $p2 )['token_seed'] );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/' . $p2 . '/return' ); $req->set_query_params( array( 'token' => $tok2, 'result' => 'cancel' ) ); $res = $server->dispatch( $req );
	check( 'PAY7: buyer cancel → attempt expired, entry cancelled, page says cancelled', PaymentRepository::get( $p2 )['status'] === 'expired' && EntryRepository::get( $e2 )['payment_status'] === 'cancelled' && strpos( (string) $res->get_data(), 'Checkout cancelled' ) !== false, true );
	$r = PaymentService::start_checkout( EntryRepository::get( $e2 ), $form );
	$p3 = (int) $r['payment']['id'];
	check( 'PAY9: new attempt after cancel (attempt 2, new session)', $r['reused'] === false && $p3 !== $p2 && PaymentRepository::get( $p3 )['attempt'] === 2, true );
	// Price change on the form must not touch the open attempt's amount (PAY9).
	$schema2 = $schema; $schema2['fields'][1]['pricing']['price'] = '9000.00';
	FormRepository::update( $form_id, array( 'form_schema' => $schema2 ) );
	check( 'PAY9: open attempt amount unchanged after form price change', PaymentRepository::get( $p3 )['amount_due_minor'], 35100 );
	// Reconciliation (PAY12): gateway says paid, webhook never arrived.
	$gw->state[ 'fs_' . $p3 ]['paid'] = true; $gw->state[ 'fs_' . $p3 ]['state'] = 'complete';
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/payments/' . $p3 . '/check' ); $res = $server->dispatch( $req );
	check( 'PAY12: "Check status now" reconciles to paid without charging again', $res->get_status() === 200 && $res->get_data()['status'] === 'paid' && count( array_filter( $gw->calls, static function ( $c ) { return $c[0] === 'create'; } ) ) === 3, true );
	check( 'PAY12: entry deposit_paid after reconciliation; action fired', EntryRepository::get( $e2 )['payment_status'] === 'deposit_paid' && count( $confirmed ) === 2, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/' . $e2 . '/payments' ); $res = $server->dispatch( $req );
	$d = $res->get_data();
	check( 'REST summary: attempts + events, no seed', count( $d['attempts'] ) === 2 && ! isset( $d['attempts'][0]['token_seed'] ) && isset( $d['attempts'][0]['events'] ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payments/providers' ); $res = $server->dispatch( $req );
	check( 'REST providers lists fakepay without secrets', $res->get_data()['unlocked'] === true && in_array( 'fakepay', array_column( $res->get_data()['providers'], 'id' ), true ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries' ); $req->set_query_params( array( 'form_id' => $form_id, 'payment_status' => 'deposit_paid,paid' ) ); $res = $server->dispatch( $req );
	check( 'REST list filter payment_status', count( $res->get_data() ), 1 );

	// PAY12 double payment: the cancelled attempt of entry 2 also "succeeds" later.
	$res = $hook( $mk_event( array( 'event_id' => 'evt_double', 'session_id' => 'fs_' . $p2, 'amount_minor' => 35100, 'provider_payment_id' => 'fp_' . $p2 ) ) );
	check( 'PAY12: late success on the replaced attempt is recorded', $res->get_data()['result'], 'applied' );
	$act2 = array_column( EntryActivity::list( $e2 ), 'event_type' );
	check( 'PAY12: double payment flagged for manual reconciliation, nothing auto-refunded', in_array( 'payment.needs_reconciliation', $act2, true ) && PaymentService::entry_summary( $e2 )['needs_reconciliation'] === true, true );

	// Gateway failure never fails the submission.
	$gw->fail_create = true;
	$res3 = $submit(); $b3 = $res3->get_data(); $created['entries'][] = (int) $b3['entry_id'];
	check( 'gateway down: 201, no redirect, payment_error message, attempt failed', $res3->get_status() === 201 && ! isset( $b3['outcome'] ) && isset( $b3['payment_error'] ) && PaymentRepository::list_for_entry( (int) $b3['entry_id'] )[0]['status'] === 'failed', true );
	$gw->fail_create = false;

	// Quote pay (CQ9/CQ11): expired quote refuses, valid quote amount wins.
	$e4 = (int) $b3['entry_id'];
	$q  = QuoteService::create_draft( $e4, array(), 1 ); $q = QuoteService::issue( (int) $q['id'], 1 );
	$qr = PaymentService::start_checkout( EntryRepository::get( $e4 ), $form, array( 'quote' => $q ) );
	check( 'CQ11: checkout from the quote uses the quote amount + quote id', is_array( $qr ) && (int) $qr['payment']['quote_id'] === (int) $q['id'] && $qr['payment']['amount_due_minor'] === (int) $q['snapshot']['due_now'], true );
	QuoteRepository::update( (int) $q['id'], array( 'status' => 'expired' ) );
	$qr2 = PaymentService::start_checkout( EntryRepository::get( $e4 ), $form, array( 'quote' => QuoteRepository::get( (int) $q['id'] ) ) );
	check( 'CQ9: expired quote cannot start a checkout (410)', is_wp_error( $qr2 ) ? $qr2->get_error_data()['status'] : null, 410 );

	// Locked capability: no checkout, normal confirmation.
	add_filter( 'neural_forms_can_payments', '__return_false', 99 );
	$res5 = $submit(); $b5 = $res5->get_data(); $created['entries'][] = (int) $b5['entry_id'];
	check( 'locked: submission succeeds without any payment attempt', $res5->get_status() === 201 && ! isset( $b5['payment'] ) && PaymentRepository::list_for_entry( (int) $b5['entry_id'] ) === array(), true );
	remove_filter( 'neural_forms_can_payments', '__return_false', 99 );
} catch ( \Throwable $e ) {
	$failed++;
	echo "FATAL ", get_class( $e ), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n";
}

// ── cleanup ───────────────────────────────────────────────────────
foreach ( $created['entries'] as $id ) { if ( $id > 0 ) { EntryActivity::delete_for_entry( (int) $id ); EntryMessageRepository::delete_by_entry( (int) $id ); QuoteRepository::delete_for_entry( (int) $id ); foreach ( PaymentRepository::list_for_entry( (int) $id ) as $pp ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_payment_events', array( 'payment_id' => (int) $pp['id'] ), array( '%d' ) ); } PaymentRepository::delete_for_entry( (int) $id ); EntryRepository::delete( (int) $id ); } }
$wpdb->query( "DELETE FROM {$wpdb->prefix}neural_forms_payment_events WHERE provider = 'fakepay'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}neural_forms_queue WHERE job_type = 'workflow' AND status IN ('completed','failed','pending')" );
foreach ( $created['forms'] as $id ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_forms', array( 'id' => (int) $id ), array( '%d' ) ); }
echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
