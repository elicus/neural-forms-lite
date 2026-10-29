<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Storage\EntryActivity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the built-in actions.
 */
abstract class AbstractAction implements WorkflowAction {

	public function validate_settings( array $settings ) {
		foreach ( $this->settings_schema() as $field ) {
			if ( ! empty( $field['required'] ) ) {
				$v = $settings[ $field['key'] ] ?? null;
				if ( null === $v || $v === '' || ( is_array( $v ) && ! $v ) ) {
					return new WP_Error( 'invalid_workflow', sprintf( __( '%s is required.', 'neural-forms-lite' ), $field['label'] ) );
				}
			}
		}
		return true;
	}

	protected function error( string $message ): WP_Error {
		return new WP_Error( 'invalid_workflow', $message );
	}

	/** Timeline row attributed to the workflow (by = 0 → system). */
	protected function activity( array $ctx, string $event_type, string $message, array $payload = array(), string $status = 'info' ): void {
		$entry_id = (int) ( $ctx['entry']['id'] ?? 0 );
		if ( $entry_id <= 0 || ! empty( $ctx['dry_run'] ) ) {
			return;
		}
		$payload['by']          = 0;
		$payload['workflow_id'] = (int) ( $ctx['workflow_id'] ?? 0 );
		$payload['run_id']      = (int) ( $ctx['run_id'] ?? 0 );
		$payload['workflow']    = (string) ( $ctx['workflow_name'] ?? '' );
		EntryActivity::record( $entry_id, $event_type, $message, $payload, $status );
	}

	/**
	 * Entry data plus the payment tokens every template may use:
	 * {payment_status}, {payment_amount}, {payment_remaining},
	 * {payment_kind}, {payment_currency}, {payment_provider}. Values come
	 * from the confirmed payment on a `payment_confirmed` run, otherwise
	 * from the entry's stored aggregate. Never from visitor input.
	 */
	protected function token_data( array $ctx ): array {
		$data = is_array( $ctx['entry']['data'] ?? null ) ? $ctx['entry']['data'] : array();
		if ( ! class_exists( '\\NeuralForms\\Payments\\PaymentStatus' ) ) {
			return $data;
		}
		$entry    = is_array( $ctx['entry'] ?? null ) ? $ctx['entry'] : array();
		$currency = (string) ( $entry['currency'] ?? 'USD' );
		$status   = (string) ( $entry['payment_status'] ?? 'none' );
		$paid     = 0;
		$remain   = (int) ( $entry['amount_total_minor'] ?? 0 );
		$kind     = '';
		$provider = '';
		$tc       = is_array( $ctx['trigger_context']['payment'] ?? null ) ? $ctx['trigger_context']['payment'] : null;
		if ( $tc ) {
			$currency = (string) ( $tc['currency'] ?? $currency );
			$paid     = (int) ( $tc['amount_paid_minor'] ?? 0 );
			$remain   = (int) ( $tc['remaining_minor'] ?? $remain );
			$kind     = (string) ( $tc['kind'] ?? '' );
			$provider = (string) ( $tc['provider'] ?? '' );
			$status   = (string) ( $tc['status'] ?: $status );
		} elseif ( $status !== 'none' && (int) ( $entry['id'] ?? 0 ) > 0 && class_exists( '\\NeuralForms\\Payments\\PaymentService' ) && empty( $ctx['dry_run'] ) ) {
			$sum    = \NeuralForms\Payments\PaymentService::entry_summary( (int) $entry['id'] );
			$paid   = (int) ( $sum['amounts']['paid'] ?? 0 );
			$remain = (int) ( $sum['amounts']['remaining'] ?? $remain );
			$status = (string) ( $sum['status'] ?? $status );
		}
		$fmt = static function ( int $minor ) use ( $currency ): string {
			return class_exists( '\\NeuralForms\\Pricing\\Money' ) ? \NeuralForms\Pricing\Money::format( $minor, $currency ) : (string) $minor;
		};
		$data['payment_status']    = \NeuralForms\Payments\PaymentStatus::label( $status );
		$data['payment_amount']    = $fmt( $paid );
		$data['payment_remaining'] = $fmt( $remain );
		$data['payment_kind']      = $kind === 'deposit' ? __( 'deposit', 'neural-forms-lite' ) : ( $kind === 'full' ? __( 'full payment', 'neural-forms-lite' ) : '' );
		$data['payment_currency']  = $currency;
		$data['payment_provider']  = $provider;
		return $data;
	}

	protected function lead_context( array $ctx ): array {
		return array(
			'source'   => 'workflow',
			'lineage'  => (string) ( $ctx['lineage'] ?? '' ),
			'run_id'   => (int) ( $ctx['run_id'] ?? 0 ),
			'workflow' => (int) ( $ctx['workflow_id'] ?? 0 ),
		);
	}
}
