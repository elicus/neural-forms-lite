<?php
/**
 * Quote numbers: `{PREFIX}-{YEAR}-{SEQ}` with a per-year counter that is
 * incremented atomically in the options table (a single UPDATE … SET
 * value = value + 1), so two admins issuing at the same moment never
 * get the same number. Numbers are assigned when a quote is ISSUED, so
 * drafts that are deleted never burn a number.
 */

namespace NeuralForms\Quotes;

final class QuoteNumbering {

	const OPTION_PREFIX = 'neural_forms_quote_seq_';

	/** Pure formatter, fixture-tested. */
	public static function format( string $prefix, int $year, int $seq ): string {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $prefix ) );
		if ( $prefix === '' ) {
			$prefix = 'Q';
		}
		return sprintf( '%s-%d-%04d', $prefix, $year, max( 1, $seq ) );
	}

	/** Allocate the next number for the current UTC year. */
	public static function next( string $prefix, ?int $year = null ): string {
		$year = $year ?? (int) gmdate( 'Y' );
		return self::format( $prefix, $year, self::next_sequence( $year ) );
	}

	/**
	 * Atomic counter. Creates the option row at 0 the first time, then
	 * increments it with one UPDATE and reads the resulting value back.
	 */
	public static function next_sequence( int $year ): int {
		global $wpdb;
		$name = self::OPTION_PREFIX . $year;
		if ( ! $wpdb instanceof \wpdb ) {
			return 1;
		}
		// Ensure the row exists (no-op when it does; autoload off).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", $name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", $name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return max( 1, $value );
	}
}
