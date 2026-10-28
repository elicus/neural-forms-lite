<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// This repository owns the wp_neural_forms_* tables and runs typed CRUD
// against them. Object caching is intentionally not used here:
// - REST handlers fetch fresh per request; entries / forms see frequent
// writes, so a stale cache would surface in the admin UI.
// - Cache invalidation across the create/update/delete + bulk paths
// would add more risk than the few percent of latency it would save.
namespace NeuralForms\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * All database reads and writes for the neural_forms_entry_messages table.
 *
 * A message row represents a single communication event attached to an entry:
 *   incoming - the original submission (type='incoming', populated at submit time)
 *   outgoing - a reply sent via the admin Reply panel
 *   note     - reserved for internal admin notes (future use)
 *
 * Rules:
 *  - Every public method receives and returns plain arrays.
 *  - meta is stored as JSON; callers always work with PHP arrays.
 *  - No business logic, no hooks - pure persistence.
 */
class EntryMessageRepository {

	// Write operations

	/**
	 * Insert a new message row.
	 *
	 * @param array $data {
	 *   @type int    $entry_id   Required. The parent entry ID.
	 *   @type string $type       'incoming', 'outgoing', or 'note'. Default 'outgoing'.
	 *   @type string $sender     Sender name or email. Default null.
	 *   @type string $recipient  Recipient email address. Default null.
	 *   @type string $subject    Message subject. Default null.
	 *   @type string $body       Required. Plain-text message body.
	 *   @type array  $meta       Arbitrary metadata array. Default [].
	 * }
	 * @return int|\WP_Error  New message ID on success, WP_Error on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$entry_id = isset( $data['entry_id'] ) ? (int) $data['entry_id'] : 0;

		if ( $entry_id <= 0 ) {
			return new \WP_Error(
				'invalid_entry_id',
				__( 'A valid entry_id is required.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$body = isset( $data['body'] ) ? (string) $data['body'] : '';

		if ( $body === '' ) {
			return new \WP_Error(
				'empty_body',
				__( 'Message body cannot be empty.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$type      = in_array( $data['type'] ?? 'outgoing', array( 'incoming', 'outgoing', 'note' ), true )
			? $data['type']
			: 'outgoing';
		$sender    = isset( $data['sender'] ) ? substr( (string) $data['sender'], 0, 255 ) : null;
		$recipient = isset( $data['recipient'] ) ? substr( (string) $data['recipient'], 0, 255 ) : null;
		$subject   = isset( $data['subject'] ) ? substr( (string) $data['subject'], 0, 500 ) : null;
		$meta      = isset( $data['meta'] ) && is_array( $data['meta'] )
			? wp_json_encode( $data['meta'] )
			: null;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'entry_id'   => $entry_id,
				'type'       => $type,
				'sender'     => $sender,
				'recipient'  => $recipient,
				'subject'    => $subject,
				'body'       => $body,
				'meta'       => $meta,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( $wpdb->last_error ) {
				\NeuralForms\Core\Logger::file(
					'storage',
					'error',
					sprintf( 'EntryMessageRepository::create() DB error (entry %d): %s', $entry_id, $wpdb->last_error )
				);
			}

			return new \WP_Error(
				'message_save_failed',
				__( 'Could not save the message.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the meta JSON column for a single message in-place.
	 *
	 * Replaces the entire meta object - pass the full desired value.
	 *
	 * @param int   $id    Message primary key.
	 * @param array $meta  New metadata array (will be JSON-encoded).
	 * @return bool
	 */
	public static function update_meta( int $id, array $meta ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array( 'meta' => wp_json_encode( $meta ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	// Read operations

	/**
	 * Fetch a single message by primary key.
	 *
	 * @param int $id
	 * @return array|null  Decoded message row, or null if not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Fetch all messages for a given entry, oldest first.
	 *
	 * @param int $entry_id
	 * @return array[]  Array of decoded message rows.
	 */
	/**
	 * Hard-delete every message belonging to an entry (privacy eraser).
	 *
	 * @return int  Rows deleted.
	 */
	public static function delete_by_entry( int $entry_id ): int {
		global $wpdb;
		$deleted = $wpdb->delete( $wpdb->prefix . 'neural_forms_entry_messages', array( 'entry_id' => $entry_id ), array( '%d' ) );
		return is_int( $deleted ) ? $deleted : 0;
	}

	public static function list_by_entry( int $entry_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE entry_id = %d ORDER BY created_at ASC, id ASC',
				self::table(),
				$entry_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'decode' ), $rows ?: array() );
	}

	// Helpers

	/**
	 * Decode a raw database row into a clean PHP array.
	 *
	 * @param array $row
	 * @return array
	 */
	private static function decode( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['entry_id'] = (int) $row['entry_id'];

		if ( isset( $row['meta'] ) && null !== $row['meta'] ) {
			$decoded_meta = json_decode( $row['meta'], true );
			$row['meta']  = is_array( $decoded_meta ) ? $decoded_meta : array();
		} else {
			$row['meta'] = array();
		}

		return $row;
	}

	/**
	 * Full table name including WP prefix.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_entry_messages';
	}
}
