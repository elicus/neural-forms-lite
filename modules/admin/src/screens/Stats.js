/**
 * Stats screen — admin dashboard for Neural Forms.
 *
 * Layout (matches the canonical rich UI):
 *
 *   ┌───────────────────────────────────────┐
 *   │ Stats                                 │  ← header (border-bottom)
 *   │ Track recent activity, AI usage…      │
 *   ├───────────────────────────────────────┤
 *   │ [Total] [7d] [Unread] [Needs reply]   │  ← 4 KPI cards (blue, mint icon)
 *   ├───────────────────┬───────────────────┤
 *   │ AI Insights       │ Recent Activity   │  ← two-column layout
 *   │ ─ Analyzed   N    │ • event_type …    │
 *   │ ─ High prio  N    │ • event_type …    │
 *   │ ─ Sales      N    │                   │
 *   │ ─ Support    N    │                   │
 *   │ ─ Top cat   ▼     │                   │
 *   ├───────────────────┴───────────────────┤
 *   │ • AI engine: On  • Queue: 0  • …      │  ← system status bar
 *   └───────────────────────────────────────┘
 *
 * REST data (single GET /stats request):
 *
 *   stats:        { entries_7d, unread, needs_reply, spam_detected }
 *   ai_insights:  { analyzed_count, high_priority_count, sales_count,
 *                   support_count, spam_detected_count, top_category }
 *   recent_activity[]
 *   system_status:{ ai_enabled, queue_pending, email_failures_24h }
 *
 * "Total forms" comes from a parallel GET /forms?per_page=1 (X-Total-
 * Count). The two requests run in parallel so the page doesn't wait
 * for both before rendering the cards it can.
 *
 * Class naming: every element uses BEM under `.neural-forms-stats` so
 * the rich CSS tokens (stat-card colours, insight rows, activity dots,
 * system status pills) apply cleanly. Authored CSS lives in
 * src/stats.css (self-contained per-screen bundle).
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';

function formatDate( s ) {
	if ( ! s ) return '';
	const d = new Date( String( s ).replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( d.getTime() ) ) return s;
	return d.toLocaleString();
}

function StatCard( { label, value, highlight } ) {
	const cls = 'neural-forms-stats__stat-card'
		+ ( highlight ? ' neural-forms-stats__stat-card--highlight' : '' );
	return (
		<div className={ cls }>
			<div className="neural-forms-stats__stat-value">{ value ?? '-' }</div>
			<div className="neural-forms-stats__stat-label">{ label }</div>
		</div>
	);
}

function InsightRow( { label, value, badge } ) {
	const valueCls = 'neural-forms-stats__insight-value'
		+ ( badge ? ' neural-forms-stats__insight-value--badge' : '' );
	return (
		<div className="neural-forms-stats__insight-row">
			<span className="neural-forms-stats__insight-label">{ label }</span>
			<span className={ valueCls }>{ value ?? '-' }</span>
		</div>
	);
}

function ActivityItem( { activity } ) {
	const status = String( activity.status || 'info' );
	const isError = status === 'error';
	const itemCls = 'neural-forms-stats__activity-item'
		+ ( isError ? ' neural-forms-stats__activity-item--error' : '' );
	const dotCls = 'neural-forms-stats__activity-dot '
		+ 'neural-forms-stats__activity-dot--' + ( isError ? 'error' : 'ok' );

	return (
		<li className={ itemCls }>
			<span className={ dotCls } aria-hidden="true" />
			<span className="neural-forms-stats__activity-label">
				{ activity.message || activity.event_type }
			</span>
			<span className="neural-forms-stats__activity-time">
				{ formatDate( activity.created_at ) }
			</span>
		</li>
	);
}

function SystemItem( { label, value, dotState } ) {
	const dotCls = 'neural-forms-stats__status-dot '
		+ 'neural-forms-stats__status-dot--' + dotState;
	return (
		<div className="neural-forms-stats__system-item">
			<span className={ dotCls } aria-hidden="true" />
			<span className="neural-forms-stats__system-label">{ label }</span>
			<span className="neural-forms-stats__system-value">{ value }</span>
		</div>
	);
}

export default function Stats() {
	const [ stats,      setStats      ] = useState( null );
	const [ formsTotal, setFormsTotal ] = useState( null );
	const [ loading,    setLoading    ] = useState( true );
	const [ error,      setError      ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );

		Promise.all( [
			api( '/stats' ).catch( ( err ) => ( { __error: err } ) ),
			api( '/forms?status=any&per_page=1' ).catch( ( err ) => ( { __error: err } ) ),
		] ).then( ( [ statsRes, formsRes ] ) => {
			if ( cancelled ) return;
			if ( statsRes && statsRes.__error ) {
				setError( statsRes.__error?.message || __( 'Could not load stats.', 'neural-forms-lite' ) );
			} else {
				setStats( statsRes );
			}
			if ( formsRes && ! formsRes.__error ) {
				setFormsTotal(
					formsRes?.total ?? ( Array.isArray( formsRes?.items ) ? formsRes.items.length : null )
				);
			}
			setLoading( false );
		} );

		return () => { cancelled = true; };
	}, [] );

	if ( loading ) {
		return (
			<div className="neural-forms-stats">
				<div className="neural-forms-stats__loading">
					{ __( 'Loading…', 'neural-forms-lite' ) }
				</div>
			</div>
		);
	}

	if ( error && ! stats ) {
		return (
			<div className="neural-forms-stats">
				<div className="neural-forms-stats__error">{ error }</div>
			</div>
		);
	}

	const s   = stats?.stats         || {};
	const ai  = stats?.ai_insights   || {};
	const sys = stats?.system_status || {};
	const act = Array.isArray( stats?.recent_activity ) ? stats.recent_activity : [];

	// AI engine status — green when on, gray when off.
	const aiEnabled = !! sys.ai_enabled;
	// Queue pending — gray when 0, amber when > 0.
	const queueDotState = ( Number( sys.queue_pending ) || 0 ) > 0 ? 'warn' : 'ok';
	// Email failures (24h) — gray when 0, red when > 0.
	const emailDotState = ( Number( sys.email_failures_24h ) || 0 ) > 0 ? 'error' : 'ok';

	return (
		<div className="neural-forms-stats">
			<header className="neural-forms-stats__header">
				{ /* Wrap title + subtitle in a __header-text container so
				     the flex layout treats them as a single block (text
				     stacks vertically on the left, future actions can
				     sit on the right). Without this wrapper,
				     justify-content: space-between on the parent pushed
				     the H1 to the left edge and the subtitle to the
				     right edge. Mirrors the Settings header structure. */ }
				<div className="neural-forms-stats__header-text">
					<h1 className="neural-forms-stats__title">
						{ __( 'Stats', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-stats__subtitle">
						{ __( 'Track recent activity, AI usage, and system health.', 'neural-forms-lite' ) }
					</p>
				</div>
			</header>

			{ /* 4-card KPI grid */ }
			<div className="neural-forms-stats__stats">
				<StatCard
					label={ __( 'Total forms', 'neural-forms-lite' ) }
					value={ formsTotal ?? '-' }
					highlight
				/>
				<StatCard
					label={ __( 'Entries (7 days)', 'neural-forms-lite' ) }
					value={ s.entries_7d ?? 0 }
				/>
				<StatCard
					label={ __( 'Unread', 'neural-forms-lite' ) }
					value={ s.unread ?? 0 }
				/>
				<StatCard
					label={ __( 'Needs reply', 'neural-forms-lite' ) }
					value={ s.needs_reply ?? 0 }
				/>
			</div>

			{ /* Two-column row: AI Insights + Recent Activity */ }
			<div className="neural-forms-stats__cols">
				<section className="neural-forms-stats__section neural-forms-stats__section--half">
					<h2 className="neural-forms-stats__section-title">
						{ __( 'AI Insights', 'neural-forms-lite' ) }
					</h2>
					<div className="neural-forms-stats__insight-rows">
						<InsightRow
							label={ __( 'Analyzed', 'neural-forms-lite' ) }
							value={ ai.analyzed_count ?? 0 }
						/>
						<InsightRow
							label={ __( 'High priority', 'neural-forms-lite' ) }
							value={ ai.high_priority_count ?? 0 }
						/>
						<InsightRow
							label={ __( 'Sales', 'neural-forms-lite' ) }
							value={ ai.sales_count ?? 0 }
						/>
						<InsightRow
							label={ __( 'Support', 'neural-forms-lite' ) }
							value={ ai.support_count ?? 0 }
						/>
						<InsightRow
							label={ __( 'Spam detected', 'neural-forms-lite' ) }
							value={ ai.spam_detected_count ?? 0 }
						/>
						<InsightRow
							label={ __( 'Top category', 'neural-forms-lite' ) }
							value={ ai.top_category || '-' }
							badge={ !! ai.top_category }
						/>
					</div>
				</section>

				<section className="neural-forms-stats__section neural-forms-stats__section--half">
					<h2 className="neural-forms-stats__section-title">
						{ __( 'Recent Activity', 'neural-forms-lite' ) }
					</h2>
					{ act.length === 0 ? (
						<p className="neural-forms-stats__empty">
							{ __( 'No activity yet.', 'neural-forms-lite' ) }
						</p>
					) : (
						<ul className="neural-forms-stats__activity-list">
							{ act.slice( 0, 8 ).map( ( a ) => (
								<ActivityItem key={ a.id || a.created_at } activity={ a } />
							) ) }
						</ul>
					) }
				</section>
			</div>

			{ /* System status bar */ }
			<div className="neural-forms-stats__system">
				<SystemItem
					label={ __( 'AI engine', 'neural-forms-lite' ) }
					value={ aiEnabled
						? __( 'On', 'neural-forms-lite' )
						: __( 'Off', 'neural-forms-lite' ) }
					dotState={ aiEnabled ? 'ok' : 'off' }
				/>
				<SystemItem
					label={ __( 'Queue', 'neural-forms-lite' ) }
					value={ String( sys.queue_pending ?? 0 ) }
					dotState={ queueDotState }
				/>
				<SystemItem
					label={ __( 'Email failures (24h)', 'neural-forms-lite' ) }
					value={ String( sys.email_failures_24h ?? 0 ) }
					dotState={ emailDotState }
				/>
			</div>
		</div>
	);
}
