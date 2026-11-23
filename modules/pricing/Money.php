<?php
namespace NeuralForms\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Money helpers (1.2.0, part B). Every amount is an integer in the
 * currency's minor unit; decimals only exist at the edges (parsing an
 * admin-typed price, formatting for display). No floats touch totals.
 */
final class Money {

	/** ISO 4217 exponents that differ from 2. Everything else is 2. */
	const EXPONENTS = array(
		'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'MGA' => 0,
		'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
		'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
	);

	/** Currencies offered in the Pricing settings dropdown (others via free ISO code). */
	const COMMON = array( 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'NZD', 'SGD', 'AED', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'JPY', 'ZAR', 'BRL', 'MXN' );

	const SYMBOLS = array(
		'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'SGD' => 'S$',
		'AED' => 'AED ', 'CHF' => 'CHF ', 'SEK' => 'kr ', 'NOK' => 'kr ', 'DKK' => 'kr ', 'PLN' => 'zł ', 'JPY' => '¥', 'ZAR' => 'R', 'BRL' => 'R$', 'MXN' => 'MX$',
	);

	public static function normalize_currency( $code ): string {
		$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
		return preg_match( '/^[A-Z]{3}$/', $c ) ? $c : 'USD';
	}

	public static function exponent( string $currency ): int {
		return self::EXPONENTS[ self::normalize_currency( $currency ) ] ?? 2;
	}

	/**
	 * Decimal string (admin input, "1,250.50" / "1250" / "12.5") → minor units.
	 * Rounds half-up to the currency's exponent. Returns null when unparseable.
	 *
	 * @param mixed $value
	 */
	public static function to_minor( $value, string $currency ) {
		if ( is_int( $value ) ) {
			return $value * self::pow10( self::exponent( $currency ) );
		}
		$s = trim( (string) $value );
		if ( $s === '' ) {
			return null;
		}
		$s = str_replace( array( ' ', ',' ), '', $s );
		if ( ! preg_match( '/^(-)?(\d+)(?:\.(\d+))?$/', $s, $m ) ) {
			return null;
		}
		$exp   = self::exponent( $currency );
		$neg   = $m[1] === '-';
		$whole = $m[2];
		$frac  = isset( $m[3] ) ? $m[3] : '';
		$frac_padded = substr( str_pad( $frac, $exp + 1, '0' ), 0, $exp + 1 ); // one extra digit for rounding
		$minor = (int) $whole * self::pow10( $exp ) + (int) substr( $frac_padded, 0, $exp );
		if ( (int) $frac_padded[ $exp ] >= 5 ) {
			$minor += 1;
		}
		return $neg ? -$minor : $minor;
	}

	/** Minor units → canonical decimal string ("1250.50"), no grouping, no symbol. */
	public static function to_decimal( int $minor, string $currency ): string {
		$exp = self::exponent( $currency );
		if ( $exp === 0 ) {
			return (string) $minor;
		}
		$neg   = $minor < 0;
		$abs   = abs( $minor );
		$whole = intdiv( $abs, self::pow10( $exp ) );
		$frac  = str_pad( (string) ( $abs % self::pow10( $exp ) ), $exp, '0', STR_PAD_LEFT );
		return ( $neg ? '-' : '' ) . $whole . '.' . $frac;
	}

	/** Display string with symbol / code and thousands grouping. */
	public static function format( int $minor, string $currency ): string {
		$currency = self::normalize_currency( $currency );
		$exp      = self::exponent( $currency );
		$dec      = self::to_decimal( $minor, $currency );
		$neg      = strpos( $dec, '-' ) === 0;
		$dec      = ltrim( $dec, '-' );
		$parts    = explode( '.', $dec );
		$whole    = preg_replace( '/\B(?=(\d{3})+(?!\d))/', ',', $parts[0] );
		$out      = $whole . ( $exp > 0 ? '.' . $parts[1] : '' );
		$sym      = self::SYMBOLS[ $currency ] ?? ( $currency . ' ' );
		return ( $neg ? '-' : '' ) . $sym . $out;
	}

	/** Round half-up of (minor × percent / 100) with percent given as a decimal string or number. */
	public static function percent_of( int $minor, $percent ): int {
		$bp = self::percent_to_basis_points( $percent ); // 10.5 % → 1050
		$num = $minor * $bp;                          // minor × bp
		$den = 10000;
		$q   = intdiv( abs( $num ), $den );
		$r   = abs( $num ) % $den;
		if ( $r * 2 >= $den ) {
			$q++;
		}
		return $num < 0 ? -$q : $q;
	}

	/** "12.5" → 1250 (basis points, i.e. hundredths of a percent). */
	public static function percent_to_basis_points( $percent ): int {
		$s = str_replace( array( ' ', ',' ), '', trim( (string) $percent ) );
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $s, $m ) ) {
			return 0;
		}
		$frac = substr( str_pad( $m[2] ?? '', 3, '0' ), 0, 3 );
		$bp   = (int) $m[1] * 100 + (int) substr( $frac, 0, 2 );
		if ( (int) $frac[2] >= 5 ) {
			$bp++;
		}
		return $bp;
	}

	private static function pow10( int $n ): int {
		return (int) ( 10 ** $n );
	}
}
