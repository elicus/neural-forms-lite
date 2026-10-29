<?php
/**
 * Buy button live harness (1.2.0 part B): renderer, shortcode, block
 * registration and POST /forms/{id}/buy with the fake gateway.
 * Same bootstrap as A6LiveHarness.php (throwaway d5 database).
 */
$_SERVER['HTTP_HOST'] = 'd5.local';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
define( 'WP_USE_THEMES', false );
require '/Users/ALI/Local Sites/diviplus/app/public/wp-load.php';

use NeuralForms\Forms\FormRepository;
use NeuralForms\Payments\PaymentForm;
use NeuralForms\Payments\PaymentProviderInterface;
use NeuralForms\Payments\PaymentProviderRegistry;
use NeuralForms\Payments\PaymentRepository;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Storage\EntryRepository;

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected ): void {
	global $passed, $failed;
	if ( $actual === $expected ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
}
final class NF_FakeGateway3 implements PaymentProviderInterface {
	public $created = 0;
	public function id(): string { return 'fakepay'; } public function label(): string { return 'FakePay'; } public function is_configured(): bool { return true; } public function is_live(): bool { return false; } public function public_status(): array { return array(); }
	public function create_checkout( array $payment, array $entry, array $form, array $urls, array $details ) { $this->created++; return array( 'session_id' => 'fs_' . $payment['id'], 'url' => 'https://fakepay.test/checkout/fs_' . $payment['id'], 'account_id' => 'acct', 'livemode' => false ); }
	public function fetch_checkout( array $payment ) { return array( 'state' => 'open', 'paid' => false, 'processing' => false, 'failed' => false, 'amount_minor' => $payment['amount_due_minor'], 'currency' => $payment['currency'], 'provider_payment_id' => '', 'account_id' => 'acct', 'livemode' => false, 'amount_refunded_minor' => 0 ); }
	public function capture( array $payment ) { return $this->fetch_checkout( $payment ); } public function expire_checkout( array $payment ) { return true; }
	public function verify_webhook( WP_REST_Request $request ) { return json_decode( (string) $request->get_body(), true ); }
	public function refund( array $payment, int $amount_minor, string $reason ) { return array( 'refund_id' => 'rf', 'amount_minor' => $amount_minor, 'total_refunded_minor' => $amount_minor, 'status' => 'succeeded' ); }
}
$gw = new NF_FakeGateway3(); add_filter( 'neural_forms_payment_providers', static function ( $p ) use ( $gw ) { $p['fakepay'] = $gw; return $p; } ); PaymentProviderRegistry::reset();
$mails = array(); add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
global $wpdb; $created = array( 'forms' => array(), 'entries' => array() );

try {
	$schema = array( 'fields' => array(
		array( 'id' => 'buyer_name', 'type' => 'text', 'label' => 'Your name' ),
		array( 'id' => 'contact', 'type' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'id' => 'book', 'type' => 'product', 'label' => 'Handbook', 'pricing' => array( 'mode' => 'fixed', 'price' => '29.00', 'quantity' => array( 'enabled' => true, 'min' => 1, 'max' => 5, 'default' => 1 ) ) ),
		array( 'id' => 'tier', 'type' => 'product', 'label' => 'Support plan', 'pricing' => array( 'mode' => 'options', 'display' => 'radio' ), 'options' => array( array( 'value' => 'basic', 'label' => 'Basic', 'price' => '10' ), array( 'value' => 'pro', 'label' => 'Pro', 'price' => '25' ) ) ),
		array( 'id' => 'address', 'type' => 'textarea', 'label' => 'Address', 'required' => true ),
	) );
	$settings = \NeuralForms\Forms\FormSettingsSanitizer::sanitize( array( 'pricing' => array( 'currency' => 'USD' ), 'payment' => array( 'enabled' => true, 'notify_admin' => false ) ) );
	$form_id  = (int) FormRepository::create( array( 'name' => 'Buy harness', 'slug' => 'buy-harness-' . time(), 'status' => 'active', 'form_schema' => $schema, 'settings' => $settings ) );
	$created['forms'][] = $form_id;
	$off_id = (int) FormRepository::create( array( 'name' => 'Buy harness off', 'slug' => 'buy-harness-off-' . time(), 'status' => 'active', 'form_schema' => $schema, 'settings' => \NeuralForms\Forms\FormSettingsSanitizer::sanitize( array( 'pricing' => array( 'currency' => 'USD' ) ) ) ) );
	$created['forms'][] = $off_id;

	// ── renderer / resolve ──────────────────────────────────────────
	check( 'block registered', \WP_Block_Type_Registry::get_instance()->is_registered( 'neural-forms/payment-form' ), true );
	check( 'shortcode registered', shortcode_exists( 'neural-forms-payment-form' ), true );
	$r = PaymentForm::resolve( array( 'formId' => $form_id ) );
	check( 'resolve: first product, fixed 29.00, qty 1..5', array( $r['ok'], $r['field']['id'], $r['unit_minor'], $r['qty_min'], $r['qty_max'], $r['qty_on'] ), array( true, 'book', 2900, 1, 5, true ) );
	$r2 = PaymentForm::resolve( array( 'formId' => $form_id, 'fieldId' => 'tier', 'choice' => 'pro' ) );
	check( 'resolve: option pro 25.00, no qty', array( $r2['ok'], $r2['unit_minor'], $r2['title'], $r2['qty_on'] ), array( true, 2500, 'Pro', false ) );
	check( 'resolve: payments off → no_payments', PaymentForm::resolve( array( 'formId' => $off_id ) )['reason'], 'no_payments' );
	check( 'resolve: unknown option → no_option', PaymentForm::resolve( array( 'formId' => $form_id, 'fieldId' => 'tier', 'choice' => 'nope' ) )['reason'], 'no_option' );
	$html = PaymentForm::render( array( 'formId' => $form_id, 'quantity' => 2, 'label' => 'Grab it', 'description' => 'Ships worldwide' ) );
	check( 'render: card with data attrs, qty 2, total 58.00, label', strpos( $html, 'data-neural-forms-payment-form' ) !== false && strpos( $html, 'data-unit="2900"' ) !== false && strpos( $html, 'value="2"' ) !== false && strpos( $html, '$58.00' ) !== false && strpos( $html, 'Grab it' ) !== false && strpos( $html, 'Ships worldwide' ) !== false && strpos( $html, '/forms/' . $form_id . '/pay' ) !== false, true );
	check( 'render: honeypot + hidden field/choice', strpos( $html, 'name="neural_forms_website"' ) !== false && strpos( $html, 'name="field" value="book"' ) !== false, true );
	check( 'render: scripts enqueued (self-registered outside wp_enqueue_scripts)', wp_script_is( 'neural-forms-payment-form', 'enqueued' ) && wp_style_is( 'neural-forms-form', 'enqueued' ), true );
	check( 'render: payments-off form → empty for visitors', PaymentForm::render( array( 'formId' => $off_id ) ), '' );
	check( 'render: preview explains why', strpos( PaymentForm::render( array( 'formId' => $off_id ), array( 'preview' => true ) ), 'does not take payments' ) !== false, true );
	check( 'shortcode renders', strpos( do_shortcode( '[neural-forms-payment-form form="' . $form_id . '" field="tier" choice="basic" label="Subscribe"]' ), 'Subscribe' ) !== false, true );
	$block = do_blocks( '<!-- wp:neural-forms/payment-form {"formId":' . $form_id . ',"fieldId":"book"} /-->' );
	check( 'block renders through do_blocks', strpos( $block, 'data-neural-forms-payment-form' ) !== false && strpos( $block, 'wp-block-neural-forms-payment-form' ) !== false, true );

	// ── catalogue ──────────────────────────────────────────────────
	$server = rest_get_server(); wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$res = $server->dispatch( new WP_REST_Request( 'GET', '/neural-forms/v1/payment-form/catalogue' ) ); $cat = $res->get_data();
	$mine = array_values( array_filter( $cat['forms'], static function ( $f ) use ( $form_id ) { return $f['id'] === $form_id; } ) );
	check( 'catalogue lists the form with two products, options and payments flag', $res->get_status() === 200 && $mine && count( $mine[0]['products'] ) === 2 && $mine[0]['payments'] === true && count( $mine[0]['products'][1]['options'] ) === 2, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/payment-form/preview' ); $req->set_query_params( array( 'formId' => (string) $form_id, 'fieldId' => 'tier', 'choice' => 'pro', 'label' => 'Go' ) ); $res = $server->dispatch( $req );
	check( 'preview endpoint returns the card html', strpos( (string) $res->get_data()['html'], 'Go' ) !== false && strpos( (string) $res->get_data()['html'], '$25.00' ) !== false, true );
	wp_set_current_user( 0 );

	// ── buy endpoint ───────────────────────────────────────────────
	$buy = static function ( array $body, int $fid ) use ( $server ) { $req = new WP_REST_Request( 'POST', '/neural-forms/v1/forms/' . $fid . '/pay' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( $body ) ); return $server->dispatch( $req ); };
	$res = $buy( array( 'field' => 'book', 'qty' => 3, 'email' => 'buyer@harness.test', 'full_name' => 'Grace Buyer', 'neural_forms_website' => '' ), $form_id );
	$b = $res->get_data();
	check( 'buy: 201 with redirect to the gateway, total 87.00', $res->get_status() === 201 && ( $b['outcome']['mode'] ?? '' ) === 'redirect' && $b['pricing']['total'] === 8700, true );
	$eid = (int) $b['entry_id']; $created['entries'][] = $eid;
	$entry = EntryRepository::get( $eid );
	check( 'buy: entry maps email + name onto the form fields, product value stored', $entry['data']['contact'] === 'buyer@harness.test' && $entry['data']['buyer_name'] === 'Grace Buyer' && $entry['data']['book']['qty'] === 3, true );
	check( 'buy: pricing snapshot + pending payment attempt', $entry['amount_total_minor'] === 8700 && $entry['payment_status'] === 'pending' && count( PaymentRepository::list_for_entry( $eid ) ) === 1, true );
	check( 'buy: activity marks the quick purchase', in_array( 'entry.payment_form', array_column( EntryActivity::list( $eid ), 'event_type' ), true ), true );
	$res = $buy( array( 'field' => 'book', 'qty' => 99, 'email' => 'buyer@harness.test', 'neural_forms_website' => '' ), $form_id );
	$created['entries'][] = (int) $res->get_data()['entry_id'];
	check( 'buy: quantity clamped to the field max (5 × 29 = 145.00)', $res->get_data()['pricing']['total'], 14500 );
	$res = $buy( array( 'field' => 'tier', 'choice' => 'basic', 'email' => 'nope', 'neural_forms_website' => '' ), $form_id );
	check( 'buy: invalid email → 422', $res->get_status(), 422 );
	$res = $buy( array( 'field' => 'book', 'qty' => 1, 'email' => 'bot@harness.test', 'neural_forms_website' => 'http://spam' ), $form_id );
	check( 'buy: honeypot → fake 201, no entry', $res->get_status() === 201 && (int) $res->get_data()['entry_id'] === 0, true );
	$res = $buy( array( 'field' => 'book', 'qty' => 1, 'email' => 'buyer@harness.test', 'neural_forms_website' => '' ), $off_id );
	check( 'buy: payments-off form → 409', $res->get_status(), 409 );
	$res = $buy( array( 'field' => 'tier', 'choice' => 'nope', 'email' => 'buyer@harness.test', 'neural_forms_website' => '' ), $form_id );
	check( 'buy: unknown option → 409', $res->get_status(), 409 );
	$res = $buy( array( 'field' => 'book', 'unit' => '1', 'price' => '0.01', 'qty' => 1, 'email' => 'buyer@harness.test', 'neural_forms_website' => '' ), $form_id );
	$created['entries'][] = (int) $res->get_data()['entry_id'];
	check( 'buy: posted prices are ignored', $res->get_data()['pricing']['total'], 2900 );
	check( 'buy: admin notification mail (form notifications) went out', count( $mails ) >= 1, true );
} catch ( \Throwable $e ) {
	$failed++;
	echo "FATAL ", get_class( $e ), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n";
}
foreach ( $created['entries'] as $id ) { if ( $id > 0 ) { EntryActivity::delete_for_entry( (int) $id ); EntryMessageRepository::delete_by_entry( (int) $id ); PaymentRepository::delete_for_entry( (int) $id ); $wpdb->delete( $wpdb->prefix . 'neural_forms_logs', array( 'entry_id' => (int) $id ), array( '%d' ) ); EntryRepository::delete( (int) $id ); } }
$wpdb->query( "DELETE FROM {$wpdb->prefix}neural_forms_queue WHERE job_type IN ('workflow','analyze') AND status IN ('completed','failed','pending')" );
foreach ( $created['forms'] as $id ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_forms', array( 'id' => (int) $id ), array( '%d' ) ); }
echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
