/**
 * Format a numeric credit value to a human-readable, locale-aware
 * 2-decimal string. Matches the user-facing copy spec:
 *
 *   0.30 credits used · 1,993.70 remaining
 *
 * Returns `null` for non-finite or non-numeric input so callers can
 * distinguish "no credit data available" from "0.00 credits". Pair
 * with extractUsage which yields null for missing usage blocks.
 *
 * Always uses the user's locale grouping (browser default). The
 * trailing ".00" is forced via minimumFractionDigits so the UI never
 * shows "1,993.7" when the cloud returns 1993.7 exactly.
 */
export default function formatCredits( value ) {
	if ( value === null || value === undefined ) return null;
	const number = typeof value === 'number' ? value : Number( value );
	if ( ! Number.isFinite( number ) ) return null;
	return number.toLocaleString( undefined, {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	} );
}
