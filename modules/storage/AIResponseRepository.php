<?php
namespace NeuralForms\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for neural_forms_entry_ai_responses — the draft history behind the
 * carousel UI. Each analyze / reanalyze / regenerate / polish call inserts
 * a row; the merchant picks one to finalize via "Use this reply" in the UI.
 *
 * Retention contract (enforced here + by a WP-Cron nightly purge):
 *   - Per entry: at most 10 non-finalized drafts. Oldest evicted on insert.
 *   - Global:    non-finalized drafts older than 30 days are purged.
 *   - The finalized draft (entries.ai_selected_response_id) is preserved
 *     indefinitely and is exempt from both caps.
 */
class AIResponseRepository {

	const KIND_ANALYZE    = 'analyze';
	const KIND_REANALYZE  = 'reanalyze';
	const KIND_REGENERATE = 'regenerate';
	const KIND_POLISH     = 'polish';

	const VALID_KINDS = [ 'analyze', 'reanalyze', 'regenerate', 'polish' ];
	const VALID_TIERS = [ 'fast', 'balanced', 'smart', 'premium' ];

	/** Per-entry cap on non-finalized drafts. Finalized rows are exempt. */
	const MAX_DRAFTS_PER_ENTRY = 10;

	/** Non-finalized drafts older than this are purged. Finalized rows exempt. */
	const DRAFT_TTL_DAYS = 30;

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Insert a draft row. Returns the new row id, or 0 on failure.
	 *
	 * Accepts a loose shape matching the analyze/regenerate/polish result
	 * arrays — unknown keys are ignored, missing keys default to sane values.
	 * Caller is responsible for pairing this with AIRepository writes on the
	 * entry itself (ai_reply_draft / ai_tier).
	 */
	public static function insert( int $entry_id, string $kind, array $data ): int {
		global $wpdb;

		if ( $entry_id <= 0 ) return 0;
		if ( ! in_array( $kind, self::VALID_KINDS, true ) ) return 0;

		$tier = isset( $data['tier_used'] ) && in_array( $data['tier_used'], self::VALID_TIERS, true )
			? (string) $data['tier_used']
			: 'fast';

		$inserted = $wpdb->insert(
			self::table(),
			[
				'entry_id'        => $entry_id,
				'kind'            => $kind,
				'tier_used'       => $tier,
				'provider'        => (string) ( $data['ai_provider'] ?? '' ),
				'model'           => (string) ( $data['ai_model']    ?? '' ),
				'summary'         => (string) ( $data['summary']     ?? '' ),
				'category'        => (string) ( $data['category']    ?? '' ),
				'priority'        => (string) ( $data['priority']    ?? '' ),
				'reply_draft'     => (string) ( $data['reply_draft'] ?? '' ),
				'polished_reply'  => (string) ( $data['polished_reply'] ?? '' ),
				'is_spam'         => (int) (bool) ( $data['is_spam']  ?? false ),
				'spam_confidence' => (int)  ( $data['spam_confidence'] ?? 0 ),
				'spam_reason'     => (string) ( $data['spam_reason'] ?? '' ),
				'score'           => isset( $data['score'] ) ? (int) $data['score'] : null,
				'tokens_used'     => (int)  ( $data['tokens_used']   ?? 0 ),
				'finalized'       => (int) (bool) ( $data['finalized'] ?? false ),
				'created_at'      => current_time( 'mysql', true ),
			],
			[ '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%d','%d','%s' ]
		);

		if ( $inserted === false ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		// Cap enforcement runs here so every insert keeps the retention
		// invariant without relying solely on the nightly cron.
		self::enforce_cap( $entry_id );

		return $id;
	}

	/**
	 * Flip the finalized flag on a draft and point the entry at it. Caller
	 * decides when to finalize — usually an explicit "Use this reply" click
	 * on the carousel, plus an implicit finalize on the very first analyze.
	 *
	 * Returns false if the draft doesn't belong to the entry.
	 */
	public static function finalize( int $entry_id, int $response_id ): bool {
		global $wpdb;

		$row = self::get( $response_id );
		if ( ! $row || (int) $row['entry_id'] !== $entry_id ) {
			return false;
		}

		$wpdb->update(
			self::table(),
			[ 'finalized' => 1 ],
			[ 'id' => $response_id ],
			[ '%d' ],
			[ '%d' ]
		);

		\NeuralForms\AI\AIRepository::set_selected_response( $entry_id, $response_id );
		return true;
	}

	/**
	 * Evict oldest non-finalized drafts for an entry until under the cap.
	 * Finalized rows never count toward the cap and are never deleted here.
	 */
	public static function enforce_cap( int $entry_id ): int {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table()
				. ' WHERE entry_id = %d AND finalized = 0',
				$entry_id
			)
		);

		if ( $count <= self::MAX_DRAFTS_PER_ENTRY ) {
			return 0;
		}

		$over = $count - self::MAX_DRAFTS_PER_ENTRY;

		// Oldest first — stable order by created_at, id as tiebreaker.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table()
				. ' WHERE entry_id = %d AND finalized = 0'
				. ' ORDER BY created_at ASC, id ASC LIMIT %d',
				$entry_id,
				$over
			)
		);

		if ( empty( $ids ) ) return 0;

		// IDs are intval-mapped immediately above, so the IN list is integer-only
		// and prepare() is unnecessary. phpcs flags concat-into-query unconditionally.
		$ids_sql = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . $ids_sql . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return count( $ids );
	}

	/**
	 * Delete non-finalized drafts older than N days across all entries.
	 * Hooked to a daily WP-Cron event. Finalized drafts are preserved.
	 */
	public static function purge_older_than( int $days = self::DRAFT_TTL_DAYS ): int {
		global $wpdb;

		if ( $days < 1 ) $days = 1;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table()
				. ' WHERE finalized = 0 AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$days
			)
		);

		return $deleted === false ? 0 : (int) $deleted;
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Oldest-first list — the carousel renders chronologically so "next"
	 * moves forward in time. Caller can reverse if they want newest-first.
	 */
	public static function list_for_entry( int $entry_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table()
				. ' WHERE entry_id = %d ORDER BY created_at ASC, id ASC',
				$entry_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_entry_ai_responses';
	}
}
