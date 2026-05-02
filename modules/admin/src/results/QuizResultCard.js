/**
 * Read-only card showing one quiz question's analytics.
 *
 * Header: question label + points pill + total answers.
 * Correct-rate bar uses the brand navy → mint gradient.
 * Per-option list shows label, count, percentage; the correct
 * option is mint-tinted so admins can see at a glance which one is
 * the right answer. The synthetic "Other" row uses neutral grey so
 * unknown values never look like a configured option.
 */

import { __, sprintf } from '@wordpress/i18n';

export default function QuizResultCard( { question } ) {
	const q = question || {};
	const total      = Number( q.total_answers )      || 0;
	const correct    = Number( q.correct_answers )    || 0;
	const incorrect  = Number( q.incorrect_answers )  || 0;
	const correctPct = Math.max( 0, Math.min( 100, Number( q.correct_percentage ) || 0 ) );
	const points     = Number( q.points_possible )    || 0;
	const options    = Array.isArray( q.options ) ? q.options : [];

	return (
		<article className="nf-results__quiz-card">
			<header className="nf-results__quiz-card-head">
				<h4 className="nf-results__quiz-card-title">{ q.label || q.field_id }</h4>
				<div className="nf-results__quiz-card-meta">
					<span className="nf-results__quiz-card-points">
						{ points === 1
							? __( '1 point', 'neural-forms-lite' )
							: sprintf(
								/* translators: %d: number of points */
								__( '%d points', 'neural-forms-lite' ),
								points
							) }
					</span>
					<span className="nf-results__quiz-card-answers">
						{ sprintf(
							/* translators: %d: total answer count */
							__( '%d total answers', 'neural-forms-lite' ),
							total
						) }
					</span>
				</div>
			</header>

			{ total === 0 && (
				<p className="nf-results__quiz-card-empty">
					{ __( 'No quiz responses yet.', 'neural-forms-lite' ) }
				</p>
			) }

			{ total > 0 && (
				<>
					<div className="nf-results__quiz-card-rate">
						<div className="nf-results__quiz-card-rate-head">
							<span className="nf-results__quiz-card-rate-label">{ __( 'Correct rate', 'neural-forms-lite' ) }</span>
							<span className="nf-results__quiz-card-rate-count">
								{ sprintf(
									/* translators: 1: correct count, 2: total, 3: percentage */
									__( '%1$d / %2$d (%3$s%%)', 'neural-forms-lite' ),
									correct,
									total,
									String( correctPct )
								) }
							</span>
						</div>
						<div className="nf-results__quiz-card-rate-bar" aria-hidden="true">
							<div
								className="nf-results__quiz-card-rate-fill"
								style={ { width: correctPct + '%' } }
							/>
						</div>
						<p className="nf-results__quiz-card-rate-detail">
							{ sprintf(
								/* translators: 1: correct count, 2: incorrect count */
								__( '%1$d correct · %2$d incorrect', 'neural-forms-lite' ),
								correct,
								incorrect
							) }
						</p>
					</div>

					<ul className="nf-results__quiz-card-list">
						{ options.map( ( opt ) => {
							const pct = Math.max( 0, Math.min( 100, Number( opt.percentage ) || 0 ) );
							const isOther = opt.value === '__other__';
							const isCorrect = !! opt.is_correct && ! isOther;
							const cls = 'nf-results__quiz-card-row'
								+ ( isCorrect ? ' nf-results__quiz-card-row--correct' : '' )
								+ ( isOther   ? ' nf-results__quiz-card-row--other'   : '' );
							return (
								<li key={ opt.value } className={ cls }>
									<div className="nf-results__quiz-card-row-head">
										<span className="nf-results__quiz-card-row-label">
											{ isCorrect && (
												<span className="nf-results__quiz-card-row-marker" aria-hidden="true">✓</span>
											) }
											{ opt.label }
										</span>
										<span className="nf-results__quiz-card-row-count">
											{ ( Number( opt.answers ) || 0 ) + ' (' + pct + '%)' }
										</span>
									</div>
									<div className="nf-results__quiz-card-row-bar" aria-hidden="true">
										<div
											className="nf-results__quiz-card-row-fill"
											style={ { width: pct + '%' } }
										/>
									</div>
								</li>
							);
						} ) }
					</ul>
				</>
			) }
		</article>
	);
}
