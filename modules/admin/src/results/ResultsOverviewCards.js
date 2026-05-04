/**
 * KPI cards row — the unified overview grid for any selected form.
 *
 * One grid, one Total entries card, one place for Spam / Status /
 * poll / quiz facts. SubmissionAnalyticsSection no longer renders
 * its own cards; this component owns the entire overview row so
 * the page never duplicates a "Total entries" / "Total submissions"
 * card.
 *
 * Card styling: first card is the accent (highlight) variant —
 * navy background + mint border, white value text — mirroring the
 * Stats screen's __stat-card--highlight pattern. Subsequent cards
 * are white with the dashboard border + xs shadow.
 */

import { __ } from '@wordpress/i18n';

function Card( { label, value, accent, statusKey } ) {
	const classes = [ 'nf-results__card' ];
	if ( accent )    classes.push( 'nf-results__card--accent' );
	if ( statusKey ) classes.push( 'nf-results__card--status', 'nf-results__card--status-' + statusKey );
	return (
		<article className={ classes.join( ' ' ) }>
			<div className={ statusKey
				? 'nf-results__card-value nf-results__card-value--text'
				: 'nf-results__card-value' }>
				{ value }
			</div>
			<div className="nf-results__card-label">{ label }</div>
		</article>
	);
}

function statusLabel( status ) {
	switch ( status ) {
		case 'active':   return __( 'Active',   'neural-forms-lite' );
		case 'archived': return __( 'Archived', 'neural-forms-lite' );
		case 'draft':
		default:         return __( 'Draft',    'neural-forms-lite' );
	}
}

export default function ResultsOverviewCards( { data, quiz, selectedForm } ) {
	const totalEntries   = Number( ( data && data.total_entries ) ?? ( quiz && quiz.total_entries ) ?? 0 ) || 0;
	const totalPollVotes = Number( data && data.total_poll_votes ) || 0;
	const pollCount      = Number( data && data.poll_count )       || 0;

	const hasPolls = pollCount > 0 || totalPollVotes > 0;
	const hasQuiz  = !! ( quiz && Number( quiz.quiz_count ) > 0 );

	const quizCount     = hasQuiz ? Number( quiz.quiz_count )       || 0 : 0;
	const totalAttempts = hasQuiz ? Number( quiz.total_attempts )   || 0 : 0;
	const avgScore      = hasQuiz ? Number( quiz.average_score )    || 0 : 0;
	const avgPctRaw     = hasQuiz ? Number( quiz.average_percentage ) : 0;
	const avgPct        = isFinite( avgPctRaw ) ? Math.max( 0, Math.min( 100, avgPctRaw ) ) : 0;
	const highestScore  = hasQuiz ? Number( quiz.highest_score ) || 0 : 0;
	const lowestScore   = hasQuiz ? Number( quiz.lowest_score )  || 0 : 0;
	const passRate      = hasQuiz && typeof quiz.pass_rate === 'number' ? quiz.pass_rate : null;

	// Simple-form extras — Spam (only when > 0; never faked) and
	// Status. These used to live in SubmissionAnalyticsSection but
	// caused a "Total entries" / "Total submissions" duplication
	// when both grids rendered. Folded back here so the Submission
	// section stays focused on the View Entries CTA.
	const spamCount = Number( selectedForm && selectedForm.spam_count ) || 0;
	const status    = String( ( selectedForm && selectedForm.status ) || '' ).toLowerCase();
	const isSimple  = ! hasPolls && ! hasQuiz;

	return (
		<div className="nf-results__cards">
			<Card label={ __( 'Total entries', 'neural-forms-lite' ) } value={ totalEntries } accent />

			{ isSimple && spamCount > 0 && (
				<Card label={ __( 'Spam blocked', 'neural-forms-lite' ) } value={ spamCount } />
			) }

			{ isSimple && (
				<Card
					label={ __( 'Status', 'neural-forms-lite' ) }
					value={ statusLabel( status ) }
					statusKey={ status || 'draft' }
				/>
			) }

			{ hasPolls && (
				<>
					<Card label={ __( 'Total poll votes', 'neural-forms-lite' ) } value={ totalPollVotes } />
					<Card label={ __( 'Poll questions',   'neural-forms-lite' ) } value={ pollCount } />
				</>
			) }

			{ hasQuiz && (
				<>
					<Card label={ __( 'Total attempts',   'neural-forms-lite' ) } value={ totalAttempts } />
					<Card label={ __( 'Average score',    'neural-forms-lite' ) } value={ avgScore } />
					<Card label={ __( 'Average %',        'neural-forms-lite' ) } value={ avgPct + '%' } />
					{ passRate !== null && (
						<Card label={ __( 'Pass rate',    'neural-forms-lite' ) } value={ passRate + '%' } />
					) }
					<Card label={ __( 'Highest score',    'neural-forms-lite' ) } value={ highestScore } />
					<Card label={ __( 'Lowest score',     'neural-forms-lite' ) } value={ lowestScore } />
					<Card label={ __( 'Quiz questions',   'neural-forms-lite' ) } value={ quizCount } />
				</>
			) }
		</div>
	);
}
