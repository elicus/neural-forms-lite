/**
 * Quiz analytics — bundles per-question results, rule-based insights,
 * and the individual-attempts table into a single section so callers
 * see one logical unit.
 *
 * Each child already renders its own <section> chrome; AnalyticsSection
 * invokes us with `chrome:false` so the page doesn't double-wrap.
 */

import QuizResultsSection from '../QuizResultsSection';
import ResultsInsights from '../ResultsInsights';
import QuizAttemptsTable from '../QuizAttemptsTable';

export default function QuizAnalyticsSection( { quiz, formId } ) {
	const insights = ( quiz && Array.isArray( quiz.insights ) ) ? quiz.insights : [];
	const showPassFail = !! ( quiz && typeof quiz.pass_rate === 'number' );

	return (
		<>
			{ insights.length > 0 && (
				<ResultsInsights insights={ insights } />
			) }

			<QuizResultsSection quiz={ quiz } />

			<QuizAttemptsTable
				attempts={ ( quiz && quiz.attempts ) || [] }
				attemptsLimit={ quiz && quiz.attempts_limit }
				attemptsTotal={ quiz && quiz.attempts_total }
				formId={ formId }
				showPassFail={ showPassFail }
			/>
		</>
	);
}
