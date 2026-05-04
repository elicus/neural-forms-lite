/**
 * Analytics screen header — Stats dashboard pattern: thin underline,
 * no card chrome, title + helper text on the left, form selector +
 * refresh button on the right.
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
		: __( 'Select a form to view its submission, poll, and quiz analytics.', 'neural-forms-lite' );

	return (
		<header className="nf-results__header">
			<div className="nf-results__title-wrap">
				<h1 className="nf-results__title">{ __( 'Form Analytics', 'neural-forms-lite' ) }</h1>
				<p className="nf-results__subtitle">{ helper }</p>
			</div>

			<div className="nf-results__controls">
				<label className="nf-results__selector">
					<span className="nf-results__selector-label">{ __( 'Form', 'neural-forms-lite' ) }</span>
					<select
						className="nf-results__selector-input"
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
						<span className="nf-results__selector-error" role="alert">{ formsError }</span>
					) }
				</label>

				<button
					type="button"
					className="nf-results__refresh"
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
