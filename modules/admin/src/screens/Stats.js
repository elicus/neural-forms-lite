/**
 * Stats screen — admin dashboard for Neural Forms.
 *
 * Layout (top → bottom):
 *
 *   ┌──────────────────────────────────────────────────────────┐
 *   │ Stats                                  [Today][7d][30d…] │
 *   │ Track recent activity, AI usage, …                       │
 *   ├──────────────────────────────────────────────────────────┤
 *   │ 6 KPI cards                                              │
 *   │   Total Forms · Entries · Unread · Needs Reply ·         │
 *   │   Spam Detected · Failed Deliveries                      │
 *   ├──────────────────────────────────────────────────────────┤
 *   │ Entries over time (inline SVG bars)                      │
 *   ├──────────────────────────┬───────────────────────────────┤
 *   │ Needs Attention          │ AI Insights                   │
 *   │ (clickable rows)         │ (counters + quick links)      │
 *   ├──────────────────────────┼───────────────────────────────┤
 *   │ Top Forms                │ Integration Health            │
 *   │ (table)                  │ (status dots; Pro-only data)  │
 *   ├──────────────────────────┴───────────────────────────────┤
 *   │ Recent Entries (last 8, links to entry detail)           │
 *   ├──────────────────────────────────────────────────────────┤
 *   │ System Activity (rebranded log card)                     │
 *   ├──────────────────────────────────────────────────────────┤
 *   │ • AI Engine • Queue • Email • Integrations • Cron        │
 *   └──────────────────────────────────────────────────────────┘
 *
 * All data lands in a single `GET /stats?range=…` request — see
 * StatsEndpoint::get_stats for the response shape. Integration
 * Health is filled by Pro via the `neural_forms_integration_health`
 * filter; the card hides itself when the providers map is empty
 * (Lite-only install).
 *
 * Class naming: every element uses BEM under `.neural-forms-stats`
 * so the per-screen `src/stats.css` bundle styles them without
 * leaking into other admin bundles.
 */

import { useEffect, useState, useMemo, useCallback } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import api from '../api';

// Range options — must match the StatsEndpoint::RANGES allowlist on
// the PHP side. `today` is the 1-day window; the others map to their
// numeric day count. Default is 7d.
const RANGE_OPTIONS = [
	{ key: 'today', label: 'Today' },
	{ key: '7d',    label: '7 days' },
	{ key: '30d',   label: '30 days' },
	{ key: '90d',   label: '90 days' },
];

const DEFAULT_RANGE = '7d';

// Admin URLs are localized as `window.neuralFormsAdmin.admin.ajaxUrl`
// but we need the wp-admin base for entry/inbox deep links. Compute
// from ajaxUrl by stripping `/admin-ajax.php` so we don't rely on a
// new localized key.
function readAdminBase() {
	const ajax = ( typeof window !== 'undefined'
		&& window.neuralFormsAdmin?.admin?.ajaxUrl )
		|| '';
	return ajax.replace( /\/admin-ajax\.php(?:\?.*)?$/, '/' );
}

const ADMIN_BASE = readAdminBase();

function entriesUrl( params = {} ) {
	const usp = new URLSearchParams( { page: 'neural-forms-entries', ...params } );
	return `${ ADMIN_BASE }admin.php?${ usp.toString() }`;
}

function entryUrl( id ) {
	return entriesUrl( { entry_id: String( id ) } );
}

function settingsUrl( anchor = '' ) {
	return `${ ADMIN_BASE }admin.php?page=neural-forms-settings${ anchor }`;
}

function integrationsUrl() {
	return `${ ADMIN_BASE }admin.php?page=neural-forms-integrations`;
}

function formatDateTime( s ) {
	if ( ! s ) return '';
	const d = new Date( String( s ).replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( d.getTime() ) ) return s;
	return d.toLocaleString();
}

function formatShortDate( ymd ) {
	if ( ! ymd ) return '';
	const d = new Date( ymd + 'T00:00:00Z' );
	if ( Number.isNaN( d.getTime() ) ) return ymd;
	return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
}

function formatNumber( n ) {
	const v = Number( n );
	if ( ! Number.isFinite( v ) ) return '0';
	return v.toLocaleString();
}

// ─── Stat card ──────────────────────────────────────────────────

function StatCard( { label, value, helper, intent } ) {
	const cls = 'neural-forms-stats__metric'
		+ ( intent ? ' neural-forms-stats__metric--' + intent : '' );
	return (
		<div className={ cls }>
			<div className="neural-forms-stats__metric-value">{ value }</div>
			<div className="neural-forms-stats__metric-label">{ label }</div>
			{ helper && (
				<div className="neural-forms-stats__metric-helper">{ helper }</div>
			) }
		</div>
	);
}

// ─── Entries trend (inline SVG bars) ────────────────────────────

function TrendChart( { points } ) {
	if ( ! Array.isArray( points ) || points.length === 0 ) {
		return (
			<p className="neural-forms-stats__empty">
				{ __( 'No entries in this period.', 'neural-forms-lite' ) }
			</p>
		);
	}

	const max = Math.max( 1, ...points.map( ( p ) => Number( p.entries ) || 0 ) );
	const total = points.reduce( ( s, p ) => s + ( Number( p.entries ) || 0 ), 0 );

	return (
		<div className="neural-forms-stats__trend">
			<div className="neural-forms-stats__trend-total">
				{ sprintf(
					/* translators: %s: total entry count across the range */
					__( '%s entries in range', 'neural-forms-lite' ),
					formatNumber( total )
				) }
			</div>
			<div
				className="neural-forms-stats__trend-bars"
				role="img"
				aria-label={ sprintf(
					/* translators: %s: total entries */
					__( 'Daily entries totaling %s', 'neural-forms-lite' ),
					formatNumber( total )
				) }
			>
				{ points.map( ( p ) => {
					const v   = Number( p.entries ) || 0;
					const pct = max > 0 ? Math.max( 2, Math.round( ( v / max ) * 100 ) ) : 0;
					return (
						<div
							key={ p.date }
							className="neural-forms-stats__trend-col"
							title={ `${ formatShortDate( p.date ) } · ${ formatNumber( v ) }` }
						>
							<div className="neural-forms-stats__trend-bar-track">
								<div
									className="neural-forms-stats__trend-bar-fill"
									style={ { height: pct + '%' } }
								/>
							</div>
							<div className="neural-forms-stats__trend-day">
								{ formatShortDate( p.date ) }
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

// ─── Needs Attention ───────────────────────────────────────────

function NeedsAttention( { items } ) {
	const safeItems = Array.isArray( items ) ? items : [];

	// Group by reason for a compact stack of clickable rows.
	const rows = useMemo( () => {
		const high   = safeItems.filter( ( e ) => e.ai_priority === 'high'   && e.status !== 'spam' && e.status !== 'trash' );
		const reply  = safeItems.filter( ( e ) => Number( e.needs_reply ) === 1 );
		const spam   = safeItems.filter( ( e ) => Number( e.ai_is_spam )  === 1 && Number( e.ai_auto_spam_applied ) === 0 && e.status !== 'spam' && e.status !== 'trash' );
		return [
			{
				key:   'high',
				label: __( 'High priority entries', 'neural-forms-lite' ),
				count: high.length,
				href:  entriesUrl( { view: 'inbox', priority: 'high' } ),
			},
			{
				key:   'reply',
				label: __( 'Entries needing reply', 'neural-forms-lite' ),
				count: reply.length,
				href:  entriesUrl( { view: 'needs_reply' } ),
			},
			{
				key:   'spam',
				label: __( 'Spam suspected (not yet moved)', 'neural-forms-lite' ),
				count: spam.length,
				href:  entriesUrl( { view: 'spam' } ),
			},
		];
	}, [ safeItems ] );

	const hasAny = rows.some( ( r ) => r.count > 0 );

	if ( ! hasAny ) {
		return (
			<p className="neural-forms-stats__empty">
				{ __( 'You are all caught up — nothing waiting on you right now.', 'neural-forms-lite' ) }
			</p>
		);
	}

	return (
		<ul className="neural-forms-stats__attention-list">
			{ rows.map( ( r ) => (
				<li
					key={ r.key }
					className={
						'neural-forms-stats__attention-item'
						+ ( r.count === 0 ? ' is-empty' : '' )
					}
				>
					<a className="neural-forms-stats__attention-link" href={ r.href }>
						<span className="neural-forms-stats__attention-label">{ r.label }</span>
						<span className="neural-forms-stats__attention-count">{ r.count }</span>
					</a>
				</li>
			) ) }
		</ul>
	);
}

// ─── AI Insights ───────────────────────────────────────────────

/**
 * Reads the Pro-capability snapshot Lite localizes at boot. The AI
 * Insights card is sourced entirely from `ai_status = 'complete'`
 * entries, and the analysis pipeline that produces those rows only
 * runs when Pro flips the `ai_process_entries` capability. On Lite
 * the numbers would always be zero, so we render a locked variant
 * with an "Upgrade to Pro" CTA instead.
 */
function readAiCapability() {
	if ( typeof window === 'undefined' ) return false;
	const caps = window.neuralFormsAdmin?.plugin?.capabilities || {};
	return !! caps.ai_process_entries;
}

function readPaymentsCapability() {
	if ( typeof window === 'undefined' ) return false;
	const caps = window.neuralFormsAdmin?.plugin?.capabilities || {};
	return !! caps.payments;
}

function paymentsUrl() {
	return readAdminBase() + 'admin.php?page=neural-forms-payments';
}

function analyticsUrl( formId ) {
	return readAdminBase() + 'admin.php?page=neural-forms-results&form_id=' + Number( formId );
}

/**
 * Payments card (1.2.0 part B). Range-scoped revenue per currency from
 * /stats.payments, the top paying forms and the lifetime "awaiting
 * payment" count. Locked installs get the Pro card; unlocked installs
 * without a single attempt in range get a one-line empty state.
 */
function PaymentsOverview( { payments, helperRange } ) {
	const unlocked = readPaymentsCapability() && payments && payments.unlocked !== false;
	const rows     = unlocked && Array.isArray( payments.rows )      ? payments.rows      : [];
	const top      = unlocked && Array.isArray( payments.top_forms ) ? payments.top_forms : [];
	const awaiting = unlocked ? Number( payments.awaiting_entries ) || 0 : 0;

	return (
		<section className="neural-forms-stats__card neural-forms-stats__payments">
			<header className="neural-forms-stats__card-head">
				<h2 className="neural-forms-stats__card-title">
					{ __( 'Payments', 'neural-forms-lite' ) }
					{ ! unlocked && <span className="neural-forms-stats__card-pro">{ __( 'Pro', 'neural-forms-lite' ) }</span> }
				</h2>
				<span className="neural-forms-stats__card-sub">{ helperRange }</span>
			</header>

			{ ! unlocked && (
				<div className="neural-forms-stats__ai neural-forms-stats__ai--locked">
					<p className="neural-forms-stats__empty">
						{ __( 'Take payments with Stripe or PayPal and see revenue, refunds and the forms that earn the most right here.', 'neural-forms-lite' ) }
					</p>
					<div className="neural-forms-stats__ai-actions">
						<a className="neural-forms-stats__link" href="https://neuralforms.ai/pricing" target="_blank" rel="noopener noreferrer">
							{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
						</a>
					</div>
				</div>
			) }

			{ unlocked && rows.length === 0 && (
				<p className="neural-forms-stats__empty">
					{ awaiting > 0
						? sprintf( __( 'No payment received in this range. %d entries are still awaiting payment.', 'neural-forms-lite' ), awaiting )
						: __( 'No payments in this range yet.', 'neural-forms-lite' ) }
				</p>
			) }

			{ unlocked && rows.map( ( r ) => (
				<div key={ r.currency + ( r.livemode ? '-live' : '-test' ) } className="neural-forms-stats__pay-group">
					{ ( rows.length > 1 || ! r.livemode ) && (
						<div className="neural-forms-stats__pay-label">
							{ r.currency }
							{ ! r.livemode && <span className="neural-forms-stats__pay-test">{ __( 'test mode', 'neural-forms-lite' ) }</span> }
						</div>
					) }
					<div className="neural-forms-stats__metrics neural-forms-stats__metrics--4">
						<StatCard label={ __( 'Net revenue', 'neural-forms-lite' ) } value={ r.formatted.net } helper={ sprintf( __( '%s received', 'neural-forms-lite' ), r.formatted.paid ) } intent="navy" />
						<StatCard label={ __( 'Payments', 'neural-forms-lite' ) } value={ formatNumber( r.paid_count ) } helper={ sprintf( __( '%d failed or cancelled', 'neural-forms-lite' ), r.failed_count ) } />
						<StatCard label={ __( 'Awaiting payment', 'neural-forms-lite' ) } value={ formatNumber( r.open_count ) } helper={ r.formatted.open } />
						<StatCard label={ __( 'Refunded', 'neural-forms-lite' ) } value={ r.formatted.refunded } helper={ __( 'In this range', 'neural-forms-lite' ) } intent={ r.refunded > 0 ? 'danger' : undefined } />
					</div>
				</div>
			) ) }

			{ unlocked && top.length > 0 && (
				<div className="neural-forms-stats__ai-rows">
					{ top.map( ( f ) => (
						<a key={ f.form_id + f.currency } className="neural-forms-stats__insight-row neural-forms-stats__pay-row" href={ analyticsUrl( f.form_id ) }>
							<span className="neural-forms-stats__insight-label">{ f.title || ( '#' + f.form_id ) }{ ! f.livemode && <span className="neural-forms-stats__pay-test">{ __( 'test', 'neural-forms-lite' ) }</span> }</span>
							<span className="neural-forms-stats__insight-value">{ f.formatted } · { sprintf( _n( '%d payment', '%d payments', f.paid_count, 'neural-forms-lite' ), f.paid_count ) }</span>
						</a>
					) ) }
				</div>
			) }

			{ unlocked && (
				<div className="neural-forms-stats__ai-actions">
					<a className="neural-forms-stats__link" href={ paymentsUrl() }>{ __( 'Open the Payments page', 'neural-forms-lite' ) }</a>
					{ awaiting > 0 && <a className="neural-forms-stats__link" href={ entriesUrl( { view: 'awaiting_payment' } ) }>{ sprintf( __( '%d awaiting payment', 'neural-forms-lite' ), awaiting ) }</a> }
				</div>
			) }
		</section>
	);
}

function AiInsights( { ai } ) {
	const canRun = readAiCapability();

	if ( ! canRun ) {
		return (
			<div className="neural-forms-stats__ai neural-forms-stats__ai--locked">
				<p className="neural-forms-stats__empty">
					{ __( 'Neural AI insights are available in Neural Forms Pro. Pro analyses every entry — category, priority, spam, sentiment — and surfaces the rollup here.', 'neural-forms-lite' ) }
				</p>
				<div className="neural-forms-stats__ai-actions">
					<a
						className="neural-forms-stats__link"
						href="https://neuralforms.ai/pricing"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
					</a>
				</div>
			</div>
		);
	}

	const safe = ai && typeof ai === 'object' ? ai : {};
	return (
		<div className="neural-forms-stats__ai">
			<div className="neural-forms-stats__ai-rows">
				<InsightRow label={ __( 'Analyzed',      'neural-forms-lite' ) } value={ formatNumber( safe.analyzed       ?? 0 ) } />
				<InsightRow label={ __( 'High priority', 'neural-forms-lite' ) } value={ formatNumber( safe.high_priority  ?? 0 ) } />
				<InsightRow label={ __( 'Needs reply',   'neural-forms-lite' ) } value={ formatNumber( safe.needs_reply    ?? 0 ) } />
				<InsightRow label={ __( 'Spam detected', 'neural-forms-lite' ) } value={ formatNumber( safe.spam_detected  ?? 0 ) } />
				<InsightRow
					label={ __( 'Top category', 'neural-forms-lite' ) }
					value={ safe.top_category || __( 'No data yet', 'neural-forms-lite' ) }
					badge={ !! safe.top_category }
				/>
				<InsightRow
					label={ __( 'Sentiment', 'neural-forms-lite' ) }
					value={ safe.sentiment || __( 'Not tracked', 'neural-forms-lite' ) }
				/>
			</div>
			<div className="neural-forms-stats__ai-actions">
				<a className="neural-forms-stats__link" href={ entriesUrl( { view: 'inbox', priority: 'high' } ) }>
					{ __( 'View high priority', 'neural-forms-lite' ) }
				</a>
				<a className="neural-forms-stats__link" href={ entriesUrl( { view: 'needs_reply' } ) }>
					{ __( 'View needs reply', 'neural-forms-lite' ) }
				</a>
				<a className="neural-forms-stats__link" href={ entriesUrl( { view: 'spam' } ) }>
					{ __( 'View spam', 'neural-forms-lite' ) }
				</a>
			</div>
		</div>
	);
}

function InsightRow( { label, value, badge } ) {
	const valueCls = 'neural-forms-stats__insight-value'
		+ ( badge ? ' neural-forms-stats__insight-value--badge' : '' );
	return (
		<div className="neural-forms-stats__insight-row">
			<span className="neural-forms-stats__insight-label">{ label }</span>
			<span className={ valueCls }>{ value }</span>
		</div>
	);
}

// ─── Top Forms ─────────────────────────────────────────────────

function TopForms( { forms } ) {
	const rows = Array.isArray( forms ) ? forms : [];
	if ( rows.length === 0 ) {
		return (
			<p className="neural-forms-stats__empty">
				{ __( 'No form submissions in this period.', 'neural-forms-lite' ) }
			</p>
		);
	}
	return (
		<table className="neural-forms-stats__top-forms">
			<thead>
				<tr>
					<th>{ __( 'Form',        'neural-forms-lite' ) }</th>
					<th>{ __( 'Entries',     'neural-forms-lite' ) }</th>
					<th>{ __( 'Unread',      'neural-forms-lite' ) }</th>
					<th>{ __( 'Needs Reply', 'neural-forms-lite' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( f ) => (
					<tr key={ f.form_id }>
						<td className="neural-forms-stats__top-forms-name">
							<a href={ entriesUrl( { form_id: String( f.form_id ), view: 'inbox' } ) }>
								{ f.title || sprintf( __( 'Form #%d', 'neural-forms-lite' ), f.form_id ) }
							</a>
						</td>
						<td>{ formatNumber( f.entries     ?? 0 ) }</td>
						<td>{ formatNumber( f.unread      ?? 0 ) }</td>
						<td>{ formatNumber( f.needs_reply ?? 0 ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

// ─── Integration Health ────────────────────────────────────────

const INTEGRATION_LABELS = {
	hubspot:      'HubSpot',
	whatsapp:     'WhatsApp',
	mailchimp:    'Mailchimp',
	google_forms: 'Google Forms',
	zapier:       'Zapier',
};

function IntegrationHealth( { health } ) {
	const providers = ( health && typeof health === 'object' && health.providers ) || {};
	const keys = Object.keys( providers );
	if ( keys.length === 0 ) return null;

	return (
		<ul className="neural-forms-stats__integrations">
			{ keys.map( ( slug ) => {
				const row = providers[ slug ] || {};
				const status  = String( row.status || ( row.configured ? 'connected' : 'not_configured' ) );
				const message = String( row.message || '' );
				let dotState = 'off';
				if ( status === 'connected' )       dotState = 'ok';
				if ( status === 'needs_attention' ) dotState = 'error';
				return (
					<li key={ slug } className="neural-forms-stats__integration">
						<span className={ 'neural-forms-stats__status-dot neural-forms-stats__status-dot--' + dotState } aria-hidden="true" />
						<span className="neural-forms-stats__integration-name">
							{ INTEGRATION_LABELS[ slug ] || slug }
						</span>
						<span className="neural-forms-stats__integration-status">
							{ status === 'connected'        && __( 'Connected',     'neural-forms-lite' ) }
							{ status === 'not_configured'   && __( 'Not configured','neural-forms-lite' ) }
							{ status === 'needs_attention'  && ( message || __( 'Needs attention', 'neural-forms-lite' ) ) }
							{ ! [ 'connected', 'not_configured', 'needs_attention' ].includes( status ) && status }
						</span>
					</li>
				);
			} ) }
		</ul>
	);
}

// ─── Recent Entries ────────────────────────────────────────────

const STATUS_PILLS = {
	high_priority: { label: 'High priority', cls: 'is-high' },
	needs_reply:   { label: 'Needs reply',   cls: 'is-reply' },
	spam:          { label: 'Spam',          cls: 'is-spam' },
	normal:        { label: 'Normal',        cls: 'is-normal' },
};

function RecentEntries( { entries } ) {
	const rows = Array.isArray( entries ) ? entries : [];
	if ( rows.length === 0 ) {
		return (
			<p className="neural-forms-stats__empty">
				{ __( 'No entries yet.', 'neural-forms-lite' ) }
			</p>
		);
	}
	return (
		<ul className="neural-forms-stats__recent">
			{ rows.map( ( e ) => {
				const pill = STATUS_PILLS[ e.status ] || STATUS_PILLS.normal;
				return (
					<li key={ e.entry_id } className="neural-forms-stats__recent-row">
						<a className="neural-forms-stats__recent-link" href={ entryUrl( e.entry_id ) }>
							<span className="neural-forms-stats__recent-form">{ e.form_title || sprintf( __( 'Form #%d', 'neural-forms-lite' ), e.form_id ) }</span>
							<span className="neural-forms-stats__recent-submitter">
								{ e.submitter || sprintf( __( 'Entry #%d', 'neural-forms-lite' ), e.entry_id ) }
							</span>
							<span className={ 'neural-forms-stats__recent-pill ' + pill.cls }>{ pill.label }</span>
							<span className="neural-forms-stats__recent-time">{ formatDateTime( e.created_at ) }</span>
						</a>
					</li>
				);
			} ) }
		</ul>
	);
}

// ─── System Activity ──────────────────────────────────────────

function SystemActivity( { activity } ) {
	const rows = Array.isArray( activity ) ? activity : [];
	if ( rows.length === 0 ) {
		return (
			<p className="neural-forms-stats__empty">
				{ __( 'No system events yet.', 'neural-forms-lite' ) }
			</p>
		);
	}
	return (
		<ul className="neural-forms-stats__activity">
			{ rows.slice( 0, 8 ).map( ( a ) => {
				const isError = String( a.status || 'info' ) === 'error';
				return (
					<li key={ a.id || a.created_at } className={ 'neural-forms-stats__activity-item' + ( isError ? ' is-error' : '' ) }>
						<span className={ 'neural-forms-stats__status-dot neural-forms-stats__status-dot--' + ( isError ? 'error' : 'ok' ) } aria-hidden="true" />
						<span className="neural-forms-stats__activity-label">{ a.message || a.event_type }</span>
						<span className="neural-forms-stats__activity-time">{ formatDateTime( a.created_at ) }</span>
					</li>
				);
			} ) }
		</ul>
	);
}

// ─── System Health pills ──────────────────────────────────────

function SystemHealth( { system } ) {
	const s = system && typeof system === 'object' ? system : {};
	const aiOn        = String( s.ai_engine || 'on' ) === 'on';
	const queueState  = ( Number( s.queue                ) || 0 ) > 0 ? 'warn'  : 'ok';
	const emailState  = ( Number( s.email_failures       ) || 0 ) > 0 ? 'error' : 'ok';
	const integState  = ( Number( s.integration_failures ) || 0 ) > 0 ? 'error' : 'ok';

	const pills = [
		{ label: __( 'Neural AI engine',           'neural-forms-lite' ), value: aiOn ? __( 'On', 'neural-forms-lite' ) : __( 'Off', 'neural-forms-lite' ), dot: aiOn ? 'ok' : 'off' },
		{ label: __( 'Queue',               'neural-forms-lite' ), value: formatNumber( s.queue                ?? 0 ),                                dot: queueState },
		{ label: __( 'Email failures',      'neural-forms-lite' ), value: formatNumber( s.email_failures       ?? 0 ),                                dot: emailState },
		{ label: __( 'Integration failures','neural-forms-lite' ), value: formatNumber( s.integration_failures ?? 0 ),                                dot: integState },
		{ label: __( 'Last cron run',       'neural-forms-lite' ), value: s.last_cron_run ? formatDateTime( s.last_cron_run ) : __( '—', 'neural-forms-lite' ), dot: s.last_cron_run ? 'ok' : 'off' },
	];

	return (
		<div className="neural-forms-stats__system">
			{ pills.map( ( p ) => (
				<div key={ p.label } className="neural-forms-stats__system-item">
					<span className={ 'neural-forms-stats__status-dot neural-forms-stats__status-dot--' + p.dot } aria-hidden="true" />
					<span className="neural-forms-stats__system-label">{ p.label }</span>
					<span className="neural-forms-stats__system-value">{ p.value }</span>
				</div>
			) ) }
		</div>
	);
}

// ─── Screen ────────────────────────────────────────────────────

export default function Stats() {
	const [ range,   setRange   ] = useState( DEFAULT_RANGE );
	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error,   setError   ] = useState( '' );

	const loadData = useCallback( ( r ) => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		api( '/stats?range=' + encodeURIComponent( r ) )
			.then( ( res ) => {
				if ( cancelled ) return;
				setData( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || __( 'Could not load stats.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [] );

	useEffect( () => loadData( range ), [ range, loadData ] );

	const summary = data?.summary || {};
	const ai      = data?.ai      || {};
	const trend   = Array.isArray( data?.trend )           ? data.trend           : [];
	const top     = Array.isArray( data?.top_forms )       ? data.top_forms       : [];
	const recent  = Array.isArray( data?.recent_entries )  ? data.recent_entries  : [];
	const attn    = Array.isArray( data?.needs_attention ) ? data.needs_attention : [];
	const log     = Array.isArray( data?.recent_activity ) ? data.recent_activity : [];
	const health  = data?.integration_health || {};
	const system  = data?.system || {};

	const showSkeleton = loading && ! data;

	const rangeLabel = ( RANGE_OPTIONS.find( ( o ) => o.key === range ) || RANGE_OPTIONS[ 1 ] ).label;
	const helperRange = sprintf(
		/* translators: %s: selected range label (e.g. "7 days") */
		__( 'Last %s', 'neural-forms-lite' ),
		rangeLabel.toLowerCase()
	);

	return (
		<div className="neural-forms-stats">
			<header className="neural-forms-stats__header">
				<div className="neural-forms-stats__header-text">
					<h1 className="neural-forms-stats__title">
						{ __( 'Stats', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-stats__subtitle">
						{ __( 'Track form performance, Neural AI insights, integrations, and system health.', 'neural-forms-lite' ) }
					</p>
				</div>
				<div className="neural-forms-stats__range" role="group" aria-label={ __( 'Date range', 'neural-forms-lite' ) }>
					{ RANGE_OPTIONS.map( ( o ) => (
						<button
							key={ o.key }
							type="button"
							className={ 'neural-forms-stats__range-pill' + ( o.key === range ? ' is-active' : '' ) }
							onClick={ () => setRange( o.key ) }
							aria-pressed={ o.key === range }
						>
							{ o.label }
						</button>
					) ) }
				</div>
			</header>

			{ error && ! data && (
				<div className="neural-forms-stats__error">{ error }</div>
			) }

			{ showSkeleton && (
				<div className="neural-forms-stats__loading">
					{ __( 'Loading…', 'neural-forms-lite' ) }
				</div>
			) }

			{ data && (
				<>
					{ /* 6-card KPI grid */ }
					<div className="neural-forms-stats__metrics">
						<StatCard
							label={ __( 'Total Forms', 'neural-forms-lite' ) }
							value={ formatNumber( summary.total_forms ?? 0 ) }
							helper={ __( 'All forms', 'neural-forms-lite' ) }
							intent="navy"
						/>
						<StatCard
							label={ __( 'Entries', 'neural-forms-lite' ) }
							value={ formatNumber( summary.entries ?? 0 ) }
							helper={ helperRange }
						/>
						<StatCard
							label={ __( 'Unread Entries', 'neural-forms-lite' ) }
							value={ formatNumber( summary.unread ?? 0 ) }
							helper={ __( 'All unread', 'neural-forms-lite' ) }
						/>
						<StatCard
							label={ __( 'Needs Reply', 'neural-forms-lite' ) }
							value={ formatNumber( summary.needs_reply ?? 0 ) }
							helper={ __( 'All open', 'neural-forms-lite' ) }
						/>
						<StatCard
							label={ __( 'Spam Detected', 'neural-forms-lite' ) }
							value={ formatNumber( summary.spam_detected ?? 0 ) }
							helper={ helperRange }
						/>
						<StatCard
							label={ __( 'Failed Deliveries', 'neural-forms-lite' ) }
							value={ formatNumber( summary.failed_deliveries ?? 0 ) }
							helper={ helperRange }
							intent={ ( Number( summary.failed_deliveries ) || 0 ) > 0 ? 'danger' : undefined }
						/>
					</div>

					{ /* Entries trend */ }
					<section className="neural-forms-stats__card">
						<header className="neural-forms-stats__card-head">
							<h2 className="neural-forms-stats__card-title">
								{ __( 'Entries over time', 'neural-forms-lite' ) }
							</h2>
							<span className="neural-forms-stats__card-sub">{ rangeLabel }</span>
						</header>
						<TrendChart points={ trend } />
					</section>

					<PaymentsOverview payments={ data?.payments } helperRange={ helperRange } />

					{ /* Two-column: Needs Attention | AI Insights */ }
					<div className="neural-forms-stats__cols">
						<section className="neural-forms-stats__card neural-forms-stats__card--half">
							<header className="neural-forms-stats__card-head">
								<h2 className="neural-forms-stats__card-title">
									{ __( 'Needs Attention', 'neural-forms-lite' ) }
								</h2>
							</header>
							<NeedsAttention items={ attn } />
						</section>

						<section className="neural-forms-stats__card neural-forms-stats__card--half">
							<header className="neural-forms-stats__card-head">
								<h2 className="neural-forms-stats__card-title">
									{ __( 'Neural AI Insights', 'neural-forms-lite' ) }
									{ /* PRO badge — the entire card sources data
									     from `ai_status = complete` entries
									     which only get produced when Pro flips
									     the ai_process_entries capability. Lite
									     installs see the locked variant rendered
									     by <AiInsights /> below. */ }
									{ ! readAiCapability() && (
										<span className="neural-forms-stats__card-pro">
											{ __( 'PRO', 'neural-forms-lite' ) }
										</span>
									) }
								</h2>
								<span className="neural-forms-stats__card-sub">{ rangeLabel }</span>
							</header>
							<AiInsights ai={ ai } />
						</section>
					</div>

					{ /* Two-column: Top Forms | Integration Health (Pro) */ }
					<div className="neural-forms-stats__cols">
						<section className="neural-forms-stats__card neural-forms-stats__card--half">
							<header className="neural-forms-stats__card-head">
								<h2 className="neural-forms-stats__card-title">
									{ __( 'Top Forms', 'neural-forms-lite' ) }
								</h2>
								<span className="neural-forms-stats__card-sub">{ rangeLabel }</span>
							</header>
							<TopForms forms={ top } />
						</section>

						{ health && health.providers && (
							<section className="neural-forms-stats__card neural-forms-stats__card--half">
								<header className="neural-forms-stats__card-head">
									<h2 className="neural-forms-stats__card-title">
										{ __( 'Integration Health', 'neural-forms-lite' ) }
									</h2>
									<a className="neural-forms-stats__card-link" href={ integrationsUrl() }>
										{ __( 'Manage', 'neural-forms-lite' ) }
									</a>
								</header>
								<IntegrationHealth health={ health } />
							</section>
						) }
					</div>

					{ /* Recent Entries */ }
					<section className="neural-forms-stats__card">
						<header className="neural-forms-stats__card-head">
							<h2 className="neural-forms-stats__card-title">
								{ __( 'Recent Entries', 'neural-forms-lite' ) }
							</h2>
							<a className="neural-forms-stats__card-link" href={ entriesUrl( { view: 'inbox' } ) }>
								{ __( 'Open inbox', 'neural-forms-lite' ) }
							</a>
						</header>
						<RecentEntries entries={ recent } />
					</section>

					{ /* System activity (lower priority — used to be the
					     "Recent Activity" card). */ }
					<section className="neural-forms-stats__card">
						<header className="neural-forms-stats__card-head">
							<h2 className="neural-forms-stats__card-title">
								{ __( 'System Activity', 'neural-forms-lite' ) }
							</h2>
							<a className="neural-forms-stats__card-link" href={ settingsUrl() }>
								{ __( 'Open Settings', 'neural-forms-lite' ) }
							</a>
						</header>
						<SystemActivity activity={ log } />
					</section>

					{ /* System Health pills */ }
					<SystemHealth system={ system } />
				</>
			) }
		</div>
	);
}
