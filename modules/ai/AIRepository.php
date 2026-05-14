<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// This repository owns the wp_neural_forms_* tables and runs typed CRUD
// against them. Object caching is intentionally not used here:
// - REST handlers fetch fresh per request; entries / forms see frequent
//   writes, so a stale cache would surface in the admin UI.
// - Cache invalidation across the create/update/delete + bulk paths
//   would add more risk than the few percent of latency it would save.
namespace NeuralForms\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the AI analysis columns on neural_forms_entries.
 *
 * Operates only on AI-specific columns; general entry CRUD stays in
 * EntryRepository. No business logic - pure persistence.
 */
class AIRepository {

	/** Valid values for the ai_status column. */
	const VALID_AI_STATUSES = [ 'none', 'queued', 'processing', 'complete', 'failed' ];

	/** Valid values for ai_category - reflects Neural Forms's inbox workflow. */
	const VALID_CATEGORIES = [ 'sales', 'support', 'partnership', 'hiring', 'spam', 'general', 'other' ];

	/** Valid values for ai_priority. */
	const VALID_PRIORITIES = [ 'low', 'medium', 'high' ];

	/** Valid values for ai_tier - mirrors the API's model-registry. */
	const VALID_TIERS = [ 'fast', 'balanced', 'smart', 'premium' ];

	// Write operations

	/**
	 * Update ai_status on a single entry.
	 *
	 * @param int    $entry_id
	 * @param string $status        Must be one of VALID_AI_STATUSES; falls back to 'failed'.
	 * @param string $error_reason  Optional human-readable error; stored only when non-empty.
	 *                              Cleared automatically when status is 'complete'.
	 * @return bool
	 */
	public static function update_status( int $entry_id, string $status, string $error_reason = '' ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::VALID_AI_STATUSES, true ) ) {
			$status = 'failed';
		}

		$data    = [ 'ai_status' => $status ];
		$formats = [ '%s' ];

		// Persist error reason on failure; clear it on success.
		if ( $status === 'failed' && $error_reason !== '' ) {
			$data['ai_error_reason'] = $error_reason;
			$formats[]               = '%s';
		} elseif ( $status === 'complete' || $status === 'queued' ) {
			$data['ai_error_reason'] = '';
			$formats[]               = '%s';
		}

		$result = $wpdb->update(
			self::table(),
			$data,
			[ 'id' => $entry_id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Persist the full analysis result and mark the entry complete.
	 *
	 * Expected keys in $result (all produced by AIResultParser::parse()):
	 *   summary, category, priority, reply_draft, score, ai_provider, ai_model
	 *
	 * @param int   $entry_id
	 * @param array $result
	 * @return bool
	 */
	public static function save_analysis( int $entry_id, array $result ): bool {
		global $wpdb;

		$tier = isset( $result['tier_used'] ) && in_array( $result['tier_used'], self::VALID_TIERS, true )
			? (string) $result['tier_used']
			: 'fast';

		$updated = $wpdb->update(
			self::table(),
			[
				'ai_status'          => 'complete',
				'ai_summary'         => (string) ( $result['summary']          ?? '' ),
				'ai_category'        => (string) ( $result['category']         ?? 'general' ),
				'ai_priority'        => (string) ( $result['priority']         ?? 'medium' ),
				'ai_reply_draft'     => (string) ( $result['reply_draft']      ?? '' ),
				'ai_score'           => (int)    ( $result['score']            ?? 50 ),
				'ai_provider'        => (string) ( $result['ai_provider']      ?? '' ),
				'ai_model'           => (string) ( $result['ai_model']         ?? '' ),
				'ai_is_spam'         => (int) (bool) ( $result['is_spam']      ?? false ),
				'ai_spam_confidence' => (int)    ( $result['spam_confidence']  ?? 0 ),
				'ai_spam_reason'     => (string) ( $result['spam_reason']      ?? '' ),
				'ai_tier'            => $tier,
				'ai_error_reason'    => '',
			],
			[ 'id' => $entry_id ],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( $updated !== false ) {
			self::fire_analysis_completed( $entry_id, $result );
		}

		return $updated !== false;
	}

	/**
	 * Persist only the spam-classification fields and mark the entry complete.
	 *
	 * Used when ai_auto_process is off but ai_spam_enabled is on - the Cloud
	 * analyze() response still carries summary/reply/category/priority, but the
	 * user hasn't opted into storing/showing those. Saving them anyway would
	 * light up the "AI Analysis" card against the user's explicit setting.
	 */
	public static function save_spam_only( int $entry_id, array $result ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			[
				'ai_status'          => 'complete',
				'ai_is_spam'         => (int) (bool) ( $result['is_spam']      ?? false ),
				'ai_spam_confidence' => (int)    ( $result['spam_confidence']  ?? 0 ),
				'ai_spam_reason'     => (string) ( $result['spam_reason']      ?? '' ),
				'ai_provider'        => (string) ( $result['ai_provider']      ?? '' ),
				'ai_model'           => (string) ( $result['ai_model']         ?? '' ),
				'ai_error_reason'    => '',
			],
			[ 'id' => $entry_id ],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( $updated !== false ) {
			self::fire_analysis_completed( $entry_id, $result );
		}

		return $updated !== false;
	}

	/**
	 * Fire the `neural_forms_entry_analysis_completed` action so
	 * downstream consumers (WhatsApp dispatcher conditional triggers,
	 * future integrations) can react to a finished AI pass.
	 *
	 * Looks up form_id from the entry row so callers don't have to
	 * thread it through every save call site. Silently no-ops if the
	 * entry can't be resolved (already a logical impossibility post-
	 * update, but defensive).
	 *
	 * Args fired:
	 *   - int   $entry_id
	 *   - int   $form_id
	 *   - array $analysis  The raw analysis result (summary, category,
	 *                       priority, is_spam, spam_confidence, etc.).
	 *
	 * Stable contract — renaming or changing the arg order is a
	 * breaking change for Pro's WhatsAppDispatcher::on_entry_analysis_completed.
	 */
	private static function fire_analysis_completed( int $entry_id, array $result ): void {
		if ( $entry_id <= 0 ) return;
		if ( ! class_exists( '\\NeuralForms\\Storage\\EntryRepository' ) ) return;
		$entry = \NeuralForms\Storage\EntryRepository::get( $entry_id );
		if ( ! is_array( $entry ) ) return;
		$form_id = (int) ( $entry['form_id'] ?? 0 );
		if ( $form_id <= 0 ) return;

		do_action( 'neural_forms_entry_analysis_completed', $entry_id, $form_id, $result );
	}

	/**
	 * Update only the ai_reply_draft column and mark the entry complete.
	 * Used by the regenerate_reply job so it never overwrites summary/category/
	 * priority/spam fields that were written by a prior full analysis.
	 *
	 * @param int    $entry_id
	 * @param string $reply    Sanitized reply draft text.
	 * @return bool
	 */
	public static function update_reply_draft( int $entry_id, string $reply, ?string $tier = null ): bool {
		global $wpdb;

		$data    = [ 'ai_status' => 'complete', 'ai_reply_draft' => $reply ];
		$formats = [ '%s', '%s' ];

		if ( $tier !== null && in_array( $tier, self::VALID_TIERS, true ) ) {
			$data['ai_tier'] = $tier;
			$formats[]       = '%s';
		}

		$result = $wpdb->update(
			self::table(),
			$data,
			[ 'id' => $entry_id ],
			$formats,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Point an entry at its canonical/finalized AI response draft. Used by
	 * the "Use this reply" action on the draft carousel. Swap semantics:
	 * updating the pointer does not log history - AIResponseRepository::finalize
	 * flips the draft's finalized flag separately.
	 */
	public static function set_selected_response( int $entry_id, int $response_id ): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::table(),
			[ 'ai_selected_response_id' => $response_id ],
			[ 'id' => $entry_id ],
			[ '%d' ],
			[ '%d' ]
		);

		return $result !== false;
	}

	// Read operations

	/**
	 * Fetch only the AI analysis columns for a single entry.
	 * Returns null if the entry does not exist.
	 *
	 * @param int $entry_id
	 * @return array|null
	 */
	public static function get_analysis( int $entry_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ai_status, ai_summary, ai_category, ai_priority,
				        ai_reply_draft, ai_score, ai_provider, ai_model,
				        ai_is_spam, ai_spam_confidence, ai_spam_reason,
				        ai_auto_spam_applied, ai_error_reason,
				        ai_tier, ai_selected_response_id
				 FROM %i WHERE id = %d',
				self::table(),
				$entry_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['ai_score']           = $row['ai_score'] !== null ? (int) $row['ai_score'] : null;
		$row['ai_is_spam']         = (int) ( $row['ai_is_spam']         ?? 0 );
		$row['ai_spam_confidence'] = (int) ( $row['ai_spam_confidence'] ?? 0 );
		$row['ai_auto_spam_applied'] = (int) ( $row['ai_auto_spam_applied'] ?? 0 );
		$row['ai_tier']            = (string) ( $row['ai_tier'] ?? 'fast' );
		$row['ai_selected_response_id'] = $row['ai_selected_response_id'] !== null
			? (int) $row['ai_selected_response_id']
			: null;

		return $row;
	}

	// Helpers

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_entries';
	}
}
