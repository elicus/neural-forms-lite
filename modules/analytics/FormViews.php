<?php
namespace NeuralForms\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * FormViews (1.3.0)
 *
 * Daily view counters per form, for views / submissions / conversion in
 * Analytics. One row per form per day (`neural_forms_form_views`), holding
 * only a number: no IP, no visitor id, no page URL.
 *
 * A view is counted by form-submit.js when the form scrolls into view,
 * once per browser session per form (sessionStorage), through
 * POST /forms/{id}/view. Site managers and previews are never counted
 * (FormRenderer only marks forms for tracking on public renders for
 * visitors who cannot manage forms).
 */
class FormViews {

	/** Days shown in the Analytics trend. */
	public const TREND_DAYS = 30;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_form_views';
	}

	/**
	 * Add one view for today (UTC). Single atomic upsert.
	 */
	public static function record( int $form_id ): bool {
		global $wpdb;
		if ( $form_id < 1 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (form_id, view_date, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1',
				self::table(),
				$form_id,
				gmdate( 'Y-m-d' )
			)
		);
		return false !== $ok;
	}

	/**
	 * Views, conversion and a daily trend for one form.
	 *
	 * Conversion only counts entries from the first day views were
	 * recorded, so entries collected before view tracking existed (or
	 * before the form was first viewed) never inflate the rate.
	 * Spam and trash are not submissions.
	 *
	 * @return array{ tracking: bool, since: ?string, views: int, submissions: int, conversion: ?float, views_30d: int, submissions_30d: int, conversion_30d: ?float, trend: array }
	 */
	public static function summary( int $form_id ): array {
		global $wpdb;
		$table   = self::table();
		$entries = $wpdb->prefix . 'neural_forms_entries';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT MIN(view_date) AS since, COALESCE(SUM(views), 0) AS views FROM %i WHERE form_id = %d', $table, $form_id ),
			ARRAY_A
		);
		$since = is_array( $row ) && ! empty( $row['since'] ) ? (string) $row['since'] : null;
		$views = is_array( $row ) ? (int) $row['views'] : 0;

		$start_30 = gmdate( 'Y-m-d', time() - ( self::TREND_DAYS - 1 ) * DAY_IN_SECONDS );

		$daily_views = array();
		$daily_subs  = array();
		$subs        = 0;
		$views_30    = 0;
		$subs_30     = 0;

		if ( null !== $since ) {
			$subs = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE form_id = %d AND status NOT IN ('spam','trash') AND created_at >= %s",
					$entries,
					$form_id,
					$since . ' 00:00:00'
				)
			);
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT view_date, views FROM %i WHERE form_id = %d AND view_date >= %s', $table, $form_id, $start_30 ), ARRAY_A ) as $r ) {
				$daily_views[ (string) $r['view_date'] ] = (int) $r['views'];
			}
			$sub_from = max( $since, $start_30 );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM %i WHERE form_id = %d AND status NOT IN ('spam','trash') AND created_at >= %s GROUP BY DATE(created_at)", $entries, $form_id, $sub_from . ' 00:00:00' ), ARRAY_A ) as $r ) {
				$daily_subs[ (string) $r['d'] ] = (int) $r['n'];
			}
		}
		// phpcs:enable

		$trend = array();
		for ( $i = self::TREND_DAYS - 1; $i >= 0; $i-- ) {
			$d        = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$v        = $daily_views[ $d ] ?? 0;
			$s        = $daily_subs[ $d ] ?? 0;
			$views_30 += $v;
			$subs_30  += $s;
			$trend[]  = array( 'date' => $d, 'views' => $v, 'submissions' => $s );
		}

		return array(
			'tracking'        => null !== $since,
			'since'           => $since,
			'views'           => $views,
			'submissions'     => $subs,
			'conversion'      => self::rate( $subs, $views ),
			'views_30d'       => $views_30,
			'submissions_30d' => $subs_30,
			'conversion_30d'  => self::rate( $subs_30, $views_30 ),
			'trend'           => $trend,
		);
	}

	/**
	 * Submissions per view as a percentage with one decimal, capped at
	 * 100 (a visitor can submit more than once per session). Null when
	 * there are no views.
	 */
	public static function rate( int $submissions, int $views ): ?float {
		if ( $views < 1 ) {
			return null;
		}
		return round( min( 100.0, $submissions / $views * 100 ), 1 );
	}

	/** Remove a form's counters (form permanently deleted). */
	public static function delete_for_form( int $form_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'form_id' => $form_id ), array( '%d' ) );
	}
}
