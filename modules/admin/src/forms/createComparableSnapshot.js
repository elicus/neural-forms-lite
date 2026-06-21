/**
 * Stable, sorted-key JSON snapshot of the saved-payload subset of a
 * builder form. Used to derive `isDirty` by comparing baseline vs
 * current — a string compare is cheaper than a deep-equal walk and
 * avoids accidentally hashing transient UI state into the dirty flag.
 *
 * The snapshot ONLY hashes fields that travel to the REST save:
 *
 *   - name       (top-level)
 *   - status     (top-level)
 *   - form_schema (fields, settings, etc.)
 *   - settings    (form-level submission/email/security settings)
 *
 * Anything else on the form object — selected field id, active tab,
 * preview-modal visibility, AI prompt text, drag hover state, etc. —
 * lives in component state, NOT on `form`, so it never reaches the
 * snapshot. That's the contract: the dirty flag must only flip when
 * the user changes something the server will actually persist.
 *
 * Why sort keys: React state is mutated through `{...form, name: x}`
 * spreads, which generally preserve insertion order; but a fetch from
 * the REST endpoint can return keys in PHP/json-encode order, and the
 * client may add keys in a different order than the server returns
 * them on a re-save. Sorting normalises that so a no-op save (which
 * round-trips the row) doesn't appear to flip dirty back on.
 *
 * Returns a string. Callers compare with `===`.
 */
export default function createComparableSnapshot( form ) {
	if ( ! form || typeof form !== 'object' ) return '';

	const subset = {
		name:        form.name        ?? '',
		status:      form.status      ?? '',
		form_schema: form.form_schema ?? {},
		settings:    form.settings    ?? {},
	};

	return stableStringify( subset );
}

/**
 * JSON.stringify with sorted object keys. Walks arrays recursively
 * (preserving order — array index IS semantic in form_schema) and
 * objects (sorting keys). Primitives, null and undefined pass through
 * to the JSON encoder unchanged.
 *
 * Internal — not exported because the public API is the snapshot
 * helper above; if other call sites need stable JSON they should
 * compose with this through createComparableSnapshot.
 */
function stableStringify( value ) {
	return JSON.stringify( normalize( value ) );
}

function normalize( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( normalize );
	}
	if ( value && typeof value === 'object' ) {
		const out = {};
		const keys = Object.keys( value ).sort();
		for ( const key of keys ) {
			out[ key ] = normalize( value[ key ] );
		}
		return out;
	}
	return value;
}
