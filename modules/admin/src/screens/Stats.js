/**
 * Stats screen - simplified for 1.1.0.
 *
 * Pulls GET /stats and renders a few aggregate cards plus a recent
 * activity log. No charts, no chart deps. The endpoint already returns
 * everything the cards need:
 *
 *   stats.{entries_7d, unread, needs_reply, spam_detected}
 *   ai_insights.{analyzed_count, high_priority_count, sales_count,
 *                support_count, spam_detected_count, top_category}
 *   recent_activity[]
 *   system_status.{ai_enabled, queue_pending, email_failures_24h}
 *
 * Plus a "Total forms" card sourced from GET /forms (which gives total
 * via X-Total-Count headers and result.total).
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';

function formatDate( s ) {
	if ( ! s ) return '';
	const d = new Date( s.replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( d.getTime() ) ) return s;
	return d.toLocaleString();
}

function Card( { label, value, hint } ) {
	return (
		<div className="nf-stats__card">
			<div className="nf-stats__card-value">{ value ?? '-' }</div>
			<div className="nf-stats__card-label">{ label }</div>
			{ hint && <div className="nf-stats__card-hint">{ hint }</div> }
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
				setFormsTotal( formsRes?.total ?? ( Array.isArray( formsRes?.items ) ? formsRes.items.length : null ) );
			}
			setLoading( false );
		} );

		return () => { cancelled = true; };
	}, [] );

	if ( loading ) {
		return <div className="neural-forms-screen"><p>{ __( 'Loading stats...', 'neural-forms-lite' ) }</p></div>;
	}
	if ( error && ! stats ) {
		return <div className="neural-forms-screen neural-forms-screen--error">{ error }</div>;
	}

	const s   = stats?.stats        || {};
	const ai  = stats?.ai_insights  || {};
	const sys = stats?.system_status|| {};
	const act = Array.isArray( stats?.recent_activity ) ? stats.recent_activity : [];

	return (
		<div className="neural-forms-screen nf-stats">
			<header className="neural-forms-screen__header">
				<h1>{ __( 'Stats', 'neural-forms-lite' ) }</h1>
			</header>

			<section className="nf-stats__grid">
				<Card label={ __( 'Total forms',     'neural-forms-lite' ) } value={ formsTotal ?? '-' } />
				<Card label={ __( 'Entries (7 days)', 'neural-forms-lite' ) } value={ s.entries_7d ?? 0 } />
				<Card label={ __( 'Unread',           'neural-forms-lite' ) } value={ s.unread ?? 0 } />
				<Card label={ __( 'Needs reply',      'neural-forms-lite' ) } value={ s.needs_reply ?? 0 } />
				<Card label={ __( 'Spam detected',    'neural-forms-lite' ) } value={ s.spam_detected ?? 0 } />
			</section>

			<section className="nf-stats__section">
				<h2>{ __( 'AI insights', 'neural-forms-lite' ) }</h2>
				<div className="nf-stats__grid">
					<Card label={ __( 'Analyzed',     'neural-forms-lite' ) } value={ ai.analyzed_count ?? 0 } />
					<Card label={ __( 'High priority', 'neural-forms-lite' ) } value={ ai.high_priority_count ?? 0 } />
					<Card label={ __( 'Sales',        'neural-forms-lite' ) } value={ ai.sales_count ?? 0 } />
					<Card label={ __( 'Support',      'neural-forms-lite' ) } value={ ai.support_count ?? 0 } />
					<Card
						label={ __( 'Top category', 'neural-forms-lite' ) }
						value={ ai.top_category || '-' }
					/>
				</div>
			</section>

			<section className="nf-stats__section">
				<h2>{ __( 'System', 'neural-forms-lite' ) }</h2>
				<div className="nf-stats__grid">
					<Card
						label={ __( 'AI engine', 'neural-forms-lite' ) }
						value={ sys.ai_enabled ? __( 'On', 'neural-forms-lite' ) : __( 'Off', 'neural-forms-lite' ) }
					/>
					<Card label={ __( 'Queue pending',         'neural-forms-lite' ) } value={ sys.queue_pending ?? 0 } />
					<Card label={ __( 'Email failures (24h)',  'neural-forms-lite' ) } value={ sys.email_failures_24h ?? 0 } />
				</div>
			</section>

			<section className="nf-stats__section">
				<h2>{ __( 'Recent activity', 'neural-forms-lite' ) }</h2>
				{ act.length === 0 && (
					<p className="nf-stats__empty">{ __( 'No activity yet.', 'neural-forms-lite' ) }</p>
				) }
				{ act.length > 0 && (
					<ul className="nf-stats__activity">
						{ act.map( ( a ) => (
							<li key={ a.id } className={ 'nf-stats__activity-item nf-stats__activity-item--' + ( a.status || 'info' ) }>
								<span className="nf-stats__activity-type">{ a.event_type }</span>
								<span className="nf-stats__activity-message">{ a.message }</span>
								<span className="nf-stats__activity-time">{ formatDate( a.created_at ) }</span>
							</li>
						) ) }
					</ul>
				) }
			</section>
		</div>
	);
}
