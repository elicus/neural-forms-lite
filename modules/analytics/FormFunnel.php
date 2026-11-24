<?php
namespace NeuralForms\Analytics;

use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Frontend\Conversational;

defined( 'ABSPATH' ) || exit;

/**
 * FormFunnel (1.3.0, report is Pro via `form_dropoff_analytics`)
 *
 * Where visitors give up on a form. Daily counters per form in
 * `neural_forms_form_funnel` (form_id, day, kind, node) → count. Nothing
 * about the visitor is stored: no IP, no session id, no values.
 *
 * Kinds:
 *   started     node ''         first time a visitor touched a field
 *   step        node step id    a step (or conversational screen) was reached
 *   quit_field  node field id   last field touched by someone who left
 *
 * Conversational screens are recorded under the id of the field / section
 * they show (the `nfcv_` prefix is stripped in the browser), so a screen
 * keeps its history when the form is edited.
 *
 * Recording runs on every install (like page-source capture); only the
 * report is gated, so an upgrade shows the history straight away.
 */
class FormFunnel {

	public const KINDS = array( 'started', 'step', 'quit_field' );

	/** Periods the report accepts (days). */
	public const PERIODS = array( 7, 30, 90 );

	/** Below this many people who started, percentages are not shown. */
	public const MIN_SAMPLE = 20;

	/** Rows in the "fields people quit on" list. */
	public const TOP_FIELDS = 10;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_form_funnel';
	}

	/** Add (or with $delta = -1, take back) one count for today (UTC). */
	public static function bump( int $form_id, string $kind, string $node, int $delta = 1 ): bool {
		global $wpdb;
		if ( $form_id < 1 || ! in_array( $kind, self::KINDS, true ) ) {
			return false;
		}
		$day = gmdate( 'Y-m-d' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $delta < 0 ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET count = count - 1 WHERE form_id = %d AND day = %s AND kind = %s AND node = %s AND count > 0',
					self::table(),
					$form_id,
					$day,
					$kind,
					$node
				)
			);
		} else {
			$ok = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (form_id, day, kind, node, count) VALUES (%d, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE count = count + 1',
					self::table(),
					$form_id,
					$day,
					$kind,
					$node
				)
			);
		}
		// phpcs:enable
		return false !== $ok;
	}

	/** Remove a form's counters (form permanently deleted). */
	public static function delete_for_form( int $form_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'form_id' => $form_id ), array( '%d' ) );
	}

	// ── Schema helpers (pure) ────────────────────────────────────────

	/** Top-level nodes as the front end renders them (welcome screen removed). */
	private static function top_nodes( array $form ): array {
		$fields = is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array();
		return array_values(
			array_filter(
				$fields,
				static function ( $n ) {
					return is_array( $n ) && ! SchemaHelper::is_welcome_screen( $n );
				}
			)
		);
	}

	/**
	 * Every id the browser may send for this form: step ids, field ids,
	 * section / content-block ids. Anything else is dropped on arrival.
	 *
	 * @return array<string, string> id => label
	 */
	public static function known_nodes( array $form ): array {
		$out  = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$out ) {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$id = (string) ( $node['id'] ?? '' );
				if ( SchemaHelper::is_row( $node ) ) {
					foreach ( is_array( $node['columns'] ?? null ) ? $node['columns'] : array() as $col ) {
						if ( is_array( $col ) && is_array( $col['fields'] ?? null ) ) {
							$walk( $col['fields'] );
						}
					}
					continue;
				}
				if ( SchemaHelper::is_step( $node ) ) {
					if ( $id !== '' ) {
						$out[ $id ] = (string) ( $node['title'] ?? '' );
					}
					$walk( is_array( $node['fields'] ?? null ) ? $node['fields'] : array() );
					continue;
				}
				if ( $id === '' || ( $node['type'] ?? '' ) === 'hidden' ) {
					continue;
				}
				$label = (string) ( $node['label'] ?? $node['title'] ?? '' );
				$out[ $id ] = $label !== '' ? $label : $id;
			}
		};
		$walk( self::top_nodes( $form ) );
		return $out;
	}

	/** True when $node is a well-formed id that exists in the form now. */
	public static function is_known( array $form, string $node ): bool {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $node ) ) {
			return false;
		}
		return array_key_exists( $node, self::known_nodes( $form ) );
	}

	/**
	 * The steps the visitor goes through today, in order:
	 * [ { node, label, conditional } ]. Empty for a single-page form.
	 */
	public static function layout( array $form, bool $conversational ): array {
		$nodes = self::top_nodes( $form );
		$out   = array();
		if ( $conversational ) {
			$known = self::known_nodes( $form );
			$n     = 0;
			foreach ( Conversational::expand( $nodes, false ) as $screen ) {
				$id = (string) substr( (string) ( $screen['id'] ?? '' ), strlen( Conversational::STEP_PREFIX ) );
				if ( $id === '' || ! isset( $known[ $id ] ) ) {
					continue;
				}
				++$n;
				$own   = false;
				foreach ( (array) ( $screen['fields'] ?? array() ) as $f ) {
					if ( is_array( $f ) && (string) ( $f['id'] ?? '' ) === $id && ! empty( $f['conditional_logic']['enabled'] ) ) {
						$own = true;
					}
				}
				$out[] = array(
					'node'        => $id,
					/* translators: %d: screen number */
					'label'       => $known[ $id ] !== $id ? $known[ $id ] : sprintf( __( 'Screen %d', 'neural-forms-lite' ), $n ),
					'conditional' => $own || ! empty( $screen['conditional_logic'] ),
				);
			}
			return count( $out ) >= 2 ? $out : array();
		}
		$n = 0;
		foreach ( $nodes as $node ) {
			if ( ! SchemaHelper::is_step( $node ) ) {
				continue;
			}
			++$n;
			$id = (string) ( $node['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$title = trim( (string) ( $node['title'] ?? '' ) );
			$cl    = $node['conditional_logic'] ?? null;
			$out[] = array(
				'node'        => $id,
				/* translators: %d: step number */
				'label'       => $title !== '' ? $title : sprintf( __( 'Step %d', 'neural-forms-lite' ), $n ),
				'conditional' => is_array( $cl ) && ! empty( $cl['enabled'] ),
			);
		}
		return count( $out ) >= 2 ? $out : array();
	}

	// ── Report ───────────────────────────────────────────────────────

	/**
	 * Build the report from raw counts (pure — fixture-tested).
	 *
	 * @param array $layout   From layout().
	 * @param array $labels   From known_nodes().
	 * @param array $counts   { started:int, step:{node:n}, quit_field:{node:n} }
	 * @param int   $views    Form views in the period.
	 * @param int   $subs     Submissions in the period.
	 */
	public static function build( array $layout, array $labels, array $counts, int $views, int $subs ): array {
		$started = (int) ( $counts['started'] ?? 0 );
		$reached = is_array( $counts['step'] ?? null ) ? $counts['step'] : array();
		$enough  = $started >= self::MIN_SAMPLE;

		$stages   = array();
		$stages[] = array( 'key' => 'viewed', 'label' => __( 'Viewed', 'neural-forms-lite' ), 'count' => $views );
		$stages[] = array( 'key' => 'started', 'label' => __( 'Started', 'neural-forms-lite' ), 'count' => $started );
		foreach ( $layout as $step ) {
			$stages[] = array(
				'key'         => 'step:' . $step['node'],
				'node'        => $step['node'],
				'label'       => $step['label'],
				'count'       => (int) ( $reached[ $step['node'] ] ?? 0 ),
				'conditional' => ! empty( $step['conditional'] ),
			);
		}
		$stages[] = array( 'key' => 'submitted', 'label' => __( 'Submitted', 'neural-forms-lite' ), 'count' => $subs );

		$biggest = null;
		foreach ( $stages as $i => &$st ) {
			$st['drop'] = null;
			if ( $i === 0 ) {
				continue;
			}
			$prev = $stages[ $i - 1 ];
			if ( $prev['count'] > 0 && $enough ) {
				$st['drop'] = round( max( 0, $prev['count'] - $st['count'] ) / $prev['count'] * 100, 1 );
			}
			// "N% leave at <stage before>": only from Started on, and not
			// into a step that logic may skip (fewer people is expected).
			if ( $i >= 2 && null !== $st['drop'] && $st['drop'] > 0 && empty( $st['conditional'] ) && $prev['key'] !== 'viewed' ) {
				if ( null === $biggest || $st['drop'] > $biggest['drop'] ) {
					$biggest = array(
						'at'    => $prev['key'] === 'started' ? __( 'the first question', 'neural-forms-lite' ) : $prev['label'],
						'key'   => $prev['key'],
						'drop'  => $st['drop'],
						'count' => $prev['count'] - $st['count'],
					);
				}
			}
		}
		unset( $st );

		$quit_raw = is_array( $counts['quit_field'] ?? null ) ? $counts['quit_field'] : array();
		$quits    = array_sum( array_map( 'intval', $quit_raw ) );
		arsort( $quit_raw );
		$fields = array();
		foreach ( array_slice( $quit_raw, 0, self::TOP_FIELDS, true ) as $node => $n ) {
			$node     = (string) $node;
			$fields[] = array(
				'node'    => $node,
				'label'   => isset( $labels[ $node ] ) ? $labels[ $node ] : __( 'Removed field', 'neural-forms-lite' ),
				'removed' => ! isset( $labels[ $node ] ),
				'count'   => (int) $n,
				'share'   => $enough && $quits > 0 ? round( $n / $quits * 100, 1 ) : null,
			);
		}

		return array(
			'enough_data' => $enough,
			'min_sample'  => self::MIN_SAMPLE,
			'multi_step'  => ! empty( $layout ),
			'started'     => $started,
			'quits'       => $quits,
			'funnel'      => $stages,
			'quit_fields' => $fields,
			'biggest'     => $enough ? $biggest : null,
		);
	}

	/** Clamp ?days= to an allowed period. */
	public static function period( $days ): int {
		$d = (int) $days;
		return in_array( $d, self::PERIODS, true ) ? $d : 30;
	}

	/** Full report for one form over the last $days days (UTC). */
	public static function report( array $form, int $days ): array {
		global $wpdb;
		$form_id = (int) $form['id'];
		$days    = self::period( $days );
		$from    = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$entries = $wpdb->prefix . 'neural_forms_entries';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$since = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i WHERE form_id = %d', self::table(), $form_id ) );
		$since = $since ? (string) $since : null;

		$counts = array( 'started' => 0, 'step' => array(), 'quit_field' => array() );
		$views  = 0;
		$subs   = 0;
		if ( null !== $since ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT kind, node, SUM(count) AS n FROM %i WHERE form_id = %d AND day >= %s GROUP BY kind, node', self::table(), $form_id, $from ),
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$kind = (string) $r['kind'];
				if ( $kind === 'started' ) {
					$counts['started'] += (int) $r['n'];
				} elseif ( isset( $counts[ $kind ] ) ) {
					$counts[ $kind ][ (string) $r['node'] ] = (int) $r['n'];
				}
			}
			$start = max( $since, $from );
			$views = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views), 0) FROM %i WHERE form_id = %d AND view_date >= %s', FormViews::table(), $form_id, $start ) );
			$subs  = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE form_id = %d AND status NOT IN ('spam','trash') AND created_at >= %s", $entries, $form_id, $start . ' 00:00:00' )
			);
		}
		// phpcs:enable

		$cv     = class_exists( '\\NeuralForms\\Frontend\\Conversational' ) && Conversational::active( $form );
		$report = self::build( self::layout( $form, $cv ), self::known_nodes( $form ), $counts, $views, $subs );

		return array_merge(
			array(
				'form_id'        => $form_id,
				'tracking'       => null !== $since,
				'since'          => $since,
				'period'         => $days,
				'conversational' => $cv,
			),
			$report
		);
	}
}
