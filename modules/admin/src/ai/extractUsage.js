/**
 * Pulls the canonical user-facing usage block off any AI response.
 *
 * The cloud contract (see api.neuralforms.ai → common/usage-payload.ts):
 *
 *   {
 *     credits_used:      number,           // decimals, 2 d.p.
 *     credits_remaining: number | null,    // null only for mock paths
 *     credits_deducted:  boolean
 *   }
 *
 * It can arrive in two wrapper shapes depending on the plugin's HTTP
 * client:
 *
 *   1. Some apiFetch wrappers return { body, status, headers } — the
 *      cloud payload sits at `response.body`.
 *   2. Others (incl. WordPress's apiFetch with parse:true, the plugin's
 *      default) unwrap the body, so the payload IS the response.
 *
 * This helper handles both shapes plus a third edge case: WP_Error /
 * thrown error objects from PHP that pass `usage` alongside the error
 * payload (e.g. "request failed but no credits deducted"). Returns
 * `null` when no usage block is present — callers should treat null as
 * "don't show a credit notice", NOT as "0 credits used".
 */
export default function extractUsage( response ) {
	if ( response == null || typeof response !== 'object' ) return null;

	// Shape 1: { body: { ..., usage } }
	if ( response.body && typeof response.body === 'object' && response.body.usage ) {
		return response.body.usage;
	}

	// Shape 2: top-level { ..., usage }
	if ( response.usage ) {
		return response.usage;
	}

	// Shape 3: WP_Error from /wp/v2 — error data may carry { data: { usage } }
	if ( response.data && typeof response.data === 'object' && response.data.usage ) {
		return response.data.usage;
	}

	return null;
}
