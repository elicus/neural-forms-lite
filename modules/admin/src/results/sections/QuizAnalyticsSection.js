/**
 * Quiz analytics — bundles per-question results and the
 * individual-attempts table into a single section so callers see one
 * logical unit. Insights renders separately so the page can interleave
 * Page Performance between Insights and the quiz body.
 *
 * Each child already renders its own <section> chrome; AnalyticsSection
 * invokes us with `chrome:false` so the page doesn't double-wrap.
 */

import QuizResultsSection from '../QuizResultsSection';
import QuizAttemptsTable from '../QuizAttemptsTable';

export default function QuizAnalyticsSection( { quiz, formId } ) {
	const showPassFail = !! ( quiz && typeof quiz.pass_rate === 'number' );

	return (
		<>
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
