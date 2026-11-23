<?php
/**
 * Wires payments into WordPress: daily reconciliation cron and the
 * entry-delete cascade. Raw HTML responses reuse QuotesBoot::serve_raw().
 */

namespace NeuralForms\Payments;

final class PaymentsBoot {

	const RECONCILE_HOOK = 'neural_forms_payment_reconcile';

	public static function boot(): void {
		add_action( self::RECONCILE_HOOK, array( PaymentService::class, 'reconcile_due' ) );
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RECONCILE_HOOK );
		}
	}
}
