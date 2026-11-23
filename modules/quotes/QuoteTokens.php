<?php
/**
 * Public access tokens for quote view / PDF / pay links.
 *
 * The token is DERIVED, not stored: HMAC-SHA256 over
 * "{quote_id}:{revision}:{token_seed}" keyed with the site's auth salt.
 * The database alone (without wp-config.php) cannot produce a valid
 * link, the plugin can rebuild the same link any time (resend, copy),
 * and a NULL seed revokes every link to that revision. Tokens are
 * 43 chars of base64url (256 bits). Verification is constant-time and
 * also checks the quote's status and validity date (CQ9, CQ10).
 */

namespace NeuralForms\Quotes;

final class QuoteTokens {

	/** 32 random bytes as hex — stored on the quote row. */
	public static function new_seed(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return hash( 'sha256', uniqid( (string) mt_rand(), true ) . microtime( true ) );
		}
	}

	public static function derive( int $quote_id, int $revision, string $seed, ?string $key = null ): string {
		if ( $seed === '' ) {
			return '';
		}
		$key = $key ?? self::key();
		$raw = hash_hmac( 'sha256', $quote_id . ':' . $revision . ':' . $seed, $key, true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Pure check of a presented token against a decoded quote row.
	 * Returns '' when valid, otherwise a reason code:
	 *   revoked | invalid | not_issued | expired | superseded
	 */
	public static function check( array $quote, string $token, ?int $now = null, ?string $key = null ): string {
		$seed = (string) ( $quote['token_seed'] ?? '' );
		if ( $seed === '' ) {
			return 'revoked';
		}
		$token = trim( $token );
		if ( $token === '' || strlen( $token ) > 128 ) {
			return 'invalid';
		}
		$expected = self::derive( (int) ( $quote['id'] ?? 0 ), (int) ( $quote['revision'] ?? 1 ), $seed, $key );
		if ( $expected === '' || ! hash_equals( $expected, $token ) ) {
			return 'invalid';
		}
		$status = (string) ( $quote['status'] ?? 'draft' );
		if ( $status === 'superseded' ) {
			return 'superseded';
		}
		if ( $status === 'expired' ) {
			return 'expired';
		}
		if ( $status !== 'issued' ) {
			return 'not_issued';
		}
		$now   = $now ?? time();
		$until = isset( $quote['valid_until'] ) && is_string( $quote['valid_until'] ) && $quote['valid_until'] !== '' ? strtotime( $quote['valid_until'] . ' UTC' ) : false;
		if ( $until !== false && $until < $now ) {
			return 'expired';
		}
		return '';
	}

	private static function key(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'auth' );
		}
		return defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'neural-forms-quotes';
	}
}
