/**
 * Section-scoped empty placeholder.
 *
 * Differs from `../EmptyState`: this one lives INSIDE a section, so
 * it's smaller, dashed, and never carries error tone — section-level
 * errors surface via the page-level EmptyState above the sections.
 */

import { __ } from '@wordpress/i18n';

export default function EmptyAnalyticsState( { title, message } ) {
	return (
		<div className="nf-section__empty">
			<p className="nf-section__empty-title">
				{ title || __( 'No data yet.', 'neural-forms-lite' ) }
			</p>
			{ message && <p className="nf-section__empty-message">{ message }</p> }
		</div>
	);
}
