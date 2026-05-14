/**
 * Mailchimp merge-field compatibility + auto-map heuristics for the
 * builder Settings → Integrations → Mailchimp panel.
 *
 * Three exports:
 *
 *   compatibleNfFieldsForMailchimp( mergeField, nfFields )
 *     For a given Mailchimp merge field, return the NF field list
 *     re-ordered with the best candidates first. Always returns the
 *     full list — Mailchimp's text-like merge fields can technically
 *     accept any scalar, so the dropdown should expose every option
 *     to admins.
 *
 *   autoMapMailchimpEmailField( nfFields )
 *     Mailchimp's subscriber email_address is NOT a regular merge
 *     field — it's the primary key of the audience. This helper
 *     picks the NF email-typed field that should drive it (the
 *     panel surfaces this as a dedicated "Mailchimp Email Address"
 *     dropdown, separate from the merge-field mapping table).
 *
 *   autoMapMailchimpMergeFields( mergeFields, nfFields, existing )
 *     Propose a merge_map object. Respects `existing`: any tag
 *     already mapped is left alone (auto-map never overrides a
 *     deliberate choice). Reserved EMAIL tag is skipped — it's
 *     handled by autoMapMailchimpEmailField. Each NF field id can
 *     only be consumed by ONE merge tag — once FNAME claims "Full
 *     Name", LNAME won't try to grab the same field.
 *
 * Compatibility rules (per spec):
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
 * Auto-map cascade (per spec):
 *   EMAIL    → handled SEPARATELY by autoMapMailchimpEmailField; the
 *              tag is skipped in the merge_map loop.
 *   FNAME /  → cascading priority:
 *      FIRST   1. label/id contains first_name / firstname / first name
 *              2. label/id contains full_name / fullname / full name
 *              3. label/id equals name / your name / contact name
 *              4. first text field whose label contains "name" but
 *                  NOT "last"/"surname" (so a "Last Name"-only form
 *                  doesn't accidentally claim it for FNAME)
 *   LNAME /  → strict: label/id contains last_name / lastname /
 *      LAST     last name / surname. If no match, LEAVE UNMAPPED.
 *              Never falls back to "Full Name" or generic "Name" —
 *              that would duplicate the same value into both first
 *              and last name slots.
 *   PHONE    → first NF phone/tel field; else first text/hidden
 *              field whose label matches /phone|mobile|cell|tel/i.
 *   COMPANY  → label/id matches /company|business|organization/i
 *   WEBSITE  → first NF url field, else label matches
 *              /website|url|site/i
 *   ADDRESS  → ONLY when label matches /address/i. Never force-maps
 *              to a generic textarea/text field.
 *   anything → exact normalised name match between merge_tag and
 *      else      NF label / id, otherwise skipped.
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
 * Pick the NF email-typed field that should drive Mailchimp's
 * subscriber email_address. Returns the field id or '' when no
 * email-typed field exists.
 *
 * Preference: the FIRST email-typed field (matches the natural
 * order forms tend to present their email field — top of the form).
 */
export function autoMapMailchimpEmailField( nfFields ) {
	if ( ! Array.isArray( nfFields ) ) return '';
	const hit = nfFields.find( ( f ) => f && f.type === 'email' );
	return hit && hit.id ? String( hit.id ) : '';
}

/**
 * Propose a merge_map. Respects the existing map: tags already
 * mapped are left alone. Returns a fresh object including the
 * preserved entries plus any new ones the heuristic could resolve.
 *
 * Consumed-id tracking: a single NF field id is never assigned to
 * more than one merge tag in a single auto-map pass. This protects
 * the "Full Name only" case from FNAME and LNAME both pointing at
 * the same field (which would duplicate the value into both Mailchimp
 * columns).
 */
export function autoMapMailchimpMergeFields( mergeFields, nfFields, existing ) {
	const next = { ...( existing && typeof existing === 'object' ? existing : {} ) };
	if ( ! Array.isArray( mergeFields ) || ! Array.isArray( nfFields ) ) return next;

	// Seed the consumed set from the existing map so the cascade
	// can't propose a field that the admin has already wired to a
	// different tag manually.
	const consumed = new Set();
	for ( const v of Object.values( next ) ) {
		if ( typeof v === 'string' && v !== '' ) consumed.add( v );
	}

	for ( const mf of mergeFields ) {
		const tag = String( mf?.tag || '' ).toUpperCase();
		if ( ! tag || tag === 'EMAIL' )       continue;
		if ( next[ tag ] && next[ tag ] !== '' ) continue; // never overwrite

		const candidate = pickCandidateForTag( tag, mf, nfFields, consumed );
		if ( candidate && candidate.id ) {
			next[ tag ] = candidate.id;
			consumed.add( candidate.id );
		}
	}
	return next;
}

function pickCandidateForTag( tag, mf, nfFields, consumed ) {
	// Generic FIRST PASS: exact normalised name match against NF
	// label / id. Skips fields already consumed by another tag.
	const tagNorm = normalize( tag );
	const tagName = normalize( mf?.name );
	const exact = nfFields.find( ( f ) => {
		if ( consumed.has( f.id ) ) return false;
		const lblN = normalize( f.label || '' );
		const idN  = normalize( f.id    || '' );
		return ( lblN && ( lblN === tagNorm || lblN === tagName ) ) ||
		       ( idN  && ( idN  === tagNorm || idN  === tagName ) );
	} );
	if ( exact ) return exact;

	// Class heuristics — matched on the tag value, then on field
	// label so admins who reuse stock names still get auto-mapped.
	switch ( tag ) {
		case 'FNAME':
		case 'FIRST':
			return pickFirstName( nfFields, consumed );
		case 'LNAME':
		case 'LAST':
			return pickLastName( nfFields, consumed );
		case 'PHONE':
			return pickPhone( nfFields, consumed );
		case 'COMPANY':
			return findFirstAvailable( nfFields, consumed, [
				{ regex: /\b(company|business|organization|org)\b/i, types: [ 'text' ] },
			] );
		case 'WEBSITE':
			return findFirstAvailable( nfFields, consumed, [
				{ regex: null, types: [ 'url' ] },
				{ regex: /\b(website|url|site|homepage)\b/i, types: [ 'text' ] },
			] );
		case 'ADDRESS':
			// Address is strict: only when the label says "address".
			// Never force-map to a generic textarea.
			return findFirstAvailable( nfFields, consumed, [
				{ regex: /\baddress\b/i, types: [ 'textarea', 'text' ] },
			] );
		default:
			return null;
	}
}

/**
 * FNAME cascade per spec:
 *   1. first_name / firstname / first name
 *   2. full_name / fullname / full name
 *   3. label/id equals "name" / "your name" / "contact name"
 *   4. any text field whose label contains "name", EXCLUDING
 *      "last" / "surname" so a Last-Name-only form doesn't
 *      claim it here.
 */
function pickFirstName( nfFields, consumed ) {
	// 1. First Name
	const step1 = findFirstAvailable( nfFields, consumed, [
		{ regex: /\bfirst[\s_-]?name\b/i, types: [ 'text' ] },
		{ regex: /\bfname\b/i,            types: [ 'text' ] },
	] );
	if ( step1 ) return step1;

	// 2. Full Name
	const step2 = findFirstAvailable( nfFields, consumed, [
		{ regex: /\bfull[\s_-]?name\b/i, types: [ 'text' ] },
		{ regex: /\bfullname\b/i,        types: [ 'text' ] },
	] );
	if ( step2 ) return step2;

	// 3. Exact "name" / "your name" / "contact name" (label
	//    normalised — no trailing words). normalize("Your Name")
	//    → "your_name"; we test the normalised form here.
	const step3 = nfFields.find( ( f ) => {
		if ( consumed.has( f.id ) ) return false;
		if ( f.type !== 'text' )    return false;
		const lblN = normalize( f.label || '' );
		const idN  = normalize( f.id    || '' );
		return lblN === 'name'         || idN === 'name'
		    || lblN === 'your_name'    || idN === 'your_name'
		    || lblN === 'contact_name' || idN === 'contact_name';
	} );
	if ( step3 ) return step3;

	// 4. Broad fallback: text field with "name" in label, but NOT
	//    one that looks like a Last Name (that's LNAME's job).
	return nfFields.find( ( f ) => {
		if ( consumed.has( f.id ) ) return false;
		if ( f.type !== 'text' )    return false;
		const lbl = String( f.label || '' );
		if ( /\b(last|surname)\b/i.test( lbl ) ) return false;
		return /\bname\b/i.test( lbl );
	} ) || null;
}

/**
 * LNAME: STRICT match — last name / surname only. No fallback.
 * If no last-name-shaped field exists, LNAME stays unmapped.
 */
function pickLastName( nfFields, consumed ) {
	return findFirstAvailable( nfFields, consumed, [
		{ regex: /\blast[\s_-]?name\b/i, types: [ 'text' ] },
		{ regex: /\blname\b/i,           types: [ 'text' ] },
		{ regex: /\bsurname\b/i,         types: [ 'text' ] },
	] );
}

/**
 * PHONE: prefer NF phone/tel-typed field. Fall back to a text/hidden
 * field whose label matches /phone|mobile|cell|tel/i.
 */
function pickPhone( nfFields, consumed ) {
	const byType = nfFields.find( ( f ) => {
		if ( consumed.has( f.id ) ) return false;
		return f.type === 'phone' || f.type === 'tel';
	} );
	if ( byType ) return byType;

	return findFirstAvailable( nfFields, consumed, [
		{ regex: /\b(phone|mobile|cell|tel|telephone)\b/i, types: [ 'text', 'hidden' ] },
	] );
}

/**
 * Run an ordered list of (regex, types) selectors and return the
 * first NF field that matches. A null regex means "type filter
 * only" (used for WEBSITE's "any url field" pass).
 */
function findFirstAvailable( nfFields, consumed, selectors ) {
	for ( const sel of selectors ) {
		const hit = nfFields.find( ( f ) => {
			if ( consumed.has( f.id ) ) return false;
			if ( Array.isArray( sel.types ) && sel.types.length > 0 && ! sel.types.includes( f.type ) ) return false;
			if ( sel.regex && ! sel.regex.test( String( f.label || '' ) ) && ! sel.regex.test( String( f.id || '' ) ) ) return false;
			return true;
		} );
		if ( hit ) return hit;
	}
	return null;
}

/**
 * Helper for the panel UI: does the given NF field id look like
 * a "Full Name" field (vs. a dedicated First Name)? The panel
 * surfaces a hint when FNAME has been auto-mapped onto a Full
 * Name field so admins know the value will land in Mailchimp's
 * First Name column.
 */
export function isFullNameField( nfField ) {
	if ( ! nfField ) return false;
	const lbl = String( nfField.label || '' );
	const id  = String( nfField.id    || '' );
	return /\bfull[\s_-]?name\b/i.test( lbl ) || /\bfullname\b/i.test( lbl )
	    || /\bfull[\s_-]?name\b/i.test( id  ) || /\bfullname\b/i.test( id );
}
