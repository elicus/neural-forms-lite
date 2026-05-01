/**
 * KPI cards row: total entries, total poll votes, poll questions count.
 *
 * Each card is intentionally a self-contained <article> so future
 * cards (response rate, conversion, AI-flagged) can slot into the
 * grid without restructuring.
 */

import { __ } from '@wordpress/i18n';

function Card( { label, value, accent } ) {
	const cls = 'nf-results__card' + ( accent ? ' nf-results__card--accent' : '' );
	return (
		<article className={ cls }>
			<div className="nf-results__card-value">{ value }</div>
			<div className="nf-results__card-label">{ label }</div>
		</article>
	);
}

export default function ResultsOverviewCards( { data } ) {
	const totalEntries    = Number( data.total_entries )    || 0;
	const totalPollVotes  = Number( data.total_poll_votes ) || 0;
	const pollCount       = Number( data.poll_count )       || 0;

	return (
		<div className="nf-results__cards">
			<Card label={ __( 'Total entries',    'neural-forms-lite' ) } value={ totalEntries } />
			<Card label={ __( 'Total poll votes', 'neural-forms-lite' ) } value={ totalPollVotes } accent />
			<Card label={ __( 'Poll questions',   'neural-forms-lite' ) } value={ pollCount } />
		</div>
	);
}
