<?php
/**
 * Quote branding / numbering settings (1.2.0 part B).
 *
 * Stored in its own option (`neural_forms_quote_settings`) rather than
 * inside `neural_forms_settings` so the generic settings endpoint and
 * its write-only key handling stay untouched. Read through
 * QuotesEndpoint (`GET|PUT /quotes/settings`), gated by the `quotes`
 * capability for writes.
 */

namespace NeuralForms\Quotes;

final class QuoteSettings {

	const OPTION = 'neural_forms_quote_settings';

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'business_name' => '',
			'address'       => '',
			'email'         => '',
			'phone'         => '',
			'website'       => '',
			'tax_id'        => '',
			'logo_url'      => '',
			'prefix'        => 'Q',
			'validity_days' => 30,
			'intro'         => '',
			'terms'         => '',
			'footer'        => '',
			'accent_color'  => '#1e1b4b',
			'invoice_title' => '',
			'invoice_note'  => '',
		);
	}

	/** Stored values merged over the defaults. */
	public static function get(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize( is_array( $stored ) ? $stored : array(), true );
	}

	/**
	 * Sanitise a partial or full settings array. Unknown keys are dropped;
	 * missing keys fall back to the defaults (or, with $merge_stored, to
	 * the stored value) so a PUT with a subset never blanks the rest.
	 */
	public static function sanitize( array $raw, bool $merge_stored = false ): array {
		$base = self::defaults();
		if ( $merge_stored && function_exists( 'get_option' ) ) {
			$stored = get_option( self::OPTION, array() );
			if ( is_array( $stored ) ) {
				$base = array_merge( $base, array_intersect_key( $stored, $base ) );
			}
		}
		$out = $base;
		foreach ( array( 'business_name', 'email', 'phone', 'website', 'tax_id', 'prefix', 'invoice_title' ) as $k ) {
			if ( array_key_exists( $k, $raw ) && is_scalar( $raw[ $k ] ) ) {
				$out[ $k ] = self::text( (string) $raw[ $k ], 190 );
			}
		}
		foreach ( array( 'address', 'intro', 'terms', 'footer', 'invoice_note' ) as $k ) {
			if ( array_key_exists( $k, $raw ) && is_scalar( $raw[ $k ] ) ) {
				$out[ $k ] = self::multiline( (string) $raw[ $k ], 4000 );
			}
		}
		if ( array_key_exists( 'logo_url', $raw ) && is_scalar( $raw['logo_url'] ) ) {
			$url             = trim( (string) $raw['logo_url'] );
			$out['logo_url'] = $url === '' ? '' : ( function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : filter_var( $url, FILTER_SANITIZE_URL ) );
		}
		if ( array_key_exists( 'validity_days', $raw ) && is_numeric( $raw['validity_days'] ) ) {
			$out['validity_days'] = max( 1, min( 365, (int) $raw['validity_days'] ) );
		}
		if ( array_key_exists( 'accent_color', $raw ) && is_scalar( $raw['accent_color'] ) ) {
			$c = trim( (string) $raw['accent_color'] );
			$out['accent_color'] = preg_match( '/^#[0-9a-fA-F]{6}$/', $c ) ? strtolower( $c ) : $base['accent_color'];
		}
		// Prefix: letters, digits and dashes only; "Q" when empty.
		$prefix        = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $out['prefix'] ) );
		$out['prefix'] = $prefix === '' ? 'Q' : substr( $prefix, 0, 12 );
		if ( $out['email'] !== '' && function_exists( 'is_email' ) && ! is_email( $out['email'] ) ) {
			$out['email'] = '';
		}
		return $out;
	}

	public static function update( array $raw ): array {
		$clean = self::sanitize( $raw, true );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/** Shape returned to the admin UI. */
	public static function for_rest(): array {
		$s                = self::get();
		$s['is_complete'] = $s['business_name'] !== '';
		return $s;
	}

	private static function text( string $s, int $max ): string {
		$s = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : trim( strip_tags( $s ) );
		return mb_substr( $s, 0, $max );
	}

	private static function multiline( string $s, int $max ): string {
		$s = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : trim( strip_tags( $s ) );
		return mb_substr( $s, 0, $max );
	}
}
