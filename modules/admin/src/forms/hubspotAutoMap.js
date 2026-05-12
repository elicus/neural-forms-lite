/**
 * Pure helpers for the per-form HubSpot mapping UI.
 *
 * Extracted to its own module so the React panel stays focused on
 * state + rendering while the rules engine (auto-mapping, field-
 * compatibility filtering, required-unmapped detection, field-tree
 * flattening) lives here as side-effect-free functions. Easier to
 * reason about, easier to unit-test if a JS test framework is added.
 *
 * Mapping direction (the entire module assumes this):
 *
 *   HubSpot field name  →  Neural Forms field ID
 *
 * The mapping object stored on form.settings.integrations.hubspot
 * .field_map has the shape:
 *
 *   { "<hubspot_field_name>": "<neural_forms_field_id>", ... }
 *
 * Mapping entries with the value '' (or absent) are treated as
 * "Do not send" — those HubSpot fields are skipped at delivery time.
 */

// HubSpot UI input kinds (the `field_type` field on a normalized
// HubSpot field). Source: HubSpotFormsMapper::normalize_field.
export const HS_TEXT     = 'text';
export const HS_TEXTAREA = 'textarea';
export const HS_SELECT   = 'select';
export const HS_RADIO    = 'radio';
export const HS_CHECKBOX = 'checkbox';      // single boolean checkbox
export const HS_DATE     = 'date';
export const HS_NUMBER   = 'number';
export const HS_FILE     = 'file';
export const HS_PHONE    = 'phone';
export const HS_EMAIL    = 'email';

// Neural Forms field types we allow as mapping TARGETS. Excludes
// layout containers (row/section/step) and form-machinery types
// (poll/quiz_question) — per the spec.
//
// `hidden` is permitted: hidden fields can carry referrer / UTM /
// page metadata and routing those into HubSpot is exactly the use
// case a per-form mapping serves.
//
// `file` is permitted: HubSpot file properties accept a STRING
// value (URL or filename) — the public submissions endpoint does
// not handle multipart, so we ship the file's URL/filename via
// HubSpotSubmissionPayloadBuilder::coerce_file (see C5). The
// payload builder NEVER ships the server filesystem path — only
// the user-facing url + filename keys from the entry's file
// metadata blob.
const SUPPORTED_NF_TYPES = new Set( [
	'text', 'textarea', 'email', 'phone', 'tel', 'url',
	'number', 'range', 'date',
	'select', 'radio', 'checkbox', 'checkboxes',
	'hidden',
	'rating',
	'file',
] );

// Excluded layout / container / complex types — keep this list
// separate (rather than just "not in supported") so a new NF type
// added to the registry doesn't silently start appearing in the
// HubSpot mapping dropdown without an explicit decision.
//
// matrix / repeater are intentionally NOT in supported: their
// per-row / per-item structure has no useful HubSpot target in v1.
// The payload builder DOES coerce them into readable strings if
// they ever reach delivery, but the dropdown hides them so admins
// don't end up mapping a survey grid into a HubSpot enumeration
// field by accident.
const EXCLUDED_NF_TYPES = new Set( [
	'row', 'section', 'step',
	'matrix', 'repeater',
	'poll', 'quiz_question',
] );

/**
 * Flatten a Neural Forms schema tree into a flat array of leaf
 * fields, descending through layout containers (row / step / row
 * inside step). Layout containers themselves are dropped — only
 * input fields with `id` survive.
 *
 * @param {Array} fields  form_schema.fields (top level)
 * @returns {Array}       flat list of input fields, preserving DFS order
 */
export function flattenNfFields( fields ) {
	const out = [];
	const walk = ( nodes ) => {
		if ( ! Array.isArray( nodes ) ) return;
		for ( const node of nodes ) {
			if ( ! node || typeof node !== 'object' ) continue;
			const type = String( node.type || '' );
			if ( type === 'row' && Array.isArray( node.columns ) ) {
				for ( const col of node.columns ) {
					if ( col && Array.isArray( col.fields ) ) walk( col.fields );
				}
				continue;
			}
			if ( type === 'step' && Array.isArray( node.fields ) ) {
				walk( node.fields );
				continue;
			}
			if ( EXCLUDED_NF_TYPES.has( type ) ) continue;
			if ( ! node.id ) continue;
			if ( ! SUPPORTED_NF_TYPES.has( type ) ) continue;
			out.push( node );
		}
	};
	walk( fields );
	return out;
}

/**
 * Normalize a label or name for fuzzy matching:
 *   "First Name"   → "first_name"
 *   "first-name"   → "first_name"
 *   "Email Address"→ "email_address"
 *
 * Used by the auto-map heuristics. Pure, side-effect-free.
 *
 * @param {string} value
 * @returns {string}
 */
export function normalizeFieldName( value ) {
	if ( typeof value !== 'string' ) return '';
	return value
		.toLowerCase()
		.trim()
		.replace( /[^a-z0-9\s_-]/g, '' )   // drop punctuation
		.replace( /[\s-]+/g, '_' )         // spaces/hyphens → underscore
		.replace( /_+/g, '_' )             // collapse repeats
		.replace( /^_+|_+$/g, '' );
}

/**
 * For a given HubSpot field, return the Neural Forms fields that are
 * "compatible" — meaning the dropdown for that HubSpot row should
 * show them. The list is ALWAYS the full set of supported NF fields,
 * but RE-ORDERED with the best candidates first so the UI's
 * "Auto-map" + manual selection both pick sensibly by default.
 *
 * Order rules (per spec):
 *   HubSpot email      → email > tel/phone > text > others
 *   HubSpot phone      → phone/tel > text > others
 *   HubSpot number     → number/range > text > others
 *   HubSpot date       → date > text > others
 *   HubSpot textarea   → textarea > text > email/phone/url > others
 *   HubSpot select/    → select > radio > checkboxes > checkbox > text > others
 *     radio/enum
 *   HubSpot file       → file is excluded for v1; falls back to text
 *                        (the field gets a "v1 cannot send files"
 *                        warning rendered by the React layer).
 *   anything else      → text-like first, then the rest
 *
 * @param {object} hubspotField   { name, type, field_type, ... }
 * @param {Array}  nfFields       Flattened NF input fields.
 * @returns {Array}               nfFields sorted with best-fit first.
 */
export function compatibleNfFieldsForHubSpot( hubspotField, nfFields ) {
	if ( ! Array.isArray( nfFields ) ) return [];
	const fieldType = String( hubspotField?.field_type || '' ).toLowerCase();
	const dataType  = String( hubspotField?.type       || '' ).toLowerCase();

	const preference = orderPreferenceFor( fieldType, dataType );
	const ranked = [ ...nfFields ];
	ranked.sort( ( a, b ) => {
		const ra = preference.indexOf( a.type );
		const rb = preference.indexOf( b.type );
		// Types not in the preference list go to the end, preserving
		// their relative order from the source list.
		const ka = ra === -1 ? preference.length : ra;
		const kb = rb === -1 ? preference.length : rb;
		if ( ka !== kb ) return ka - kb;
		return 0;
	} );
	return ranked;
}

function orderPreferenceFor( fieldType, dataType ) {
	switch ( fieldType ) {
		case 'email':
			return [ 'email', 'tel', 'phone', 'text', 'url', 'textarea', 'hidden' ];
		case 'phone':
		case 'tel':
			return [ 'phone', 'tel', 'text', 'email', 'hidden' ];
		case 'number':
			return [ 'number', 'range', 'text', 'hidden' ];
		case 'date':
			return [ 'date', 'text', 'hidden' ];
		case 'textarea':
			return [ 'textarea', 'text', 'email', 'phone', 'tel', 'url', 'hidden' ];
		case 'select':
		case 'radio':
		case 'checkbox':
			// Single-selection HubSpot inputs map cleanly to NF
			// select / radio / hidden. Allow text as last-resort.
			return [ 'select', 'radio', 'text', 'hidden' ];
		case 'file':
			// HubSpot file properties accept a string value (URL).
			// NF file is the best match — the delivery builder
			// turns the stored { filename, url } metadata into
			// "filename (url)" before shipping. Text-like fields
			// are the fallback for admins who'd rather pre-format
			// the value themselves (e.g. a hidden field carrying
			// a pre-uploaded CDN URL).
			return [ 'file', 'url', 'text', 'textarea', 'hidden' ];
	}
	// Fall back on the data type when fieldType is unknown.
	if ( dataType === 'enumeration' ) {
		return [ 'select', 'radio', 'checkboxes', 'text', 'hidden' ];
	}
	return [ 'text', 'email', 'phone', 'tel', 'url', 'textarea', 'select', 'radio', 'checkboxes', 'number', 'date', 'hidden' ];
}

/**
 * Auto-map heuristic. Given the HubSpot field list and the flattened
 * NF field list, return a `field_map` object proposing pairings.
 * Respects an `existing` map: HubSpot fields that already have a
 * non-empty mapping are left alone (auto-map never overwrites a
 * user's deliberate choice).
 *
 * Rules:
 *   - Exact normalized name match between HubSpot name and NF id
 *     OR HubSpot name and NF label.
 *   - Heuristic class match:
 *       email-like        → first NF email field
 *       firstname/lastname→ first NF text field whose label normalizes
 *                           to first_name / last_name
 *       phone/mobile      → first NF phone/tel field
 *       message/comment   → first NF textarea field
 *       company           → first NF text field whose label containing "company"
 *       website/url       → first NF url field
 *       file/upload/attachment/resume/document/cv
 *                         → first NF file field, else NF url field
 *   - The same NF field may map to AT MOST ONE HubSpot field. Once
 *     a NF field is "claimed", subsequent HubSpot matches fall back
 *     to other candidates.
 *
 * @param {Array}  hubspotFields  Normalized HubSpot fields list.
 * @param {Array}  nfFields       Flattened NF input fields.
 * @param {object} existing       Existing field_map (preserves user choices).
 * @returns {object}              { <hubspot_name>: <nf_field_id>, ... }
 */
export function autoMapHubSpotFields( hubspotFields, nfFields, existing = {} ) {
	const out = { ...( existing && typeof existing === 'object' ? existing : {} ) };
	const claimed = new Set();
	// Track NF fields already consumed by existing user choices so
	// the auto-map doesn't propose duplicates.
	for ( const v of Object.values( out ) ) {
		if ( typeof v === 'string' && v !== '' ) claimed.add( v );
	}

	const byId    = new Map( nfFields.map( ( f ) => [ String( f.id ), f ] ) );
	const byLabel = new Map( nfFields.map( ( f ) => [ normalizeFieldName( String( f.label || '' ) ), f ] ) );

	const pickFirstByType = ( types, predicate ) => {
		for ( const f of nfFields ) {
			if ( claimed.has( String( f.id ) ) ) continue;
			if ( ! types.includes( String( f.type ) ) ) continue;
			if ( predicate && ! predicate( f ) ) continue;
			return f;
		}
		return null;
	};

	for ( const hs of hubspotFields || [] ) {
		const name = String( hs?.name || '' );
		if ( name === '' ) continue;
		// Respect existing user-set mapping (even '' which means "Do not send").
		if ( Object.prototype.hasOwnProperty.call( out, name ) && out[ name ] !== undefined ) {
			continue;
		}
		const norm = normalizeFieldName( name );

		// 1) Exact id match.
		const idHit = byId.get( name ) || byId.get( norm );
		if ( idHit && ! claimed.has( String( idHit.id ) ) ) {
			out[ name ] = String( idHit.id );
			claimed.add( String( idHit.id ) );
			continue;
		}

		// 2) Exact normalized label match.
		const labelHit = byLabel.get( norm );
		if ( labelHit && ! claimed.has( String( labelHit.id ) ) ) {
			out[ name ] = String( labelHit.id );
			claimed.add( String( labelHit.id ) );
			continue;
		}

		// 3) Heuristic by HubSpot field type + name.
		const fieldType = String( hs?.field_type || '' ).toLowerCase();
		let pick = null;

		if ( fieldType === 'email' || /(^|_)email($|_)/.test( norm ) ) {
			pick = pickFirstByType( [ 'email' ] )
				|| pickFirstByType( [ 'text' ], ( f ) => /email/.test( normalizeFieldName( f.label || '' ) ) );
		} else if ( norm === 'firstname' || norm === 'first_name' ) {
			pick = pickFirstByType( [ 'text' ], ( f ) => /first(_name)?/.test( normalizeFieldName( f.label || '' ) ) );
		} else if ( norm === 'lastname' || norm === 'last_name' ) {
			pick = pickFirstByType( [ 'text' ], ( f ) => /last(_name)?/.test( normalizeFieldName( f.label || '' ) ) );
		} else if ( fieldType === 'phone' || /(phone|mobile)/.test( norm ) ) {
			pick = pickFirstByType( [ 'phone', 'tel' ] )
				|| pickFirstByType( [ 'text' ], ( f ) => /(phone|mobile)/.test( normalizeFieldName( f.label || '' ) ) );
		} else if ( /(^|_)(message|comment|comments)($|_)/.test( norm ) ) {
			pick = pickFirstByType( [ 'textarea' ] );
		} else if ( norm === 'company' ) {
			pick = pickFirstByType( [ 'text' ], ( f ) => /company/.test( normalizeFieldName( f.label || '' ) ) );
		} else if ( /(^|_)(website|url)($|_)/.test( norm ) ) {
			pick = pickFirstByType( [ 'url' ] );
		} else if ( fieldType === 'number' ) {
			pick = pickFirstByType( [ 'number', 'range' ] );
		} else if ( fieldType === 'date' ) {
			pick = pickFirstByType( [ 'date' ] );
		} else if ( fieldType === 'textarea' ) {
			pick = pickFirstByType( [ 'textarea' ] );
		} else if ( fieldType === 'select' || fieldType === 'radio' || String( hs?.type ).toLowerCase() === 'enumeration' ) {
			pick = pickFirstByType( [ 'select', 'radio' ] );
		} else if (
			fieldType === 'file'
			|| /(^|_)(file|upload|attachment|resume|document|cv)($|_)/.test( norm )
		) {
			// HubSpot file properties accept a URL string. NF file
			// is the natural target — the delivery builder packages
			// the entry's stored { filename, url } as "filename
			// (url)" so HubSpot stores a usable contact-property
			// value. We also fall back to a URL-typed NF field for
			// admins who pre-host their files.
			pick = pickFirstByType( [ 'file' ] )
				|| pickFirstByType( [ 'url' ] );
		}

		if ( pick ) {
			out[ name ] = String( pick.id );
			claimed.add( String( pick.id ) );
		}
	}

	return out;
}

/**
 * Find HubSpot fields that are flagged required but have no NF
 * mapping (or an empty-string mapping). Used by the React panel to
 * render a "Required HubSpot field X is not mapped" warning row.
 *
 * @param {Array}  hubspotFields
 * @param {object} fieldMap
 * @returns {Array}  HubSpot fields whose required=true and field_map[name] is missing/empty.
 */
export function findRequiredUnmapped( hubspotFields, fieldMap ) {
	if ( ! Array.isArray( hubspotFields ) ) return [];
	const map = ( fieldMap && typeof fieldMap === 'object' ) ? fieldMap : {};
	return hubspotFields.filter( ( hs ) => {
		if ( ! hs?.required ) return false;
		const v = map[ hs.name ];
		return v === undefined || v === null || v === '';
	} );
}
