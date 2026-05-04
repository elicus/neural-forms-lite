/**
 * Generic Analytics section wrapper.
 *
 * Each Analytics descriptor follows this contract:
 *   {
 *     id:           unique key — used for React key + .nf-section--{id} hook
 *     title:        human heading. null/empty = no <header>
 *     description:  optional subtitle line under the title
 *     isAvailable:  show this section at all? false = nothing rendered
 *     isEmpty:      render the section's empty placeholder instead of its body
 *     data:         the section's own props bag (no shared shape required)
 *     render:       fn( data ) -> ReactElement. Only called when
 *                   isAvailable && !isEmpty.
 *     emptyTitle:   used by EmptyAnalyticsState when isEmpty
 *     emptyMessage: used by EmptyAnalyticsState when isEmpty
 *   }
 *
 * The wrapper is presentational only — descriptor assembly lives in
 * `buildSections.js` so adding a new section is one entry there.
 *
 * Existing components (PollResultsSection, QuizResultsSection,
 * QuizAttemptsTable) already render their own <section> chrome; for
 * those we render their JSX directly inside this wrapper without an
 * outer header, so the markup doesn't double up.
 */

import EmptyAnalyticsState from './EmptyAnalyticsState';

export default function AnalyticsSection( {
	id,
	title,
	description,
	isAvailable = true,
	isEmpty = false,
	data,
	render,
	emptyTitle,
	emptyMessage,
	chrome = true,
} ) {
	if ( ! isAvailable ) return null;

	const body = isEmpty
		? <EmptyAnalyticsState title={ emptyTitle } message={ emptyMessage } />
		: ( typeof render === 'function' ? render( data ) : null );

	if ( ! chrome ) {
		return body;
	}

	return (
		<section className={ 'nf-results__section nf-section nf-section--' + ( id || 'generic' ) }>
			{ title && (
				<header className="nf-results__section-head">
					<h3 className="nf-results__section-title">{ title }</h3>
					{ description && (
						<p className="nf-results__section-subtitle">{ description }</p>
					) }
				</header>
			) }
			{ body }
		</section>
	);
}
