<?php
namespace NeuralForms\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Lead scoring + routing helpers (1.3.0, `workflows` capability).
 *
 * - Hot lead = AI score >= hot_score() on a live entry (status new/read).
 *   Default 80, filter `neural_forms_hot_lead_score` (1-100).
 * - Team routing for the `route_lead` workflow action: round robin
 *   (pointer per workflow action in option `neural_forms_route_pointer`)
 *   or "fewest open leads" (assigned, live, stage not won/lost).
 */
final class LeadRouting {

	public const DEFAULT_HOT_SCORE = 80;
	public const POINTER_OPTION    = 'neural_forms_route_pointer';
	public const MAX_TEAM          = 20;

	public static function hot_score(): int {
		$v = function_exists( 'apply_filters' ) ? apply_filters( 'neural_forms_hot_lead_score', self::DEFAULT_HOT_SCORE ) : self::DEFAULT_HOT_SCORE;
		return max( 1, min( 100, (int) $v ) );
	}

	/** SQL predicate for hot leads; `$prefix` is a table alias like 'e.'. Only integers are interpolated. */
	public static function hot_where( string $prefix = '' ): string {
		$p = preg_match( '/^[a-z]\.$/', $prefix ) ? $prefix : '';
		return sprintf( "%sai_score >= %d AND %sstatus IN ('new','read')", $p, self::hot_score(), $p );
	}

	public static function is_hot( array $entry ): bool {
		return isset( $entry['ai_score'] ) && null !== $entry['ai_score'] && (int) $entry['ai_score'] >= self::hot_score()
			&& in_array( (string) ( $entry['status'] ?? '' ), array( 'new', 'read' ), true );
	}

	/** Positive unique user ids, in the order given, capped at MAX_TEAM. */
	public static function clean_user_ids( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			$id = is_scalar( $id ) ? (int) $id : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX_TEAM ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Next user after the last one this rotation picked (wraps around).
	 * A user removed from the list just drops out of the rotation.
	 */
	public static function pick_round_robin( string $rotation_key, array $user_ids ): int {
		if ( ! $user_ids ) {
			return 0;
		}
		$pointers = get_option( self::POINTER_OPTION, array() );
		$pointers = is_array( $pointers ) ? $pointers : array();
		$last     = (int) ( $pointers[ $rotation_key ] ?? 0 );
		$pos      = array_search( $last, $user_ids, true );
		$next     = false === $pos ? $user_ids[0] : $user_ids[ ( $pos + 1 ) % count( $user_ids ) ];

		$pointers[ $rotation_key ] = $next;
		update_option( self::POINTER_OPTION, $pointers, false );
		return $next;
	}

	/** Drop a deleted workflow's rotation pointer. */
	public static function forget( int $workflow_id ): void {
		$pointers = get_option( self::POINTER_OPTION, array() );
		if ( is_array( $pointers ) && isset( $pointers[ 'w' . $workflow_id ] ) ) {
			unset( $pointers[ 'w' . $workflow_id ] );
			$pointers ? update_option( self::POINTER_OPTION, $pointers, false ) : delete_option( self::POINTER_OPTION );
		}
	}

	/** User with the fewest open leads; ties go to the earlier user in the list. */
	public static function pick_fewest_open( array $user_ids ): int {
		if ( ! $user_ids ) {
			return 0;
		}
		$counts = array_fill_keys( $user_ids, 0 );
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT assigned_to, COUNT(*) AS n FROM %i WHERE assigned_to IN ($placeholders) AND status IN ('new','read') AND lead_stage NOT IN ('won','lost') GROUP BY assigned_to", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $wpdb->prefix . 'neural_forms_entries' ), $user_ids )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$counts[ (int) $r['assigned_to'] ] = (int) $r['n'];
		}
		$best = $user_ids[0];
		foreach ( $user_ids as $id ) {
			if ( $counts[ $id ] < $counts[ $best ] ) {
				$best = $id;
			}
		}
		return $best;
	}
}
