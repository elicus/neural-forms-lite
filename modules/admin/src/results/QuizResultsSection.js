/**
 * Quiz analytics section. Mirrors PollResultsSection's shape so the
 * Results screen reads visually consistent across analytics types.
 *
 * Renders one QuizResultCard per question in `quiz.questions`.
 */

import { __ } from '@wordpress/i18n';
import QuizResultCard from './QuizResultCard';

export default function QuizResultsSection( { quiz } ) {
	const questions = ( quiz && Array.isArray( quiz.questions ) ) ? quiz.questions : [];
	if ( questions.length === 0 ) return null;

	return (
		<section className="nf-results__section">
			<header className="nf-results__section-head">
				<h3 className="nf-results__section-title">{ __( 'Quiz Results', 'neural-forms-lite' ) }</h3>
				<p className="nf-results__section-subtitle">
					{ __( 'Per-question correct rate and option breakdown.', 'neural-forms-lite' ) }
				</p>
			</header>

			<div className="nf-results__quiz-grid">
				{ questions.map( ( q ) => (
					<QuizResultCard key={ q.field_id } question={ q } />
				) ) }
			</div>
		</section>
	);
}
