/**
 * Read-only card showing one poll's question + bar list.
 *
 * Visually mirrors the inspector's PollResultsPanel but tuned for an
 * analytics surface: bigger numbers, more breathing room, brand
 * gradient bars (#080f5b → #15db95). The synthetic "Other" row uses
 * the neutral grey treatment so it never looks like a configured option.
 */

import { __, sprintf } from '@wordpress/i18n';

export default function PollResultCard( { poll } ) {
	const total   = Number( poll && poll.total_votes ) || 0;
	const options = ( poll && Array.isArray( poll.options ) ) ? poll.options : [];

	return (
		<article className="nf-results__poll-card">
			<header className="nf-results__poll-card-head">
				<h4 className="nf-results__poll-card-title">
					{ poll.label || poll.field_id }
				</h4>
				<span className="nf-results__poll-card-total">
					{ sprintf(
						/* translators: %d: total vote count */
						__( '%d total votes', 'neural-forms-lite' ),
						total
					) }
				</span>
			</header>

			{ options.length === 0 && (
				<p className="nf-results__poll-card-empty">
					{ __( 'No votes yet.', 'neural-forms-lite' ) }
				</p>
			) }

			<ul className="nf-results__poll-card-list">
				{ options.map( ( opt ) => {
					const pct = Math.max( 0, Math.min( 100, Number( opt.percentage ) || 0 ) );
					const isOther = opt.value === '__other__';
					const cls = 'nf-results__poll-card-row'
						+ ( isOther ? ' nf-results__poll-card-row--other' : '' );
					return (
						<li key={ opt.value } className={ cls }>
							<div className="nf-results__poll-card-row-head">
								<span className="nf-results__poll-card-row-label">{ opt.label }</span>
								<span className="nf-results__poll-card-row-count">
									{ ( Number( opt.votes ) || 0 ) + ' (' + pct + '%)' }
								</span>
							</div>
							<div className="nf-results__poll-card-bar" aria-hidden="true">
								<div
									className="nf-results__poll-card-bar-fill"
									style={ { width: pct + '%' } }
								/>
							</div>
						</li>
					);
				} ) }
			</ul>
		</article>
	);
}
