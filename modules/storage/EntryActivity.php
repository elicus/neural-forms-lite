<?php
namespace NeuralForms\Storage;

use NeuralForms\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Entry activity timeline (1.2.0).
 *
 * Reuses `neural_forms_logs` (entry_id, event_type, payload, status,
 * message, created_at). Timeline rows use these event_type prefixes:
 *
 *   entry.assigned         payload { from, to, by }
 *   entry.stage_changed    payload { from, to, by, source }
 *   entry.follow_up_set    payload { due, by }
 *   entry.follow_up_done   payload { by }
 *   entry.follow_up_cleared payload { by }
 *   entry.reply_sent       payload { message_id, by }
 *   workflow.*             written by the workflow engine (A4)
 *
 * `by` is the WordPress user id (0 for cron / system).
 * Everything else in the logs table (ai errors, test emails…) is not
 * part of the timeline: list() only returns rows whose event_type
 * starts with `entry.` or `workflow.`.
 */
final class EntryActivity {

	const PREFIXES = array( 'entry.', 'workflow.' );

	/**
	 * Append a timeline row.
	 *
	 * @param int    $entry_id
	 * @param string $event_type  One of the entry.* / workflow.* types.
	 * @param string $message     Short human sentence (already translated).
	 * @param array  $payload     Small structured context.
	 * @param string $status      info | warning | error
	 */
	public static function record( int $entry_id, string $event_type, string $message, array $payload = array(), string $status = 'info' ): void {
		if ( ! isset( $payload['by'] ) ) {
			$payload['by'] = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		}
		Logger::log( $event_type, $status, $message, $payload, $entry_id );
	}

	/**
	 * Timeline rows for an entry, newest first.
	 *
	 * @return array[] [ { id, event_type, status, message, payload, created_at, by, by_name }, ... ]
	 */
	public static function list( int $entry_id, int $limit = 100 ): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}
		$table = $wpdb->prefix . 'neural_forms_logs';
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_type, status, message, payload, created_at FROM %i WHERE entry_id = %d AND ( event_type LIKE %s OR event_type LIKE %s ) ORDER BY id DESC LIMIT %d',
				$table,
				$entry_id,
				$wpdb->esc_like( 'entry.' ) . '%',
				$wpdb->esc_like( 'workflow.' ) . '%',
				$limit
			),
			ARRAY_A
		);

		$out   = array();
		$names = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$payload = is_array( $payload ) ? $payload : array();
			$by      = isset( $payload['by'] ) ? (int) $payload['by'] : 0;
			if ( $by > 0 && ! isset( $names[ $by ] ) ) {
				$user         = function_exists( 'get_userdata' ) ? get_userdata( $by ) : null;
				$names[ $by ] = $user && isset( $user->display_name ) ? (string) $user->display_name : '';
			}
			$out[] = array(
				'id'         => (int) $row['id'],
				'event_type' => (string) $row['event_type'],
				'status'     => (string) $row['status'],
				'message'    => (string) $row['message'],
				'payload'    => $payload,
				'created_at' => (string) $row['created_at'],
				'by'         => $by,
				'by_name'    => $by > 0 ? ( $names[ $by ] ?? '' ) : '',
			);
		}
		return $out;
	}

	/**
	 * Remove an entry's timeline (called when the entry is deleted).
	 */
	public static function delete_for_entry( int $entry_id ): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE entry_id = %d AND ( event_type LIKE %s OR event_type LIKE %s )',
				$wpdb->prefix . 'neural_forms_logs',
				$entry_id,
				$wpdb->esc_like( 'entry.' ) . '%',
				$wpdb->esc_like( 'workflow.' ) . '%'
			)
		);
	}
}
