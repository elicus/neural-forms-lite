/**
 * Read-only card showing one quiz question's analytics — chart-style.
 *
 * Header: question label + meta badges (points / total answers /
 * correct rate / difficulty).
 *
 * Summary: small CSS conic-gradient donut + stacked Correct vs Wrong
 * bar. The donut is intentionally tiny (60px) so the card stays
 * scannable; bars do the heavy lifting because horizontal bars
 * compare options better than pies.
 *
 * Per-option list: each row is a horizontal bar.
 *   - Correct option carries a "Correct answer" helper line.
 *   - The wrong option with the most votes (when there is one)
 *     carries a "Most common wrong" helper line.
 *   - Synthetic "Other" rows render in neutral grey so unknown
 *     values never look like a configured option.
 *
 * Difficulty (rules — display only):
 *   Easy   ≥ 75%
 *   Medium 50–74.99%
 *   Hard   < 50%
 *   No data when total_answers === 0
 */

import { __, sprintf } from '@wordpress/i18n';

function classifyDifficulty( total, correctPct ) {
	if ( total <= 0 )       return { key: 'none',   label: __( 'No data', 'neural-forms-lite' ) };
	if ( correctPct >= 75 ) return { key: 'easy',   label: __( 'Easy',    'neural-forms-lite' ) };
	if ( correctPct >= 50 ) return { key: 'medium', label: __( 'Medium',  'neural-forms-lite' ) };
	return                       { key: 'hard',   label: __( 'Hard',    'neural-forms-lite' ) };
}

function findMostWrongValue( options ) {
	let topValue = null;
	let topCount = 0;
	for ( const opt of options ) {
		if ( ! opt || opt.value === '__other__' || opt.is_correct ) continue;
		const c = Number( opt.answers ) || 0;
		if ( c > topCount ) {
			topCount = c;
			topValue = opt.value;
		}
	}
	return topCount > 0 ? topValue : null;
}

export default function QuizResultCard( { question } ) {
	const q = question || {};
	const total      = Number( q.total_answers )      || 0;
	const correct    = Number( q.correct_answers )    || 0;
	const incorrect  = Number( q.incorrect_answers )  || 0;
	const correctPct = Math.max( 0, Math.min( 100, Number( q.correct_percentage ) || 0 ) );
	const points     = Number( q.points_possible )    || 0;
	const options    = Array.isArray( q.options ) ? q.options : [];

	const mostWrong  = findMostWrongValue( options );
	const difficulty = classifyDifficulty( total, correctPct );

	return (
		<article className="nf-results__quiz-card">
			<header className="nf-results__quiz-card-head">
				<h4 className="nf-results__quiz-card-title">{ q.label || q.field_id }</h4>
			</header>

			<div className="nf-results__quiz-card-badges" role="group">
				<span className="nf-results__quiz-card-badge nf-results__quiz-card-badge--points">
					{ points === 1
						? __( '1 point', 'neural-forms-lite' )
						: sprintf(
							/* translators: %d: number of points */
							__( '%d points', 'neural-forms-lite' ),
							points
						) }
				</span>
				<span className="nf-results__quiz-card-badge nf-results__quiz-card-badge--neutral">
					{ sprintf(
						/* translators: %d: total answer count */
						__( '%d answers', 'neural-forms-lite' ),
						total
					) }
				</span>
				{ total > 0 && (
					<span className="nf-results__quiz-card-badge nf-results__quiz-card-badge--rate">
						{ sprintf(
							/* translators: %s: correct rate percentage */
							__( '%s%% correct', 'neural-forms-lite' ),
							String( correctPct )
						) }
					</span>
				) }
				<span className={ 'nf-results__quiz-card-badge nf-results__quiz-card-badge--difficulty nf-results__quiz-card-badge--' + difficulty.key }>
					{ difficulty.label }
				</span>
			</div>

			{ total === 0 && (
				<p className="nf-results__quiz-card-empty">
					{ __( 'No answers yet.', 'neural-forms-lite' ) }
				</p>
			) }

			{ total > 0 && (
				<>
					<div className="nf-results__quiz-summary">
						<div
							className="nf-results__quiz-donut"
							style={ { '--pct': correctPct } }
							role="img"
							aria-label={ sprintf(
								/* translators: %s: correct rate percentage */
								__( 'Correct rate: %s percent', 'neural-forms-lite' ),
								String( correctPct )
							) }
						>
							<span className="nf-results__quiz-donut__center">{ correctPct + '%' }</span>
						</div>
						<div className="nf-results__quiz-summary-body">
							<span className="nf-results__quiz-summary-label">{ __( 'Correct rate', 'neural-forms-lite' ) }</span>
							<span className="nf-results__quiz-summary-meta">
								{ sprintf(
									/* translators: 1: correct count, 2: total attempts */
									__( '%1$d of %2$d answered correctly', 'neural-forms-lite' ),
									correct,
									total
								) }
							</span>
						</div>
					</div>

					<div className="nf-results__quiz-card-section-label">
						{ __( 'Answer breakdown', 'neural-forms-lite' ) }
					</div>

					<ul className="nf-results__quiz-card-list">
						{ options.map( ( opt ) => {
							const pct = Math.max( 0, Math.min( 100, Number( opt.percentage ) || 0 ) );
							const isOther     = opt.value === '__other__';
							const isCorrect   = !! opt.is_correct && ! isOther;
							const isMostWrong = ! isCorrect && ! isOther && opt.value === mostWrong;
							const cls = 'nf-results__quiz-card-row'
								+ ( isCorrect   ? ' nf-results__quiz-card-row--correct'    : '' )
								+ ( isMostWrong ? ' nf-results__quiz-card-row--most-wrong' : '' )
								+ ( isOther     ? ' nf-results__quiz-card-row--other'      : '' );
							let helper = '';
							if ( isCorrect )        helper = __( 'Correct answer',     'neural-forms-lite' );
							else if ( isMostWrong ) helper = __( 'Most picked wrong',  'neural-forms-lite' );
							return (
								<li key={ opt.value } className={ cls }>
									<div className="nf-results__quiz-card-row-head">
										<span className="nf-results__quiz-card-row-label">
											{ isCorrect && (
												<span className="nf-results__quiz-card-row-marker" aria-hidden="true">✓</span>
											) }
											<span className="nf-results__quiz-card-row-text">{ opt.label }</span>
											{ helper && (
												<span className="nf-results__quiz-card-row-tag">{ helper }</span>
											) }
										</span>
										<span className="nf-results__quiz-card-row-count">
											<span className="nf-results__quiz-card-row-count-pct">{ pct + '%' }</span>
											<span className="nf-results__quiz-card-row-count-n">{ '(' + ( Number( opt.answers ) || 0 ) + ')' }</span>
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
