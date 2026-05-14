/**
 * Mailchimp merge-field compatibility + auto-map heuristics for the
 * builder Settings → Integrations → Mailchimp panel.
 *
 * Two exports:
 *
 *   compatibleNfFieldsForMailchimp( mergeField, nfFields )
 *     For a given Mailchimp merge field, return the NF field list
 *     re-ordered with the best candidates first. Always returns the
 *     full list — Mailchimp's text-like merge fields can technically
 *     accept any scalar, so the dropdown should expose every option
 *     to admins.
 *
 *   autoMapMailchimpMergeFields( mergeFields, nfFields, existing )
 *     Propose a merge_map object. Respects `existing`: any tag
 *     already mapped is left alone (auto-map never overrides a
 *     deliberate choice). Reserved EMAIL tag is skipped — the
 *     subscriber's email_field key drives the email_address, not
 *     merge_map (matches the sanitizer's behavior).
 *
 * Compatibility rules (per C3 spec):
 *   Mailchimp text     → text > email > phone > tel > url > textarea > hidden
 *   Mailchimp number   → number > range > text > hidden
 *   Mailchimp phone    → phone > tel > text > hidden
 *   Mailchimp date /   → date > text > hidden
 *      birthday
 *   Mailchimp address  → textarea > text > hidden
 *   Mailchimp dropdown → select > radio > text > hidden
 *      / radio
 *   Mailchimp imageurl → url > text > hidden
 *   Mailchimp zip      → text > number > hidden
 *
 * Auto-map heuristic (per C3 spec):
 *   EMAIL    → handled SEPARATELY by the email_field key (not in
 *              merge_map; see sanitizer)
 *   FNAME /  → first NF field whose label matches /first|fname|name/i,
 *      FIRST     preferring `text` type
 *   LNAME /  → first NF field whose label matches /last|lname|surname/i
 *      LAST
 *   PHONE    → first NF phone/tel field
 *   COMPANY  → first NF field whose label matches /company|business/i
 *   WEBSITE  → first NF url field
 *   ADDRESS  → first NF textarea field with /address/i label, else
 *              first textarea
 *   anything → exact normalised name match between merge_tag and
 *      else      NF label / id, otherwise skipped
 */

function normalize( s ) {
	return String( s || '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /_+/g, '_' )
		.replace( /^_+|_+$/g, '' );
}

/**
 * Return the NF fields sorted with the best-fit candidates first
 * for a given Mailchimp merge field.
 */
export function compatibleNfFieldsForMailchimp( mergeField, nfFields ) {
	if ( ! Array.isArray( nfFields ) ) return [];
	const type = String( mergeField?.type || '' ).toLowerCase();
	const preference = orderPreferenceFor( type );
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

function orderPreferenceFor( type ) {
	switch ( type ) {
		case 'number':
			return [ 'number', 'range', 'text', 'hidden' ];
		case 'phone':
			return [ 'phone', 'tel', 'text', 'hidden' ];
		case 'date':
		case 'birthday':
			return [ 'date', 'text', 'hidden' ];
		case 'address':
			return [ 'textarea', 'text', 'hidden' ];
		case 'dropdown':
		case 'radio':
			return [ 'select', 'radio', 'text', 'hidden' ];
		case 'imageurl':
		case 'url':
			return [ 'url', 'text', 'hidden' ];
		case 'zip':
			return [ 'text', 'number', 'hidden' ];
		case 'text':
		default:
			return [ 'text', 'email', 'phone', 'tel', 'url', 'textarea', 'hidden' ];
	}
}

/**
 * Propose a merge_map. Respects the existing map: tags already
 * mapped are left alone. Returns a fresh object including the
 * preserved entries plus any new ones the heuristic could resolve.
 */
export function autoMapMailchimpMergeFields( mergeFields, nfFields, existing ) {
	const next = { ...( existing && typeof existing === 'object' ? existing : {} ) };
	if ( ! Array.isArray( mergeFields ) || ! Array.isArray( nfFields ) ) return next;

	for ( const mf of mergeFields ) {
		const tag = String( mf?.tag || '' ).toUpperCase();
		if ( ! tag || tag === 'EMAIL' )       continue;
		if ( next[ tag ] && next[ tag ] !== '' ) continue; // never overwrite

		const candidate = pickCandidateForTag( tag, mf, nfFields );
		if ( candidate ) next[ tag ] = candidate.id;
	}
	return next;
}

function pickCandidateForTag( tag, mf, nfFields ) {
	const tagNorm = normalize( tag );
	const tagName = normalize( mf?.name );

	// Generic: exact normalised name match against NF label / id.
	const exact = nfFields.find( ( f ) => {
		const lblN = normalize( f.label || '' );
		const idN  = normalize( f.id    || '' );
		return ( lblN && ( lblN === tagNorm || lblN === tagName ) ) ||
		       ( idN  && ( idN  === tagNorm || idN  === tagName ) );
	} );
	if ( exact ) return exact;

	// Class heuristics — matched on the merge tag, then on the field
	// label so admins who reuse a stock name like "company" still get
	// auto-mapped.
	switch ( tag ) {
		case 'FNAME':
		case 'FIRST':
			return findByLabel( nfFields, /\b(first|fname|name)\b/i, [ 'text' ] );
		case 'LNAME':
		case 'LAST':
			return findByLabel( nfFields, /\b(last|lname|surname)\b/i, [ 'text' ] );
		case 'PHONE':
			return findByType( nfFields, [ 'phone', 'tel' ] );
		case 'COMPANY':
			return findByLabel( nfFields, /\b(company|business|organization)\b/i, [ 'text' ] );
		case 'WEBSITE':
			return findByType( nfFields, [ 'url' ] )
				|| findByLabel( nfFields, /\b(website|url|site)\b/i, [ 'text' ] );
		case 'ADDRESS':
			return findByLabel( nfFields, /\baddress\b/i, [ 'textarea' ] )
				|| findByType( nfFields, [ 'textarea' ] );
		default:
			return null;
	}
}

function findByLabel( nfFields, regex, typePreference ) {
	const matches = nfFields.filter( ( f ) => regex.test( String( f.label || '' ) ) );
	if ( matches.length === 0 ) return null;
	if ( ! Array.isArray( typePreference ) || typePreference.length === 0 ) return matches[ 0 ];
	matches.sort( ( a, b ) => {
		const ra = typePreference.indexOf( a.type );
		const rb = typePreference.indexOf( b.type );
		const ka = ra === -1 ? typePreference.length : ra;
		const kb = rb === -1 ? typePreference.length : rb;
		return ka - kb;
	} );
	return matches[ 0 ];
}

function findByType( nfFields, types ) {
	for ( const t of types ) {
		const hit = nfFields.find( ( f ) => f.type === t );
		if ( hit ) return hit;
	}
	return null;
}
