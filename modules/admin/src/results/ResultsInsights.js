/**
 * Rule-based insights pane. Renders the server-built `insights` array
 * as a stack of small cards. Each insight has type ('success' |
 * 'warning' | 'info'), title, message.
 *
 * Returns null when there are no insights so the pane disappears
 * cleanly on screens that have no signal yet.
 */

import { __ } from '@wordpress/i18n';

export default function ResultsInsights( { insights } ) {
	if ( ! Array.isArray( insights ) || insights.length === 0 ) return null;

	return (
		<section className="nf-results__section nf-results__insights">
			<header className="nf-results__section-head">
				<h3 className="nf-results__section-title">{ __( 'Insights', 'neural-forms-lite' ) }</h3>
				<p className="nf-results__section-subtitle">
					{ __( 'Auto-generated highlights based on the latest data.', 'neural-forms-lite' ) }
				</p>
			</header>

			<ul className="nf-results__insights-list">
				{ insights.map( ( i, idx ) => {
					const t = ( i && i.type ) || 'info';
					return (
						<li key={ idx } className={ 'nf-results__insight nf-results__insight--' + t }>
							<span className="nf-results__insight-icon" aria-hidden="true">
								{ t === 'success' ? '✓' : t === 'warning' ? '!' : 'i' }
							</span>
							<div className="nf-results__insight-body">
								<p className="nf-results__insight-title">{ i.title || '' }</p>
								{ i.message && (
									<p className="nf-results__insight-message">{ i.message }</p>
								) }
							</div>
						</li>
					);
				} ) }
			</ul>
		</section>
	);
}
