/**
 * Section wrapper rendering one PollResultCard per poll field. Future
 * sections (Survey, Quiz, Rating, Drop-off) will follow this same
 * shape: a titled section plus a list of result cards.
 */

import { __ } from '@wordpress/i18n';
import PollResultCard from './PollResultCard';

export default function PollResultsSection( { polls } ) {
	if ( ! Array.isArray( polls ) || polls.length === 0 ) {
		return null;
	}
	return (
		<section className="nf-results__section">
			<header className="nf-results__section-head">
				<h3 className="nf-results__section-title">{ __( 'Poll Results', 'neural-forms-lite' ) }</h3>
				<p className="nf-results__section-subtitle">
					{ __( 'Per-question vote counts and percentages.', 'neural-forms-lite' ) }
				</p>
			</header>

			<div className="nf-results__poll-grid">
				{ polls.map( ( poll ) => (
					<PollResultCard key={ poll.field_id } poll={ poll } />
				) ) }
			</div>
		</section>
	);
}
