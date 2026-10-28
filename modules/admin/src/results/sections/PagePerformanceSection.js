/**
 * Page Performance — Pro analytics section.
 *
 * Surfaces "which pages are generating submissions, spam, and
 * high-priority leads" for forms embedded across multiple pages on
 * the same site. Driven by the entries table's existing `page_url`
 * column (server-recorded from HTTP_REFERER), so existing entries
 * with a referrer surface immediately — no submission-side change
 * is required.
 *
 * Pro-gated: when the user's install has not unlocked
 * page_performance_analytics, the section renders an upsell card
 * explaining the feature. Pro flips the capability via its
 * Unlocker — no Pro-bundle JS imports needed here, the UI gating
 * reads the Lite-localized capability snapshot.
 *
 * Layout:
 *   - Top cards: top page · highest priority · spam-heavy · untracked
 *   - Main rows: page meta + entries + spam % + priority bar + share
 *   - Empty state: "no page data yet" with explanatory copy
 *   - Locked state: subtle Pro upsell when capability is off
 *
 * Data fetch is owned here (the section descriptor passes formId);
 * fetching inside the section keeps buildSections.js focused on
 * shape, not network. Failure is non-fatal — we surface a small
 * inline error and leave the rest of the analytics page intact.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api';

function getCapabilities() {
	const root = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	return ( root.plugin && root.plugin.capabilities ) || {};
}

function formatRelative( iso ) {
	if ( ! iso ) return '—';
	try {
		const d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
		if ( Number.isNaN( d.getTime() ) ) return iso;
		const diff = ( Date.now() - d.getTime() ) / 1000;
		if ( diff < 60 )       return __( 'just now', 'neural-forms-lite' );
		if ( diff < 3600 )     return Math.floor( diff / 60 ) + 'm ago';
		if ( diff < 86400 )    return Math.floor( diff / 3600 ) + 'h ago';
		if ( diff < 86400 * 7) return Math.floor( diff / 86400 ) + 'd ago';
		return d.toLocaleDateString();
	} catch ( e ) {
		return iso;
	}
}

function entriesLink( formId ) {
	return 'admin.php?page=neural-forms-entries&form_id=' + Number( formId );
}

function PriorityBar( { high, medium, low, total } ) {
	if ( total <= 0 ) return null;
	const pct = ( n ) => ( n / total ) * 100;
	return (
		<div className="nf-pp__pri-bar" aria-hidden="true">
			{ high   > 0 && <span className="nf-pp__pri-bar-seg nf-pp__pri-bar-seg--high"  style={ { width: pct( high  ) + '%' } } /> }
			{ medium > 0 && <span className="nf-pp__pri-bar-seg nf-pp__pri-bar-seg--med"   style={ { width: pct( medium) + '%' } } /> }
			{ low    > 0 && <span className="nf-pp__pri-bar-seg nf-pp__pri-bar-seg--low"   style={ { width: pct( low   ) + '%' } } /> }
		</div>
	);
}

function VolumeBar( { value, max } ) {
	if ( max <= 0 ) return null;
	const pct = Math.max( 4, Math.round( ( value / max ) * 100 ) );
	return (
		<div className="nf-pp__vol-bar" aria-hidden="true">
			<span className="nf-pp__vol-bar-fill" style={ { width: pct + '%' } } />
		</div>
	);
}

function LockedCard() {
	return (
		<section className="nf-pp nf-pp--locked">
			<header className="nf-pp__head">
				<h3 className="nf-pp__title">{ __( 'Page Performance', 'neural-forms-lite' ) }</h3>
				<span className="nf-pp__pro-pill">{ __( 'Pro', 'neural-forms-lite' ) }</span>
			</header>
			<p className="nf-pp__desc">
				{ __(
					'See which pages are generating submissions, spam, and high-priority leads when the same form is embedded across multiple pages.',
					'neural-forms-lite'
				) }
			</p>
			<a
				className="nf-pp__upgrade"
				href="https://neuralforms.ai/pricing/"
				target="_blank"
				rel="noreferrer"
			>
				{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
			</a>
		</section>
	);
}

function EmptyCard() {
	return (
		<section className="nf-pp">
			<header className="nf-pp__head">
				<h3 className="nf-pp__title">{ __( 'Page Performance', 'neural-forms-lite' ) }</h3>
			</header>
			<p className="nf-pp__desc">
				{ __( 'No page data yet.', 'neural-forms-lite' ) }
				{ ' ' }
				{ __(
					'Page performance will appear after new submissions are received. Existing entries created before this feature may not include page source data.',
					'neural-forms-lite'
				) }
			</p>
		</section>
	);
}

export default function PagePerformanceSection( { formId } ) {
	const caps = useMemo( getCapabilities, [] );
	const unlocked = !! caps.page_performance_analytics;

	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error,   setError   ] = useState( '' );

	useEffect( () => {
		if ( ! unlocked || ! formId ) return;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		api( `/forms/${ Number( formId ) }/analytics/page-performance` )
			.then( ( res ) => {
				if ( cancelled ) return;
				setData( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || __( 'Could not load page performance.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ formId, unlocked ] );

	if ( ! unlocked ) return <LockedCard />;
	if ( ! formId )   return null;

	if ( loading && ! data ) {
		return (
			<section className="nf-pp">
				<header className="nf-pp__head">
					<h3 className="nf-pp__title">{ __( 'Page Performance', 'neural-forms-lite' ) }</h3>
				</header>
				<p className="nf-pp__desc">{ __( 'Loading...', 'neural-forms-lite' ) }</p>
			</section>
		);
	}

	if ( error ) {
		return (
			<section className="nf-pp">
				<header className="nf-pp__head">
					<h3 className="nf-pp__title">{ __( 'Page Performance', 'neural-forms-lite' ) }</h3>
				</header>
				<p className="nf-pp__error">{ error }</p>
			</section>
		);
	}

	const pages       = Array.isArray( data?.pages ) ? data.pages : [];
	const total       = Number( data?.total_entries ) || 0;
	const untracked   = Number( data?.untracked_entries ) || 0;
	const trackedRows = pages.filter( ( p ) => p.page_key !== '__unknown__' );
	const unknownRow  = pages.find( ( p ) => p.page_key === '__unknown__' );

	if ( total === 0 || pages.length === 0 ) return <EmptyCard />;

	// Top-card derivations.
	const topPage     = trackedRows[ 0 ] || null;
	const topPriority = [ ...trackedRows ].sort( ( a, b ) => b.high_priority - a.high_priority )[ 0 ] || null;
	const spamHeavy   = [ ...trackedRows ].sort( ( a, b ) => b.spam_rate     - a.spam_rate )[ 0 ] || null;

	const maxEntries = trackedRows.reduce( ( m, p ) => Math.max( m, p.entries ), 0 );

	return (
		<section className="nf-pp">
			<header className="nf-pp__head">
				<h3 className="nf-pp__title">{ __( 'Page Performance', 'neural-forms-lite' ) }</h3>
				<p className="nf-pp__sub">
					{ __( 'See which pages are generating submissions, spam, and high-priority leads.', 'neural-forms-lite' ) }
				</p>
			</header>

			<div className="nf-pp__cards">
				{ topPage && (
					<div className="nf-pp__card nf-pp__card--primary">
						<div className="nf-pp__card-label">{ __( 'Top page', 'neural-forms-lite' ) }</div>
						<div className="nf-pp__card-page" title={ topPage.page_url }>
							{ topPage.page_path || topPage.page_url || '—' }
						</div>
						<div className="nf-pp__card-stat">{ topPage.entries } { __( 'entries', 'neural-forms-lite' ) }</div>
					</div>
				) }
				{ topPriority && topPriority.high_priority > 0 && (
					<div className="nf-pp__card">
						<div className="nf-pp__card-label">{ __( 'Highest priority page', 'neural-forms-lite' ) }</div>
						<div className="nf-pp__card-page" title={ topPriority.page_url }>
							{ topPriority.page_path || topPriority.page_url || '—' }
						</div>
						<div className="nf-pp__card-stat">
							{ topPriority.high_priority } { __( 'high', 'neural-forms-lite' ) }
							<span className="nf-pp__rate">{ topPriority.high_priority_rate.toFixed( 1 ) }%</span>
						</div>
					</div>
				) }
				{ spamHeavy && spamHeavy.spam_rate > 0 && (
					<div className="nf-pp__card">
						<div className="nf-pp__card-label">{ __( 'Spam-heavy page', 'neural-forms-lite' ) }</div>
						<div className="nf-pp__card-page" title={ spamHeavy.page_url }>
							{ spamHeavy.page_path || spamHeavy.page_url || '—' }
						</div>
						<div className="nf-pp__card-stat">
							{ spamHeavy.spam } { __( 'spam', 'neural-forms-lite' ) }
							<span className="nf-pp__rate nf-pp__rate--warn">{ spamHeavy.spam_rate.toFixed( 1 ) }%</span>
						</div>
					</div>
				) }
				{ untracked > 0 && (
					<div className="nf-pp__card nf-pp__card--muted">
						<div className="nf-pp__card-label">{ __( 'Unknown source', 'neural-forms-lite' ) }</div>
						<div className="nf-pp__card-page">{ __( 'No referrer recorded', 'neural-forms-lite' ) }</div>
						<div className="nf-pp__card-stat">{ untracked } { __( 'entries', 'neural-forms-lite' ) }</div>
					</div>
				) }
			</div>

			<table className="nf-pp__table">
				<thead>
					<tr>
						<th className="nf-pp__th nf-pp__th--page">{ __( 'Page', 'neural-forms-lite' ) }</th>
						<th className="nf-pp__th">{ __( 'Entries', 'neural-forms-lite' ) }</th>
						<th className="nf-pp__th">{ __( 'Spam', 'neural-forms-lite' ) }</th>
						<th className="nf-pp__th">{ __( 'Priority', 'neural-forms-lite' ) }</th>
						<th className="nf-pp__th">{ __( 'Share', 'neural-forms-lite' ) }</th>
						<th className="nf-pp__th">{ __( 'Last entry', 'neural-forms-lite' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ trackedRows.map( ( p ) => (
						<tr key={ p.page_key } className="nf-pp__tr">
							<td className="nf-pp__td nf-pp__td--page">
								<div className="nf-pp__page-title">
									{ p.page_path || p.page_url || '—' }
								</div>
								{ p.page_url && (
									<a
										className="nf-pp__page-url"
										href={ p.page_url }
										target="_blank"
										rel="noreferrer"
										title={ p.page_url }
									>
										{ p.page_origin }
									</a>
								) }
							</td>
							<td className="nf-pp__td nf-pp__td--num">
								<div className="nf-pp__num">{ p.entries }</div>
								<VolumeBar value={ p.entries } max={ maxEntries } />
							</td>
							<td className="nf-pp__td nf-pp__td--num">
								{ p.spam }
								{ p.spam_rate > 0 && (
									<span className={ 'nf-pp__rate' + ( p.spam_rate >= 20 ? ' nf-pp__rate--warn' : '' ) }>
										{ p.spam_rate.toFixed( 1 ) }%
									</span>
								) }
							</td>
							<td className="nf-pp__td">
								<div className="nf-pp__pri-counts">
									<span className="nf-pp__pri-pill nf-pp__pri-pill--high">{ p.high_priority }</span>
									<span className="nf-pp__pri-pill nf-pp__pri-pill--med">{ p.medium_priority }</span>
									<span className="nf-pp__pri-pill nf-pp__pri-pill--low">{ p.low_priority }</span>
								</div>
								<PriorityBar
									high={ p.high_priority }
									medium={ p.medium_priority }
									low={ p.low_priority }
									total={ p.high_priority + p.medium_priority + p.low_priority }
								/>
							</td>
							<td className="nf-pp__td nf-pp__td--num">{ p.conversion_share.toFixed( 1 ) }%</td>
							<td className="nf-pp__td">{ formatRelative( p.last_entry_at ) }</td>
						</tr>
					) ) }
					{ unknownRow && (
						<tr key="__unknown__" className="nf-pp__tr nf-pp__tr--muted">
							<td className="nf-pp__td nf-pp__td--page">
								<div className="nf-pp__page-title">{ __( 'Unknown page', 'neural-forms-lite' ) }</div>
								<div className="nf-pp__page-url">{ __( 'No referrer recorded', 'neural-forms-lite' ) }</div>
							</td>
							<td className="nf-pp__td nf-pp__td--num">{ unknownRow.entries }</td>
							<td className="nf-pp__td nf-pp__td--num">{ unknownRow.spam }</td>
							<td className="nf-pp__td">—</td>
							<td className="nf-pp__td nf-pp__td--num">{ unknownRow.conversion_share.toFixed( 1 ) }%</td>
							<td className="nf-pp__td">{ formatRelative( unknownRow.last_entry_at ) }</td>
						</tr>
					) }
				</tbody>
			</table>

			<p className="nf-pp__cta">
				<a className="nf-pp__cta-link" href={ entriesLink( formId ) }>
					{ __( 'View all entries for this form', 'neural-forms-lite' ) }
				</a>
			</p>
		</section>
	);
}
