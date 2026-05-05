<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// Direct $wpdb calls against wp_neural_forms_* tables only. Object caching
// is skipped intentionally - these reads back the admin UI which expects
// fresh data after every status / reply / AI mutation.
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Config;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET /stats
 *
 * Single endpoint that returns all data for the admin Stats screen.
 * Five payload sections:
 *
 *   needs_attention  - up to 8 entries requiring action (high-priority,
 *                      needs-reply, or spam-suspected-but-not-yet-moved).
 *   stats            - aggregate counts: entries last 7 days, unread,
 *                      needs-reply, spam detected.
 *   ai_insights      - top category, high-priority count, sales/support
 *                      count from completed AI analyses.
 *   recent_activity  - last 10 log events (reply, notification, AI).
 *   system_status    - AI on/off, pending queue depth, email failure count.
 *
 * All queries are simple aggregations or small-LIMIT fetches - no heavy
 * joins or full-table scans beyond what the existing indexes cover.
 */
class StatsEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route( $ns, '/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		// Page Performance (1.1.0+): per-form aggregation of entries
		// grouped by source page. Capability-gated for Pro; Lite
		// callers receive a feature_locked error so the analytics
		// bundle can render an upsell card.
		register_rest_route( $ns, '/forms/(?P<id>\d+)/analytics/page-performance', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_page_performance' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id' => [
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
	}

	public function get_stats( WP_REST_Request $request ) {
		return rest_ensure_response( [
			'needs_attention' => $this->needs_attention(),
			'stats'           => $this->stats(),
			'ai_insights'     => $this->ai_insights(),
			'recent_activity' => $this->recent_activity(),
			'system_status'   => $this->system_status(),
		] );
	}

	// Sections

	/**
	 * Entries that need a human decision:
	 *   - AI marked high-priority (inbox only)
	 *   - Flagged needs_reply (inbox only)
	 *   - AI suspected spam but not auto-moved (not already spam/trash)
	 */
	private function needs_attention(): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.form_id, e.status, e.is_starred, e.needs_reply,
				        e.ai_priority, e.ai_category, e.ai_is_spam, e.ai_auto_spam_applied,
				        e.submitted_at, e.created_at, e.data,
				        f.name AS form_name
				 FROM %i e
				 LEFT JOIN %i f ON e.form_id = f.id
				 WHERE (
				     ( e.ai_priority = 'high'  AND e.status IN ('new','read') )
				     OR ( e.needs_reply = 1      AND e.status IN ('new','read') )
				     OR ( e.ai_is_spam = 1 AND e.ai_auto_spam_applied = 0
				          AND e.status NOT IN ('spam','trash') )
				 )
				 ORDER BY e.created_at DESC
				 LIMIT 8",
				$entries,
				$forms
			),
			ARRAY_A
		);

		return array_map( function ( array $row ): array {
			$data    = json_decode( $row['data'] ?? '{}', true ) ?: [];
			$preview = array_slice( $data, 0, 3, true );

			return [
				'id'                  => (int) $row['id'],
				'form_id'             => (int) $row['form_id'],
				'form_name'           => isset( $row['form_name'] ) ? (string) $row['form_name'] : null,
				'status'              => $row['status'],
				'is_starred'          => (int) $row['is_starred'],
				'needs_reply'         => (int) $row['needs_reply'],
				'ai_priority'         => $row['ai_priority'],
				'ai_category'         => $row['ai_category'],
				'ai_is_spam'          => (int) $row['ai_is_spam'],
				'ai_auto_spam_applied' => (int) $row['ai_auto_spam_applied'],
				'submitted_at'        => $row['submitted_at'],
				'created_at'          => $row['created_at'],
				'preview'             => $preview,
			];
		}, $rows ?: [] );
	}

	/**
	 * Four aggregate counts in a single conditional-aggregation query.
	 */
	private function stats(): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   SUM( created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 7 DAY ) ) AS entries_7d,
				   SUM( status = 'new' )                                             AS unread,
				   SUM( needs_reply = 1 AND status IN ('new','read') )               AS needs_reply,
				   SUM( ai_is_spam = 1 )                                             AS spam_detected
				 FROM %i",
				$wpdb->prefix . 'neural_forms_entries'
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return [ 'entries_7d' => 0, 'unread' => 0, 'needs_reply' => 0, 'spam_detected' => 0 ];
		}

		return array_map( 'intval', $row );
	}

	/**
	 * Category distribution + priority/sales/support/spam counts from completed analyses.
	 *
	 * Returns:
	 *   analyzed_count      - total entries where ai_status = 'complete'
	 *   top_category        - highest-count non-generic category, or null
	 *   categories          - up to 4 category/count pairs (for future category list)
	 *   high_priority_count - entries where ai_priority = 'high'
	 *   sales_count         - entries where ai_category = 'sales'
	 *   support_count       - entries where ai_category = 'support'
	 *   spam_detected_count - entries where ai_is_spam = 1 (among completed)
	 */
	private function ai_insights(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entries';

		// Top non-generic categories - drives the top_category field and
		// the categories array for any future breakdown UI.
		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ai_category AS category, COUNT(*) AS count
				 FROM %i
				 WHERE ai_status = 'complete'
				   AND ai_category IS NOT NULL
				   AND ai_category != ''
				   AND ai_category != 'general'
				 GROUP BY ai_category
				 ORDER BY count DESC
				 LIMIT 4",
				$table
			),
			ARRAY_A
		);

		// Single conditional-aggregation pass over completed entries.
		$agg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   COUNT(*)                          AS analyzed_count,
				   SUM( ai_priority = 'high' )       AS high_priority_count,
				   SUM( ai_category = 'sales' )      AS sales_count,
				   SUM( ai_category = 'support' )    AS support_count,
				   SUM( ai_is_spam = 1 )             AS spam_detected_count
				 FROM %i
				 WHERE ai_status = 'complete'",
				$table
			),
			ARRAY_A
		);

		return [
			'top_category'        => ! empty( $categories ) ? $categories[0]['category'] : null,
			'categories'          => array_map( static function ( array $r ): array {
				return [ 'category' => $r['category'], 'count' => (int) $r['count'] ];
			}, $categories ?: [] ),
			'analyzed_count'      => $agg ? (int) $agg['analyzed_count']      : 0,
			'high_priority_count' => $agg ? (int) $agg['high_priority_count'] : 0,
			'sales_count'         => $agg ? (int) $agg['sales_count']         : 0,
			'support_count'       => $agg ? (int) $agg['support_count']       : 0,
			'spam_detected_count' => $agg ? (int) $agg['spam_detected_count'] : 0,
		];
	}

	/**
	 * Last 10 actionable log events - reply, notification, and AI outcomes.
	 * Generic/noisy events (queued, status updates) are excluded.
	 */
	private function recent_activity(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entry_id, event_type, message, status, created_at
				 FROM %i
				 WHERE event_type IN (
				     'reply_sent',           'reply_send_failed',
				     'ai_auto_spam',         'ai_processing_error',
				     'admin_notification_sent',  'admin_notification_failed',
				     'autoresponder_sent',       'autoresponder_failed'
				 )
				 ORDER BY created_at DESC
				 LIMIT 10",
				$wpdb->prefix . 'neural_forms_logs'
			),
			ARRAY_A
		);

		return array_map( function ( array $row ): array {
			return [
				'id'         => (int) $row['id'],
				'entry_id'   => $row['entry_id'] ? (int) $row['entry_id'] : null,
				'event_type' => $row['event_type'],
				'message'    => (string) $row['message'],
				'status'     => $row['status'],
				'created_at' => $row['created_at'],
			];
		}, $rows ?: [] );
	}

	/**
	 * AI on/off, pending queue depth, email failure count in last 24 hours.
	 */
	private function system_status(): array {
		global $wpdb;

		$pending_jobs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE job_type = 'analyze_entry'
				   AND status IN ('pending','failed')
				   AND attempts < %d",
				$wpdb->prefix . 'neural_forms_queue',
				\NeuralForms\AI\AIQueueProcessor::MAX_ATTEMPTS
			)
		);

		$email_failures = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE event_type IN (
				     'reply_send_failed',
				     'admin_notification_failed',
				     'autoresponder_failed'
				 )
				 AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 24 HOUR )",
				$wpdb->prefix . 'neural_forms_logs'
			)
		);

		return [
			'ai_enabled'      => (bool) Config::get( 'ai_enabled' ),
			'ai_spam_enabled' => (bool) Config::get( 'ai_spam_enabled' ),
			'pending_jobs'    => $pending_jobs,
			'email_failures'  => $email_failures,
		];
	}

	// Page Performance

	/**
	 * GET /forms/{id}/analytics/page-performance
	 *
	 * Per-page aggregation of entries for a single form. Groups by a
	 * normalized origin+path slice of `page_url` (the column already
	 * recorded from HTTP_REFERER on every submission), so existing
	 * entries surface without a schema change.
	 *
	 * Pro-only: Lite callers receive a feature_locked error so the
	 * Analytics bundle can render an upsell card without a 403 toast.
	 *
	 * Returns: {
	 *   form_id, form_name, total_entries, untracked_entries,
	 *   pages: [ {
	 *     page_path, page_url, page_origin,
	 *     entries, spam, not_spam,
	 *     high_priority, medium_priority, low_priority, unknown_priority,
	 *     unread, needs_reply,
	 *     conversion_share, spam_rate, high_priority_rate,
	 *     last_entry_at
	 *   } ]
	 * }
	 *
	 * Sort: entries desc, then high_priority desc, then last_entry_at desc.
	 */
	public function get_page_performance( WP_REST_Request $request ) {
		// Pro capability gate. Distinct error so the Analytics bundle
		// renders an upsell rather than a generic permission denial.
		if ( ! \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::PAGE_PERFORMANCE_ANALYTICS ) ) {
			return new \WP_Error(
				'pro_feature_locked',
				__( 'Page Performance Analytics is available in Neural Forms Pro.', 'neural-forms-lite' ),
				[ 'status' => 403, 'feature' => \NeuralForms\Core\Capabilities::PAGE_PERFORMANCE_ANALYTICS ]
			);
		}

		$form_id = (int) $request['id'];
		if ( $form_id < 1 ) {
			return new \WP_Error( 'invalid_form_id', __( 'Invalid form id.', 'neural-forms-lite' ), [ 'status' => 400 ] );
		}

		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';

		// Form name lookup (cheap, used by the response envelope).
		$form_name = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $forms, $form_id )
		);
		if ( $form_name === '' ) {
			return new \WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), [ 'status' => 404 ] );
		}

		// Pull every entry's page_url + status + ai_priority. We do
		// the bucketing in PHP rather than SQL because we need
		// origin+path normalization (strip query strings, lowercase
		// host) — easier to express deterministically in PHP than
		// SUBSTRING_INDEX gymnastics. Per-form scope keeps result
		// sizes bounded by typical form volumes (≤ tens of thousands
		// of rows per form even for high-traffic sites).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, page_url, status, ai_priority, ai_is_spam, ai_auto_spam_applied,
				        is_starred, needs_reply, submitted_at, created_at
				 FROM %i WHERE form_id = %d',
				$entries,
				$form_id
			),
			ARRAY_A
		) ?: [];

		$buckets = []; // page_key => bucket
		$total = 0;
		$untracked = 0;

		foreach ( $rows as $r ) {
			$total++;
			$norm = self::normalize_page_url( (string) ( $r['page_url'] ?? '' ) );
			if ( $norm['key'] === '' ) {
				$untracked++;
				$key = '__unknown__';
				$norm = [
					'key'    => $key,
					'origin' => '',
					'path'   => '',
					'url'    => '',
				];
			} else {
				$key = $norm['key'];
			}

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = [
					'page_key'         => $key,
					'page_origin'      => $norm['origin'],
					'page_path'        => $norm['path'],
					'page_url'         => $norm['url'],
					'entries'          => 0,
					'spam'             => 0,
					'not_spam'         => 0,
					'high_priority'    => 0,
					'medium_priority'  => 0,
					'low_priority'     => 0,
					'unknown_priority' => 0,
					'unread'           => 0,
					'needs_reply'      => 0,
					'last_entry_at'    => null,
				];
			}
			$b = &$buckets[ $key ];
			$b['entries']++;

			$status = (string) ( $r['status'] ?? '' );
			if ( $status === 'spam' ) {
				$b['spam']++;
			} else {
				$b['not_spam']++;
			}

			$priority = strtolower( (string) ( $r['ai_priority'] ?? '' ) );
			if ( $priority === 'high' )      $b['high_priority']++;
			elseif ( $priority === 'medium' ) $b['medium_priority']++;
			elseif ( $priority === 'low' )    $b['low_priority']++;
			else                              $b['unknown_priority']++;

			if ( $status === 'new' ) $b['unread']++;
			if ( (int) ( $r['needs_reply'] ?? 0 ) === 1 ) $b['needs_reply']++;

			$ts = (string) ( $r['submitted_at'] ?? $r['created_at'] ?? '' );
			if ( $ts !== '' && ( $b['last_entry_at'] === null || $ts > $b['last_entry_at'] ) ) {
				$b['last_entry_at'] = $ts;
			}
			unset( $b );
		}

		// Compute rates + share, then sort.
		$pages = array_values( array_map(
			static function ( array $b ) use ( $total ): array {
				$entries = (int) $b['entries'];
				$b['conversion_share']  = $total > 0   ? round( ( $entries / $total ) * 100, 2 ) : 0.0;
				$b['spam_rate']         = $entries > 0 ? round( ( (int) $b['spam']          / $entries ) * 100, 2 ) : 0.0;
				$b['high_priority_rate']= $entries > 0 ? round( ( (int) $b['high_priority'] / $entries ) * 100, 2 ) : 0.0;
				return $b;
			},
			$buckets
		) );

		usort( $pages, static function ( $a, $b ) {
			if ( $a['entries']       !== $b['entries']      ) return $b['entries']      <=> $a['entries'];
			if ( $a['high_priority'] !== $b['high_priority']) return $b['high_priority']<=> $a['high_priority'];
			return strcmp( (string) ( $b['last_entry_at'] ?? '' ), (string) ( $a['last_entry_at'] ?? '' ) );
		} );

		return rest_ensure_response( [
			'form_id'           => $form_id,
			'form_name'         => $form_name,
			'total_entries'     => $total,
			'untracked_entries' => $untracked,
			'pages'             => $pages,
			// Future hook: AI insight will consume `pages` aggregate
			// (NOT raw entries) to summarize "page X is your best
			// converter, page Y has high spam rate". See brief Part F.
		] );
	}

	/**
	 * Normalize a stored page_url into { key, origin, path, url }.
	 *
	 *   key    = canonical group identifier (origin + path, lowercased)
	 *   origin = scheme://host[:port]
	 *   path   = the page's path (defaults to '/')
	 *   url    = clean origin + path (no query / no fragment)
	 *
	 * Query strings are stripped because the brief calls them out
	 * as a privacy hazard (utm_*, email=, name=, token=). The
	 * untracked / empty case returns key=''. parse_url is safe with
	 * untrusted input — we re-build the URL ourselves rather than
	 * trust whatever was in the column.
	 */
	private static function normalize_page_url( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return [ 'key' => '', 'origin' => '', 'path' => '', 'url' => '' ];
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) ) {
			return [ 'key' => '', 'origin' => '', 'path' => '', 'url' => '' ];
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] )   ? strtolower( (string) $parts['host'] )   : '';
		$port   = isset( $parts['port'] )   ? ':' . (int) $parts['port']              : '';
		$path   = isset( $parts['path'] )   ? (string) $parts['path']                 : '/';
		if ( $path === '' ) $path = '/';

		// Without a host the URL is unusable for analytics grouping.
		if ( $host === '' ) {
			return [ 'key' => '', 'origin' => '', 'path' => '', 'url' => '' ];
		}

		$origin = $scheme !== '' ? "{$scheme}://{$host}{$port}" : "//{$host}{$port}";
		$url    = $origin . $path;
		return [
			'key'    => strtolower( $origin . $path ),
			'origin' => $origin,
			'path'   => $path,
			'url'    => $url,
		];
	}
}
