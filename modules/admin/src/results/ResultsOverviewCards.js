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

export default function ResultsOverviewCards( { data, quiz } ) {
	const totalEntries   = Number( ( data && data.total_entries ) ?? ( quiz && quiz.total_entries ) ?? 0 ) || 0;
	const totalPollVotes = Number( data && data.total_poll_votes ) || 0;
	const pollCount      = Number( data && data.poll_count )       || 0;

	const hasPolls = pollCount > 0 || totalPollVotes > 0;
	const hasQuiz  = !! ( quiz && Number( quiz.quiz_count ) > 0 );

	const quizCount   = hasQuiz ? Number( quiz.quiz_count )       || 0  : 0;
	const avgScore    = hasQuiz ? Number( quiz.average_score )    || 0  : 0;
	const avgPctRaw   = hasQuiz ? Number( quiz.average_percentage ) : 0;
	const avgPct      = isFinite( avgPctRaw ) ? Math.max( 0, Math.min( 100, avgPctRaw ) ) : 0;

	return (
		<div className="nf-results__cards">
			<Card label={ __( 'Total entries', 'neural-forms-lite' ) } value={ totalEntries } />

			{ hasPolls && (
				<>
					<Card label={ __( 'Total poll votes', 'neural-forms-lite' ) } value={ totalPollVotes } accent />
					<Card label={ __( 'Poll questions',   'neural-forms-lite' ) } value={ pollCount } />
				</>
			) }

			{ hasQuiz && (
				<>
					<Card label={ __( 'Average score',    'neural-forms-lite' ) } value={ avgScore } accent />
					<Card label={ __( 'Average %',        'neural-forms-lite' ) } value={ avgPct + '%' } />
					<Card label={ __( 'Quiz questions',   'neural-forms-lite' ) } value={ quizCount } />
				</>
			) }
		</div>
	);
}
