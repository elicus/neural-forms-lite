/**
 * Submission analytics — for simple (non-poll, non-quiz) forms, this
 * section now contains only the "View entries" call-to-action.
 *
 * The Total / Spam / Status cards moved up into the unified overview
 * grid (ResultsOverviewCards) so the page no longer duplicates a
 * Total entries card. Keeping the CTA in its own section preserves
 * the dashboard rhythm — the user still gets a visible next step
 * after the cards.
 */

import { __ } from '@wordpress/i18n';

function entriesUrl( formId ) {
	return 'admin.php?page=neural-forms-entries&form_id=' + Number( formId );
}

export default function SubmissionAnalyticsSection( { totalEntries, formId } ) {
	if ( Number( formId ) <= 0 ) return null;
	const total = Math.max( 0, Number( totalEntries ) || 0 );

	return (
		<p className="nf-results__simple-cta">
			<a
				className="nf-results__simple-cta-btn"
				href={ entriesUrl( formId ) }
				title={ __( 'Open Entries filtered to this form', 'neural-forms-lite' ) }
			>
				{ total > 0
					? __( 'View entries', 'neural-forms-lite' )
					: __( 'View entries (none yet)', 'neural-forms-lite' ) }
			</a>
		</p>
	);
}
