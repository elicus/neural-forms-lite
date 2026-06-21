/**
 * Read-only poll results panel for the builder inspector.
 *
 * Mounts only when the selected field is a poll AND the form has been
 * saved at least once (formId > 0). For unsaved forms it renders the
 * "Save the form to view poll results." note instead.
 *
 * Fetches GET /forms/{id}/polls/{field_id} and renders:
 *   - total votes
 *   - one bar per configured option (label, count, percentage)
 *   - synthetic "Other" bucket only when the API includes it
 *   - refresh button
 *
 * Re-fetches when the selected field id changes. Edits to the poll's
 * options[] in the inspector do NOT auto-refetch — stale results would
 * be misleading on a freshly-edited schema. The user can click Refresh
 * after saving the form to update.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';

export default function PollResultsPanel( { formId, fieldId } ) {
	const isSaved = Number( formId ) > 0;

	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error,   setError   ] = useState( '' );

	const load = useCallback( () => {
		if ( ! isSaved || ! fieldId ) return;
		setLoading( true );
		setError( '' );
		api( `/forms/${ Number( formId ) }/polls/${ encodeURIComponent( fieldId ) }` )
			.then( ( res ) => { setData( res ); } )
			.catch( ( err ) => {
				const msg = ( err && err.message ) ? String( err.message ) : __( 'Failed to load poll results.', 'neural-forms-lite' );
				setError( msg );
				setData( null );
			} )
			.finally( () => { setLoading( false ); } );
	}, [ formId, fieldId, isSaved ] );

	useEffect( () => {
		if ( ! isSaved ) {
			setData( null );
			return;
		}
		load();
	}, [ load, isSaved ] );

	if ( ! isSaved ) {
		return (
			<div className="nf-poll-results">
				<h4 className="nf-poll-results__title">{ __( 'Poll results', 'neural-forms-lite' ) }</h4>
				<p className="nf-poll-results__hint">
					{ __( 'Save the form to view poll results.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	const total   = data && typeof data.total_votes === 'number' ? data.total_votes : 0;
	const options = data && Array.isArray( data.options ) ? data.options : [];

	return (
		<div className="nf-poll-results">
			<div className="nf-poll-results__head">
				<h4 className="nf-poll-results__title">{ __( 'Poll results', 'neural-forms-lite' ) }</h4>
				<button
					type="button"
					className="nf-poll-results__refresh"
					onClick={ load }
					disabled={ loading }
					aria-label={ __( 'Refresh poll results', 'neural-forms-lite' ) }
					title={ __( 'Refresh poll results', 'neural-forms-lite' ) }
				>
					{ loading ? __( 'Loading…', 'neural-forms-lite' ) : __( 'Refresh', 'neural-forms-lite' ) }
				</button>
			</div>

			{ error && (
				<p className="nf-poll-results__error" role="alert">{ error }</p>
			) }

			{ ! error && (
				<>
					<p className="nf-poll-results__total">
						{ sprintf(
							/* translators: %d: total vote count */
							__( 'Total votes: %d', 'neural-forms-lite' ),
							total
						) }
					</p>

					{ options.length === 0 && ! loading && (
						<p className="nf-poll-results__hint">
							{ __( 'No votes yet.', 'neural-forms-lite' ) }
						</p>
					) }

					<ul className="nf-poll-results__list">
						{ options.map( ( opt ) => {
							const pct = Math.max( 0, Math.min( 100, Number( opt.percentage ) || 0 ) );
							const isOther = opt.value === '__other__';
							const cls = 'nf-poll-results__row' + ( isOther ? ' nf-poll-results__row--other' : '' );
							return (
								<li key={ opt.value } className={ cls }>
									<div className="nf-poll-results__row-head">
										<span className="nf-poll-results__row-label">{ opt.label }</span>
										<span className="nf-poll-results__row-count">
											{ sprintf(
												/* translators: 1: vote count, 2: percentage */
												__( '%1$d (%2$s%%)', 'neural-forms-lite' ),
												Number( opt.votes ) || 0,
												String( pct )
											) }
										</span>
									</div>
									<div className="nf-poll-results__bar" aria-hidden="true">
										<div
											className="nf-poll-results__bar-fill"
											style={ { width: pct + '%' } }
										/>
									</div>
								</li>
							);
						} ) }
					</ul>
				</>
			) }
		</div>
	);
}
