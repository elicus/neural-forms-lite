<?php
/**
 * B2 live harness (1.2.0 part B) — quotes against the LOCAL site, Pro active.
 * Same bootstrap as A6LiveHarness.php:
 *
 *   P82="/Users/ALI/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
 *   SOCK="/Users/ALI/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock"
 *   "$P82" -d mysqli.default_socket="$SOCK" tests/live/B2QuotesHarness.php
 */
$_SERVER['HTTP_HOST'] = 'd5.local';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
define( 'WP_USE_THEMES', false );
require '/Users/ALI/Local Sites/diviplus/app/public/wp-load.php';

use NeuralForms\Database;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Pricing\PriceEngine;
use NeuralForms\Quotes\QuoteNumbering;
use NeuralForms\Quotes\QuotePdf;
use NeuralForms\Quotes\QuoteRepository;
use NeuralForms\Quotes\QuoteService;
use NeuralForms\Quotes\QuoteSettings;
use NeuralForms\Quotes\QuoteTokens;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Storage\EntryRepository;

$passed = 0; $failed = 0;
function check( string $label, $actual, $expected ): void {
	global $passed, $failed;
	if ( $actual === $expected ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
}
global $wpdb;
$mails = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
$created = array( 'forms' => array(), 'entries' => array(), 'quotes' => array() );
$settings_before = get_option( QuoteSettings::OPTION, null );
$seq_opt         = QuoteNumbering::OPTION_PREFIX . gmdate( 'Y' );
$seq_before      = get_option( $seq_opt, null );

try {
	// ── 1. schema ───────────────────────────────────────────────────
	update_option( 'neural_forms_db_version', '1.2.1' );
	Database::maybe_upgrade();
	check( 'upgrade: db version current', get_option( 'neural_forms_db_version' ), NEURAL_FORMS_DB_VERSION );
	check( 'upgrade: quotes table exists', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'neural_forms_quotes' ) ) === $wpdb->prefix . 'neural_forms_quotes', true );
	check( 'cron: expiry job scheduled', wp_next_scheduled( 'neural_forms_quote_expire' ) !== false, true );
	check( 'pdf renderer available (Pro)', QuotePdf::available(), true );

	// ── 2. settings ─────────────────────────────────────────────────
	QuoteSettings::update( array( 'business_name' => 'Harness Studio', 'email' => 'quotes@harness.test', 'prefix' => 'HQ', 'validity_days' => 30, 'terms' => 'Net 14.' ) );
	check( 'settings stored', QuoteSettings::get()['business_name'], 'Harness Studio' );

	// ── 3. priced entry ─────────────────────────────────────────────
	$schema  = array( 'fields' => array(
		array( 'id' => 'full_name', 'type' => 'text', 'label' => 'Name' ),
		array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'id' => 'service', 'type' => 'product', 'label' => 'Service', 'pricing' => array( 'mode' => 'fixed', 'price' => '1000.00' ) ),
		array( 'id' => 'addons', 'type' => 'product', 'label' => 'Add-ons', 'pricing' => array( 'mode' => 'options', 'display' => 'checkboxes' ), 'options' => array( array( 'value' => 'seo', 'label' => 'SEO', 'price' => '150' ), array( 'value' => 'copy', 'label' => 'Copywriting', 'price' => '150' ) ) ),
	) );
	$form_id = (int) FormRepository::create( array( 'name' => 'B2 quotes form', 'slug' => 'b2-quotes-' . time(), 'status' => 'active', 'form_schema' => $schema,
		'settings' => \NeuralForms\Forms\FormSettingsSanitizer::sanitize( array( 'pricing' => array( 'currency' => 'USD', 'discounts' => array( array( 'id' => 'ten', 'label' => 'Launch offer', 'type' => 'percent', 'value' => '10' ) ), 'deposit' => array( 'type' => 'percent', 'value' => '30' ) ) ) ) ) );
	$created['forms'][] = $form_id;
	$form    = FormRepository::find( $form_id );
	$data    = array( 'full_name' => 'Ada Lovelace', 'email' => 'ada@harness.test', 'service' => array( 'choice' => '', 'qty' => 1 ), 'addons' => array( 'choice' => array( 'seo', 'copy' ) ) );
	$pricing = PriceEngine::evaluate( $form, $data, array() );
	check( 'entry pricing: 1,170.00', $pricing['total'], 117000 );
	$entry_id = (int) EntryRepository::create( $form_id, $data, array( 'pricing' => $pricing ) );
	$created['entries'][] = $entry_id;
	check( 'entry stored with snapshot', (int) EntryRepository::get( $entry_id )['amount_total_minor'], 117000 );

	// ── 4. draft ────────────────────────────────────────────────────
	$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
	wp_set_current_user( $admin_id );
	$q = QuoteService::create_draft( $entry_id, array(), $admin_id );
	check( 'draft created', is_array( $q ) && $q['status'] === 'draft', true );
	$qid = (int) $q['id']; $created['quotes'][] = $qid;
	check( 'draft: no number yet', $q['number'], null );
	check( 'draft: 3 lines, total carried', array( count( $q['snapshot']['line_items'] ), $q['amount_total_minor'], $q['amount_due_minor'] ), array( 3, 117000, 35100 ) );
	check( 'draft: customer detected from entry', $q['snapshot']['customer'], array( 'name' => 'Ada Lovelace', 'email' => 'ada@harness.test' ) );
	check( 'draft: business block from settings', $q['snapshot']['business']['name'], 'Harness Studio' );
	$q = QuoteService::update_draft( $qid, array( 'line_items' => array( array( 'label' => 'Service', 'qty' => 1, 'unit' => '1000' ), array( 'label' => 'SEO', 'unit' => '150' ) ), 'notes' => 'Thanks for your interest.' ) );
	check( 'draft edited: subtotal 1,150.00 → total 1,035.00', array( $q['snapshot']['subtotal'], $q['amount_total_minor'] ), array( 115000, 103500 ) );
	$bad = QuoteService::update_draft( $qid, array( 'line_items' => array( array( 'label' => '', 'unit' => '1' ) ) ) );
	check( 'draft edit validation → 422', is_wp_error( $bad ) ? $bad->get_error_data()['status'] : null, 422 );
	$no_link = QuoteService::links( QuoteRepository::get( $qid ) );
	check( 'draft has no public links', $no_link, array() );
	$act = array_column( EntryActivity::list( $entry_id ), 'event_type' );
	check( 'activity: quote.created', in_array( 'quote.created', $act, true ), true );

	// ── 5. issue ────────────────────────────────────────────────────
	$seq_now = (int) get_option( $seq_opt, 0 );
	$q = QuoteService::issue( $qid, $admin_id );
	check( 'issued', is_array( $q ) && $q['status'] === 'issued', true );
	check( 'number format HQ-YYYY-NNNN', preg_match( '/^HQ-' . gmdate( 'Y' ) . '-\d{4,}$/', (string) $q['number'] ), 1 );
	check( 'number sequence advanced by one', (int) get_option( $seq_opt, 0 ), $seq_now + 1 );
	check( 'valid_until ≈ 30 days', abs( strtotime( $q['valid_until'] . ' UTC' ) - ( time() + 30 * DAY_IN_SECONDS ) ) < 120, true );
	check( 'token seed set', is_string( $q['token_seed'] ) && strlen( $q['token_seed'] ) === 64, true );
	$again = QuoteService::issue( $qid, $admin_id );
	check( 'issue twice → 409', is_wp_error( $again ) ? $again->get_error_data()['status'] : null, 409 );
	$edit_issued = QuoteService::update_draft( $qid, array( 'notes' => 'x' ) );
	check( 'edit issued → 409 (immutable, CQ8)', is_wp_error( $edit_issued ) ? $edit_issued->get_error_data()['status'] : null, 409 );
	$del_issued = QuoteService::delete_draft( $qid );
	check( 'delete issued → 409', is_wp_error( $del_issued ) ? $del_issued->get_error_data()['status'] : null, 409 );
	check( 'can_start_payment', QuoteService::can_start_payment( $q ), true );
	$links = QuoteService::links( $q );
	check( 'links: view + pdf', isset( $links['view'], $links['pdf'] ), true );
	$exposed = QuoteService::expose( $q );
	check( 'expose: no seed, has formatted + links', ! isset( $exposed['token_seed'] ) && $exposed['formatted']['total'] === '$1,035.00' && $exposed['links']['view'] === $links['view'], true );

	// CQ8: change the form's prices → issued quote unchanged.
	$schema2 = $schema; $schema2['fields'][2]['pricing']['price'] = '9000.00';
	FormRepository::update( $form_id, array( 'form_schema' => $schema2 ) );
	check( 'CQ8: form price changed', PriceEngine::evaluate( FormRepository::find( $form_id ), $data, array() )['total'] > 117000, true );
	check( 'CQ8: issued quote total unchanged', QuoteRepository::get( $qid )['amount_total_minor'], 103500 );

	// ── 6. public routes ────────────────────────────────────────────
	$server = rest_get_server();
	$token  = (string) parse_url( $links['view'], PHP_URL_QUERY ); parse_str( $token, $qs ); $token = (string) ( $qs['token'] ?? '' );
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/view' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'public view: 200 raw html with number', $res->get_status() === 200 && ! empty( $res->get_headers()['X-Neural-Forms-Raw'] ) && strpos( (string) $res->get_data(), (string) $q['number'] ) !== false, true );
	check( 'public view: branding + notes present (CQ7)', strpos( (string) $res->get_data(), 'Harness Studio' ) !== false && strpos( (string) $res->get_data(), 'Thanks for your interest.' ) !== false, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/view' ); $req->set_query_params( array( 'token' => 'nope' ) ); $res = $server->dispatch( $req );
	check( 'public view: wrong token → 404 (CQ10)', $res->get_status(), 404 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/view' ); $res = $server->dispatch( $req );
	check( 'public view: no token → 404', $res->get_status(), 404 );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/pdf' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'public pdf: 200 application/pdf starting with %PDF', $res->get_status() === 200 && strpos( (string) ( $res->get_headers()['Content-Type'] ?? '' ), 'application/pdf' ) === 0 && strncmp( (string) $res->get_data(), '%PDF', 4 ) === 0, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid ); $res = $server->dispatch( $req );
	check( 'admin GET without login → 401/403', in_array( $res->get_status(), array( 401, 403 ), true ), true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/preview' ); $res = $server->dispatch( $req );
	check( 'preview without login → 401/403 (CQ10)', in_array( $res->get_status(), array( 401, 403 ), true ), true );

	// ── 7. admin REST ───────────────────────────────────────────────
	wp_set_current_user( $admin_id );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/' . $entry_id . '/quotes' ); $res = $server->dispatch( $req );
	check( 'REST list: one quote, no seed', $res->get_status() === 200 && count( $res->get_data() ) === 1 && ! isset( $res->get_data()[0]['token_seed'] ), true );
	$req = new WP_REST_Request( 'PUT', '/neural-forms/v1/quotes/settings' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( wp_json_encode( array( 'validity_days' => 45 ) ) ); $res = $server->dispatch( $req );
	check( 'REST settings PUT merges', $res->get_status() === 200 && $res->get_data()['validity_days'] === 45 && $res->get_data()['business_name'] === 'Harness Studio' && $res->get_data()['pdf_available'] === true, true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/preview' ); $res = $server->dispatch( $req );
	check( 'REST preview (admin): html', $res->get_status() === 200 && strpos( (string) $res->get_data(), 'nf-quote__page' ) !== false, true );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/entries/' . $entry_id . '/quotes' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( '{}' ); $res = $server->dispatch( $req );
	check( 'REST create draft → 201', $res->get_status(), 201 );
	$q2id = (int) $res->get_data()['id']; $created['quotes'][] = $q2id;
	$req = new WP_REST_Request( 'DELETE', '/neural-forms/v1/quotes/' . $q2id ); $res = $server->dispatch( $req );
	check( 'REST delete draft', $res->get_status() === 200 && QuoteRepository::get( $q2id ) === null, true );

	// Locked capability → writes refused, reads fine.
	add_filter( 'neural_forms_can_quotes', '__return_false', 99 );
	$req = new WP_REST_Request( 'POST', '/neural-forms/v1/entries/' . $entry_id . '/quotes' ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_body( '{}' ); $res = $server->dispatch( $req );
	check( 'locked: create → pro_feature_locked', $res->get_status() === 403 && $res->get_data()['code'] === 'pro_feature_locked', true );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/entries/' . $entry_id . '/quotes' ); $res = $server->dispatch( $req );
	check( 'locked: list still readable', $res->get_status(), 200 );
	remove_filter( 'neural_forms_can_quotes', '__return_false', 99 );

	// ── 8. send ─────────────────────────────────────────────────────
	$r = QuoteService::send( $qid, 'ada@harness.test', 'Here is the quote we discussed.', $admin_id );
	check( 'send ok', is_array( $r ) && $r['sent'] === true, true );
	$last = end( $mails );
	check( 'mail: subject has number, body has link + message', strpos( (string) $last['subject'], (string) $q['number'] ) !== false && strpos( (string) $last['message'], $links['view'] ) !== false && strpos( (string) $last['message'], 'Here is the quote we discussed.' ) !== false, true );
	check( 'mail: pdf attached', is_array( $last['attachments'] ) && count( $last['attachments'] ) === 1 && substr( (string) $last['attachments'][0], -4 ) === '.pdf', true );
	check( 'mail: temp attachment removed afterwards', file_exists( (string) $last['attachments'][0] ), false );
	$msgs = EntryMessageRepository::list_by_entry( $entry_id );
	check( 'message recorded with quote meta', count( $msgs ) === 1 && (int) ( $msgs[0]['meta']['quote_id'] ?? 0 ) === $qid && $msgs[0]['type'] === 'outgoing', true );
	check( 'sent_at set', QuoteRepository::get( $qid )['sent_at'] !== null, true );
	$act = array_column( EntryActivity::list( $entry_id ), 'event_type' );
	check( 'activity: quote.issued + quote.sent', in_array( 'quote.issued', $act, true ) && in_array( 'quote.sent', $act, true ), true );
	check( 'lead stage untouched by quotes', EntryRepository::get( $entry_id )['lead_stage'], 'new' );
	check( 'payment status untouched by quotes', EntryRepository::get( $entry_id )['payment_status'], 'pending' );

	// ── 9. revise + supersede ───────────────────────────────────────
	$rev = QuoteService::revise( $qid, $admin_id );
	check( 'revision draft: revision 2, same number', is_array( $rev ) && $rev['revision'] === 2 && $rev['number'] === $q['number'] && $rev['supersedes_id'] === $qid && $rev['status'] === 'draft', true );
	$rev_id = (int) $rev['id']; $created['quotes'][] = $rev_id;
	$dup = QuoteService::revise( $qid, $admin_id );
	check( 'second revise while draft exists → 409', is_wp_error( $dup ) ? $dup->get_error_data()['status'] : null, 409 );
	check( 'old quote still issued while revision is a draft', QuoteRepository::get( $qid )['status'], 'issued' );
	$rev = QuoteService::issue( $rev_id, $admin_id );
	check( 'revision issued', $rev['status'], 'issued' );
	check( 'old quote superseded', QuoteRepository::get( $qid )['status'], 'superseded' );
	check( 'old token → superseded (CQ9)', QuoteTokens::check( QuoteRepository::get( $qid ), $token ), 'superseded' );
	$req = new WP_REST_Request( 'GET', '/neural-forms/v1/quotes/' . $qid . '/view' ); $req->set_query_params( array( 'token' => $token ) ); $res = $server->dispatch( $req );
	check( 'old public link → 410', $res->get_status(), 410 );
	check( 'superseded cannot start payment', QuoteService::can_start_payment( QuoteRepository::get( $qid ) ), false );
	check( 'revision link differs from old link', QuoteService::links( $rev )['view'] !== $links['view'], true );

	// ── 10. expiry cron ─────────────────────────────────────────────
	QuoteRepository::update( $rev_id, array( 'valid_until' => '2020-01-01 00:00:00' ) );
	check( 'past validity: token check expired before cron', QuoteTokens::check( QuoteRepository::get( $rev_id ), QuoteTokens::derive( $rev_id, 2, QuoteRepository::get( $rev_id )['token_seed'] ) ), 'expired' );
	$n = QuoteService::expire_due();
	check( 'cron expired ≥ 1', $n >= 1, true );
	check( 'status expired', QuoteRepository::get( $rev_id )['status'], 'expired' );
	check( 'expired cannot start payment (CQ9)', QuoteService::can_start_payment( QuoteRepository::get( $rev_id ) ), false );
	check( 'expired has no links', QuoteService::links( QuoteRepository::get( $rev_id ) ), array() );
	$rev3 = QuoteService::revise( $rev_id, $admin_id );
	check( 'expired can be revised', is_array( $rev3 ) && $rev3['revision'] === 3, true );
	$created['quotes'][] = (int) $rev3['id'];

	// ── 11. numbering is atomic + monotonic ─────────────────────────
	$a = QuoteNumbering::next( 'HQ' ); $b = QuoteNumbering::next( 'HQ' );
	check( 'numbering: consecutive, distinct', $a !== $b && (int) substr( $b, -4 ) === (int) substr( $a, -4 ) + 1, true );

	// ── 12. entry delete cascades ───────────────────────────────────
	$req = new WP_REST_Request( 'DELETE', '/neural-forms/v1/entries/' . $entry_id ); $res = $server->dispatch( $req );
	check( 'entry deleted via REST', in_array( $res->get_status(), array( 200, 204 ), true ), true );
	check( 'quotes removed with the entry', QuoteRepository::list_for_entry( $entry_id ), array() );
	$created['entries'] = array();
} catch ( \Throwable $e ) {
	$failed++;
	echo "FATAL ", get_class( $e ), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n";
}

// ── cleanup ───────────────────────────────────────────────────────
foreach ( $created['quotes'] as $id ) { QuoteRepository::delete( (int) $id ); }
foreach ( $created['entries'] as $id ) { if ( $id > 0 ) { EntryActivity::delete_for_entry( (int) $id ); EntryMessageRepository::delete_by_entry( (int) $id ); QuoteRepository::delete_for_entry( (int) $id ); EntryRepository::delete( (int) $id ); } }
foreach ( $created['forms'] as $id ) { $wpdb->delete( $wpdb->prefix . 'neural_forms_forms', array( 'id' => (int) $id ), array( '%d' ) ); }
if ( null === $settings_before ) { delete_option( QuoteSettings::OPTION ); } else { update_option( QuoteSettings::OPTION, $settings_before, false ); }
if ( null === $seq_before ) { delete_option( $seq_opt ); } else { update_option( $seq_opt, $seq_before, false ); }
echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
