<?php
/**
 * Contract every payment provider implements (1.2.0 part B).
 *
 * Lite owns the orchestration (attempts, idempotency, status model,
 * webhook routes, return page, reconciliation); a provider only talks
 * to its gateway. Pro registers Stripe and PayPal through the
 * `neural_forms_payment_providers` filter. Every method returns plain
 * arrays or WP_Error — never throws across the boundary. No provider
 * may ever receive or log card data; hosted checkout only.
 */

namespace NeuralForms\Payments;

interface PaymentProviderInterface {

	/** Stable id used in settings, rows and routes: `stripe`, `paypal`. */
	public function id(): string;

	public function label(): string;

	/** Credentials present for the active mode. */
	public function is_configured(): bool;

	/** True when the active credentials are live (not test / sandbox). */
	public function is_live(): bool;

	/**
	 * Create a hosted checkout for one stored attempt.
	 *
	 * @param array $payment Decoded `neural_forms_payments` row (amount, currency, kind, idempotency_key).
	 * @param array $entry   Decoded entry.
	 * @param array $form    Decoded form.
	 * @param array $urls    { success, cancel, webhook } absolute URLs built by Lite.
	 * @param array $details { description, customer_email, customer_name, line_items[{label, amount_minor, qty}] }.
	 * @return array|\WP_Error { session_id, url, account_id, livemode, provider_payment_id?, meta? }
	 */
	public function create_checkout( array $payment, array $entry, array $form, array $urls, array $details );

	/**
	 * Read the current state of an attempt from the gateway (reconciliation,
	 * return page). Normalised shape:
	 * { state: open|complete|expired|cancelled, paid: bool, processing: bool,
	 *   failed: bool, amount_minor, currency, provider_payment_id, account_id,
	 *   livemode, amount_refunded_minor }
	 *
	 * @return array|\WP_Error
	 */
	public function fetch_checkout( array $payment );

	/**
	 * Called from the return URL for providers that need a server-side
	 * capture after the buyer approved (PayPal). Stripe returns the
	 * fetch_checkout() result unchanged. Must be idempotent.
	 *
	 * @return array|\WP_Error  same shape as fetch_checkout()
	 */
	public function capture( array $payment );

	/**
	 * Expire / cancel an open checkout so a replacement can be created
	 * (PAY9). Best effort; a WP_Error is logged, not fatal.
	 *
	 * @return true|\WP_Error
	 */
	public function expire_checkout( array $payment );

	/**
	 * Verify a webhook request (raw body + signature headers) and return
	 * the normalised event, or WP_Error (→ 400). Shape:
	 * { event_id, type, livemode, account_id, kind: completed|paid|processing|
	 *   failed|expired|cancelled|refunded|ignored, session_id, provider_payment_id,
	 *   amount_minor, currency, amount_refunded_minor, raw_type }
	 *
	 * @return array|\WP_Error
	 */
	public function verify_webhook( \WP_REST_Request $request );

	/** Public status for the admin UI: never secrets. { configured, live, label, account } */
	public function public_status(): array;
}
