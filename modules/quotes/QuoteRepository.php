<?php
/**
 * Storage for `neural_forms_quotes` (1.2.0 part B).
 *
 * Rows are decoded to arrays; `snapshot` is JSON in the table and an
 * array in PHP. `token_seed` is returned by get()/list() because the
 * service needs it to derive links — the REST layer strips it before
 * responding (see QuotesEndpoint::expose()).
 */

namespace NeuralForms\Quotes;

final class QuoteRepository {

	const STATUSES = array( 'draft', 'issued', 'expired', 'superseded' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_quotes';
	}

	/** @return int|false */
	public static function create( array $fields ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array(
			'entry_id'           => (int) ( $fields['entry_id'] ?? 0 ),
			'form_id'            => (int) ( $fields['form_id'] ?? 0 ),
			'number'             => isset( $fields['number'] ) && $fields['number'] !== '' ? (string) $fields['number'] : null,
			'revision'           => max( 1, (int) ( $fields['revision'] ?? 1 ) ),
			'supersedes_id'      => ! empty( $fields['supersedes_id'] ) ? (int) $fields['supersedes_id'] : null,
			'status'             => in_array( $fields['status'] ?? 'draft', self::STATUSES, true ) ? $fields['status'] : 'draft',
			'currency'           => substr( (string) ( $fields['currency'] ?? 'USD' ), 0, 3 ),
			'amount_total_minor' => (int) ( $fields['amount_total_minor'] ?? 0 ),
			'amount_due_minor'   => (int) ( $fields['amount_due_minor'] ?? 0 ),
			'snapshot'           => wp_json_encode( is_array( $fields['snapshot'] ?? null ) ? $fields['snapshot'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'notes'              => isset( $fields['notes'] ) ? (string) $fields['notes'] : null,
			'valid_until'        => $fields['valid_until'] ?? null,
			'token_seed'         => $fields['token_seed'] ?? null,
			'issued_by'          => (int) ( $fields['issued_by'] ?? 0 ),
			'issued_at'          => $fields['issued_at'] ?? null,
			'sent_at'            => null,
			'created_by'         => (int) ( $fields['created_by'] ?? 0 ),
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/** Newest first. */
	public static function list_for_entry( int $entry_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE entry_id = %d ORDER BY id DESC', self::table(), $entry_id ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Guarded update. Only the listed columns can change; `snapshot` is
	 * re-encoded. Returns true when a row was touched.
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'status', 'number', 'snapshot', 'notes', 'valid_until', 'token_seed', 'issued_by', 'issued_at', 'sent_at', 'amount_total_minor', 'amount_due_minor', 'currency' );
		$data    = array();
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$v = $fields[ $k ];
			if ( $k === 'snapshot' ) {
				$v = wp_json_encode( is_array( $v ) ? $v : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			} elseif ( $k === 'status' && ! in_array( $v, self::STATUSES, true ) ) {
				continue;
			}
			$data[ $k ] = $v;
		}
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$r = $wpdb->update( self::table(), $data, array( 'id' => $id ) );
		return false !== $r && $r > 0;
	}

	/**
	 * Single guarded status transition (WHERE status = $from) so two
	 * concurrent requests cannot both issue the same draft.
	 */
	public static function transition( int $id, string $from, string $to, array $extra = array() ): bool {
		global $wpdb;
		if ( ! in_array( $to, self::STATUSES, true ) ) {
			return false;
		}
		$set  = array( 'status = %s', 'updated_at = %s' );
		$args = array( $to, current_time( 'mysql', true ) );
		foreach ( $extra as $k => $v ) {
			if ( ! in_array( $k, array( 'number', 'valid_until', 'token_seed', 'issued_by', 'issued_at', 'sent_at' ), true ) ) {
				continue;
			}
			if ( null === $v ) {
				$set[] = "$k = NULL";
			} else {
				$set[]  = "$k = %s";
				$args[] = (string) $v;
			}
		}
		$args[] = $id;
		$args[] = $from;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $set ) . ' WHERE id = %d AND status = %s', $args ) );
		return false !== $r && $r > 0;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function delete_for_entry( int $entry_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$r = $wpdb->delete( self::table(), array( 'entry_id' => $entry_id ), array( '%d' ) );
		return false === $r ? 0 : (int) $r;
	}

	/** Ids of issued quotes whose validity date has passed. */
	public static function issued_past_validity( string $now_utc, int $limit = 500 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND valid_until IS NOT NULL AND valid_until < %s ORDER BY id ASC LIMIT %d', self::table(), 'issued', $now_utc, $limit ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	public static function decode( array $row ): array {
		$snap            = isset( $row['snapshot'] ) && is_string( $row['snapshot'] ) ? json_decode( $row['snapshot'], true ) : null;
		$row['snapshot'] = is_array( $snap ) ? $snap : array();
		foreach ( array( 'id', 'entry_id', 'form_id', 'revision', 'issued_by', 'created_by', 'amount_total_minor', 'amount_due_minor' ) as $k ) {
			$row[ $k ] = isset( $row[ $k ] ) ? (int) $row[ $k ] : 0;
		}
		$row['supersedes_id'] = isset( $row['supersedes_id'] ) && null !== $row['supersedes_id'] ? (int) $row['supersedes_id'] : null;
		foreach ( array( 'number', 'notes', 'valid_until', 'token_seed', 'issued_at', 'sent_at' ) as $k ) {
			$row[ $k ] = isset( $row[ $k ] ) && $row[ $k ] !== '' ? (string) $row[ $k ] : null;
		}
		return $row;
	}
}
