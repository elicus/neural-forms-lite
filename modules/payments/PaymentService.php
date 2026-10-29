<?php
/**
 * Payment orchestration (1.2.0 part B).
 *
 *   start_checkout()   one attempt row (idempotent, PAY8/PAY9) + hosted checkout URL
 *   handle_event()     verified webhook event → attempt status → entry aggregate
 *   reconcile()        read the gateway and apply the result the same way
 *   return_view()      HTML for the buyer's return page, from STORED state (PAY3)
 *   reconcile_due()    daily cron for attempts still open after an hour
 *
 * The only way an attempt becomes `paid` is PaymentStatus::apply_event(),
 * fed either by a signature-verified webhook or by a server-to-server
 * fetch — never by a return URL or a query parameter.
 */

namespace NeuralForms\Payments;

use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Logger;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Notifications\NotificationService;
use NeuralForms\Pricing\Money;
use NeuralForms\Quotes\QuoteRepository;
use NeuralForms\Quotes\QuoteService;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryRepository;
use WP_Error;

final class PaymentService {

	const REUSE_WINDOW = 23 * HOUR_IN_SECONDS;

	/** Normalised per-form payment settings. */
	public static function settings( array $form ): array {
		$raw = is_array( $form['settings']['payment'] ?? null ) ? $form['settings']['payment'] : array();
		return array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'provider'     => isset( $raw['provider'] ) && is_string( $raw['provider'] ) && $raw['provider'] !== '' ? $raw['provider'] : 'auto',
			'submit_label' => isset( $raw['submit_label'] ) && is_string( $raw['submit_label'] ) ? $raw['submit_label'] : '',
			'description'  => isset( $raw['description'] ) && is_string( $raw['description'] ) ? $raw['description'] : '',
			'success_url'  => isset( $raw['success_url'] ) && is_string( $raw['success_url'] ) ? $raw['success_url'] : '',
			'cancel_url'   => isset( $raw['cancel_url'] ) && is_string( $raw['cancel_url'] ) ? $raw['cancel_url'] : '',
			'send_invoice' => array_key_exists( 'send_invoice', $raw ) ? ! empty( $raw['send_invoice'] ) : true,
		);
	}

	/** Payments switched on for this form AND possible on this install. */
	public static function enabled_for( array $form ): bool {
		if ( ! Capabilities::can( Capabilities::PAYMENTS ) ) {
			return false;
		}
		$s = self::settings( $form );
		return $s['enabled'] && count( PaymentProviderRegistry::configured() ) > 0;
	}

	/**
	 * Providers the visitor may use for a form: the configured one(s)
	 * matching the setting. `auto` = first configured; `choice` = all.
	 *
	 * @return array<string,PaymentProviderInterface>
	 */
	public static function providers_for( array $form ): array {
		$configured = PaymentProviderRegistry::configured();
		$s          = self::settings( $form );
		if ( $s['provider'] === 'choice' ) {
			return $configured;
		}
		if ( $s['provider'] !== 'auto' && isset( $configured[ $s['provider'] ] ) ) {
			return array( $s['provider'] => $configured[ $s['provider'] ] );
		}
		return array_slice( $configured, 0, 1, true );
	}

	/**
	 * Create (or reuse) the checkout for an entry.
	 *
	 * @param array $entry   Decoded entry.
	 * @param array $form    Decoded form.
	 * @param array $opts    { provider?: string, quote?: array }
	 * @return array|WP_Error { url, payment, reused }
	 */
	public static function start_checkout( array $entry, array $form, array $opts = array() ) {
		if ( ! Capabilities::can( Capabilities::PAYMENTS ) ) {
			return Capabilities::locked_error( Capabilities::PAYMENTS );
		}
		$entry_id = (int) ( $entry['id'] ?? 0 );
		$quote    = isset( $opts['quote'] ) && is_array( $opts['quote'] ) ? $opts['quote'] : null;

		// Amount: from the quote (CQ11) or the entry's own snapshot.
		if ( $quote ) {
			if ( (int) $quote['entry_id'] !== $entry_id ) {
				return new WP_Error( 'quote_mismatch', __( 'The quote does not belong to this entry.', 'neural-forms-lite' ), array( 'status' => 409 ) );
			}
			if ( ! QuoteService::can_start_payment( $quote ) ) {
				return new WP_Error( 'quote_not_payable', __( 'This quote is no longer valid for payment.', 'neural-forms-lite' ), array( 'status' => 410 ) );
			}
			$snap     = $quote['snapshot'];
			$currency = (string) $snap['currency'];
			$due      = (int) ( $snap['due_now'] ?? 0 );
			$kind     = is_array( $snap['deposit'] ?? null ) && (int) ( $snap['deposit']['amount'] ?? 0 ) > 0 && $due < (int) ( $snap['total'] ?? 0 ) ? 'deposit' : 'full';
			$desc     = sprintf( __( 'Quote %s', 'neural-forms-lite' ), (string) $quote['number'] );
			$lines    = is_array( $snap['line_items'] ?? null ) ? $snap['line_items'] : array();
			$has_adj  = ( is_array( $snap['discount'] ?? null ) && (int) ( $snap['discount']['amount'] ?? 0 ) > 0 );
		} else {
			$snap     = is_array( $entry['pricing'] ?? null ) ? $entry['pricing'] : array();
			$currency = (string) ( $snap['currency'] ?? 'USD' );
			$due      = (int) ( $snap['due_now'] ?? 0 );
			$kind     = is_array( $snap['deposit'] ?? null ) && (int) ( $snap['deposit']['amount'] ?? 0 ) > 0 && $due < (int) ( $snap['total'] ?? 0 ) ? 'deposit' : 'full';
			$desc     = (string) ( $form['name'] ?? __( 'Form submission', 'neural-forms-lite' ) );
			$lines    = array();
			foreach ( is_array( $snap['line_items'] ?? null ) ? $snap['line_items'] : array() as $l ) {
				$lines[] = array( 'label' => (string) ( $l['choice_label'] ?: ( $l['label'] ?? '' ) ), 'unit' => (int) ( $l['unit'] ?? 0 ), 'qty' => (int) ( $l['qty'] ?? 1 ) );
			}
			$has_adj = ( is_array( $snap['discount'] ?? null ) && (int) ( $snap['discount']['amount'] ?? 0 ) > 0 );
		}
		if ( $due <= 0 ) {
			return new WP_Error( 'nothing_due', __( 'There is nothing to pay for this entry.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}

		// Provider.
		$available = self::providers_for( $form );
		$wanted    = isset( $opts['provider'] ) && is_string( $opts['provider'] ) ? $opts['provider'] : '';
		if ( $wanted !== '' ) {
			if ( ! isset( $available[ $wanted ] ) && ! isset( PaymentProviderRegistry::configured()[ $wanted ] ) ) {
				return new WP_Error( 'provider_unavailable', __( 'That payment method is not available.', 'neural-forms-lite' ), array( 'status' => 422 ) );
			}
			$provider = $available[ $wanted ] ?? PaymentProviderRegistry::configured()[ $wanted ];
		} else {
			$provider = reset( $available );
		}
		if ( ! $provider instanceof PaymentProviderInterface ) {
			return new WP_Error( 'no_provider', __( 'No payment provider is connected. Connect Stripe or PayPal under Integrations.', 'neural-forms-lite' ), array( 'status' => 424 ) );
		}

		// Reuse an open attempt with the same amount (double click / refresh, PAY8).
		$attempts = PaymentRepository::list_for_entry( $entry_id );
		foreach ( $attempts as $a ) {
			if ( in_array( $a['status'], PaymentStatus::SETTLED, true ) && (int) ( $a['quote_id'] ?? 0 ) === (int) ( $quote['id'] ?? 0 ) ) {
				return new WP_Error( 'already_paid', __( 'This payment has already been made.', 'neural-forms-lite' ), array( 'status' => 409 ) );
			}
		}
		foreach ( $attempts as $a ) {
			if ( $a['status'] === 'pending' && $a['provider'] === $provider->id() && $a['checkout_url'] && (int) $a['amount_due_minor'] === $due && $a['currency'] === strtoupper( $currency )
				&& (int) ( $a['quote_id'] ?? 0 ) === (int) ( $quote['id'] ?? 0 ) && ( time() - strtotime( $a['created_at'] . ' UTC' ) ) < self::REUSE_WINDOW ) {
				return array( 'url' => $a['checkout_url'], 'payment' => $a, 'reused' => true );
			}
		}
		// Anything else still open is superseded (PAY9); best-effort expire at the gateway.
		foreach ( $attempts as $a ) {
			if ( $a['status'] === 'pending' ) {
				$p = PaymentProviderRegistry::get( (string) $a['provider'] );
				if ( $p && $a['session_id'] ) {
					$p->expire_checkout( $a );
				}
			}
		}
		$attempt = count( $attempts ) + 1;
		$key     = 'nf-' . $entry_id . '-' . (int) ( $quote['id'] ?? 0 ) . '-' . $attempt;
		$id      = PaymentRepository::create(
			array(
				'entry_id'         => $entry_id,
				'form_id'          => (int) ( $entry['form_id'] ?? $form['id'] ?? 0 ),
				'quote_id'         => $quote ? (int) $quote['id'] : null,
				'provider'         => $provider->id(),
				'livemode'         => $provider->is_live(),
				'kind'             => $kind,
				'amount_due_minor' => $due,
				'currency'         => $currency,
				'idempotency_key'  => $key,
				'attempt'          => $attempt,
				'meta'             => array( 'description' => $desc, 'customer_email' => NotificationService::submitter_email( $form, is_array( $entry['data'] ?? null ) ? $entry['data'] : array() ), 'customer_name' => NotificationService::submitter_name( $form, is_array( $entry['data'] ?? null ) ? $entry['data'] : array() ), 'form_name' => (string) ( $form['name'] ?? '' ) ),
			)
		);
		if ( ! $id ) {
			// Unique key hit: a parallel request created it a moment ago — reuse that row.
			foreach ( PaymentRepository::list_for_entry( $entry_id ) as $a ) {
				if ( $a['idempotency_key'] === $key ) {
					return $a['checkout_url'] ? array( 'url' => $a['checkout_url'], 'payment' => $a, 'reused' => true ) : new WP_Error( 'in_progress', __( 'Payment is being prepared, please retry.', 'neural-forms-lite' ), array( 'status' => 409 ) );
				}
			}
			return new WP_Error( 'db_error', __( 'Could not create the payment record.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		PaymentRepository::replace_open( $entry_id, $id );
		$payment = PaymentRepository::get( $id );

		$data    = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
		$details = array(
			'description'    => $desc . ' — #' . $entry_id,
			'customer_email' => NotificationService::submitter_email( $form, $data ),
			'customer_name'  => NotificationService::submitter_name( $form, $data ),
			'line_items'     => array(),
		);
		if ( $kind === 'deposit' || $has_adj || empty( $lines ) ) {
			$details['line_items'][] = array( 'label' => $kind === 'deposit' ? sprintf( __( 'Deposit — %s', 'neural-forms-lite' ), $desc ) : $desc, 'amount_minor' => $due, 'qty' => 1 );
		} else {
			foreach ( $lines as $l ) {
				$details['line_items'][] = array( 'label' => (string) ( $l['label'] ?? $desc ), 'amount_minor' => (int) ( $l['unit'] ?? 0 ), 'qty' => max( 1, (int) ( $l['qty'] ?? 1 ) ) );
			}
		}
		$result = $provider->create_checkout( $payment, $entry, $form, self::urls( $payment, $form ), $details );
		if ( is_wp_error( $result ) ) {
			PaymentRepository::update( $id, array( 'status' => 'failed', 'meta' => array_merge( $payment['meta'], array( 'error' => $result->get_error_message() ) ) ) );
			EntryActivity::record( $entry_id, 'payment.start_failed', sprintf( __( 'Could not start %1$s checkout: %2$s', 'neural-forms-lite' ), $provider->label(), $result->get_error_message() ), array( 'payment_id' => $id, 'by' => 0 ), 'error' );
			Logger::log( 'payment_start_failed', 'error', $result->get_error_message(), array( 'payment_id' => $id, 'provider' => $provider->id() ), $entry_id );
			return $result;
		}
		PaymentRepository::update(
			$id,
			array(
				'session_id'          => (string) ( $result['session_id'] ?? '' ),
				'checkout_url'        => (string) ( $result['url'] ?? '' ),
				'account_id'          => (string) ( $result['account_id'] ?? '' ),
				'livemode'            => ! empty( $result['livemode'] ),
				'provider_payment_id' => (string) ( $result['provider_payment_id'] ?? '' ),
				'meta'                => array_merge( $payment['meta'], is_array( $result['meta'] ?? null ) ? $result['meta'] : array() ),
			)
		);
		$payment = PaymentRepository::get( $id );
		self::sync_entry( $entry_id );
		EntryActivity::record( $entry_id, 'payment.started', sprintf( __( '%1$s checkout started for %2$s (%3$s)', 'neural-forms-lite' ), $provider->label(), Money::format( $due, $currency ), $kind === 'deposit' ? __( 'deposit', 'neural-forms-lite' ) : __( 'full amount', 'neural-forms-lite' ) ), array( 'payment_id' => $id, 'by' => 0 ) );
		return array( 'url' => (string) $result['url'], 'payment' => $payment, 'reused' => false );
	}

	/**
	 * Public REST root the gateways may call back to. Gateways reject or
	 * cannot reach private hosts (`.local`, `localhost`, plain http), so a
	 * site behind a tunnel can point this at the tunnel:
	 * `define( 'NEURAL_FORMS_PAYMENT_PUBLIC_URL', 'https://abc.ngrok.app' )`
	 * or the `neural_forms_payment_public_url` filter. Paths stay the same.
	 */
	public static function public_rest_url( string $path ): string {
		$url  = rest_url( $path );
		$base = defined( 'NEURAL_FORMS_PAYMENT_PUBLIC_URL' ) ? (string) NEURAL_FORMS_PAYMENT_PUBLIC_URL : '';
		$base = (string) apply_filters( 'neural_forms_payment_public_url', $base );
		if ( $base !== '' && preg_match( '#^https?://#i', $base ) ) {
			$home = untrailingslashit( home_url() );
			if ( strpos( $url, $home ) === 0 ) {
				$url = untrailingslashit( $base ) . substr( $url, strlen( $home ) );
			}
		}
		return $url;
	}

	/** Absolute URLs handed to the gateway. */
	public static function urls( array $payment, array $form ): array {
		$token = PaymentTokens::derive( (int) $payment['id'], (string) $payment['token_seed'] );
		$base  = self::public_rest_url( 'neural-forms/v1/payments/' . (int) $payment['id'] . '/return' );
		return array(
			// `nf_token`, not `token`: PayPal appends its own `token=<order id>` to the return URL.
			'success' => add_query_arg( array( 'nf_token' => $token, 'result' => 'success' ), $base ),
			'cancel'  => add_query_arg( array( 'nf_token' => $token, 'result' => 'cancel' ), $base ),
			'webhook' => self::public_rest_url( 'neural-forms/v1/payments/webhook/' . (string) $payment['provider'] ),
		);
	}

	/**
	 * Process one verified, normalised gateway event.
	 *
	 * @return array { result, payment_id?, reason? }
	 */
	public static function handle_event( string $provider_id, array $event ): array {
		$event_id = (string) ( $event['event_id'] ?? '' );
		$type     = (string) ( $event['raw_type'] ?? $event['type'] ?? '' );
		if ( $event_id === '' ) {
			return array( 'result' => 'rejected', 'reason' => 'missing_event_id' );
		}
		$row_id = PaymentRepository::record_event( $provider_id, $event_id, $type, ! empty( $event['livemode'] ) );
		if ( $row_id === 0 ) {
			return array( 'result' => 'duplicate' );
		}
		$payment = null;
		if ( ! empty( $event['session_id'] ) ) {
			$payment = PaymentRepository::find_by_session( $provider_id, (string) $event['session_id'] );
		}
		if ( ! $payment && ! empty( $event['provider_payment_id'] ) ) {
			$payment = PaymentRepository::find_by_provider_payment_id( $provider_id, (string) $event['provider_payment_id'] );
		}
		if ( ! $payment ) {
			PaymentRepository::finish_event( $row_id, null, 'unmatched' );
			return array( 'result' => 'unmatched' );
		}
		$outcome = self::apply( $payment, $event, $row_id );
		return array_merge( array( 'payment_id' => (int) $payment['id'] ), $outcome );
	}

	/** Shared by webhooks and reconciliation. */
	private static function apply( array $payment, array $event, int $event_row_id = 0 ): array {
		$entry_id = (int) $payment['entry_id'];
		$r        = PaymentStatus::apply_event( $payment, $event );
		if ( ! $r['changed'] ) {
			if ( $event_row_id ) {
				PaymentRepository::finish_event( $event_row_id, (int) $payment['id'], $r['reason'] );
			}
			if ( in_array( $r['reason'], array( 'mode_mismatch', 'account_mismatch', 'currency_mismatch', 'amount_mismatch' ), true ) ) {
				$meta                         = $payment['meta'];
				$meta['flags']                = array_values( array_unique( array_merge( (array) ( $meta['flags'] ?? array() ), array( $r['reason'] ) ) ) );
				$meta['needs_reconciliation'] = true;
				PaymentRepository::update( (int) $payment['id'], array( 'meta' => $meta ) );
				EntryActivity::record( $entry_id, 'payment.mismatch', sprintf( __( 'A %1$s event was ignored: %2$s. Check the payment in your gateway dashboard.', 'neural-forms-lite' ), (string) $payment['provider'], str_replace( '_', ' ', $r['reason'] ) ), array( 'payment_id' => (int) $payment['id'], 'event' => (string) ( $event['raw_type'] ?? '' ), 'by' => 0 ), 'warning' );
			}
			return array( 'result' => 'ignored', 'reason' => $r['reason'] );
		}
		// Atomic: only the worker that still sees the old status wins (PAY5 under concurrency).
		if ( ! PaymentRepository::update_if_status( (int) $payment['id'], (string) $payment['status'], $r['fields'] ) ) {
			if ( $event_row_id ) {
				PaymentRepository::finish_event( $event_row_id, (int) $payment['id'], 'raced' );
			}
			return array( 'result' => 'ignored', 'reason' => 'raced' );
		}
		$payment = PaymentRepository::get( (int) $payment['id'] );
		if ( $event_row_id ) {
			PaymentRepository::finish_event( $event_row_id, (int) $payment['id'], 'applied:' . $r['reason'] );
		}
		$aggregate = self::sync_entry( $entry_id );
		$label     = PaymentStatus::label( (string) $payment['status'] );
		$amount    = Money::format( (int) ( $payment['status'] === 'paid' || in_array( $payment['status'], PaymentStatus::SETTLED, true ) ? $payment['amount_paid_minor'] : $payment['amount_due_minor'] ), (string) $payment['currency'] );
		EntryActivity::record( $entry_id, 'payment.' . (string) $payment['status'], sprintf( '%1$s: %2$s (%3$s)', $label, $amount, (string) $payment['provider'] ), array( 'payment_id' => (int) $payment['id'], 'kind' => $payment['kind'], 'by' => 0 ), (string) $payment['status'] === 'failed' ? 'error' : 'info' );
		if ( $r['newly_paid'] ) {
			/**
			 * Fires once per verified, recorded successful payment. Never from
			 * a return URL. B4 maps it to the `payment_confirmed` workflow trigger.
			 *
			 * @param int   $entry_id
			 * @param array $payment   Decoded attempt row.
			 * @param array $aggregate PaymentStatus::aggregate() for the entry.
			 */
			do_action( 'neural_forms_payment_confirmed', $entry_id, $payment, $aggregate );
			self::maybe_auto_invoice( $payment );
		}
		return array( 'result' => 'applied', 'status' => (string) $payment['status'], 'newly_paid' => $r['newly_paid'] );
	}

	// ── Invoices ─────────────────────────────────────────────────────

	/** Assigns the invoice number on first use (stored in meta, never changes). */
	public static function ensure_invoice_number( array $payment ): array {
		if ( ! empty( $payment['meta']['invoice_number'] ) ) {
			return $payment;
		}
		$meta                   = $payment['meta'];
		$meta['invoice_number'] = \NeuralForms\Quotes\QuoteNumbering::next( 'INV' );
		$meta['invoice_issued_at'] = gmdate( 'Y-m-d H:i:s' );
		PaymentRepository::update( (int) $payment['id'], array( 'meta' => $meta ) );
		return PaymentRepository::get( (int) $payment['id'] ) ?: $payment;
	}

	/** Public links for the invoice (same token as the return page). */
	public static function invoice_links( array $payment ): array {
		if ( empty( $payment['token_seed'] ) ) {
			return array();
		}
		$token = PaymentTokens::derive( (int) $payment['id'], (string) $payment['token_seed'] );
		$base  = rest_url( 'neural-forms/v1/payments/' . (int) $payment['id'] . '/invoice' );
		$out   = array( 'view' => add_query_arg( 'token', $token, $base ) );
		if ( \NeuralForms\Quotes\QuotePdf::available() ) {
			$out['pdf'] = add_query_arg( array( 'token' => $token, 'format' => 'pdf' ), $base );
		}
		return $out;
	}

	/** Invoice HTML (and PDF when Pro provides it) for a settled attempt. */
	public static function invoice_html( array $payment, array $options = array() ): string {
		$entry = EntryRepository::get( (int) $payment['entry_id'] );
		$form  = $entry ? FormRepository::find( (int) $entry['form_id'] ) : null;
		$quote = ! empty( $payment['quote_id'] ) ? QuoteRepository::get( (int) $payment['quote_id'] ) : null;
		return InvoiceRenderer::html( $payment, $entry ?: array(), $form ?: array(), $quote, \NeuralForms\Quotes\QuoteSettings::get(), $options );
	}

	public static function invoice_pdf( array $payment ): ?string {
		$html = self::invoice_html( $payment, array( 'mode' => 'pdf' ) );
		return \NeuralForms\Quotes\QuotePdf::from_html( $html, array( 'payment_id' => (int) $payment['id'] ) );
	}

	/** Email the invoice. @return array|WP_Error */
	public static function send_invoice( int $payment_id, string $to = '', string $message = '', int $user_id = 0, bool $automatic = false ) {
		$payment = PaymentRepository::get( $payment_id );
		if ( ! $payment ) {
			return new WP_Error( 'not_found', __( 'Payment not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $payment['status'], PaymentStatus::SETTLED, true ) ) {
			return new WP_Error( 'payment_state', __( 'Only confirmed payments have an invoice.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		$to = sanitize_email( $to !== '' ? $to : (string) ( $payment['meta']['customer_email'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid recipient email address.', 'neural-forms-lite' ), array( 'status' => 422 ) );
		}
		$payment  = self::ensure_invoice_number( $payment );
		$settings = \NeuralForms\Quotes\QuoteSettings::get();
		$biz      = (string) ( $settings['business_name'] ?: get_bloginfo( 'name' ) );
		$c        = (string) $payment['currency'];
		$number   = (string) $payment['meta']['invoice_number'];
		$links    = self::invoice_links( $payment );
		$name     = (string) ( $payment['meta']['customer_name'] ?? '' );
		$lines    = array( $name !== '' ? sprintf( __( 'Hi %s,', 'neural-forms-lite' ), $name ) : __( 'Hello,', 'neural-forms-lite' ), '' );
		if ( trim( $message ) !== '' ) {
			$lines[] = sanitize_textarea_field( $message );
			$lines[] = '';
		}
		$lines[] = sprintf( __( 'Thank you for your payment of %1$s. Your invoice %2$s is attached.', 'neural-forms-lite' ), Money::format( (int) $payment['amount_paid_minor'], $c ), $number );
		$agg = self::entry_summary( (int) $payment['entry_id'] );
		if ( (int) $agg['amounts']['remaining'] > 0 ) {
			$lines[] = sprintf( __( 'Remaining balance: %s.', 'neural-forms-lite' ), $agg['formatted']['remaining'] );
		}
		$lines[] = '';
		$lines[] = __( 'View the invoice online:', 'neural-forms-lite' );
		$lines[] = $links['view'] ?? '';
		$lines[] = '';
		$lines[] = $biz;
		$body    = implode( "\n", $lines );
		$subject = sprintf( __( 'Invoice %1$s from %2$s', 'neural-forms-lite' ), $number, $biz );

		$attachments = array();
		$tmp         = null;
		$pdf         = self::invoice_pdf( $payment );
		if ( is_string( $pdf ) ) {
			$tmp = wp_tempnam( 'invoice.pdf' );
			if ( $tmp ) {
				$named = dirname( $tmp ) . '/' . strtolower( preg_replace( '/[^A-Za-z0-9\-]+/', '-', $number ) ) . '.pdf';
				if ( ! file_exists( $named ) && @rename( $tmp, $named ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$tmp = $named;
				}
				file_put_contents( $tmp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$attachments[] = $tmp;
			}
		}
		$sender  = NotificationService::resolve_sender( \NeuralForms\Core\Config::all() );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $sender['email'] !== '' ) {
			$headers[] = 'Reply-To: ' . ( $sender['name'] !== '' ? $sender['name'] . ' <' . $sender['email'] . '>' : $sender['email'] );
		}
		$sent = NotificationService::send_mail( $to, $subject, $body, $headers, $attachments );
		if ( $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		\NeuralForms\Storage\EntryMessageRepository::create(
			array(
				'entry_id'  => (int) $payment['entry_id'],
				'type'      => 'outgoing',
				'sender'    => $sender['email'] !== '' ? $sender['email'] : (string) get_bloginfo( 'admin_email' ),
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'meta'      => array( 'status' => $sent ? 'sent' : 'failed', 'payment_id' => (int) $payment['id'], 'invoice_number' => $number, 'automatic' => $automatic, 'reviewed_by' => $user_id, 'pdf_attached' => ! empty( $attachments ) ),
			)
		);
		if ( ! $sent ) {
			EntryActivity::record( (int) $payment['entry_id'], 'payment.invoice_failed', sprintf( __( 'Invoice %s could not be emailed', 'neural-forms-lite' ), $number ), array( 'payment_id' => (int) $payment['id'], 'to' => $to, 'by' => $user_id ), 'error' );
			return new WP_Error( 'send_failed', __( 'The email could not be sent. Check the site email configuration.', 'neural-forms-lite' ), array( 'status' => 424 ) );
		}
		$meta                    = $payment['meta'];
		$meta['invoice_sent_at'] = gmdate( 'Y-m-d H:i:s' );
		$meta['invoice_sent_to'] = $to;
		PaymentRepository::update( (int) $payment['id'], array( 'meta' => $meta ) );
		EntryActivity::record( (int) $payment['entry_id'], 'payment.invoice_sent', sprintf( __( 'Invoice %1$s emailed to %2$s%3$s', 'neural-forms-lite' ), $number, $to, $automatic ? ' ' . __( '(automatically)', 'neural-forms-lite' ) : '' ), array( 'payment_id' => (int) $payment['id'], 'to' => $to, 'by' => $user_id ) );
		return array( 'sent' => true, 'number' => $number, 'payment' => PaymentRepository::get( (int) $payment['id'] ) );
	}

	/** Automatic invoice after a confirmed payment when the form has it on (default on). */
	private static function maybe_auto_invoice( array $payment ): void {
		$entry = EntryRepository::get( (int) $payment['entry_id'] );
		$form  = $entry ? FormRepository::find( (int) $entry['form_id'] ) : null;
		if ( ! $form || ! self::settings( $form )['send_invoice'] ) {
			return;
		}
		$to = (string) ( $payment['meta']['customer_email'] ?? '' );
		if ( $to === '' || ! is_email( $to ) ) {
			return;
		}
		$r = self::send_invoice( (int) $payment['id'], $to, '', 0, true );
		unset( $r );
	}

	// ── Refunds ──────────────────────────────────────────────────────

	/**
	 * Refund through the gateway, then record it (webhook redeliveries of
	 * the same refund are harmless: refunds are applied as cumulative totals).
	 *
	 * @param int    $amount_minor 0 = everything still refundable.
	 * @return array|WP_Error { payment, refunded_minor }
	 */
	public static function refund( int $payment_id, int $amount_minor = 0, string $reason = '', int $user_id = 0 ) {
		if ( ! Capabilities::can( Capabilities::PAYMENTS ) ) {
			return Capabilities::locked_error( Capabilities::PAYMENTS );
		}
		$payment = PaymentRepository::get( $payment_id );
		if ( ! $payment ) {
			return new WP_Error( 'not_found', __( 'Payment not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $payment['status'], PaymentStatus::SETTLED, true ) ) {
			return new WP_Error( 'payment_state', __( 'Only confirmed payments can be refunded.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		$refundable = (int) $payment['amount_paid_minor'] - (int) $payment['amount_refunded_minor'];
		if ( $refundable <= 0 ) {
			return new WP_Error( 'nothing_to_refund', __( 'This payment has already been fully refunded.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		$amount = $amount_minor > 0 ? $amount_minor : $refundable;
		if ( $amount > $refundable ) {
			return new WP_Error( 'amount_too_high', sprintf( __( 'You can refund at most %s.', 'neural-forms-lite' ), Money::format( $refundable, (string) $payment['currency'] ) ), array( 'status' => 422 ) );
		}
		$provider = PaymentProviderRegistry::get( (string) $payment['provider'] );
		if ( ! $provider ) {
			return new WP_Error( 'no_provider', __( 'The payment provider is not available.', 'neural-forms-lite' ), array( 'status' => 424 ) );
		}
		$reason = mb_substr( sanitize_text_field( $reason ), 0, 200 );
		$r      = $provider->refund( $payment, $amount, $reason );
		if ( is_wp_error( $r ) ) {
			EntryActivity::record( (int) $payment['entry_id'], 'payment.refund_failed', sprintf( __( 'Refund of %1$s failed: %2$s', 'neural-forms-lite' ), Money::format( $amount, (string) $payment['currency'] ), $r->get_error_message() ), array( 'payment_id' => $payment_id, 'by' => $user_id ), 'error' );
			return $r;
		}
		$total = isset( $r['total_refunded_minor'] ) && (int) $r['total_refunded_minor'] > 0 ? (int) $r['total_refunded_minor'] : (int) $payment['amount_refunded_minor'] + (int) ( $r['amount_minor'] ?? $amount );
		$meta  = $payment['meta'];
		$meta['refunds']   = array_values( (array) ( $meta['refunds'] ?? array() ) );
		$meta['refunds'][] = array( 'id' => (string) ( $r['refund_id'] ?? '' ), 'amount_minor' => (int) ( $r['amount_minor'] ?? $amount ), 'status' => (string) ( $r['status'] ?? 'succeeded' ), 'reason' => $reason, 'by' => $user_id, 'at' => gmdate( 'Y-m-d H:i:s' ) );
		PaymentRepository::update( $payment_id, array( 'meta' => $meta ) );
		$row_id = PaymentRepository::record_event( (string) $payment['provider'], 'refund:' . ( $r['refund_id'] ?: $payment_id . ':' . time() ), 'refund.issued', ! empty( $payment['livemode'] ) );
		$fresh  = PaymentRepository::get( $payment_id );
		$out    = self::apply( $fresh, array( 'kind' => 'refunded', 'amount_refunded_minor' => $total, 'livemode' => $payment['livemode'], 'raw_type' => 'refund.issued' ), $row_id );
		EntryActivity::record( (int) $payment['entry_id'], 'payment.refund_issued', sprintf( __( 'Refund of %1$s issued via %2$s%3$s', 'neural-forms-lite' ), Money::format( (int) ( $r['amount_minor'] ?? $amount ), (string) $payment['currency'] ), $provider->label(), $reason !== '' ? ' — ' . $reason : '' ), array( 'payment_id' => $payment_id, 'refund_id' => (string) ( $r['refund_id'] ?? '' ), 'by' => $user_id ) );
		unset( $out );
		return array( 'payment' => self::expose( PaymentRepository::get( $payment_id ) ), 'refunded_minor' => (int) ( $r['amount_minor'] ?? $amount ) );
	}

	/** Recompute and store the entry's aggregate status. */
	public static function sync_entry( int $entry_id ): array {
		$entry     = EntryRepository::get( $entry_id );
		$attempts  = PaymentRepository::list_for_entry( $entry_id );
		$due       = (int) ( $entry['amount_due_minor'] ?? 0 );
		$total     = (int) ( $entry['amount_total_minor'] ?? 0 );
		$aggregate = PaymentStatus::aggregate( $attempts, $due, $total );
		if ( $entry && ( $entry['payment_status'] ?? '' ) !== $aggregate['status'] ) {
			EntryRepository::update( $entry_id, array( 'payment_status' => $aggregate['status'] ) );
		}
		if ( $aggregate['needs_reconciliation'] && $attempts ) {
			$newest = $attempts[0];
			if ( empty( $newest['meta']['double_payment_flagged'] ) ) {
				$meta                           = $newest['meta'];
				$meta['double_payment_flagged'] = true;
				$meta['needs_reconciliation']   = true;
				PaymentRepository::update( (int) $newest['id'], array( 'meta' => $meta ) );
				EntryActivity::record( $entry_id, 'payment.needs_reconciliation', __( 'More than one payment succeeded for this entry. Review and refund the extra one in your gateway dashboard; nothing was refunded automatically.', 'neural-forms-lite' ), array( 'by' => 0 ), 'warning' );
			}
		}
		$aggregate['currency'] = (string) ( $entry['currency'] ?? ( $attempts[0]['currency'] ?? 'USD' ) );
		return $aggregate;
	}

	/**
	 * Ask the gateway for the current state and apply it (PAY12). With
	 * $capture the provider may complete an approved order (PayPal).
	 *
	 * @return array|WP_Error { result, status }
	 */
	public static function reconcile( array $payment, bool $capture = false ) {
		$provider = PaymentProviderRegistry::get( (string) $payment['provider'] );
		if ( ! $provider ) {
			return new WP_Error( 'no_provider', __( 'The payment provider is not available.', 'neural-forms-lite' ), array( 'status' => 424 ) );
		}
		if ( ! $payment['session_id'] && ! $payment['provider_payment_id'] ) {
			return array( 'result' => 'ignored', 'status' => $payment['status'] );
		}
		$state = $capture ? $provider->capture( $payment ) : $provider->fetch_checkout( $payment );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$event = self::state_to_event( $state, (string) $payment['provider'] );
		$row   = PaymentRepository::record_event( (string) $payment['provider'], 'recon:' . ( $payment['session_id'] ?: $payment['provider_payment_id'] ) . ':' . time() . ':' . wp_rand( 100, 999 ), 'reconcile', ! empty( $state['livemode'] ) );
		$out   = self::apply( $payment, $event, $row );
		if ( $out['result'] === 'applied' && ! empty( $state['amount_refunded_minor'] ) ) {
			$fresh = PaymentRepository::get( (int) $payment['id'] );
			if ( $fresh ) {
				self::apply( $fresh, array( 'kind' => 'refunded', 'amount_refunded_minor' => (int) $state['amount_refunded_minor'], 'livemode' => $state['livemode'] ?? null ) );
			}
		}
		$fresh = PaymentRepository::get( (int) $payment['id'] );
		return array( 'result' => $out['result'], 'status' => $fresh ? $fresh['status'] : $payment['status'], 'reason' => $out['reason'] ?? '' );
	}

	/** Normalised fetch result → event shape understood by PaymentStatus. */
	public static function state_to_event( array $state, string $provider ): array {
		$kind = 'ignored';
		if ( ! empty( $state['paid'] ) ) {
			$kind = 'paid';
		} elseif ( ! empty( $state['processing'] ) ) {
			$kind = 'processing';
		} elseif ( ! empty( $state['failed'] ) ) {
			$kind = 'failed';
		} elseif ( in_array( $state['state'] ?? '', array( 'expired', 'cancelled' ), true ) ) {
			$kind = 'expired';
		}
		return array(
			'kind'                => $kind,
			'raw_type'            => 'reconcile:' . $provider,
			'amount_minor'        => $state['amount_minor'] ?? null,
			'currency'            => $state['currency'] ?? '',
			'provider_payment_id' => $state['provider_payment_id'] ?? '',
			'account_id'          => $state['account_id'] ?? '',
			'livemode'            => $state['livemode'] ?? null,
			'paid_at'             => $state['paid_at'] ?? null,
		);
	}

	/** Daily cron. */
	public static function reconcile_due(): int {
		$n = 0;
		foreach ( PaymentRepository::open_older_than( HOUR_IN_SECONDS ) as $p ) {
			$r = self::reconcile( $p );
			if ( ! is_wp_error( $r ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Buyer cancelled at the gateway: close the attempt unless it was in fact paid. */
	public static function buyer_cancelled( array $payment ): array {
		$r = self::reconcile( $payment );
		$p = PaymentRepository::get( (int) $payment['id'] );
		if ( $p && $p['status'] === 'pending' ) {
			PaymentRepository::update( (int) $p['id'], array( 'status' => 'expired' ) );
			EntryActivity::record( (int) $p['entry_id'], 'payment.expired', __( 'Checkout cancelled by the visitor', 'neural-forms-lite' ), array( 'payment_id' => (int) $p['id'], 'by' => 0 ) );
			self::sync_entry( (int) $p['entry_id'] );
			$p = PaymentRepository::get( (int) $p['id'] );
		}
		return $p ?: $payment;
	}

	/** REST shape for the inbox: never the seed. */
	public static function expose( array $payment ): array {
		unset( $payment['token_seed'] );
		$c                           = (string) $payment['currency'];
		$payment['status_label']     = PaymentStatus::label( (string) $payment['status'] );
		$payment['formatted']        = array(
			'due'      => Money::format( (int) $payment['amount_due_minor'], $c ),
			'paid'     => Money::format( (int) $payment['amount_paid_minor'], $c ),
			'refunded' => Money::format( (int) $payment['amount_refunded_minor'], $c ),
		);
		$meta                        = $payment['meta'];
		unset( $meta['error_detail'] );
		$payment['meta']             = $meta;
		$provider                    = PaymentProviderRegistry::get( (string) $payment['provider'] );
		$payment['provider_label']   = $provider ? $provider->label() : ucfirst( (string) $payment['provider'] );
		$payment['dashboard_url']    = $provider && method_exists( $provider, 'dashboard_url' ) ? (string) $provider->dashboard_url( $payment ) : '';
		$payment['refundable_minor'] = in_array( $payment['status'], PaymentStatus::SETTLED, true ) ? max( 0, (int) $payment['amount_paid_minor'] - (int) $payment['amount_refunded_minor'] ) : 0;
		$payment['invoice']          = in_array( $payment['status'], PaymentStatus::SETTLED, true ) ? array( 'number' => (string) ( $meta['invoice_number'] ?? '' ), 'sent_at' => $meta['invoice_sent_at'] ?? null, 'sent_to' => $meta['invoice_sent_to'] ?? null ) : null;
		return $payment;
	}

	/** Aggregate + attempts for one entry (inbox card). */
	public static function entry_summary( int $entry_id ): array {
		$entry    = EntryRepository::get( $entry_id );
		$attempts = PaymentRepository::list_for_entry( $entry_id );
		$agg      = PaymentStatus::aggregate( $attempts, (int) ( $entry['amount_due_minor'] ?? 0 ), (int) ( $entry['amount_total_minor'] ?? 0 ) );
		$c        = (string) ( $entry['currency'] ?? 'USD' );
		return array(
			'status'       => $agg['status'],
			'status_label' => PaymentStatus::label( $agg['status'] ),
			'amounts'      => array(
				'total'     => (int) ( $entry['amount_total_minor'] ?? 0 ),
				'due'       => (int) ( $entry['amount_due_minor'] ?? 0 ),
				'paid'      => $agg['amount_paid_minor'],
				'refunded'  => $agg['amount_refunded_minor'],
				'remaining' => $agg['remaining_minor'],
			),
			'formatted'    => array(
				'total'     => Money::format( (int) ( $entry['amount_total_minor'] ?? 0 ), $c ),
				'paid'      => Money::format( $agg['amount_paid_minor'], $c ),
				'refunded'  => Money::format( $agg['amount_refunded_minor'], $c ),
				'remaining' => Money::format( $agg['remaining_minor'], $c ),
			),
			'currency'             => $c,
			'needs_reconciliation' => $agg['needs_reconciliation'] || (bool) array_filter( $attempts, static function ( $a ) { return ! empty( $a['meta']['needs_reconciliation'] ); } ),
			'attempts'             => array_map( array( self::class, 'expose' ), $attempts ),
		);
	}

	/**
	 * Return page: reconcile (and capture) then render from stored state.
	 *
	 * @return array { html, status }
	 */
	public static function return_view( array $payment, string $result ): array {
		$fresh = $payment;
		if ( $result === 'cancel' ) {
			$fresh = self::buyer_cancelled( $payment );
		} elseif ( in_array( $payment['status'], array( 'pending', 'processing' ), true ) ) {
			$r = self::reconcile( $payment, true );
			unset( $r );
			$fresh = PaymentRepository::get( (int) $payment['id'] ) ?: $payment;
		}
		$entry    = EntryRepository::get( (int) $fresh['entry_id'] );
		$form     = $entry ? FormRepository::find( (int) $entry['form_id'] ) : null;
		$settings = $form ? self::settings( $form ) : self::settings( array() );
		$agg      = $entry ? PaymentStatus::aggregate( PaymentRepository::list_for_entry( (int) $entry['id'] ), (int) $entry['amount_due_minor'], (int) $entry['amount_total_minor'] ) : null;
		$c        = (string) $fresh['currency'];
		$status   = (string) $fresh['status'];
		$e        = static function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); };
		$back     = (string) ( $entry['page_url'] ?? '' );
		if ( $back === '' || ! wp_http_validate_url( $back ) ) {
			$back = home_url( '/' );
		}
		// Custom success / cancel pages configured on the form win (state is appended read-only).
		$custom = $status === 'paid' || $status === 'processing' ? $settings['success_url'] : ( $status === 'expired' ? $settings['cancel_url'] : '' );
		if ( $custom !== '' && wp_http_validate_url( $custom ) ) {
			return array( 'redirect' => add_query_arg( array( 'nf_payment' => $status, 'nf_entry' => (int) $fresh['entry_id'] ), $custom ), 'status' => $status );
		}
		switch ( $status ) {
			case 'paid':
				$title = $fresh['kind'] === 'deposit' ? __( 'Deposit received', 'neural-forms-lite' ) : __( 'Payment received', 'neural-forms-lite' );
				$text  = sprintf( __( 'Thank you. We received %s.', 'neural-forms-lite' ), Money::format( (int) $fresh['amount_paid_minor'], $c ) );
				if ( $agg && $agg['remaining_minor'] > 0 ) {
					$text .= ' ' . sprintf( __( 'Remaining balance: %s.', 'neural-forms-lite' ), Money::format( $agg['remaining_minor'], $c ) );
				}
				$tone = 'ok';
				break;
			case 'processing':
				$title = __( 'Payment is being processed', 'neural-forms-lite' );
				$text  = __( 'Your payment method takes a little longer to confirm. We will update the status as soon as the payment provider confirms it; you can close this page.', 'neural-forms-lite' );
				$tone  = 'wait';
				break;
			case 'pending':
				$title = __( 'Waiting for confirmation', 'neural-forms-lite' );
				$text  = __( 'We have not received a confirmation from the payment provider yet. This page refreshes automatically.', 'neural-forms-lite' );
				$tone  = 'wait';
				break;
			case 'failed':
				$title = __( 'Payment failed', 'neural-forms-lite' );
				$text  = __( 'The payment could not be completed. No money was taken. Please try again or contact us.', 'neural-forms-lite' );
				$tone  = 'bad';
				break;
			case 'refunded':
			case 'partially_refunded':
				$title = PaymentStatus::label( $status );
				$text  = sprintf( __( '%s has been refunded.', 'neural-forms-lite' ), Money::format( (int) $fresh['amount_refunded_minor'], $c ) );
				$tone  = 'ok';
				break;
			default:
				$title = __( 'Checkout cancelled', 'neural-forms-lite' );
				$text  = __( 'Your submission was saved but no payment was made. We will be in touch.', 'neural-forms-lite' );
				$tone  = 'bad';
		}
		$refresh = ( $status === 'pending' && $result === 'success' ) ? '<meta http-equiv="refresh" content="6">' : '';
		$site    = (string) get_bloginfo( 'name' );
		$html    = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">' . $refresh . '<title>' . $e( $title ) . ' · ' . $e( $site ) . '</title>'
			. '<style>body{margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;color:#111827}.box{max-width:520px;margin:60px auto;background:#fff;padding:36px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}.mark{width:56px;height:56px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:bold}.ok .mark{background:#d1fae5;color:#065f46}.wait .mark{background:#fef3c7;color:#92400e}.bad .mark{background:#fee2e2;color:#991b1b}h1{font-size:22px;margin:0 0 10px}p{color:#4b5563;line-height:1.55;margin:0 0 18px}.meta{font-size:13px;color:#6b7280;margin-bottom:22px}a.btn{display:inline-block;padding:11px 20px;border-radius:8px;background:#1e1b4b;color:#fff;text-decoration:none;font-weight:600}</style></head>'
			. '<body><div class="box ' . $e( $tone ) . '"><div class="mark">' . ( $tone === 'ok' ? '&#10003;' : ( $tone === 'wait' ? '&#8230;' : '&#10005;' ) ) . '</div><h1>' . $e( $title ) . '</h1><p>' . $e( $text ) . '</p>'
			. '<div class="meta">' . $e( sprintf( __( 'Reference #%1$d · %2$s', 'neural-forms-lite' ), (int) $fresh['entry_id'], (string) ( $fresh['meta']['description'] ?? '' ) ) ) . '</div>'
			. '<a class="btn" href="' . $e( $back ) . '">' . $e( __( 'Back to the site', 'neural-forms-lite' ) ) . '</a></div></body></html>';
		return array( 'html' => $html, 'status' => $status );
	}
}
