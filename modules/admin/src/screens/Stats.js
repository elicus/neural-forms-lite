/**
 * Stats screen - placeholder. Re-implementation pulls from GET /stats
 * (StatsEndpoint.php) and renders five panels: needs_attention,
 * stats, ai_insights, recent_activity, system_status.
 */

import { __ } from '@wordpress/i18n';

export default function Stats() {
	return (
		<div className="neural-forms-screen neural-forms-screen--placeholder">
			<h1>{ __( 'Stats', 'neural-forms-lite' ) }</h1>
			<p>{ __( 'The Stats dashboard is being rebuilt.', 'neural-forms-lite' ) }</p>
		</div>
	);
}
