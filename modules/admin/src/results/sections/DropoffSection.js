/**
 * Drop-off — Pro analytics section (1.3.0).
 *
 * Where visitors give up on a form: a funnel (Viewed → Started → each
 * step → Submitted) and the last field people touched before leaving.
 * Works for single-page forms too (no step bars). Recording is free in
 * Lite; this report needs the `form_dropoff_analytics` capability and
 * otherwise shows the upsell card in the same spot.
 *
 * GET /forms/{id}/analytics/dropoff?days=7|30|90 — see FormFunnel::report().
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api';

const PERIODS = [ 7, 30, 90 ];

function getCapabilities() {
	const root = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	return ( root.plugin && root.plugin.capabilities ) || {};
}

function Shell( { children, period, onPeriod } ) {
	return (
		<section className="nf-pp nf-drop">
			<header className="nf-pp__head nf-drop__head">
				<div>
					<h3 className="nf-pp__title">{ __( 'Drop-off', 'neural-forms-lite' ) }</h3>
					<p className="nf-pp__sub">{ __( 'Where visitors stop filling in this form.', 'neural-forms-lite' ) }</p>
				</div>
				{ onPeriod && (
					<div className="nf-drop__periods" role="group" aria-label={ __( 'Period', 'neural-forms-lite' ) }>
						{ PERIODS.map( ( d ) => (
							<button
								key={ d }
								type="button"
								className={ 'nf-drop__period' + ( d === period ? ' is-active' : '' ) }
								aria-pressed={ d === period }
								onClick={ () => onPeriod( d ) }
							>
								{ /* translators: %d: number of days */ sprintf( __( '%d days', 'neural-forms-lite' ), d ) }
							</button>
						) ) }
					</div>
				) }
			</header>
			{ children }
		</section>
	);
}

function LockedCard() {
	return (
		<section className="nf-pp nf-pp--locked">
			<header className="nf-pp__head">
				<h3 className="nf-pp__title">{ __( 'Drop-off', 'neural-forms-lite' ) }</h3>
				<span className="nf-pp__pro-pill">{ __( 'Pro', 'neural-forms-lite' ) }</span>
			</header>
			<p className="nf-pp__desc">
				{ __(
					'See which step or field people quit on: a funnel from first view to submit, and the last field touched before visitors left. Your form is already collecting this data.',
					'neural-forms-lite'
				) }
			</p>
			<a className="nf-pp__upgrade" href="https://neuralforms.ai/pricing/" target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
			</a>
		</section>
	);
}

function Funnel( { stages, enough } ) {
	const max = stages.reduce( ( m, s ) => Math.max( m, Number( s.count ) || 0 ), 0 );
	return (
		<ol className="nf-drop__funnel">
			{ stages.map( ( s ) => {
				const n   = Number( s.count ) || 0;
				const pct = max > 0 ? Math.max( 2, Math.round( ( n / max ) * 100 ) ) : 0;
				return (
					<li key={ s.key } className="nf-drop__stage">
						<span className="nf-drop__label" title={ s.label }>
							{ s.label }
							{ s.conditional && (
								<span className="nf-drop__hint" title={ __( 'Conditional logic can skip this step, so fewer people reaching it is expected.', 'neural-forms-lite' ) }>
									{ __( 'conditional', 'neural-forms-lite' ) }
								</span>
							) }
						</span>
						<span className="nf-drop__bar" aria-hidden="true">
							<span className="nf-drop__bar-fill" style={ { width: pct + '%' } } />
						</span>
						<span className="nf-drop__count">{ n.toLocaleString() }</span>
						<span className="nf-drop__loss">
							{ enough && s.drop !== null && s.drop !== undefined && s.drop > 0
								? '−' + s.drop + '%'
								: '' }
						</span>
					</li>
				);
			} ) }
		</ol>
	);
}

function QuitFields( { rows, enough, quits } ) {
	if ( ! rows.length ) {
		return <p className="nf-pp__desc">{ __( 'Nobody has left this form part-way yet.', 'neural-forms-lite' ) }</p>;
	}
	return (
		<table className="nf-pp__table nf-drop__table">
			<thead>
				<tr>
					<th className="nf-pp__th">{ __( 'Field', 'neural-forms-lite' ) }</th>
					<th className="nf-pp__th">{ __( 'People who left', 'neural-forms-lite' ) }</th>
					<th className="nf-pp__th">{ __( 'Share', 'neural-forms-lite' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( r ) => (
					<tr key={ r.node }>
						<td className={ 'nf-pp__td' + ( r.removed ? ' nf-drop__removed' : '' ) }>{ r.label }</td>
						<td className="nf-pp__td nf-pp__td--num">{ Number( r.count ).toLocaleString() }</td>
						<td className="nf-pp__td nf-pp__td--num">
							{ enough && r.share !== null ? r.share + '%' : '—' }
						</td>
					</tr>
				) ) }
			</tbody>
			<tfoot>
				<tr>
					<td className="nf-pp__td nf-drop__foot" colSpan={ 3 }>
						{ sprintf(
							/* translators: %d: number of visitors who left */
							_n(
								'%d visitor started and left without submitting. "Last field touched" is where they were, not proof that field made them leave.',
								'%d visitors started and left without submitting. "Last field touched" is where they were, not proof that field made them leave.',
								quits,
								'neural-forms-lite'
							),
							quits
						) }
					</td>
				</tr>
			</tfoot>
		</table>
	);
}

export default function DropoffSection( { formId } ) {
	const caps     = useMemo( getCapabilities, [] );
	const unlocked = !! caps.form_dropoff_analytics;

	const [ period, setPeriod ] = useState( 30 );
	const [ data, setData ]     = useState( null );
	const [ error, setError ]   = useState( '' );

	useEffect( () => {
		if ( ! unlocked || ! formId ) return undefined;
		let cancelled = false;
		setError( '' );
		api( `/forms/${ Number( formId ) }/analytics/dropoff?days=${ period }` )
			.then( ( res ) => { if ( ! cancelled ) setData( res ); } )
			.catch( ( err ) => {
				if ( ! cancelled ) setError( err?.message || __( 'Could not load drop-off data.', 'neural-forms-lite' ) );
			} );
		return () => { cancelled = true; };
	}, [ formId, unlocked, period ] );

	if ( ! unlocked ) return <LockedCard />;
	if ( ! formId ) return null;

	if ( error ) {
		return <Shell><p className="nf-pp__error">{ error }</p></Shell>;
	}
	if ( ! data ) {
		return <Shell><p className="nf-pp__desc">{ __( 'Loading...', 'neural-forms-lite' ) }</p></Shell>;
	}
	if ( ! data.tracking ) {
		return (
			<Shell>
				<p className="nf-pp__desc">
					{ __( 'No drop-off data yet. It is recorded when visitors start filling in the published form (your own visits as an admin are not counted).', 'neural-forms-lite' ) }
				</p>
			</Shell>
		);
	}

	const enough  = !! data.enough_data;
	const stages  = Array.isArray( data.funnel ) ? data.funnel : [];
	const fields  = Array.isArray( data.quit_fields ) ? data.quit_fields : [];
	const biggest = data.biggest;

	return (
		<Shell period={ period } onPeriod={ ( d ) => { setData( null ); setPeriod( d ); } }>
			{ biggest && (
				<p className="nf-drop__callout">
					{ sprintf(
						/* translators: 1: percentage, 2: step or field name */
						__( '%1$s%% of visitors leave at %2$s.', 'neural-forms-lite' ),
						biggest.drop,
						biggest.at
					) }
				</p>
			) }
			{ ! enough && (
				<p className="nf-drop__note">
					{ sprintf(
						/* translators: %d: minimum number of visitors */
						__( 'Not enough data yet: percentages appear once %d visitors have started this form in the selected period.', 'neural-forms-lite' ),
						Number( data.min_sample ) || 20
					) }
				</p>
			) }

			<Funnel stages={ stages } enough={ enough } />

			<h4 className="nf-drop__subtitle">{ __( 'Fields people quit on', 'neural-forms-lite' ) }</h4>
			<QuitFields rows={ fields } enough={ enough } quits={ Number( data.quits ) || 0 } />

			{ data.since && (
				<p className="nf-drop__since">
					{ sprintf(
						/* translators: %s: date */
						__( 'Tracking since %s. Submissions count from that day only.', 'neural-forms-lite' ),
						new Date( data.since + 'T00:00:00Z' ).toLocaleDateString()
					) }
				</p>
			) }
		</Shell>
	);
}
