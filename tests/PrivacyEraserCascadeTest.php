<?php
/**
 * Privacy eraser cascade for quotes / payments + timeline retention (1.2.0).
 *
 * Run from the plugin root:
 *
 *   php tests/PrivacyEraserCascadeTest.php
 *
 * Coverage:
 *   1. PaymentRepository::anonymised_meta() / QuoteRepository::anonymised_snapshot()
 *      blank the customer's details and keep the financial data
 *   2. PrivacyTools::erase() on an entry WITHOUT a confirmed payment: open
 *      checkout closed at the gateway, quotes + attempts deleted, timeline
 *      deleted, nothing retained
 *   3. PrivacyTools::erase() on an entry WITH a confirmed payment: entry and
 *      timeline deleted, quotes + attempts kept with customer details blanked,
 *      quote links revoked, amounts / provider ids untouched, retained + message
 *   4. Logger::cleanup() keeps entry.* / workflow.* / quote.* / payment.* rows
 *
 * Uses the real repositories on an in-memory $wpdb double.
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable -- CLI-only test runner.
	if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );

	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
	function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
	function current_time( $type, $gmt = false ) { return '2026-11-23 00:00:00'; }

	function check( string $label, $actual, $expected, int &$passed, int &$failed ): void {
		$ok = $actual === $expected;
		if ( $ok ) $passed++; else $failed++;
		echo ( $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m" ) . '  ' . $label . "\n";
		if ( ! $ok ) {
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	/** In-memory stand-in for the three calls the repositories make. */
	class WPDB_Memory {
		public $prefix = 'wp_';
		public $tables = array();
		public function prepare( $sql, ...$args ) {
			if ( count( $args ) === 1 && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array( 'sql' => $sql, 'args' => array_values( $args ) );
		}
		public function get_results( $q, $output = null ) {
			// SELECT * FROM %i WHERE entry_id = %d ORDER BY id DESC
			list( $table, $entry_id ) = $q['args'];
			$rows = array_values( array_filter( $this->tables[ $table ] ?? array(), static function ( $r ) use ( $entry_id ) { return (int) $r['entry_id'] === (int) $entry_id; } ) );
			usort( $rows, static function ( $a, $b ) { return $b['id'] <=> $a['id']; } );
			return $rows;
		}
		public function update( $table, $data, $where ) {
			$n = 0;
			foreach ( $this->tables[ $table ] ?? array() as $i => $r ) {
				if ( (int) $r['id'] === (int) $where['id'] ) {
					$this->tables[ $table ][ $i ] = array_merge( $r, $data );
					$n++;
				}
			}
			return $n;
		}
		public function delete( $table, $where, $format = null ) {
			$before = count( $this->tables[ $table ] ?? array() );
			$this->tables[ $table ] = array_values( array_filter( $this->tables[ $table ] ?? array(), static function ( $r ) use ( $where ) { return (int) $r['entry_id'] !== (int) $where['entry_id']; } ) );
			return $before - count( $this->tables[ $table ] );
		}
		public function query( $q ) {
			// update_if_status(): UPDATE <table> SET updated_at = %s, status = %s WHERE id = %d AND status = %s
			if ( ! preg_match( '/^UPDATE (\S+) SET/', $q['sql'], $m ) ) {
				return 0;
			}
			list( , $to, $id, $from ) = $q['args'];
			foreach ( $this->tables[ $m[1] ] ?? array() as $i => $r ) {
				if ( (int) $r['id'] === (int) $id && $r['status'] === $from ) {
					$this->tables[ $m[1] ][ $i ]['status'] = $to;
					return 1;
				}
			}
			return 0;
		}
	}
	$GLOBALS['wpdb'] = new WPDB_Memory();

	$GLOBALS['__nf_entries'] = array();
	$GLOBALS['__nf_deleted'] = array( 'entries' => array(), 'timeline' => array(), 'expired' => array() );
	eval( '
	namespace NeuralForms\\Storage {
		class EntryRepository {
			public static function find_by_email( string $email, int $per_page = 50, int $page = 1 ): array {
				return array_values( array_filter( $GLOBALS["__nf_entries"], fn( $e ) => strtolower( $e["data"]["email"] ) === strtolower( $email ) ) );
			}
			public static function delete( int $id ): bool {
				$GLOBALS["__nf_deleted"]["entries"][] = $id;
				$GLOBALS["__nf_entries"] = array_values( array_filter( $GLOBALS["__nf_entries"], fn( $e ) => (int) $e["id"] !== $id ) );
				return true;
			}
		}
		class EntryMessageRepository { public static function delete_by_entry( int $id ): int { return 1; } }
		class AIResponseRepository   { public static function delete_by_entry( int $id ): int { return 1; } }
		class EntryActivity          { public static function delete_for_entry( int $id ): void { $GLOBALS["__nf_deleted"]["timeline"][] = $id; } }
	}
	namespace NeuralForms\\Payments {
		class FakeGateway { public function expire_checkout( array $attempt ) { $GLOBALS["__nf_deleted"]["expired"][] = (int) $attempt["id"]; } }
		class PaymentProviderRegistry { public static function get( string $id ) { return $id === "stripe" ? new FakeGateway() : null; } }
	}
	' );

	$root = dirname( __DIR__ );
	require $root . '/modules/payments/PaymentStatus.php';
	require $root . '/modules/payments/PaymentRepository.php';
	require $root . '/modules/quotes/QuoteRepository.php';
	require $root . '/modules/privacy/PrivacyTools.php';

	$passed = 0;
	$failed = 0;

	// ── 1. pure helpers ──
	$meta = \NeuralForms\Payments\PaymentRepository::anonymised_meta( array( 'customer_name' => 'Ann Lee', 'customer_email' => 'ann@x.co', 'form_name' => 'Order', 'invoice_number' => 'INV-2026-0007', 'invoice' => array( 'number' => 'INV-2026-0007', 'sent_to' => 'ann@x.co', 'sent_at' => '2026-11-01 10:00:00' ) ) );
	check( '1a. meta: name + email blanked',          array( $meta['customer_name'], $meta['customer_email'] ), array( '', '' ), $passed, $failed );
	check( '1b. meta: invoice recipient blanked',     $meta['invoice']['sent_to'], '', $passed, $failed );
	check( '1c. meta: invoice number + form kept',    array( $meta['invoice_number'], $meta['invoice']['number'], $meta['form_name'] ), array( 'INV-2026-0007', 'INV-2026-0007', 'Order' ), $passed, $failed );
	check( '1d. meta: flagged anonymised',            $meta['anonymised'], true, $passed, $failed );
	check( '1e. meta: empty meta stays minimal',      \NeuralForms\Payments\PaymentRepository::anonymised_meta( array() ), array( 'anonymised' => true ), $passed, $failed );

	$snap = \NeuralForms\Quotes\QuoteRepository::anonymised_snapshot( array( 'customer' => array( 'name' => 'Ann Lee', 'email' => 'ann@x.co', 'address' => array( '1 Road' ) ), 'business' => array( 'name' => 'Acme' ), 'line_items' => array( array( 'label' => 'Book', 'total_minor' => 2900 ) ), 'totals' => array( 'total_minor' => 2900 ), 'notes' => 'Call Ann on 555' ) );
	check( '1f. snapshot: customer blanked',          $snap['customer'], array( 'name' => '', 'email' => '', 'address' => array() ), $passed, $failed );
	check( '1g. snapshot: notes blanked',             $snap['notes'], '', $passed, $failed );
	check( '1h. snapshot: lines, totals, business kept', array( $snap['line_items'][0]['total_minor'], $snap['totals']['total_minor'], $snap['business']['name'] ), array( 2900, 2900, 'Acme' ), $passed, $failed );

	// ── fixtures: entry 10 unpaid, entry 20 paid ──
	$P = 'wp_neural_forms_payments';
	$Q = 'wp_neural_forms_quotes';
	$pay = static function ( int $id, int $entry, string $status, string $provider = 'stripe' ) {
		return array( 'id' => $id, 'entry_id' => $entry, 'form_id' => 1, 'quote_id' => null, 'provider' => $provider, 'livemode' => 0, 'session_id' => 'cs_' . $id, 'provider_payment_id' => 'pi_' . $id, 'amount_due_minor' => 2900, 'amount_paid_minor' => $status === 'paid' ? 2900 : 0, 'amount_refunded_minor' => 0, 'status' => $status, 'attempt' => 1, 'checkout_url' => 'https://pay.example/' . $id, 'token_seed' => 'seed', 'paid_at' => null, 'meta' => json_encode( array( 'customer_name' => 'Ann Lee', 'customer_email' => 'ann@x.co', 'invoice_number' => 'INV-2026-000' . $id ) ) );
	};
	$quote = static function ( int $id, int $entry ) {
		return array( 'id' => $id, 'entry_id' => $entry, 'form_id' => 1, 'number' => 'Q-2026-000' . $id, 'revision' => 1, 'status' => 'issued', 'amount_total_minor' => 2900, 'amount_due_minor' => 2900, 'notes' => 'private note', 'token_seed' => 'seed' . $id, 'snapshot' => json_encode( array( 'customer' => array( 'name' => 'Ann Lee', 'email' => 'ann@x.co' ), 'totals' => array( 'total_minor' => 2900 ) ) ) );
	};
	$GLOBALS['wpdb']->tables[ $P ] = array( $pay( 1, 10, 'pending' ), $pay( 2, 10, 'failed' ), $pay( 3, 20, 'paid' ), $pay( 4, 20, 'pending' ), $pay( 5, 30, 'paid' ) );
	$GLOBALS['wpdb']->tables[ $Q ] = array( $quote( 1, 10 ), $quote( 2, 20 ), $quote( 3, 30 ) );
	$GLOBALS['__nf_entries']       = array(
		array( 'id' => 10, 'form_id' => 1, 'data' => array( 'email' => 'ann@x.co' ) ),
		array( 'id' => 20, 'form_id' => 1, 'data' => array( 'email' => 'ann@x.co' ) ),
		array( 'id' => 30, 'form_id' => 1, 'data' => array( 'email' => 'bob@x.co' ) ),
	);

	$r = \NeuralForms\Privacy\PrivacyTools::erase( 'ann@x.co', 1 );

	$rows = static function ( string $table, int $entry ) { return array_values( array_filter( $GLOBALS['wpdb']->tables[ $table ], static function ( $r ) use ( $entry ) { return (int) $r['entry_id'] === $entry; } ) ); };

	// ── 2. entry without a confirmed payment ──
	check( '2a. both entries erased',                 $GLOBALS['__nf_deleted']['entries'], array( 10, 20 ), $passed, $failed );
	check( '2b. timelines erased',                    $GLOBALS['__nf_deleted']['timeline'], array( 10, 20 ), $passed, $failed );
	check( '2c. unpaid entry: attempts deleted',      count( $rows( $P, 10 ) ), 0, $passed, $failed );
	check( '2d. unpaid entry: quotes deleted',        count( $rows( $Q, 10 ) ), 0, $passed, $failed );
	check( '2e. open checkouts closed at the gateway', $GLOBALS['__nf_deleted']['expired'], array( 1, 4 ), $passed, $failed );

	// ── 3. entry with a confirmed payment ──
	$kept = $rows( $P, 20 );
	usort( $kept, static function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
	check( '3a. paid entry: attempts kept',           array_column( $kept, 'id' ), array( 3, 4 ), $passed, $failed );
	check( '3b. paid attempt still paid, amount kept', array( $kept[0]['status'], $kept[0]['amount_paid_minor'], $kept[0]['provider_payment_id'] ), array( 'paid', 2900, 'pi_3' ), $passed, $failed );
	check( '3c. open attempt of that entry closed',   $kept[1]['status'], 'replaced', $passed, $failed );
	$m3 = json_decode( $kept[0]['meta'], true );
	check( '3d. customer details blanked on attempts', array( $m3['customer_name'], $m3['customer_email'], json_decode( $kept[1]['meta'], true )['customer_email'] ), array( '', '', '' ), $passed, $failed );
	check( '3e. invoice number kept',                 $m3['invoice_number'], 'INV-2026-0003', $passed, $failed );
	check( '3f. checkout link removed',               array( $kept[0]['checkout_url'], $kept[1]['checkout_url'] ), array( null, null ), $passed, $failed );
	$q2 = $rows( $Q, 20 )[0];
	check( '3g. quote kept, customer blanked',        json_decode( $q2['snapshot'], true )['customer'], array( 'name' => '', 'email' => '' ), $passed, $failed );
	check( '3h. quote totals + number kept',          array( json_decode( $q2['snapshot'], true )['totals']['total_minor'], $q2['number'], $q2['amount_total_minor'] ), array( 2900, 'Q-2026-0002', 2900 ), $passed, $failed );
	check( '3i. quote link revoked, notes cleared',   array( $q2['token_seed'], $q2['notes'] ), array( null, null ), $passed, $failed );
	check( '3j. result: removed 2, retained 3 rows',  array( $r['items_removed'], $r['items_retained'], $r['done'] ), array( 2, 3, true ), $passed, $failed );
	check( '3k. result explains what was kept',       count( $r['messages'] ), 1, $passed, $failed );
	// Entry 30 belongs to someone else; its rows keep the fixture's stored details.
	check( '3l. another entry\'s rows untouched',            array( json_decode( $rows( $P, 30 )[0]['meta'], true )['customer_email'], json_decode( $rows( $Q, 30 )[0]['snapshot'], true )['customer']['email'], $rows( $Q, 30 )[0]['token_seed'] ), array( 'ann@x.co', 'ann@x.co', 'seed3' ), $passed, $failed );

	// ── 4. log cleanup keeps the timeline ──
	$src = file_get_contents( $root . '/modules/core/Logger.php' );
	preg_match( '/public static function cleanup\(\).+?\n\t\}/s', $src, $m ) || exit( 2 );
	foreach ( array( 'entry.', 'workflow.', 'quote.', 'payment.' ) as $i => $prefix ) {
		check( '4' . chr( 97 + $i ) . ". cleanup keeps {$prefix}* rows", strpos( $m[0], "event_type NOT LIKE '{$prefix}%'" ) !== false, true, $passed, $failed );
	}
	check( '4e. cleanup still prunes after 60 days',  strpos( $m[0], 'INTERVAL 60 DAY' ) !== false, true, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
