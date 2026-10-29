<?php
/**
 * Payment status model (pure, fixture-tested).
 *
 * Attempt statuses (`neural_forms_payments.status`):
 *   pending     checkout created, nothing confirmed
 *   processing  buyer completed checkout with a delayed method (bank debit…)
 *   paid        gateway confirmed the money (webhook or verified fetch)
 *   failed      gateway reported a failed charge
 *   expired     checkout expired / was cancelled by the buyer
 *   replaced    superseded by a newer attempt (PAY9)
 *   refunded / partially_refunded  after a paid attempt
 *
 * Entry aggregate (`neural_forms_entries.payment_status`):
 *   none | pending | processing | failed | cancelled | deposit_paid | paid |
 *   partially_refunded | refunded
 *
 * Rules: a settled attempt never regresses (PAY5); a deposit success is
 * never shown as "paid in full" (PAY2); failed / expired attempts only
 * count when nothing is settled (PAY7).
 */

namespace NeuralForms\Payments;

final class PaymentStatus {

	const ATTEMPT_STATUSES = array( 'pending', 'processing', 'paid', 'failed', 'expired', 'replaced', 'refunded', 'partially_refunded' );
	const ENTRY_STATUSES   = array( 'none', 'pending', 'processing', 'failed', 'cancelled', 'deposit_paid', 'paid', 'partially_refunded', 'refunded' );
	const SETTLED          = array( 'paid', 'refunded', 'partially_refunded' );

	/** Rank used to refuse regressions. Higher never moves to lower. */
	private static function rank( string $status ): int {
		switch ( $status ) {
			case 'paid':
			case 'partially_refunded':
			case 'refunded':
				return 3;
			case 'processing':
				return 2;
			case 'failed':
			case 'expired':
			case 'replaced':
				return 1;
			default:
				return 0;
		}
	}

	/**
	 * Apply a normalised gateway event to an attempt. Returns the new
	 * attempt fields to persist (possibly empty when nothing changes) and
	 * flags: { fields: array, changed: bool, newly_paid: bool, reason: string }.
	 *
	 * $event: { kind, amount_minor, currency, amount_refunded_minor, provider_payment_id, livemode, account_id }
	 */
	public static function apply_event( array $payment, array $event ): array {
		$current = (string) ( $payment['status'] ?? 'pending' );
		$kind    = (string) ( $event['kind'] ?? 'ignored' );
		$fields  = array();
		$reason  = '';

		// Integrity checks (PAY4 / PAY10): mode, account, currency, amount.
		if ( array_key_exists( 'livemode', $event ) && null !== $event['livemode'] && (bool) $event['livemode'] !== (bool) ( $payment['livemode'] ?? false ) ) {
			return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'mode_mismatch' );
		}
		if ( ! empty( $event['account_id'] ) && ! empty( $payment['account_id'] ) && (string) $event['account_id'] !== (string) $payment['account_id'] ) {
			return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'account_mismatch' );
		}
		if ( in_array( $kind, array( 'paid', 'processing', 'completed' ), true ) ) {
			if ( ! empty( $event['currency'] ) && strtoupper( (string) $event['currency'] ) !== strtoupper( (string) ( $payment['currency'] ?? '' ) ) ) {
				return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'currency_mismatch' );
			}
			if ( isset( $event['amount_minor'] ) && null !== $event['amount_minor'] && (int) $event['amount_minor'] !== (int) ( $payment['amount_due_minor'] ?? 0 ) ) {
				return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'amount_mismatch' );
			}
		}

		$next = $current;
		switch ( $kind ) {
			case 'paid':
				$next = 'paid';
				break;
			case 'completed': // checkout finished but money not confirmed yet
			case 'processing':
				$next = 'processing';
				break;
			case 'failed':
				$next = 'failed';
				break;
			case 'expired':
			case 'cancelled':
				$next = 'expired';
				break;
			case 'refunded':
				$refunded = (int) ( $event['amount_refunded_minor'] ?? 0 );
				$paid     = (int) ( $payment['amount_paid_minor'] ?? 0 );
				if ( $paid <= 0 || $refunded <= 0 ) {
					return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'refund_without_payment' );
				}
				$fields['amount_refunded_minor'] = min( $paid, $refunded );
				$next                            = $fields['amount_refunded_minor'] >= $paid ? 'refunded' : 'partially_refunded';
				break;
			default:
				return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'ignored' );
		}

		// Never regress a settled or more advanced attempt (PAY5), except
		// paid → refund states which share the top rank.
		if ( self::rank( $next ) < self::rank( $current ) ) {
			return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'regression_blocked' );
		}
		if ( in_array( $current, self::SETTLED, true ) && $next === 'paid' ) {
			return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'already_paid' );
		}
		if ( $next === $current && empty( $fields ) ) {
			return array( 'fields' => array(), 'changed' => false, 'newly_paid' => false, 'reason' => 'no_change' );
		}

		$fields['status'] = $next;
		if ( ! empty( $event['provider_payment_id'] ) && empty( $payment['provider_payment_id'] ) ) {
			$fields['provider_payment_id'] = (string) $event['provider_payment_id'];
		}
		if ( ! empty( $event['account_id'] ) && empty( $payment['account_id'] ) ) {
			$fields['account_id'] = (string) $event['account_id'];
		}
		$newly_paid = false;
		if ( $next === 'paid' ) {
			$fields['amount_paid_minor'] = isset( $event['amount_minor'] ) && null !== $event['amount_minor'] ? (int) $event['amount_minor'] : (int) ( $payment['amount_due_minor'] ?? 0 );
			$fields['paid_at']           = gmdate( 'Y-m-d H:i:s', isset( $event['paid_at'] ) && $event['paid_at'] ? (int) $event['paid_at'] : time() );
			$newly_paid                  = true;
		}
		return array( 'fields' => $fields, 'changed' => true, 'newly_paid' => $newly_paid, 'reason' => $next );
	}

	/**
	 * Entry aggregate from its attempts (newest first or any order).
	 *
	 * @param array $attempts  Decoded payment rows.
	 * @param int   $due_minor Amount the entry asks for now (deposit or full).
	 * @param int   $total_minor Full price.
	 */
	public static function aggregate( array $attempts, int $due_minor, int $total_minor ): array {
		$paid     = 0;
		$refunded = 0;
		$kinds    = array();
		$settled  = array();
		$latest   = null;
		usort( $attempts, static function ( $a, $b ) { return (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ); } );
		foreach ( $attempts as $a ) {
			if ( in_array( $a['status'] ?? '', self::SETTLED, true ) ) {
				$paid     += (int) ( $a['amount_paid_minor'] ?? 0 );
				$refunded += (int) ( $a['amount_refunded_minor'] ?? 0 );
				$kinds[]   = (string) ( $a['kind'] ?? 'full' );
				$settled[] = $a;
			}
			if ( null === $latest && ( $a['status'] ?? '' ) !== 'replaced' ) {
				$latest = $a;
			}
		}
		$net       = $paid - $refunded;
		$remaining = max( 0, $total_minor - $net );
		if ( $settled ) {
			if ( $paid > 0 && $refunded >= $paid ) {
				$status = 'refunded';
			} elseif ( $refunded > 0 ) {
				$status = 'partially_refunded';
			} elseif ( $net >= $total_minor || ( ! in_array( 'deposit', $kinds, true ) && $net >= $due_minor ) ) {
				$status = 'paid';
			} else {
				$status = 'deposit_paid';
			}
		} elseif ( $due_minor <= 0 && $total_minor <= 0 ) {
			$status = 'none';
		} elseif ( null === $latest ) {
			$status = $due_minor > 0 ? 'pending' : 'none';
		} else {
			switch ( (string) $latest['status'] ) {
				case 'processing':
					$status = 'processing';
					break;
				case 'failed':
					$status = 'failed';
					break;
				case 'expired':
					$status = 'cancelled';
					break;
				default:
					$status = 'pending';
			}
		}
		return array(
			'status'                => $status,
			'amount_paid_minor'     => $paid,
			'amount_refunded_minor' => $refunded,
			'amount_net_minor'      => $net,
			'remaining_minor'       => $remaining,
			'settled_count'         => count( $settled ),
			'needs_reconciliation'  => count( $settled ) > 1 && ( $net > $total_minor || self::duplicate_intent( $settled ) ),
		);
	}

	/** Two settled attempts for the same intent (same quote + kind) = the visitor paid twice (PAY12). */
	private static function duplicate_intent( array $settled ): bool {
		$seen = array();
		foreach ( $settled as $a ) {
			$key = (int) ( $a['quote_id'] ?? 0 ) . ':' . (string) ( $a['kind'] ?? 'full' );
			if ( isset( $seen[ $key ] ) ) {
				return true;
			}
			$seen[ $key ] = true;
		}
		return false;
	}

	public static function label( string $status ): string {
		$map = array(
			'none'               => __( 'No payment required', 'neural-forms-lite' ),
			'pending'            => __( 'Pending payment', 'neural-forms-lite' ),
			'processing'         => __( 'Processing', 'neural-forms-lite' ),
			'failed'             => __( 'Failed', 'neural-forms-lite' ),
			'cancelled'          => __( 'Cancelled', 'neural-forms-lite' ),
			'expired'            => __( 'Expired', 'neural-forms-lite' ),
			'replaced'           => __( 'Replaced', 'neural-forms-lite' ),
			'deposit_paid'       => __( 'Deposit paid', 'neural-forms-lite' ),
			'paid'               => __( 'Paid in full', 'neural-forms-lite' ),
			'partially_refunded' => __( 'Partially refunded', 'neural-forms-lite' ),
			'refunded'           => __( 'Refunded', 'neural-forms-lite' ),
		);
		return $map[ $status ] ?? $status;
	}
}
