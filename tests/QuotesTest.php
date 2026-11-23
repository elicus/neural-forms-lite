<?php
/**
 * B2 (1.2.0 part B): quotes — pure parts.
 *
 *   - QuoteBuilder::from_entry() on the draft fixture (1,170 / 351 / 819)
 *   - apply_edits() recalculation, validation errors, discount floor, deposit cap
 *   - QuoteNumbering::format(), QuoteSettings::sanitize()
 *   - QuoteTokens: derive / check (CQ9 / CQ10 state rules), revocation
 *   - QuoteService::can_start_payment()
 *   - QuoteRenderer: snapshot + branding reproduced (CQ7), escaping, pdf mode
 *   - Database::sql_quotes() columns + DB version bump
 *
 *   php tests/QuotesTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); } }
	if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return filter_var( $u, FILTER_SANITIZE_URL ); } }
	if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; } }
	if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public $code, $message, $data; public function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->message = $m; $this->data = $d; } } }
	class NF_Quotes_WPDB_Stub { public $prefix = 'wp_'; public $options = 'wp_options'; }
	$GLOBALS['wpdb'] = new NF_Quotes_WPDB_Stub();
	$plugin_root = dirname( __DIR__ );
	foreach ( array( '/modules/forms/ConditionEngine.php', '/modules/forms/SchemaHelper.php', '/modules/pricing/Money.php', '/modules/pricing/Expression.php', '/modules/pricing/PriceEngine.php', '/modules/quotes/QuoteSettings.php', '/modules/quotes/QuoteNumbering.php', '/modules/quotes/QuoteTokens.php', '/modules/quotes/QuoteBuilder.php', '/modules/quotes/QuoteRenderer.php', '/modules/quotes/QuoteService.php', '/includes/class-database.php' ) as $f ) {
		require_once $plugin_root . $f;
	}
}

namespace NeuralForms\Tests {
	use NeuralForms\Database;
	use NeuralForms\Pricing\PriceEngine;
	use NeuralForms\Quotes\QuoteBuilder;
	use NeuralForms\Quotes\QuoteNumbering;
	use NeuralForms\Quotes\QuoteRenderer;
	use NeuralForms\Quotes\QuoteService;
	use NeuralForms\Quotes\QuoteSettings;
	use NeuralForms\Quotes\QuoteTokens;

	$passed = 0; $failed = 0;
	function check( string $label, $actual, $expected ): void {
		global $passed, $failed;
		if ( $actual === $expected ) { $passed++; }
		else { $failed++; echo "FAIL  $label\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n"; }
	}

	$root     = dirname( __DIR__ );
	$fixtures = json_decode( (string) file_get_contents( $root . '/tests/fixtures/pricing.json' ), true );
	$case     = $fixtures['cases'][0];
	$form     = $case['form'] + array( 'id' => 7, 'name' => 'Website project' );
	$pricing  = PriceEngine::evaluate( $form, $case['data'], array() );
	$entry    = array( 'id' => 42, 'form_id' => 7, 'data' => $case['data'] + array( 'full_name' => 'Ada Lovelace', 'email' => 'ada@example.com' ), 'pricing' => $pricing );
	$settings = QuoteSettings::sanitize( array( 'business_name' => 'Elicus <b>Studio</b>', 'email' => 'hello@elicus.com', 'prefix' => 'q!', 'validity_days' => 45, 'terms' => 'Payment within 14 days.', 'accent_color' => '#AA00ff' ) );

	// ── settings sanitising ────────────────────────────────────────
	check( 'settings: html stripped from business name', $settings['business_name'], 'Elicus Studio' );
	check( 'settings: prefix cleaned + uppercased', $settings['prefix'], 'Q' );
	check( 'settings: validity kept inside 1..365', $settings['validity_days'], 45 );
	check( 'settings: colour lowercased', $settings['accent_color'], '#aa00ff' );
	check( 'settings: validity > 365 clamped', QuoteSettings::sanitize( array( 'validity_days' => 999 ) )['validity_days'], 365 );
	check( 'settings: bad colour falls back', QuoteSettings::sanitize( array( 'accent_color' => 'red' ) )['accent_color'], '#1e1b4b' );
	check( 'settings: bad email dropped', QuoteSettings::sanitize( array( 'email' => 'nope' ) )['email'], '' );

	// ── snapshot from the entry (CQ6 numbers carried over) ─────────
	$snap = QuoteBuilder::from_entry( $entry, $form, $settings, array( 'name' => 'Ada Lovelace', 'email' => 'ada@example.com' ) );
	check( 'snapshot: currency', $snap['currency'], 'USD' );
	check( 'snapshot: three lines', count( $snap['line_items'] ), 3 );
	check( 'snapshot: first line label', $snap['line_items'][0]['label'], 'Service' );
	check( 'snapshot: add-on line label = choice, description = field', array( $snap['line_items'][1]['label'], $snap['line_items'][1]['description'] ), array( 'SEO', 'Add-ons' ) );
	check( 'snapshot: subtotal 1,300.00', $snap['subtotal'], 130000 );
	check( 'snapshot: discount 10% = 130.00', $snap['discount']['amount'], 13000 );
	check( 'snapshot: total 1,170.00', $snap['total'], 117000 );
	check( 'snapshot: due now 351.00', $snap['due_now'], 35100 );
	check( 'snapshot: remaining 819.00', $snap['remaining'], 81900 );
	check( 'snapshot: business block copied from settings', $snap['business']['name'], 'Elicus Studio' );
	check( 'snapshot: terms copied from settings', $snap['terms'], 'Payment within 14 days.' );
	check( 'snapshot: customer', $snap['customer'], array( 'name' => 'Ada Lovelace', 'email' => 'ada@example.com' ) );
	check( 'snapshot: source', $snap['source']['entry_id'] . '/' . $snap['source']['form_id'], '42/7' );
	$fmt = QuoteBuilder::formatted( $snap );
	check( 'formatted: total', $fmt['total'], '$1,170.00' );
	check( 'formatted: lines', $fmt['lines'][1], array( 'unit' => '$150.00', 'total' => '$150.00' ) );

	// CQ8: the snapshot is a copy — changing the form afterwards changes nothing.
	$form2 = $form;
	$form2['form_schema']['fields'][0]['pricing']['price'] = '5000.00';
	$pricing2 = PriceEngine::evaluate( $form2, $case['data'], array() );
	check( 'CQ8: form re-evaluates to a new total', $pricing2['total'] > $pricing['total'], true );
	check( 'CQ8: issued snapshot keeps its total', $snap['total'], 117000 );

	// ── edits ──────────────────────────────────────────────────────
	$edited = QuoteBuilder::apply_edits( $snap, array( 'line_items' => array( array( 'label' => 'Service', 'qty' => 2, 'unit' => '1000.00' ), array( 'label' => 'SEO', 'unit' => '150' ) ) ) );
	check( 'edits: subtotal recomputed (2,150.00)', $edited['subtotal'], 215000 );
	check( 'edits: percent discount re-applied (215.00)', $edited['discount']['amount'], 21500 );
	check( 'edits: total 1,935.00', $edited['total'], 193500 );
	check( 'edits: 30% deposit re-applied (580.50)', $edited['due_now'], 58050 );
	check( 'edits: remaining', $edited['remaining'], 193500 - 58050 );
	check( 'edits: line total', $edited['line_items'][0]['total'], 200000 );
	check( 'edits: deposit removed → due = total', QuoteBuilder::apply_edits( $snap, array( 'deposit' => null ) )['due_now'], 117000 );
	check( 'edits: fixed discount over subtotal floors at zero', QuoteBuilder::apply_edits( $snap, array( 'discount' => array( 'type' => 'fixed', 'value' => '99999' ) ) )['total'], 0 );
	check( 'edits: fixed deposit capped at total', QuoteBuilder::apply_edits( $snap, array( 'deposit' => array( 'type' => 'fixed', 'value' => '99999' ) ) )['remaining'], 0 );
	check( 'edits: unit_minor accepted', QuoteBuilder::apply_edits( $snap, array( 'line_items' => array( array( 'label' => 'X', 'unit_minor' => 1234 ) ) ) )['subtotal'], 1234 );
	check( 'edits: missing label rejected', is_string( QuoteBuilder::apply_edits( $snap, array( 'line_items' => array( array( 'label' => '', 'unit' => '1' ) ) ) ) ), true );
	check( 'edits: bad qty rejected', is_string( QuoteBuilder::apply_edits( $snap, array( 'line_items' => array( array( 'label' => 'A', 'qty' => 0, 'unit' => '1' ) ) ) ) ), true );
	check( 'edits: bad unit rejected', is_string( QuoteBuilder::apply_edits( $snap, array( 'line_items' => array( array( 'label' => 'A', 'unit' => 'abc' ) ) ) ) ), true );
	check( 'edits: percent > 100 rejected', is_string( QuoteBuilder::apply_edits( $snap, array( 'discount' => array( 'type' => 'percent', 'value' => '150' ) ) ) ), true );
	check( 'edits: bad customer email rejected', is_string( QuoteBuilder::apply_edits( $snap, array( 'customer' => array( 'email' => 'x' ) ) ) ), true );
	check( 'edits: notes stripped of html', QuoteBuilder::apply_edits( $snap, array( 'notes' => '<b>Thanks</b>' ) )['notes'], 'Thanks' );
	$many = array_fill( 0, 101, array( 'label' => 'L', 'unit' => '1' ) );
	check( 'edits: > 100 lines rejected', is_string( QuoteBuilder::apply_edits( $snap, array( 'line_items' => $many ) ) ), true );

	// ── numbering ──────────────────────────────────────────────────
	check( 'number: Q-2026-0001', QuoteNumbering::format( 'Q', 2026, 1 ), 'Q-2026-0001' );
	check( 'number: prefix cleaned', QuoteNumbering::format( 'ab c!', 2026, 12 ), 'ABC-2026-0012' );
	check( 'number: empty prefix → Q', QuoteNumbering::format( '', 2026, 10000 ), 'Q-2026-10000' );

	// ── tokens (CQ9 / CQ10) ────────────────────────────────────────
	$seed  = QuoteTokens::new_seed();
	$quote = array( 'id' => 9, 'revision' => 1, 'status' => 'issued', 'token_seed' => $seed, 'valid_until' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
	$token = QuoteTokens::derive( 9, 1, $seed );
	check( 'token: 43 chars base64url', strlen( $token ) === 43 && preg_match( '/^[A-Za-z0-9_-]+$/', $token ) === 1, true );
	check( 'token: deterministic', QuoteTokens::derive( 9, 1, $seed ), $token );
	check( 'token: differs per revision', QuoteTokens::derive( 9, 2, $seed ) !== $token, true );
	check( 'token: differs per key', QuoteTokens::derive( 9, 1, $seed, 'other' ) !== $token, true );
	check( 'token: valid', QuoteTokens::check( $quote, $token ), '' );
	check( 'token: wrong token', QuoteTokens::check( $quote, substr( $token, 1 ) . 'A' ), 'invalid' );
	check( 'token: empty', QuoteTokens::check( $quote, '' ), 'invalid' );
	check( 'token: draft not visible', QuoteTokens::check( array( 'status' => 'draft' ) + $quote, $token ), 'not_issued' );
	check( 'token: expired by date', QuoteTokens::check( array( 'valid_until' => '2020-01-01 00:00:00' ) + $quote, $token ), 'expired' );
	check( 'token: expired status', QuoteTokens::check( array( 'status' => 'expired' ) + $quote, $token ), 'expired' );
	check( 'token: superseded', QuoteTokens::check( array( 'status' => 'superseded' ) + $quote, $token ), 'superseded' );
	check( 'token: revoked seed', QuoteTokens::check( array( 'token_seed' => null ) + $quote, $token ), 'revoked' );
	check( 'payment: issued + valid may pay', QuoteService::can_start_payment( $quote ), true );
	check( 'payment: expired may not', QuoteService::can_start_payment( array( 'valid_until' => '2020-01-01 00:00:00' ) + $quote ), false );
	check( 'payment: superseded may not', QuoteService::can_start_payment( array( 'status' => 'superseded' ) + $quote ), false );
	check( 'payment: draft may not', QuoteService::can_start_payment( array( 'status' => 'draft' ) + $quote ), false );

	// ── renderer (CQ7) ─────────────────────────────────────────────
	$snap_x = $snap;
	$snap_x['notes'] = 'See <script>alert(1)</script> attached'; // raw value straight into the snapshot — the renderer must escape it
	$row    = array( 'id' => 9, 'revision' => 2, 'number' => 'Q-2026-0007', 'status' => 'issued', 'issued_at' => '2026-10-29 10:00:00', 'valid_until' => '2026-11-28 10:00:00', 'created_at' => '2026-10-29 09:00:00', 'snapshot' => $snap_x );
	$html   = QuoteRenderer::html( $row, array( 'mode' => 'html', 'toolbar' => true, 'pdf_url' => 'https://x.test/pdf' ) );
	foreach ( array( 'Q-2026-0007', 'Elicus Studio', 'hello@elicus.com', 'Ada Lovelace', 'SEO', 'Copywriting', '$1,300.00', '$130.00', '$1,170.00', '$351.00', '$819.00', 'Payment within 14 days.', 'Launch offer (10%)', '#aa00ff', 'Revision', 'Download PDF', 'not a tax invoice' ) as $needle ) {
		check( "render: contains $needle", strpos( $html, $needle ) !== false, true );
	}
	check( 'render: script escaped', strpos( $html, '<script>' ) === false && strpos( $html, '&lt;script&gt;' ) !== false, true );
	check( 'render: no toolbar in pdf mode', strpos( QuoteRenderer::html( $row, array( 'mode' => 'pdf' ) ), '<div class="nf-quote__toolbar">' ), false );
	check( 'render: pdf mode has no flex/grid', preg_match( '/display:\s*(flex|grid)/', QuoteRenderer::html( $row, array( 'mode' => 'pdf' ) ) ), 0 );
	check( 'render: draft title', strpos( QuoteRenderer::html( array( 'id' => 1, 'status' => 'draft', 'created_at' => '2026-10-29 09:00:00', 'snapshot' => $snap ) ), 'Draft' ) !== false, true );
	check( 'filename', QuoteRenderer::filename( $row ), 'q-2026-0007.pdf' );
	check( 'filename: draft', QuoteRenderer::filename( array( 'id' => 5 ) ), 'quote-draft-5.pdf' );

	// ── schema ─────────────────────────────────────────────────────
	$m = new \ReflectionMethod( Database::class, 'sql_quotes' );
	$m->setAccessible( true );
	$sql = $m->invoke( null, '' );
	foreach ( array( 'wp_neural_forms_quotes', 'entry_id', 'number varchar(40)', 'revision', 'supersedes_id', 'status varchar(20)', 'snapshot longtext', 'valid_until datetime', 'token_seed varchar(64)', 'KEY entry_id', 'KEY status' ) as $needle ) {
		check( "sql: $needle", strpos( $sql, $needle ) !== false, true );
	}
	$main = (string) file_get_contents( $root . '/neural-forms-lite.php' );
	check( 'db version is at least 1.2.2', version_compare( preg_match( "/NEURAL_FORMS_DB_VERSION', '([0-9.]+)'/", $main, $mm ) ? $mm[1] : '0', '1.2.2', '>=' ), true );

	echo "QuotesTest: $passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
