<?php
namespace NeuralForms\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Human display of a stored product value (1.2.0, part B):
 *   options  → "SEO, Copywriting" (+ " × 2" when quantity is on)
 *   fixed    → "Included" / "Included × 3"
 *   input    → "25.00"
 * Used by emails, CSV export, the AI payload and the inbox (JS mirror in
 * entries/helpers.js formatProductValue).
 */
final class ProductFormatter {

	/**
	 * @param mixed $raw   Stored value { choice, qty, amount }.
	 * @param array $field Product field schema.
	 */
	public static function display( $raw, array $field ): string {
		$p   = PriceEngine::product_pricing( $field );
		$raw = is_array( $raw ) ? $raw : array();
		$qty = $p['quantity']['enabled'] && isset( $raw['qty'] ) && is_numeric( $raw['qty'] ) ? (int) $raw['qty'] : 1;
		$suffix = $p['quantity']['enabled'] ? ' × ' . $qty : '';

		if ( $p['mode'] === 'input' ) {
			$amount = isset( $raw['amount'] ) && is_numeric( $raw['amount'] ) ? (string) $raw['amount'] : '';
			return $amount;
		}
		if ( $p['mode'] === 'fixed' ) {
			return __( 'Included', 'neural-forms-lite' ) . $suffix;
		}
		$labels = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
			$opt = (array) $opt;
			$v   = (string) ( $opt['value'] ?? '' );
			if ( $v === '' ) {
				$v = (string) ( $opt['label'] ?? '' );
			}
			if ( $v !== '' ) {
				$labels[ $v ] = (string) ( $opt['label'] ?? $v );
			}
		}
		$choices = $raw['choice'] ?? array();
		$choices = is_array( $choices ) ? $choices : array( $choices );
		$out     = array();
		foreach ( $choices as $c ) {
			$c = (string) $c;
			if ( $c === '' ) {
				continue;
			}
			$out[] = $labels[ $c ] ?? $c;
		}
		return $out ? implode( ', ', $out ) . $suffix : '';
	}
}
