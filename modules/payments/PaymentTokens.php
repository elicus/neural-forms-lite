<?php
/**
 * Return-page tokens: derived like quote tokens (HMAC of id + seed with
 * the auth salt). The token only grants READ access to the stored state
 * of one attempt; it never marks anything paid (PAY3).
 */

namespace NeuralForms\Payments;

final class PaymentTokens {

	public static function new_seed(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return hash( 'sha256', uniqid( (string) mt_rand(), true ) . microtime( true ) );
		}
	}

	public static function derive( int $payment_id, string $seed, ?string $key = null ): string {
		if ( $seed === '' ) {
			return '';
		}
		$key = $key ?? ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'neural-forms-payments' );
		$raw = hash_hmac( 'sha256', 'payment:' . $payment_id . ':' . $seed, $key, true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function check( array $payment, string $token, ?string $key = null ): bool {
		$seed = (string) ( $payment['token_seed'] ?? '' );
		$token = trim( $token );
		if ( $seed === '' || $token === '' || strlen( $token ) > 128 ) {
			return false;
		}
		$expected = self::derive( (int) ( $payment['id'] ?? 0 ), $seed, $key );
		return $expected !== '' && hash_equals( $expected, $token );
	}
}
