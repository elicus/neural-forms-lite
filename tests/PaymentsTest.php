<?php
/**
 * B3 (1.2.0 part B): payments — pure parts.
 *
 *   - PaymentStatus::apply_event(): paid / processing / failed / expired /
 *     refund transitions, no regression (PAY5), integrity mismatches
 *     (PAY4 / PAY10), refund without payment
 *   - PaymentStatus::aggregate(): deposit vs full (PAY2), failed after
 *     paid stays paid (PAY5), double payment flag (PAY12), labels
 *   - PaymentTokens derive / check (PAY3)
 *   - FormSettingsSanitizer::sanitize_payment(), FormValidator::validate_settings_payment()
 *   - Database SQL for the two tables
 *
 *   php tests/PaymentsTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	$GLOBALS['nf_test_filters'] = array();
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['nf_test_filters'] ) ? $GLOBALS['nf_test_filters'][ $hook ] : $value; } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); } }
	if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return filter_var( $u, FILTER_SANITIZE_URL ); } }
	if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
	if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
	if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
	if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public $code, $message, $data; public function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } } }
	class NF_Pay_WPDB_Stub { public $prefix = 'wp_'; public $options = 'wp_options'; }
	$GLOBALS['wpdb'] = new NF_Pay_WPDB_Stub();
	$plugin_root = dirname( __DIR__ );
	foreach ( array( '/modules/core/Capabilities.php', '/modules/forms/ConditionEngine.php', '/modules/forms/SchemaHelper.php', '/modules/pricing/Money.php', '/modules/pricing/Expression.php', '/modules/pricing/PriceEngine.php', '/modules/forms/FormSettingsSanitizer.php', '/modules/forms/FormValidator.php', '/modules/payments/PaymentStatus.php', '/modules/payments/PaymentTokens.php', '/includes/class-database.php' ) as $f ) {
		require_once $plugin_root . $f;
	}
}

namespace NeuralForms\Tests {
	use NeuralForms\Database;
	use NeuralForms\Forms\FormSettingsSanitizer;
	use NeuralForms\Forms\FormValidator;
	use NeuralForms\Payments\PaymentStatus;
	use NeuralForms\Payments\PaymentTokens;

	$passed = 0; $failed = 0;
	function check( string $label, $actual, $expected ): void {
		global $passed, $failed;
		if ( $actual === $expected ) { $passed++; }
		else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
	}
	$base = array( 'id' => 1, 'entry_id' => 10, 'provider' => 'stripe', 'livemode' => false, 'account_id' => 'acct_1', 'kind' => 'deposit', 'amount_due_minor' => 35100, 'amount_paid_minor' => 0, 'amount_refunded_minor' => 0, 'currency' => 'USD', 'status' => 'pending' );
	$ev   = static function ( array $o = array() ) { return $o + array( 'kind' => 'paid', 'amount_minor' => 35100, 'currency' => 'usd', 'livemode' => false, 'account_id' => 'acct_1', 'provider_payment_id' => 'pi_1' ); };

	// ── apply_event ────────────────────────────────────────────────
	$r = PaymentStatus::apply_event( $base, $ev() );
	check( 'paid: applied', array( $r['changed'], $r['newly_paid'], $r['fields']['status'], $r['fields']['amount_paid_minor'] ), array( true, true, 'paid', 35100 ) );
	check( 'paid: provider id captured', $r['fields']['provider_payment_id'], 'pi_1' );
	check( 'paid: paid_at set', isset( $r['fields']['paid_at'] ), true );
	$paid = array_merge( $base, $r['fields'] );
	check( 'duplicate paid → already_paid, not newly paid', array( PaymentStatus::apply_event( $paid, $ev() )['changed'], PaymentStatus::apply_event( $paid, $ev() )['reason'] ), array( false, 'already_paid' ) );
	check( 'PAY5: failed after paid blocked', PaymentStatus::apply_event( $paid, $ev( array( 'kind' => 'failed' ) ) )['reason'], 'regression_blocked' );
	check( 'PAY5: expired after paid blocked', PaymentStatus::apply_event( $paid, $ev( array( 'kind' => 'expired' ) ) )['reason'], 'regression_blocked' );
	check( 'PAY5: processing after paid blocked', PaymentStatus::apply_event( $paid, $ev( array( 'kind' => 'processing' ) ) )['reason'], 'regression_blocked' );
	check( 'out of order: processing then paid', PaymentStatus::apply_event( array_merge( $base, array( 'status' => 'processing' ) ), $ev() )['fields']['status'], 'paid' );
	check( 'completed (not yet paid) → processing', PaymentStatus::apply_event( $base, $ev( array( 'kind' => 'completed' ) ) )['fields']['status'], 'processing' );
	check( 'failed from pending', PaymentStatus::apply_event( $base, $ev( array( 'kind' => 'failed' ) ) )['fields']['status'], 'failed' );
	check( 'paid after failed (retry on same session)', PaymentStatus::apply_event( array_merge( $base, array( 'status' => 'failed' ) ), $ev() )['fields']['status'], 'paid' );
	check( 'cancelled → expired', PaymentStatus::apply_event( $base, $ev( array( 'kind' => 'cancelled' ) ) )['fields']['status'], 'expired' );
	check( 'PAY4: amount mismatch ignored', PaymentStatus::apply_event( $base, $ev( array( 'amount_minor' => 1000 ) ) )['reason'], 'amount_mismatch' );
	check( 'PAY4: currency mismatch ignored', PaymentStatus::apply_event( $base, $ev( array( 'currency' => 'EUR' ) ) )['reason'], 'currency_mismatch' );
	check( 'PAY4: account mismatch ignored', PaymentStatus::apply_event( $base, $ev( array( 'account_id' => 'acct_other' ) ) )['reason'], 'account_mismatch' );
	check( 'PAY10: live event on test row ignored', PaymentStatus::apply_event( $base, $ev( array( 'livemode' => true ) ) )['reason'], 'mode_mismatch' );
	check( 'unknown event kind ignored', PaymentStatus::apply_event( $base, $ev( array( 'kind' => 'ignored' ) ) )['reason'], 'ignored' );
	check( 'refund without payment ignored', PaymentStatus::apply_event( $base, $ev( array( 'kind' => 'refunded', 'amount_refunded_minor' => 100 ) ) )['reason'], 'refund_without_payment' );
	$pr = PaymentStatus::apply_event( $paid, $ev( array( 'kind' => 'refunded', 'amount_refunded_minor' => 10000 ) ) );
	check( 'partial refund', array( $pr['fields']['status'], $pr['fields']['amount_refunded_minor'], $pr['newly_paid'] ), array( 'partially_refunded', 10000, false ) );
	$fr = PaymentStatus::apply_event( $paid, $ev( array( 'kind' => 'refunded', 'amount_refunded_minor' => 99999 ) ) );
	check( 'full refund capped at paid', array( $fr['fields']['status'], $fr['fields']['amount_refunded_minor'] ), array( 'refunded', 35100 ) );
	check( 'paid after refund blocked', PaymentStatus::apply_event( array_merge( $paid, $fr['fields'] ), $ev() )['reason'], 'already_paid' );

	// ── aggregate ──────────────────────────────────────────────────
	check( 'aggregate: nothing due → none', PaymentStatus::aggregate( array(), 0, 0 )['status'], 'none' );
	check( 'aggregate: due, no attempts → pending', PaymentStatus::aggregate( array(), 35100, 117000 )['status'], 'pending' );
	$a1 = array_merge( $paid, array( 'id' => 1 ) );
	$g  = PaymentStatus::aggregate( array( $a1 ), 35100, 117000 );
	check( 'PAY2: deposit paid, not paid in full', array( $g['status'], $g['amount_paid_minor'], $g['remaining_minor'] ), array( 'deposit_paid', 35100, 81900 ) );
	$full = array_merge( $base, array( 'id' => 2, 'kind' => 'full', 'status' => 'paid', 'amount_due_minor' => 117000, 'amount_paid_minor' => 117000 ) );
	check( 'aggregate: full paid', PaymentStatus::aggregate( array( $full ), 117000, 117000 )['status'], 'paid' );
	check( 'aggregate: deposit + balance paid later → paid', PaymentStatus::aggregate( array( $a1, array_merge( $base, array( 'id' => 3, 'kind' => 'full', 'status' => 'paid', 'amount_due_minor' => 81900, 'amount_paid_minor' => 81900 ) ) ), 35100, 117000 )['status'], 'paid' );
	check( 'PAY5: later failed attempt keeps deposit_paid', PaymentStatus::aggregate( array( $a1, array_merge( $base, array( 'id' => 4, 'status' => 'failed' ) ) ), 35100, 117000 )['status'], 'deposit_paid' );
	check( 'PAY7: latest failed → failed', PaymentStatus::aggregate( array( array_merge( $base, array( 'id' => 5, 'status' => 'failed' ) ) ), 35100, 117000 )['status'], 'failed' );
	check( 'PAY7: latest expired → cancelled', PaymentStatus::aggregate( array( array_merge( $base, array( 'id' => 6, 'status' => 'expired' ) ) ), 35100, 117000 )['status'], 'cancelled' );
	check( 'PAY7: processing', PaymentStatus::aggregate( array( array_merge( $base, array( 'id' => 7, 'status' => 'processing' ) ) ), 35100, 117000 )['status'], 'processing' );
	check( 'replaced attempts ignored for latest', PaymentStatus::aggregate( array( array_merge( $base, array( 'id' => 9, 'status' => 'replaced' ) ), array_merge( $base, array( 'id' => 8, 'status' => 'pending' ) ) ), 35100, 117000 )['status'], 'pending' );
	$dbl = PaymentStatus::aggregate( array( $full, array_merge( $full, array( 'id' => 11 ) ) ), 117000, 117000 );
	check( 'PAY12: two successful payments flagged', array( $dbl['needs_reconciliation'], $dbl['status'], $dbl['amount_paid_minor'] ), array( true, 'paid', 234000 ) );
	$dbl2 = PaymentStatus::aggregate( array( $a1, array_merge( $a1, array( 'id' => 12 ) ) ), 35100, 117000 );
	check( 'PAY12: two deposits for one intent flagged even below total', array( $dbl2['needs_reconciliation'], $dbl2['status'] ), array( true, 'deposit_paid' ) );
	check( 'PAY12: deposit + balance (different intent) not flagged', PaymentStatus::aggregate( array( $a1, array_merge( $base, array( 'id' => 13, 'kind' => 'full', 'status' => 'paid', 'amount_due_minor' => 81900, 'amount_paid_minor' => 81900 ) ) ), 35100, 117000 )['needs_reconciliation'], false );
	check( 'aggregate: refunded', PaymentStatus::aggregate( array( array_merge( $full, array( 'status' => 'refunded', 'amount_refunded_minor' => 117000 ) ) ), 117000, 117000 )['status'], 'refunded' );
	check( 'aggregate: partially refunded', PaymentStatus::aggregate( array( array_merge( $full, array( 'status' => 'partially_refunded', 'amount_refunded_minor' => 1000 ) ) ), 117000, 117000 )['status'], 'partially_refunded' );
	check( 'label', PaymentStatus::label( 'deposit_paid' ), 'Deposit paid' );
	check( 'entry statuses whitelist', in_array( 'deposit_paid', PaymentStatus::ENTRY_STATUSES, true ) && ! in_array( 'replaced', PaymentStatus::ENTRY_STATUSES, true ), true );

	// ── tokens (PAY3) ──────────────────────────────────────────────
	$seed = PaymentTokens::new_seed();
	$tok  = PaymentTokens::derive( 5, $seed );
	check( 'token: shape', strlen( $tok ) === 43, true );
	check( 'token: valid', PaymentTokens::check( array( 'id' => 5, 'token_seed' => $seed ), $tok ), true );
	check( 'token: wrong id', PaymentTokens::check( array( 'id' => 6, 'token_seed' => $seed ), $tok ), false );
	check( 'token: empty seed', PaymentTokens::check( array( 'id' => 5, 'token_seed' => '' ), $tok ), false );
	check( 'token: tampered', PaymentTokens::check( array( 'id' => 5, 'token_seed' => $seed ), 'A' . substr( $tok, 1 ) ), false );

	// ── settings sanitising + validation ───────────────────────────
	check( 'sanitize: defaults drop the key', FormSettingsSanitizer::sanitize_payment( array( 'enabled' => false ) ), array() );
	$sp = FormSettingsSanitizer::sanitize_payment( array( 'enabled' => '1', 'provider' => 'Stripe!', 'submit_label' => '<b>Pay</b>', 'success_url' => 'https://x.test/ok' ) );
	check( 'sanitize: enabled + provider cleaned', array( $sp['enabled'], $sp['provider'], $sp['submit_label'], $sp['success_url'] ), array( true, 'stripe', 'Pay', 'https://x.test/ok' ) );
	check( 'sanitize: non-array → empty', FormSettingsSanitizer::sanitize_payment( 'x' ), array() );
	$schema_np = array( 'fields' => array( array( 'id' => 'email', 'type' => 'email', 'label' => 'E' ) ) );
	$schema_p  = array( 'fields' => array( array( 'id' => 'svc', 'type' => 'product', 'label' => 'S', 'pricing' => array( 'mode' => 'fixed', 'price' => '10' ) ) ) );
	$GLOBALS['nf_test_filters']['neural_forms_can_payments'] = false;
	$v = FormValidator::validate_settings_payment( array( 'payment' => array( 'enabled' => true ) ), $schema_p );
	check( 'validate: locked → 403 pro_feature_locked', is_wp_error( $v ) ? $v->get_error_code() : null, 'pro_feature_locked' );
	$GLOBALS['nf_test_filters']['neural_forms_can_payments'] = true;
	$v = FormValidator::validate_settings_payment( array( 'payment' => array( 'enabled' => true ) ), $schema_np );
	check( 'validate: no priced item → 422', is_wp_error( $v ) ? $v->get_error_data()['status'] : null, 422 );
	check( 'validate: ok with priced item', FormValidator::validate_settings_payment( array( 'payment' => array( 'enabled' => true ) ), $schema_p ), true );
	check( 'validate: disabled always ok', FormValidator::validate_settings_payment( array( 'payment' => array( 'enabled' => false ) ), $schema_np ), true );
	$v = FormValidator::validate_settings_payment( array( 'payment' => array( 'enabled' => true, 'success_url' => 'javascript:alert(1)' ) ), $schema_p );
	check( 'validate: bad success url → 422', is_wp_error( $v ) ? $v->get_error_data()['status'] : null, 422 );

	// ── schema ─────────────────────────────────────────────────────
	foreach ( array( 'sql_payments' => array( 'wp_neural_forms_payments', 'UNIQUE KEY idempotency_key', 'amount_paid_minor', 'amount_refunded_minor', 'livemode', 'token_seed', 'KEY session_id' ), 'sql_payment_events' => array( 'wp_neural_forms_payment_events', 'UNIQUE KEY provider_event (provider,event_id)', 'processed_at' ) ) as $method => $needles ) {
		$m = new \ReflectionMethod( Database::class, $method ); $m->setAccessible( true ); $sql = $m->invoke( null, '' );
		foreach ( $needles as $n ) { check( "sql $method: $n", strpos( $sql, $n ) !== false, true ); }
	}
	$main = (string) file_get_contents( dirname( __DIR__ ) . '/neural-forms-lite.php' );
	check( 'db version ≥ 1.2.3', version_compare( preg_match( "/NEURAL_FORMS_DB_VERSION', '([0-9.]+)'/", $main, $mm ) ? $mm[1] : '0', '1.2.3', '>=' ), true );

	echo "PaymentsTest: $passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
