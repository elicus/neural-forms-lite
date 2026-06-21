/**
 * Analytics screen header — single hero card.
 *
 * Layout (desktop):
 *   ┌────────────────────────────────────────────────────────┐
 *   │ Form Analytics                  [ Form ▾ ] [ Refresh ] │
 *   │ Understand submissions, polls, …                       │
 *   └────────────────────────────────────────────────────────┘
 *
 * Layout (≤ 900px): controls stack below the title block; the
 * label/select pair and refresh button each go full width.
 *
 * No data-flow changes — this is the same form list, the same
 * onSelect/onRefresh handlers, just a new visual shell.
 */

import { __, sprintf } from '@wordpress/i18n';

export default function ResultsHeader( {
	forms,
	formsLoading,
	formsError,
	selectedId,
	selectedFormName,
	onSelect,
	onRefresh,
	refreshing,
	canRefresh,
} ) {
	const helper = selectedFormName
		? sprintf(
			/* translators: %s: form name */
			__( 'Showing analytics for %s', 'neural-forms-lite' ),
			selectedFormName
		)
		: __( 'Understand submissions, polls, quizzes, and form performance.', 'neural-forms-lite' );

	return (
		<header className="nf-analytics-hero">
			<div className="nf-analytics-hero__copy">
				<h1 className="nf-analytics-hero__title">{ __( 'Form Analytics', 'neural-forms-lite' ) }</h1>
				<p className="nf-analytics-hero__subtitle">{ helper }</p>
			</div>

			<div className="nf-analytics-hero__controls">
				<label className="nf-analytics-hero__selector">
					<span className="nf-analytics-hero__selector-label">{ __( 'Form', 'neural-forms-lite' ) }</span>
					<select
						className="nf-analytics-hero__selector-input"
						value={ selectedId || '' }
						onChange={ ( e ) => onSelect( Number( e.target.value ) || 0 ) }
						disabled={ formsLoading }
					>
						<option value="">
							{ formsLoading
								? __( 'Loading…', 'neural-forms-lite' )
								: __( '— Select a form —', 'neural-forms-lite' ) }
						</option>
						{ forms.map( ( f ) => {
							const polls = Number( f.poll_count ) || 0;
							const quiz  = Number( f.quiz_count ) || 0;
							const parts = [];
							if ( polls > 0 ) parts.push( `${ polls } poll${ polls === 1 ? '' : 's' }` );
							if ( quiz  > 0 ) parts.push( `${ quiz } quiz question${ quiz === 1 ? '' : 's' }` );
							const suffix = parts.length ? ` (${ parts.join( ', ' ) })` : '';
							return (
								<option key={ f.id } value={ f.id }>
									{ f.name }{ suffix }
								</option>
							);
						} ) }
					</select>
					{ formsError && (
						<span className="nf-analytics-hero__selector-error" role="alert">{ formsError }</span>
					) }
				</label>

				<button
					type="button"
					className="nf-analytics-hero__refresh"
					onClick={ onRefresh }
					disabled={ ! canRefresh || refreshing }
					title={ __( 'Refresh results', 'neural-forms-lite' ) }
				>
					{ refreshing ? __( 'Loading…', 'neural-forms-lite' ) : __( 'Refresh', 'neural-forms-lite' ) }
				</button>
			</div>
		</header>
	);
}
