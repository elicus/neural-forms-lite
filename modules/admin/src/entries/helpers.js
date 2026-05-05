/**
 * Pure formatting + parsing helpers shared across the Entries screen.
 * No React, no API calls — anything stateful belongs in a hook.
 */

export function formatDate( s ) {
	if ( ! s ) return '';
	const d = new Date( s.replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( d.getTime() ) ) return s;
	return d.toLocaleString();
}

export function previewSummary( preview ) {
	if ( ! preview || typeof preview !== 'object' ) return '';
	const parts = [];
	for ( const v of Object.values( preview ) ) {
		if ( v == null ) continue;
		if ( typeof v === 'string' ) parts.push( v );
		else if ( typeof v === 'number' || typeof v === 'boolean' ) parts.push( String( v ) );
		else if ( Array.isArray( v ) ) parts.push( v.join( ', ' ) );
		if ( parts.length >= 2 ) break;
	}
	return parts.join( ' · ' ).slice( 0, 200 );
}

export function pickSubmitterEmail( preview, data ) {
	const candidates = [ preview, data ];
	for ( const obj of candidates ) {
		if ( ! obj || typeof obj !== 'object' ) continue;
		for ( const v of Object.values( obj ) ) {
			if ( typeof v === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v ) ) {
				return v;
			}
		}
	}
	return '';
}

/**
 * Extract a usable error message from a wp api-fetch rejection.
 * api-fetch normalizes server errors to { code, message, data } before
 * rejecting; we surface message and fall back to the code if missing.
 */
export function errorMessage( err, fallback ) {
	if ( ! err ) return fallback;
	if ( typeof err === 'string' ) return err;
	if ( err.message ) return err.message;
	if ( err.code )    return err.code;
	return fallback;
}

/**
 * Pull the structured upgrade-modal payload from an upstream
 * CloudPlanException (feature_locked / quota_exceeded). Returns null
 * for ordinary errors so callers can fall back to a generic toast.
 */
export function planErrorPayload( err ) {
	if ( ! err || typeof err !== 'object' ) return null;
	const code = err.code || err.data?.code;
	if ( code !== 'feature_locked' && code !== 'quota_exceeded' && code !== 'pro_feature_locked' ) return null;
	return {
		code,
		message: err.message || '',
		feature: err.data?.feature || '',
		quota:   err.data?.quota   || null,
		retryAfter: err.data?.retry_after || null,
	};
}

/**
 * Pull a normalized SafetyGate payload from an error response.
 *
 * Returns one of:
 *   - { kind: 'blocked', message, category, action, usage } when the
 *     cloud responded blocked_by_safety
 *   - { kind: 'unavailable', message, usage } when ai_safety_unavailable
 *   - null otherwise
 *
 * Callers render a UI message like "AI skipped this content because
 * it appears unsafe. No credits were deducted." and skip the usual
 * "request failed" toast — the user has done nothing wrong from a
 * client-error perspective.
 */
export function safetyErrorPayload( err ) {
	if ( ! err || typeof err !== 'object' ) return null;
	const code = err.code || err.data?.code;
	if ( code !== 'blocked_by_safety' && code !== 'ai_safety_unavailable' ) return null;

	// Cloud sends `safety` and `usage` flat alongside `code`. apiFetch
	// places them either at the top level (recent middleware) or under
	// `data` (legacy); read both.
	const safety = err.safety || err.data?.safety || null;
	const usage  = err.usage  || err.data?.usage  || null;

	if ( code === 'ai_safety_unavailable' ) {
		return {
			kind: 'unavailable',
			message: err.message || '',
			usage,
		};
	}

	return {
		kind: 'blocked',
		message: err.message || '',
		category: ( safety && safety.category ) || null,
		action:   ( safety && safety.action )   || 'block',
		usage,
	};
}
