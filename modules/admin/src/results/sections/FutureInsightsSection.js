/**
 * Subtle "Advanced insights" placeholder.
 *
 * Renders only when there's a baseline of real data on the page
 * already — otherwise it would read as a half-finished UI rather
 * than a forward-looking promise. Today's gate: the form has at
 * least one non-trash entry. Future gates can be looser as we ship
 * the actual features.
 *
 * This file is the FUTURE EXTENSION POINT for:
 *
 * 1. AI insights ─────────────────────────────────────────────────
 *    Future endpoint:
 *      POST /wp-json/neural-forms/v1/forms/{id}/analytics/ai-insights
 *
 *    Required behavior (do not implement until product green-lights):
 *      - admin-only (capability: NEURAL_FORMS_CAP_MANAGE_FORMS)
 *      - manually triggered via a "Generate insights" button — never
 *        on page load and never on a cron job
 *      - sends only aggregate / anonymised text data; never PII
 *      - caches the response (e.g. neural_forms_ai_insights table or
 *        AIResponseRepository::kind = 'analytics_insight') so a
 *        second view does not re-bill the user
 *      - returns: { summary, themes, sentiment, recommendations,
 *        warnings, generated_at, ai_tier }
 *
 * 2. Demographics / device / source ──────────────────────────────
 *    DO NOT collect by default. Privacy stance:
 *      - country / device / referrer must be opt-in via a Settings
 *        toggle; default off
 *      - precise IP / fingerprinting is out of scope entirely
 *      - per-entry rows store only the bucketed value (country code,
 *        device class) — never the raw header
 *      - the toggle's help text must explain what is collected and
 *        link to the privacy policy
 *
 * 3. Conversion tracking ────────────────────────────────────────
 *    Future hook lives here too. Same opt-in gate.
 *
 * 4. Spam / security analytics ──────────────────────────────────
 *    Already partially surfaced via SubmissionAnalyticsSection's
 *    "Spam blocked" card. Future work: per-day spam trend chart,
 *    top offending IP buckets (pre-anonymised), block-list size.
 */

import { __ } from '@wordpress/i18n';

export default function FutureInsightsSection() {
	const rows = [
		{ id: 'ai-summary',   label: __( 'AI summary',          'neural-forms-lite' ) },
		{ id: 'sentiment',    label: __( 'Sentiment & themes',  'neural-forms-lite' ) },
		{ id: 'demographics', label: __( 'Country & device',    'neural-forms-lite' ) },
		{ id: 'source',       label: __( 'Traffic source',      'neural-forms-lite' ) },
	];
	const comingSoon = __( 'Coming soon', 'neural-forms-lite' );

	return (
		<div className="nf-future-insights__rows" role="list">
			{ rows.map( ( r ) => (
				<div key={ r.id } className="nf-future-insights__row" role="listitem">
					<span className="nf-future-insights__label">{ r.label }</span>
					<span className="nf-future-insights__badge">{ comingSoon }</span>
				</div>
			) ) }
		</div>
	);
}
