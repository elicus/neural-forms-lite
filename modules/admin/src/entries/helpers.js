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
 * Build a `{ field_id: label }` map from a form row's `form_schema`.
 * Empty object when the schema is missing or contains no labelled
 * fields — callers should fall back to the raw key for display.
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
	const map = {};
	for ( const f of out ) {
		if ( ! f.id ) continue;
		const label = ( typeof f.label === 'string' && f.label.trim() !== '' )
			? f.label
			: String( f.id );
		map[ String( f.id ) ] = label;
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
