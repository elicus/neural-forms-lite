/**
 * Poll analytics — wraps the existing PollResultsSection so polls
 * participate in the section descriptor pattern without duplicating
 * the per-card render logic.
 *
 * The wrapped component already renders its own <section> chrome
 * (header + cards). AnalyticsSection invokes us with `chrome:false`
 * so we don't double-wrap.
 */

import PollResultsSection from '../PollResultsSection';

export default function PollAnalyticsSection( { polls } ) {
	return <PollResultsSection polls={ polls || [] } />;
}
