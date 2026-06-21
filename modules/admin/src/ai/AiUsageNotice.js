/**
 * Subtle, post-completion credit-usage notice.
 *
 * Renders one of three states:
 *
 *   1. usage absent (null/undefined)            → renders nothing
 *   2. usage.credits_deducted === true          → "0.30 credits used · 1,993.70 remaining"
 *      (or just "0.30 credits used" when credits_remaining is null)
 *   3. usage.credits_deducted === false         → "No credits were deducted."
 *      Builds trust on failed requests where the cloud explicitly
 *      tells us nothing was charged.
 *
 * Style is intentionally muted — small font, neutral foreground, no
 * heavy chrome. The notice should reassure, not interrupt.
 *
 * Props:
 *   - usage: the canonical usage block from extractUsage(response).
 *   - className: optional extra class for layout customisation.
 *   - style: optional inline style overrides.
 *
 * Display rule reminder (product brief):
 *   - Show usage ONLY after request completion.
 *   - Clear `usage` to null when starting a new request so we don't
 *     show a stale notice from a prior call.
 */

import { __ } from '@wordpress/i18n';
import formatCredits from './formatCredits';

const BASE_STYLE = {
	fontSize: 12,
	color: '#475569',
	margin: 0,
	lineHeight: 1.4,
};

export default function AiUsageNotice( { usage, className = '', style = {} } ) {
	if ( ! usage || typeof usage !== 'object' ) return null;

	const merged = { ...BASE_STYLE, ...style };

	if ( usage.credits_deducted === false ) {
		return (
			<p
				className={ `neural-forms-ai-usage-notice neural-forms-ai-usage-notice--no-charge ${ className }`.trim() }
				style={ merged }
			>
				{ __( 'No credits were deducted.', 'neural-forms-lite' ) }
			</p>
		);
	}

	const used      = formatCredits( usage.credits_used );
	const remaining = formatCredits( usage.credits_remaining );

	if ( used === null ) return null;

	return (
		<p
			className={ `neural-forms-ai-usage-notice ${ className }`.trim() }
			style={ merged }
		>
			{ remaining !== null
				? /* translators: 1: credits used (e.g. "0.30"), 2: credits remaining (e.g. "1,993.70") */
				  __( '%1$s credits used · %2$s remaining', 'neural-forms-lite' )
						.replace( '%1$s', used )
						.replace( '%2$s', remaining )
				: /* translators: %s: credits used (e.g. "0.30") */
				  __( '%s credits used', 'neural-forms-lite' ).replace( '%s', used ) }
		</p>
	);
}
