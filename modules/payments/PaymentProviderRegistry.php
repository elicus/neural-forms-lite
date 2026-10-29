<?php
/**
 * Registry of payment providers. Lite ships none; Pro adds Stripe and
 * PayPal via `neural_forms_payment_providers` (array id => instance).
 */

namespace NeuralForms\Payments;

final class PaymentProviderRegistry {

	/** @var array<string,PaymentProviderInterface>|null */
	private static $cache = null;

	/** @return array<string,PaymentProviderInterface> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$raw = apply_filters( 'neural_forms_payment_providers', array() );
			$out = array();
			foreach ( is_array( $raw ) ? $raw : array() as $p ) {
				if ( $p instanceof PaymentProviderInterface ) {
					$id = preg_replace( '/[^a-z0-9_]/', '', strtolower( $p->id() ) );
					if ( $id !== '' ) {
						$out[ $id ] = $p;
					}
				}
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	public static function get( string $id ): ?PaymentProviderInterface {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** @return array<string,PaymentProviderInterface> */
	public static function configured(): array {
		return array_filter( self::all(), static function ( $p ) { return $p->is_configured(); } );
	}

	/** Shape for the builder / inbox: never secrets. */
	public static function for_rest(): array {
		$out = array();
		foreach ( self::all() as $id => $p ) {
			$out[] = array_merge( array( 'id' => $id, 'label' => $p->label(), 'configured' => $p->is_configured(), 'live' => $p->is_live() ), $p->public_status() );
		}
		return $out;
	}

	public static function reset(): void {
		self::$cache = null;
	}
}
