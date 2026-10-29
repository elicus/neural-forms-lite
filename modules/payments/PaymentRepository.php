<?php
/**
 * Storage for `neural_forms_payments` and `neural_forms_payment_events`.
 */

namespace NeuralForms\Payments;

final class PaymentRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_payments';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_payment_events';
	}

	/** @return int|false  false also when the idempotency key already exists. */
	public static function create( array $f ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array(
			'entry_id'              => (int) ( $f['entry_id'] ?? 0 ),
			'form_id'               => (int) ( $f['form_id'] ?? 0 ),
			'quote_id'              => ! empty( $f['quote_id'] ) ? (int) $f['quote_id'] : null,
			'provider'              => substr( (string) ( $f['provider'] ?? '' ), 0, 20 ),
			'livemode'              => ! empty( $f['livemode'] ) ? 1 : 0,
			'account_id'            => isset( $f['account_id'] ) && $f['account_id'] !== '' ? substr( (string) $f['account_id'], 0, 120 ) : null,
			'session_id'            => isset( $f['session_id'] ) && $f['session_id'] !== '' ? substr( (string) $f['session_id'], 0, 190 ) : null,
			'provider_payment_id'   => isset( $f['provider_payment_id'] ) && $f['provider_payment_id'] !== '' ? substr( (string) $f['provider_payment_id'], 0, 190 ) : null,
			'kind'                  => ( $f['kind'] ?? 'full' ) === 'deposit' ? 'deposit' : 'full',
			'amount_due_minor'      => (int) ( $f['amount_due_minor'] ?? 0 ),
			'amount_paid_minor'     => (int) ( $f['amount_paid_minor'] ?? 0 ),
			'amount_refunded_minor' => 0,
			'currency'              => strtoupper( substr( (string) ( $f['currency'] ?? 'USD' ), 0, 3 ) ),
			'status'                => isset( $f['status'] ) && in_array( $f['status'], PaymentStatus::ATTEMPT_STATUSES, true ) ? $f['status'] : 'pending',
			'idempotency_key'       => substr( (string) ( $f['idempotency_key'] ?? '' ), 0, 64 ),
			'attempt'               => max( 1, (int) ( $f['attempt'] ?? 1 ) ),
			'token_seed'            => $f['token_seed'] ?? PaymentTokens::new_seed(),
			'checkout_url'          => isset( $f['checkout_url'] ) ? (string) $f['checkout_url'] : null,
			'meta'                  => wp_json_encode( is_array( $f['meta'] ?? null ) ? $f['meta'] : array() ),
			'paid_at'               => null,
			'created_at'            => $now,
			'updated_at'            => $now,
		);
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( self::table(), $row );
		$wpdb->suppress_errors( false );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function find_by_session( string $provider, string $session_id ): ?array {
		global $wpdb;
		if ( $session_id === '' ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE provider = %s AND session_id = %s ORDER BY id DESC LIMIT 1', self::table(), $provider, $session_id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function find_by_provider_payment_id( string $provider, string $pid ): ?array {
		global $wpdb;
		if ( $pid === '' ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE provider = %s AND provider_payment_id = %s ORDER BY id DESC LIMIT 1', self::table(), $provider, $pid ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/** Newest first. */
	public static function list_for_entry( int $entry_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE entry_id = %d ORDER BY id DESC', self::table(), $entry_id ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'status', 'session_id', 'provider_payment_id', 'account_id', 'livemode', 'amount_paid_minor', 'amount_refunded_minor', 'checkout_url', 'meta', 'paid_at' );
		$data    = array();
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$v = $fields[ $k ];
			if ( $k === 'meta' ) {
				$v = wp_json_encode( is_array( $v ) ? $v : array() );
			} elseif ( $k === 'status' && ! in_array( $v, PaymentStatus::ATTEMPT_STATUSES, true ) ) {
				continue;
			} elseif ( $k === 'livemode' ) {
				$v = $v ? 1 : 0;
			}
			$data[ $k ] = $v;
		}
		if ( empty( $data ) ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$r = $wpdb->update( self::table(), $data, array( 'id' => $id ) );
		return false !== $r;
	}

	/**
	 * Guarded status change: applies $fields only while the row still has
	 * $from_status, so two workers (webhook + reconcile, two browser
	 * refreshes) cannot both move the same attempt to `paid` and fire the
	 * confirmed hook twice. Returns true when this call made the change.
	 */
	public static function update_if_status( int $id, string $from_status, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'status', 'provider_payment_id', 'account_id', 'amount_paid_minor', 'amount_refunded_minor', 'paid_at' );
		$set     = array( 'updated_at = %s' );
		$values  = array( current_time( 'mysql', true ) );
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$v = $fields[ $k ];
			if ( null === $v ) {
				$set[] = "$k = NULL";
			} else {
				$set[]    = "$k = " . ( in_array( $k, array( 'amount_paid_minor', 'amount_refunded_minor' ), true ) ? '%d' : '%s' );
				$values[] = $v;
			}
		}
		$values[] = $id;
		$values[] = $from_status;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $set ) . ' WHERE id = %d AND status = %s', $values ) );
		return false !== $r && $r > 0;
	}

	/** Mark every open attempt of an entry (except $keep_id) as replaced. */
	public static function replace_open( int $entry_id, int $keep_id = 0 ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$r = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE entry_id = %d AND status = %s AND id <> %d', self::table(), 'replaced', current_time( 'mysql', true ), $entry_id, 'pending', $keep_id ) );
		return false === $r ? 0 : (int) $r;
	}

	/** Open attempts older than $seconds (for reconciliation). */
	public static function open_older_than( int $seconds, int $limit = 200 ): array {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status IN ('pending','processing') AND session_id IS NOT NULL AND created_at < %s ORDER BY id ASC LIMIT %d", self::table(), $before, $limit ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Admin list. $args: status (comma list), provider, form_id, kind,
	 * date_from / date_to (Y-m-d, site-local days), search (entry id,
	 * provider ids, invoice number), page, per_page.
	 *
	 * @return array { rows: array, total: int }
	 */
	public static function search( array $args ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$values = array();
		if ( ! empty( $args['status'] ) ) {
			$wanted = array_values( array_filter( array_map( 'trim', explode( ',', (string) $args['status'] ) ), static function ( $v ) { return in_array( $v, PaymentStatus::ATTEMPT_STATUSES, true ); } ) );
			if ( $wanted ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $wanted ), '%s' ) ) . ')';
				foreach ( $wanted as $w ) { $values[] = $w; }
			}
		}
		if ( ! empty( $args['provider'] ) ) {
			$where[]  = 'provider = %s';
			$values[] = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $args['provider'] ) );
		}
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$values[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['kind'] ) && in_array( $args['kind'], array( 'deposit', 'full' ), true ) ) {
			$where[]  = 'kind = %s';
			$values[] = $args['kind'];
		}
		if ( ! empty( $args['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = get_gmt_from_date( $args['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $args['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = get_gmt_from_date( $args['date_to'] . ' 23:59:59' );
		}
		if ( ! empty( $args['search'] ) ) {
			$term = trim( (string) $args['search'] );
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			if ( ctype_digit( $term ) ) {
				$where[]  = '( entry_id = %d OR id = %d OR session_id LIKE %s OR provider_payment_id LIKE %s OR meta LIKE %s )';
				$values[] = (int) $term; $values[] = (int) $term; $values[] = $like; $values[] = $like; $values[] = $like;
			} else {
				$where[]  = '( session_id LIKE %s OR provider_payment_id LIKE %s OR meta LIKE %s )';
				$values[] = $like; $values[] = $like; $values[] = $like;
			}
		}
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sql      = 'WHERE ' . implode( ' AND ', $where );
		$table    = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$sql}", $values ) : "SELECT COUNT(*) FROM {$table} {$sql}" );
		$q     = "SELECT * FROM {$table} {$sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $q, array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array( 'rows' => array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() ), 'total' => $total );
	}

	/** Totals per currency for the Payments page: paid, refunded, pending (open attempts), counts. */
	public static function totals( array $args = array() ): array {
		global $wpdb;
		$table = self::table();
		$where = '1=1';
		$values = array();
		if ( ! empty( $args['form_id'] ) ) {
			$where   .= ' AND form_id = %d';
			$values[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$values[] = get_gmt_from_date( $args['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $args['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$values[] = get_gmt_from_date( $args['date_to'] . ' 23:59:59' );
		}
		$q = "SELECT currency, livemode,
			SUM(CASE WHEN status IN ('paid','refunded','partially_refunded') THEN amount_paid_minor ELSE 0 END) AS paid,
			SUM(CASE WHEN status IN ('paid','refunded','partially_refunded') THEN amount_refunded_minor ELSE 0 END) AS refunded,
			SUM(CASE WHEN status IN ('pending','processing') THEN amount_due_minor ELSE 0 END) AS open_amount,
			SUM(CASE WHEN status IN ('paid','refunded','partially_refunded') THEN 1 ELSE 0 END) AS paid_count,
			SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS open_count,
			SUM(CASE WHEN status IN ('failed','expired') THEN 1 ELSE 0 END) AS failed_count
			FROM {$table} WHERE {$where} GROUP BY currency, livemode ORDER BY livemode DESC, currency ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $values ? $wpdb->prepare( $q, $values ) : $q, ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$c     = (string) $r['currency'];
			$out[] = array(
				'currency'     => $c,
				'livemode'     => ! empty( $r['livemode'] ),
				'paid'         => (int) $r['paid'],
				'refunded'     => (int) $r['refunded'],
				'net'          => (int) $r['paid'] - (int) $r['refunded'],
				'open'         => (int) $r['open_amount'],
				'paid_count'   => (int) $r['paid_count'],
				'open_count'   => (int) $r['open_count'],
				'failed_count' => (int) $r['failed_count'],
			);
		}
		return $out;
	}

	public static function delete_for_entry( int $entry_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$r = $wpdb->delete( self::table(), array( 'entry_id' => $entry_id ), array( '%d' ) );
		return false === $r ? 0 : (int) $r;
	}

	/**
	 * Record a provider event BEFORE processing. Returns the new row id,
	 * or 0 when the (provider, event_id) pair was already stored (PAY5).
	 */
	public static function record_event( string $provider, string $event_id, string $type, bool $livemode ): int {
		global $wpdb;
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( self::events_table(), array( 'provider' => substr( $provider, 0, 20 ), 'event_id' => substr( $event_id, 0, 190 ), 'event_type' => substr( $type, 0, 80 ), 'livemode' => $livemode ? 1 : 0, 'received_at' => current_time( 'mysql', true ) ) );
		$wpdb->suppress_errors( false );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function finish_event( int $event_row_id, ?int $payment_id, string $result, string $detail = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::events_table(), array( 'payment_id' => $payment_id, 'result' => substr( $result, 0, 40 ), 'detail' => $detail !== '' ? substr( $detail, 0, 2000 ) : null, 'processed_at' => current_time( 'mysql', true ) ), array( 'id' => $event_row_id ) );
	}

	public static function list_events_for_payment( int $payment_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, provider, event_id, event_type, livemode, result, detail, received_at, processed_at FROM %i WHERE payment_id = %d ORDER BY id DESC LIMIT %d', self::events_table(), $payment_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function decode( array $row ): array {
		foreach ( array( 'id', 'entry_id', 'form_id', 'amount_due_minor', 'amount_paid_minor', 'amount_refunded_minor', 'attempt' ) as $k ) {
			$row[ $k ] = isset( $row[ $k ] ) ? (int) $row[ $k ] : 0;
		}
		$row['quote_id'] = isset( $row['quote_id'] ) && null !== $row['quote_id'] ? (int) $row['quote_id'] : null;
		$row['livemode'] = ! empty( $row['livemode'] );
		$meta            = isset( $row['meta'] ) && is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : null;
		$row['meta']     = is_array( $meta ) ? $meta : array();
		foreach ( array( 'account_id', 'session_id', 'provider_payment_id', 'checkout_url', 'paid_at', 'token_seed' ) as $k ) {
			$row[ $k ] = isset( $row[ $k ] ) && $row[ $k ] !== '' ? (string) $row[ $k ] : null;
		}
		return $row;
	}
}
