/**
 * Individual Attempts table for the Results screen.
 *
 * Renders the latest server-capped slice (default 50 most-recent).
 * Each row expands inline to show per-question answers — selected
 * label, correct / incorrect verdict, correct answer reveal, points.
 * No drawer / modal: keeps the surface lightweight.
 *
 * "View entry" links to the existing Entries screen filtered by
 * form id. (Deep-link to a specific entry is not yet supported by
 * the rich admin bundle's Entries screen, so we land on the
 * filtered list and let the user click through.)
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

function formatDate( raw ) {
	if ( ! raw || typeof raw !== 'string' ) return '';
	// "YYYY-MM-DD HH:MM:SS" → display as-is. Rich formatting can land later.
	return raw.replace( /:\d{2}$/, '' );
}

/**
 * Build the deep-link URL that opens an individual entry's detail
 * view inside the Entries inbox. The rich admin bundle already reads
 * `?entry=N` on mount (URLSearchParams in the entries list effect),
 * fetches GET /entries/{id}, and switches to the detail view. So
 * passing both form_id (filter) AND entry (auto-open) is enough —
 * no bundle rebuild required.
 *
 * URL contract:
 *   admin.php?page=neural-forms-entries&form_id={form_id}&entry={entry_id}
 *
 * The form_id keeps the inbox filtered to the right form so the row
 * also exists in the visible list when the user closes the detail.
 * If the entry id is invalid, the bundle's apiFetch swallows the 404
 * and leaves the inbox list visible (verified in build/index.js).
 */
function entryLink( formId, entryId ) {
	const f = Number( formId )  || 0;
	const e = Number( entryId ) || 0;
	const params = [];
	if ( f > 0 ) params.push( 'form_id=' + f );
	if ( e > 0 ) params.push( 'entry='   + e );
	const query = params.length ? '&' + params.join( '&' ) : '';
	return 'admin.php?page=neural-forms-entries' + query;
}

function AttemptRow( { attempt, formId, expanded, onToggle, showPassFail } ) {
	const score    = Number( attempt.score )      || 0;
	const maxScore = Number( attempt.max_score )  || 0;
	const pct      = Number( attempt.percentage ) || 0;
	const correct  = Number( attempt.correct_count )   || 0;
	const wrong    = Number( attempt.incorrect_count ) || 0;
	const passed   = ( showPassFail && typeof attempt.passed === 'boolean' ) ? attempt.passed : null;

	return (
		<>
			<tr className={ 'nf-attempts__row' + ( expanded ? ' is-expanded' : '' ) }>
				<td className="nf-attempts__td nf-attempts__td--id">{ '#' + attempt.entry_id }</td>
				<td className="nf-attempts__td">{ attempt.identity || ( '#' + attempt.entry_id ) }</td>
				<td className="nf-attempts__td nf-attempts__td--meta">{ formatDate( attempt.submitted_at ) }</td>
				<td className="nf-attempts__td nf-attempts__td--score">
					<strong>{ score } / { maxScore }</strong>
					<span className="nf-attempts__pct"> ({ pct }%)</span>
				</td>
				<td className="nf-attempts__td">
					{ passed === null ? (
						<span className="nf-attempts__verdict nf-attempts__verdict--neutral">—</span>
					) : passed ? (
						<span className="nf-attempts__verdict nf-attempts__verdict--passed">{ __( 'Passed', 'neural-forms-lite' ) }</span>
					) : (
						<span className="nf-attempts__verdict nf-attempts__verdict--failed">{ __( 'Failed', 'neural-forms-lite' ) }</span>
					) }
				</td>
				<td className="nf-attempts__td nf-attempts__td--summary">
					{ sprintf(
						/* translators: 1: correct count, 2: incorrect count */
						__( '%1$d correct · %2$d wrong', 'neural-forms-lite' ),
						correct, wrong
					) }
				</td>
				<td className="nf-attempts__td nf-attempts__td--actions">
					<div className="nf-attempts__action-group">
						<button
							type="button"
							className="nf-attempts__expand-btn"
							onClick={ () => onToggle( attempt.entry_id ) }
							aria-expanded={ expanded }
						>
							{ expanded ? __( 'Hide answers', 'neural-forms-lite' ) : __( 'Show answers', 'neural-forms-lite' ) }
						</button>
						<a
							className="nf-attempts__view-btn"
							href={ entryLink( formId, attempt.entry_id ) }
							title={ __( 'Open this entry', 'neural-forms-lite' ) }
							aria-label={ __( 'Open entry #', 'neural-forms-lite' ) + Number( attempt.entry_id ) }
						>
							{ __( 'View entry', 'neural-forms-lite' ) }
						</a>
					</div>
				</td>
			</tr>
			{ expanded && (
				<tr className="nf-attempts__detail-row">
					<td colSpan={ 7 } className="nf-attempts__td nf-attempts__detail-cell">
						<ul className="nf-attempts__answers">
							{ ( attempt.answers || [] ).map( ( a, i ) => (
								<li key={ i } className={
									'nf-attempts__answer'
									+ ( a.correct ? ' nf-attempts__answer--correct' : ' nf-attempts__answer--incorrect' )
								}>
									<p className="nf-attempts__answer-q">{ a.label || a.field_id }</p>
									<p className="nf-attempts__answer-line">
										<span className="nf-attempts__answer-tag">
											{ a.correct ? __( '✓ Correct', 'neural-forms-lite' ) : __( '✗ Incorrect', 'neural-forms-lite' ) }
										</span>
										<span className="nf-attempts__answer-meta">
											{ sprintf(
												/* translators: 1: awarded, 2: possible */
												__( '%1$d / %2$d pts', 'neural-forms-lite' ),
												Number( a.points_awarded ) || 0,
												Number( a.points_possible ) || 0
											) }
										</span>
									</p>
									{ ( a.selected_label !== null && a.selected_label !== undefined ) ? (
										<p className="nf-attempts__answer-line">
											<span className="nf-attempts__answer-label">{ __( 'Selected:', 'neural-forms-lite' ) }</span>
											<span className="nf-attempts__answer-value">{ a.selected_label || a.selected_value }</span>
										</p>
									) : (
										<p className="nf-attempts__answer-line nf-attempts__answer-line--muted">
											{ __( 'No answer submitted.', 'neural-forms-lite' ) }
										</p>
									) }
									{ ! a.correct && a.correct_label && (
										<p className="nf-attempts__answer-line">
											<span className="nf-attempts__answer-label">{ __( 'Correct:', 'neural-forms-lite' ) }</span>
											<span className="nf-attempts__answer-value">{ a.correct_label }</span>
										</p>
									) }
								</li>
							) ) }
						</ul>
					</td>
				</tr>
			) }
		</>
	);
}

export default function QuizAttemptsTable( { attempts, attemptsLimit, attemptsTotal, formId, showPassFail } ) {
	const [ expandedId, setExpandedId ] = useState( null );
	const onToggle = ( id ) => setExpandedId( ( cur ) => ( cur === id ? null : id ) );

	if ( ! Array.isArray( attempts ) || attempts.length === 0 ) {
		return (
			<section className="nf-results__section">
				<header className="nf-results__section-head">
					<h3 className="nf-results__section-title">{ __( 'Individual Attempts', 'neural-forms-lite' ) }</h3>
				</header>
				<p className="nf-results__empty-inline">
					{ __( 'No quiz attempts yet.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	const showingLimit = Number( attemptsLimit ) || 50;
	const totalCount   = Number( attemptsTotal ) || attempts.length;
	const truncated    = totalCount > attempts.length;

	return (
		<section className="nf-results__section">
			<header className="nf-results__section-head">
				<h3 className="nf-results__section-title">{ __( 'Individual Attempts', 'neural-forms-lite' ) }</h3>
				<p className="nf-results__section-subtitle">
					{ truncated
						? sprintf(
							/* translators: 1: shown count, 2: total */
							__( 'Showing the latest %1$d of %2$d attempts.', 'neural-forms-lite' ),
							attempts.length, totalCount
						)
						: sprintf(
							/* translators: %d: count */
							__( '%d attempt(s)', 'neural-forms-lite' ),
							attempts.length
						) }
				</p>
			</header>

			<div className="nf-attempts__wrap">
				<table className="nf-attempts__table">
					<thead>
						<tr>
							<th className="nf-attempts__th">{ __( 'Entry', 'neural-forms-lite' ) }</th>
							<th className="nf-attempts__th">{ __( 'Submitter', 'neural-forms-lite' ) }</th>
							<th className="nf-attempts__th">{ __( 'Submitted', 'neural-forms-lite' ) }</th>
							<th className="nf-attempts__th">{ __( 'Score', 'neural-forms-lite' ) }</th>
							<th className="nf-attempts__th">{ __( 'Result', 'neural-forms-lite' ) }</th>
							<th className="nf-attempts__th">{ __( 'Summary', 'neural-forms-lite' ) }</th>
							<th className="nf-attempts__th nf-attempts__th--actions">{ __( 'Actions', 'neural-forms-lite' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ attempts.map( ( a ) => (
							<AttemptRow
								key={ a.entry_id }
								attempt={ a }
								formId={ formId }
								expanded={ expandedId === a.entry_id }
								onToggle={ onToggle }
								showPassFail={ showPassFail }
							/>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
}
