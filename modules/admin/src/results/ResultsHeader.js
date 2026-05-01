/**
 * Results screen header chrome: title, form selector, refresh button.
 */

import { __ } from '@wordpress/i18n';

export default function ResultsHeader( {
	forms,
	formsLoading,
	formsError,
	selectedId,
	onSelect,
	onRefresh,
	refreshing,
	canRefresh,
} ) {
	return (
		<header className="nf-results__header">
			<div className="nf-results__title-wrap">
				<h1 className="nf-results__title">{ __( 'Results', 'neural-forms-lite' ) }</h1>
				<p className="nf-results__subtitle">
					{ __( 'Poll, survey, and analytics insights for your forms.', 'neural-forms-lite' ) }
				</p>
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
							const suffix = polls > 0 ? ` (${ polls } poll${ polls === 1 ? '' : 's' })` : '';
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
