import { __ } from '@wordpress/i18n';
/**
 * Pure formatting + parsing helpers shared across the Entries screen.
 * No React, no API calls — anything stateful belongs in a hook.
 */

/**
 * Walk a form's nested schema and yield every leaf field. Schemas
 * can nest fields under rows / columns / steps (and steps can have
 * their own rows/columns), so we recurse anywhere a `fields` array
 * shows up. Mirrors Lite's PHP `SchemaHelper::flatten_fields`.
 */
function walkFields( fields, out ) {
	if ( ! Array.isArray( fields ) ) return;
	for ( const f of fields ) {
		if ( ! f || typeof f !== 'object' ) continue;
		// Repeater is a LEAF field that happens to carry its child
		// templates under `f.fields`. The wire shape stores it under
		// its OWN top-level id (entry.data[repeater_id] = […items]),
		// so we must emit the repeater itself and NOT recurse into
		// the children template (those are rendered per iteration
		// with prefixed ids on the wire — not addressable as their
		// raw ids here). Without this branch the walker treats the
		// repeater as a layout container and the entry-detail
		// formatter never finds the parent schema, falling back to
		// JSON.stringify().
		if ( f.type === 'repeater' ) {
			if ( f.id ) out.push( f );
			continue;
		}
		// Container nodes (row, column, step) carry their children
		// in a nested `fields` array; recurse but don't emit them.
		if ( Array.isArray( f.fields ) ) {
			walkFields( f.fields, out );
			continue;
		}
		// Step containers sometimes carry rows under a different key.
		if ( Array.isArray( f.rows ) ) {
			for ( const r of f.rows ) {
				if ( r && Array.isArray( r.fields ) ) walkFields( r.fields, out );
			}
			continue;
		}
		// Leaf field — must have an id to be addressable.
		if ( f.id ) out.push( f );
	}
}

/**
 * Human-readable fallback names per field type, used when a schema
 * field ships with no label (or with a label that's just its
 * auto-generated slug).
 */
const FIELD_TYPE_LABELS = {
	text:          'Text field',
	textarea:      'Message',
	email:         'Email address',
	phone:         'Phone number',
	number:        'Number',
	url:           'URL',
	select:        'Selection',
	radio:         'Choice',
	checkbox:      'Checkbox',
	checkboxes:    'Choices',
	date:          'Date',
	file:          'File upload',
	rating:        'Rating',
	acceptance:    'Consent',
	poll:          'Poll',
	quiz_question: 'Quiz question',
	matrix:        'Matrix',
	repeater:      'Group',
	hidden:        'Hidden field',
};

/** Detect labels shaped like the AI generator's auto-slug (e.g. `text_mpv1gytfc`). */
function looksLikeAutoSlug( s ) {
	return typeof s === 'string' && /^[a-z]+(?:_[a-z0-9]+){1,}$/.test( s );
}

function humanizeFieldType( type ) {
	return FIELD_TYPE_LABELS[ type ] || 'Field';
}

/**
 * Build a `{ field_id: label }` map from a form row's `form_schema`.
 * When a field's `label` is empty OR looks like an auto-generated slug,
 * fall back to a human-readable type name + counter (e.g. "Text field",
 * "Selection 1", "Selection 2"). Empty object when the schema is missing.
 */
export function buildFieldLabels( form ) {
	if ( ! form || typeof form !== 'object' ) return {};
	const schema = form.form_schema && typeof form.form_schema === 'object'
		? form.form_schema
		: null;
	if ( ! schema ) return {};
	const fields = Array.isArray( schema.fields ) ? schema.fields : [];
	const out    = [];
	walkFields( fields, out );

	// Pass 1: count how many fields of each type need a fallback label.
	// The counter suffix is only appended when a type has 2+ fallbacks —
	// a lone text field reads better as "Text field" than "Text field 1".
	const typeTotals = {};
	for ( const f of out ) {
		if ( ! f.id ) continue;
		const rawLabel = typeof f.label === 'string' ? f.label.trim() : '';
		const meaningful = rawLabel !== ''
			&& rawLabel !== String( f.id )
			&& ! looksLikeAutoSlug( rawLabel );
		if ( ! meaningful ) {
			const type = String( f.type || 'field' );
			typeTotals[ type ] = ( typeTotals[ type ] || 0 ) + 1;
		}
	}

	// Pass 2: build the map.
	const map          = {};
	const typeCounters = {};
	for ( const f of out ) {
		if ( ! f.id ) continue;
		const id       = String( f.id );
		const rawLabel = typeof f.label === 'string' ? f.label.trim() : '';
		const meaningful = rawLabel !== ''
			&& rawLabel !== id
			&& ! looksLikeAutoSlug( rawLabel );

		if ( meaningful ) {
			map[ id ] = rawLabel;
		} else {
			const type      = String( f.type || 'field' );
			const humanType = humanizeFieldType( type );
			if ( ( typeTotals[ type ] || 0 ) > 1 ) {
				typeCounters[ type ] = ( typeCounters[ type ] || 0 ) + 1;
				map[ id ] = humanType + ' ' + typeCounters[ type ];
			} else {
				map[ id ] = humanType;
			}
		}
	}
	return map;
}

/**
 * Build a `{ field_id: full_field_object }` map. The entry-detail
 * Matrix renderer needs the field's settings.rows / settings.columns
 * to resolve stored row/column values back to labels, so plain
 * `fieldLabels` is not enough. Empty object when the schema is
 * missing.
 */
export function buildFieldSchemaMap( form ) {
	if ( ! form || typeof form !== 'object' ) return {};
	const schema = form.form_schema && typeof form.form_schema === 'object'
		? form.form_schema
		: null;
	if ( ! schema ) return {};
	const fields = Array.isArray( schema.fields ) ? schema.fields : [];
	const out    = [];
	walkFields( fields, out );
	const map = {};
	for ( const f of out ) {
		if ( ! f.id ) continue;
		map[ String( f.id ) ] = f;
	}
	return map;
}

/**
 * Map a stored Repeater value to a per-item list of { childLabel,
 * childType, raw, display } lines. Mirrors PHP
 * RepeaterFormatter::format_value_lines.
 *
 * Walks the SCHEMA's children template so unknown wire keys drop
 * out naturally. Returns an empty list when the value isn't an
 * array or when the schema has no children.
 */
/**
 * Display text for a select / radio / checkboxes value: the option
 * LABEL(s) for the stored VALUE(s) ("laptop" → "Laptop"). Mirrors PHP
 * NotificationService::display_choice(). Returns null when the field
 * is not a choice field so callers fall through to their default.
 */
export function formatChoiceValue( value, field ) {
	if ( ! field || ! [ 'select', 'radio', 'checkboxes' ].includes( field.type ) ) return null;
	const labels = {};
	for ( const opt of ( Array.isArray( field.options ) ? field.options : [] ) ) {
		if ( ! opt || typeof opt !== 'object' ) continue;
		const val = opt.value != null && String( opt.value ) !== '' ? String( opt.value ) : String( opt.label ?? '' );
		if ( val === '' ) continue;
		labels[ val ] = ( opt.label != null && String( opt.label ).trim() !== '' ) ? String( opt.label ) : val;
	}
	const values = Array.isArray( value ) ? value.filter( ( v ) => v != null && typeof v !== 'object' ) : [ value ];
	return values
		.filter( ( v ) => v != null && String( v ) !== '' )
		.map( ( v ) => labels[ String( v ) ] ?? String( v ) )
		.join( ', ' );
}

/**
 * Product value { choice, qty, amount } → "SEO, Copywriting × 2" (mirrors
 * PHP ProductFormatter::display).
 */
export function formatProductValue( value, field ) {
	if ( ! field || field.type !== 'product' ) return null;
	const p = ( field.pricing && typeof field.pricing === 'object' ) ? field.pricing : { mode: 'options' };
	const v = ( value && typeof value === 'object' && ! Array.isArray( value ) ) ? value : {};
	const qtyOn = !! ( p.quantity && p.quantity.enabled );
	const qty = qtyOn && v.qty != null && v.qty !== '' ? Number( v.qty ) : 1;
	const suffix = qtyOn ? ' × ' + qty : '';
	if ( p.mode === 'input' ) return v.amount != null ? String( v.amount ) : '';
	if ( p.mode === 'fixed' ) return __( 'Included', 'neural-forms-lite' ) + suffix;
	const labels = {};
	for ( const opt of ( Array.isArray( field.options ) ? field.options : [] ) ) {
		if ( ! opt ) continue;
		const val = opt.value != null && String( opt.value ) !== '' ? String( opt.value ) : String( opt.label ?? '' );
		if ( val ) labels[ val ] = opt.label ? String( opt.label ) : val;
	}
	const choices = Array.isArray( v.choice ) ? v.choice : ( v.choice != null && v.choice !== '' ? [ v.choice ] : [] );
	const text = choices.map( ( c ) => labels[ String( c ) ] ?? String( c ) ).join( ', ' );
	return text ? text + suffix : '';
}

/** Minor units → "$1,250.50" (mirrors PHP Money::format for the inbox). */
export function formatMinor( minor, currency ) {
	const exp = { BIF: 0, CLP: 0, DJF: 0, GNF: 0, JPY: 0, KMF: 0, KRW: 0, MGA: 0, PYG: 0, RWF: 0, UGX: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0, BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3 }[ currency ] ?? 2;
	const sym = { USD: '$', EUR: '€', GBP: '£', INR: '₹', AUD: 'A$', CAD: 'C$', NZD: 'NZ$', SGD: 'S$', JPY: '¥', ZAR: 'R', BRL: 'R$', MXN: 'MX$' }[ currency ] ?? ( currency + ' ' );
	const n = Number( minor ) || 0;
	const neg = n < 0;
	const abs = Math.abs( n );
	const whole = Math.floor( abs / Math.pow( 10, exp ) );
	const frac = String( abs % Math.pow( 10, exp ) ).padStart( exp, '0' );
	return ( neg ? '-' : '' ) + sym + whole.toString().replace( /\B(?=(\d{3})+(?!\d))/g, ',' ) + ( exp ? '.' + frac : '' );
}

export function formatRepeaterValueLines( value, field ) {
	const out = [];
	if ( ! field || typeof field !== 'object' ) return out;
	const childrenSchema = Array.isArray( field.fields ) ? field.fields : [];
	const items = Array.isArray( value ) ? value : [];
	if ( childrenSchema.length === 0 ) return out;

	for ( let i = 0; i < items.length; i++ ) {
		const row = items[ i ];
		if ( ! row || typeof row !== 'object' ) continue;
		const childLines = [];
		for ( const child of childrenSchema ) {
			if ( ! child || typeof child !== 'object' || ! child.id ) continue;
			const childId    = String( child.id );
			const childType  = String( child.type || 'text' );
			const childLabel = String( child.label || childId );
			const raw        = ( childId in row ) ? row[ childId ] : null;
			childLines.push( {
				childId,
				childLabel,
				childType,
				raw,
				display: repeaterChildDisplay( child, raw ),
			} );
		}
		out.push( { itemIndex: i, childLines } );
	}
	return out;
}

function repeaterChildDisplay( child, raw ) {
	const type = String( child.type || 'text' );
	if ( raw == null ) return '';

	if ( type === 'radio' || type === 'select' ) {
		const v = typeof raw === 'string' || typeof raw === 'number' ? String( raw ) : '';
		if ( v === '' ) return '';
		return repeaterOptionLabel( child, v );
	}
	if ( type === 'checkboxes' ) {
		const arr = Array.isArray( raw ) ? raw : [];
		const out = [];
		for ( const v of arr ) {
			if ( v == null ) continue;
			const s = String( v );
			if ( s === '' ) continue;
			out.push( repeaterOptionLabel( child, s ) );
		}
		return out.join( ', ' );
	}
	if ( type === 'checkbox' || type === 'acceptance' ) {
		const truthy = ( raw === 1 || raw === '1' || raw === true );
		return truthy ? 'Yes' : 'No';
	}
	if ( type === 'file' ) {
		if ( raw && typeof raw === 'object' && typeof raw.filename === 'string' ) {
			return raw.filename;
		}
		return '';
	}
	if ( Array.isArray( raw ) ) {
		return raw.filter( ( v ) => v != null && typeof v !== 'object' ).map( String ).join( ', ' );
	}
	return String( raw );
}

function repeaterOptionLabel( child, value ) {
	const options = Array.isArray( child.options ) ? child.options : [];
	for ( const opt of options ) {
		if ( ! opt || typeof opt !== 'object' ) continue;
		const ov = String( opt.value || '' );
		const ol = String( opt.label || '' );
		const eff = ov !== '' ? ov : ol;
		if ( eff === value ) {
			return ol !== '' ? ol : eff;
		}
	}
	return value;
}

/**
 * Map a stored Matrix value to a list of { rowLabel, display } pairs
 * in schema-row order. Mirrors PHP MatrixFormatter::format_value_lines.
 *
 * For radio mode each `display` is a single column label; for checkbox
 * mode it is the column labels joined with ", ". Rows whose stored
 * value is absent/empty appear with display === '' so the renderer can
 * decide how to show them (dash, "No answer", etc.).
 */
export function formatMatrixValueLines( value, field ) {
	const out = [];
	if ( ! field || typeof field !== 'object' ) return out;
	const settings  = ( field.settings && typeof field.settings === 'object' ) ? field.settings : {};
	const selection = settings.selection_type === 'checkbox' ? 'checkbox' : 'radio';
	const rowsRaw   = Array.isArray( settings.rows ) ? settings.rows : [];
	const colsRaw   = Array.isArray( settings.columns ) ? settings.columns : [];
	const v         = ( value && typeof value === 'object' ) ? value : {};

	const colLabels = {};
	for ( const c of colsRaw ) {
		if ( ! c || typeof c !== 'object' ) continue;
		const cv = String( c.value || '' );
		const cl = String( c.label || '' );
		const eff = cv !== '' ? cv : cl;
		if ( eff === '' ) continue;
		colLabels[ eff ] = cl !== '' ? cl : eff;
	}

	for ( const r of rowsRaw ) {
		if ( ! r || typeof r !== 'object' ) continue;
		const rv = String( r.value || '' );
		const rl = String( r.label || '' );
		const rowValue = rv !== '' ? rv : rl;
		if ( rowValue === '' ) continue;
		const rowLabel = rl !== '' ? rl : rowValue;

		const raw = v[ rowValue ];
		let display = '';

		if ( selection === 'checkbox' && Array.isArray( raw ) ) {
			const picked = [];
			for ( const x of raw ) {
				if ( x == null ) continue;
				const s = String( x );
				if ( s === '' ) continue;
				picked.push( colLabels[ s ] != null ? colLabels[ s ] : s );
			}
			display = picked.join( ', ' );
		} else if ( selection === 'radio' && ( typeof raw === 'string' || typeof raw === 'number' ) ) {
			const s = String( raw );
			if ( s !== '' ) display = colLabels[ s ] != null ? colLabels[ s ] : s;
		}

		out.push( { rowValue, rowLabel, display } );
	}

	return out;
}

export function formatDate( s ) {
	if ( ! s ) return '';
	const d = new Date( s.replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( d.getTime() ) ) return s;
	return d.toLocaleString();
}

/**
 * Build the short Preview column text from an entry's `preview`
 * payload (the first three entry-data values the server slices off).
 *
 * The endpoint has no schema awareness — preview values arrive in
 * their stored shape: scalars for text-like fields, arrays of
 * strings for checkbox groups, parallel-keyed objects for matrix,
 * arrays of objects for repeater, file metadata objects for file
 * uploads. The previous implementation called Array.prototype.join
 * on a repeater value, which invoked each item object's default
 * .toString() and produced "[object Object], [object Object]".
 *
 * This helper now routes every value through a recursive
 * stringifier that:
 *   - flattens arrays of objects (repeater) into "item summary;
 *     item summary; +N more" where each item summary is the first
 *     two non-empty scalar values joined with " · ".
 *   - extracts the filename from a file metadata object.
 *   - emits up to two scalar values from a plain object (matrix /
 *     any other future field type) joined with " · ".
 *   - never returns "[object Object]" or a raw "{" / "[" character.
 *
 * No schema is required — the function is purely structural — so
 * the list endpoint stays unaware of field types. Output is capped
 * at 200 chars to fit the table cell.
 *
 * @param {Object} preview
 * @returns {string}
 */
export function previewSummary( preview ) {
	if ( ! preview || typeof preview !== 'object' ) return '';
	const parts = [];
	for ( const v of Object.values( preview ) ) {
		const formatted = formatPreviewValue( v );
		if ( formatted === '' ) continue;
		parts.push( formatted );
		if ( parts.length >= 2 ) break;
	}
	return parts.join( ' · ' ).slice( 0, 200 );
}

/**
 * Top-level stringifier for one preview value. Handles the
 * structured shapes the entries pipeline stores (file metadata,
 * matrix rows, repeater iteration lists) plus the legacy scalars.
 */
function formatPreviewValue( v ) {
	if ( v == null ) return '';
	if ( typeof v === 'string' )  return v;
	if ( typeof v === 'number' )  return String( v );
	if ( typeof v === 'boolean' ) return v ? '1' : '0';

	if ( Array.isArray( v ) ) {
		// Empty array (checkboxes group with no picks, etc.).
		if ( v.length === 0 ) return '';
		// Array of scalars (legacy checkboxes / matrix-checkbox row
		// when not nested through the object layer). Join with the
		// pre-existing comma+space convention.
		if ( v.every( ( x ) => x == null || typeof x !== 'object' ) ) {
			return v.filter( ( x ) => x != null && x !== '' ).map( String ).join( ', ' );
		}
		// Array of objects (Repeater iterations). Summarise each item
		// (first two non-empty scalar values per object) and join
		// with "; ". Truncate to 2 items + "+N more" so the table
		// cell doesn't overflow.
		const summaries = [];
		for ( const item of v ) {
			const s = formatPreviewObject( item );
			if ( s !== '' ) summaries.push( s );
		}
		if ( summaries.length === 0 ) return '';
		const MAX = 2;
		const head = summaries.slice( 0, MAX ).join( '; ' );
		const rest = summaries.length - MAX;
		return rest > 0 ? `${ head }; +${ rest } more` : head;
	}

	if ( typeof v === 'object' ) {
		// File metadata short-circuit — these objects carry a
		// `filename` string that's the natural preview surface.
		if ( typeof v.filename === 'string' && v.filename !== '' ) {
			return v.filename;
		}
		// Matrix row→col map OR any other plain object: pull the
		// first non-empty scalar values out.
		return formatPreviewObject( v );
	}

	return '';
}

/**
 * Summarise a single object value (one repeater iteration, one
 * matrix row map, etc.) by collecting up to two non-empty scalar
 * values joined with " · ". Recursive arrays / file metadata
 * nested inside the object are flattened to a single string before
 * being considered.
 */
function formatPreviewObject( obj ) {
	if ( ! obj || typeof obj !== 'object' || Array.isArray( obj ) ) return '';
	const scalars = [];
	for ( const val of Object.values( obj ) ) {
		if ( val == null || val === '' ) continue;
		let piece = '';
		if ( typeof val === 'string' )       piece = val;
		else if ( typeof val === 'number' || typeof val === 'boolean' ) piece = String( val );
		else if ( Array.isArray( val ) ) {
			piece = val.filter( ( x ) => x != null && typeof x !== 'object' && x !== '' ).map( String ).join( ', ' );
		}
		else if ( typeof val === 'object' && typeof val.filename === 'string' && val.filename !== '' ) {
			piece = val.filename;
		}
		if ( piece === '' ) continue;
		scalars.push( piece );
		if ( scalars.length >= 2 ) break;
	}
	return scalars.join( ' · ' );
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
 * Pull the structured "credits exhausted" payload from a 402 response.
 *
 * Returns `{ message, upgradeUrl, balance }` when the REST error code is
 * `credits_exhausted` (Lite/Pro pre-flight when local cache reports zero
 * credits) — null otherwise. Callers render an inline "Buy credits" CTA
 * pointing at upgradeUrl instead of the generic error toast.
 */
export function creditsExhaustedPayload( err ) {
	if ( ! err || typeof err !== 'object' ) return null;
	const code = err.code || err.data?.code;
	if ( code !== 'credits_exhausted' ) return null;
	return {
		message:    err.message || '',
		upgradeUrl: err.data?.upgrade_url || 'https://neuralforms.ai/pricing',
		balance:    err.data?.balance || null,
	};
}

/**
 * Proactive credits-exhausted check.
 *
 * Returns a payload (matching creditsExhaustedPayload's shape) when:
 *   - Pro is the one rendering AI actions (capabilities allow them), AND
 *   - the live plan snapshot says the subscription is active, AND
 *   - the credit balance is zero or negative.
 *
 * Used by AiAnalysisCard + ReplyComposer to swap the action buttons
 * for a "Credits exhausted" notice BEFORE the user clicks — saving the
 * round-trip and the doomed-request error. Returns null otherwise so
 * callers fall through to the normal action rendering.
 *
 * Subscription gate matters: a user on a free plan / no subscription
 * shouldn't see this notice — they need the upgrade-to-Pro upsell,
 * which is the existing canReanalyze=false path.
 */
export function liveCreditsExhausted() {
	if ( typeof window === 'undefined' ) return null;
	const plan = window?.neuralFormsAdmin?.plan;
	if ( ! plan || typeof plan !== 'object' ) return null;
	const status = String( plan?.subscription?.status || '' ).toLowerCase();
	if ( status !== 'active' && status !== 'trialing' ) return null;
	const credits = Number( plan?.balance?.credits ?? plan?.balance?.available ?? NaN );
	if ( ! Number.isFinite( credits ) || credits > 0 ) return null;
	return {
		message:    '',
		upgradeUrl: plan?.upgrade_url || 'https://neuralforms.ai/pricing',
		balance:    plan?.balance || null,
	};
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
