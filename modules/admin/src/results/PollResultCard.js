/**
 * Read-only card showing one poll's options as a horizontal bar
 * chart. Bars use the brand navy → mint gradient; the leading vote
 * option is mint-tinted and labelled "Top vote" so admins can see
 * the winner at a glance. The synthetic "Other" row keeps neutral
 * grey so unknown values never look like a configured option.
 */

import { __, sprintf } from '@wordpress/i18n';

function findTopValue( options ) {
	let topValue = null;
	let topVotes = 0;
	for ( const opt of options ) {
		if ( ! opt || opt.value === '__other__' ) continue;
		const v = Number( opt.votes ) || 0;
		if ( v > topVotes ) {
			topVotes = v;
			topValue = opt.value;
		}
	}
	return topVotes > 0 ? topValue : null;
}

export default function PollResultCard( { poll } ) {
	const total   = Number( poll && poll.total_votes ) || 0;
	const options = ( poll && Array.isArray( poll.options ) ) ? poll.options : [];
	const topValue = findTopValue( options );

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

			{ ( options.length === 0 || total === 0 ) && (
				<p className="nf-results__poll-card-empty">
					{ __( 'No votes yet.', 'neural-forms-lite' ) }
				</p>
			) }

			{ options.length > 0 && total > 0 && (
				<ul className="nf-results__poll-card-list">
					{ options.map( ( opt ) => {
						const pct = Math.max( 0, Math.min( 100, Number( opt.percentage ) || 0 ) );
						const isOther = opt.value === '__other__';
						const isTop   = ! isOther && opt.value === topValue;
						const cls = 'nf-results__poll-card-row'
							+ ( isOther ? ' nf-results__poll-card-row--other' : '' )
							+ ( isTop   ? ' nf-results__poll-card-row--top'   : '' );
						return (
							<li key={ opt.value } className={ cls }>
								<div className="nf-results__poll-card-row-head">
									<span className="nf-results__poll-card-row-label">{ opt.label }</span>
									<span className="nf-results__poll-card-row-count">
										{ ( Number( opt.votes ) || 0 ) + ' (' + pct + '%)' }
									</span>
								</div>
								{ isTop && (
									<p className="nf-results__poll-card-row-helper">
										{ __( 'Top vote', 'neural-forms-lite' ) }
									</p>
								) }
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
			) }
		</article>
	);
}
