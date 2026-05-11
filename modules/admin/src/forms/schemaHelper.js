/**
 * Mirror of PHP SchemaHelper for the React builder.
 *
 * Rows are layout containers, never form fields. Validation, submission,
 * exports, and notifications all flatten rows + columns away on the
 * server. The builder UI handles them as draggable groups.
 */

export const ROW_TYPE     = 'row';
export const COLUMN_TYPE  = 'column';
export const STEP_TYPE    = 'step';
export const SECTION_TYPE = 'section';
export const MIN_COLUMNS  = 1;
export const MAX_COLUMNS  = 4;
export const VALID_GAPS   = [ 'none', 'small', 'medium', 'large' ];
export const DEFAULT_GAP  = 'medium';

export const DEFAULT_STEP_BUTTON_NEXT     = 'Next';
export const DEFAULT_STEP_BUTTON_PREVIOUS = 'Back';

export const VALID_SECTION_STYLES  = [ 'default', 'minimal', 'boxed' ];
export const DEFAULT_SECTION_STYLE = 'default';

let _idCounter = 0;
function token( prefix ) {
	_idCounter += 1;
	return prefix + '_' + Date.now().toString( 36 ) + _idCounter.toString( 36 );
}

export const newRowId     = () => token( 'row' );
export const newColumnId  = () => token( 'col' );
export const newStepId    = () => token( 'step' );
export const newSectionId = () => token( 'section' );
export const newFieldId   = ( type ) => token( ( type || 'field' ).replace( /[^a-z0-9]+/gi, '' ).toLowerCase() );

export function isRow( node ) {
	return !! ( node && node.type === ROW_TYPE );
}

/**
 * True when a node is a layout step. Steps are top-level layout
 * containers - they own a `fields[]` array of real fields and rows.
 * Steps NEVER appear inside other steps, columns, or rows.
 */
export function isStep( node ) {
	return !! ( node && node.type === STEP_TYPE );
}

/**
 * True when a node is a layout section (visual divider / heading +
 * optional description). Sections are leaves - they never contain
 * fields in 1.1.0.
 */
export function isSection( node ) {
	return !! ( node && node.type === SECTION_TYPE );
}

/**
 * True when a node looks like a layout column (lives inside a row).
 *
 * Columns historically had no `type` field, so we accept "shape
 * matches column" too: a plain object with an array `fields` and no
 * `type` (or `type === "column"`). Exposing this lets callers reason
 * about layout-vs-real-field without hard-coding the prefix check.
 */
export function isColumn( node ) {
	if ( ! node || typeof node !== 'object' ) return false;
	if ( node.type === COLUMN_TYPE ) return true;
	if ( node.type ) return false;
	return Array.isArray( node.fields );
}

export function isLayoutNode( node ) {
	return isRow( node ) || isColumn( node ) || isStep( node ) || isSection( node );
}

/**
 * True when a node represents a real input field that produces an
 * entry value. Anything with `type` set and not equal to "row",
 * "column", "step", or "section" qualifies. Used by validators /
 * submission flatteners to know what counts as user data vs.
 * structural scaffolding.
 */
export function isRealField( node ) {
	if ( ! node || typeof node !== 'object' ) return false;
	if ( ! node.type ) return false;
	return node.type !== ROW_TYPE
		&& node.type !== COLUMN_TYPE
		&& node.type !== STEP_TYPE
		&& node.type !== SECTION_TYPE;
}

export function clampColumnCount( n ) {
	const v = parseInt( n, 10 ) || 0;
	return Math.max( MIN_COLUMNS, Math.min( MAX_COLUMNS, v ) );
}

/** Walk every leaf field, in source order. Steps + rows + columns are skipped. */
export function flattenFields( nodes ) {
	const out = [];
	if ( ! Array.isArray( nodes ) ) return out;
	for ( const node of nodes ) {
		if ( ! node ) continue;
		if ( isStep( node ) ) {
			out.push( ...flattenFields( node.fields || [] ) );
			continue;
		}
		if ( isRow( node ) ) {
			for ( const col of ( node.columns || [] ) ) {
				out.push( ...flattenFields( col?.fields || [] ) );
			}
			continue;
		}
		if ( isRealField( node ) ) out.push( node );
	}
	return out;
}

/** Build a brand-new row with N empty columns. */
export function makeRow( colCount ) {
	const count = clampColumnCount( colCount );
	const columns = [];
	for ( let i = 0; i < count; i += 1 ) {
		columns.push( { id: newColumnId(), fields: [] } );
	}
	return {
		id: newRowId(),
		type: ROW_TYPE,
		settings: { columns: count, gap: 'medium' },
		columns,
	};
}

/**
 * Resize a row's columns without losing fields.
 *
 * Growing: append empty columns.
 * Shrinking: every field from the dropped columns is appended to the
 * last kept column. Never destroys a field.
 */
export function resizeRow( row, nextCount ) {
	if ( ! isRow( row ) ) return row;
	const next = clampColumnCount( nextCount );
	let columns = Array.isArray( row.columns ) ? row.columns.slice() : [];

	if ( columns.length > next ) {
		const kept   = columns.slice( 0, next );
		const tail   = columns.slice( next );
		const last   = next - 1;
		const merged = ( kept[ last ]?.fields || [] ).slice();
		for ( const col of tail ) {
			for ( const f of ( col?.fields || [] ) ) merged.push( f );
		}
		kept[ last ] = { ...( kept[ last ] || { id: newColumnId() } ), fields: merged };
		columns = kept;
	} else if ( columns.length < next ) {
		while ( columns.length < next ) {
			columns.push( { id: newColumnId(), fields: [] } );
		}
	}

	return {
		...row,
		settings: { ...( row.settings || {} ), columns: next },
		columns,
	};
}

/** Find which container ([rowIndex, colIndex] or [-1, -1] for top level) holds a given field id. */
export function locateField( nodes, fieldId ) {
	for ( let i = 0; i < nodes.length; i += 1 ) {
		const node = nodes[ i ];
		if ( ! node ) continue;
		if ( ! isRow( node ) ) {
			if ( node.id === fieldId ) return { topIndex: i, rowIndex: -1, colIndex: -1, fieldIndex: i };
			continue;
		}
		const cols = node.columns || [];
		for ( let c = 0; c < cols.length; c += 1 ) {
			const fields = cols[ c ]?.fields || [];
			for ( let f = 0; f < fields.length; f += 1 ) {
				if ( fields[ f ]?.id === fieldId ) {
					return { topIndex: i, rowIndex: i, colIndex: c, fieldIndex: f };
				}
			}
		}
	}
	return null;
}

/** Duplicate a row with fresh ids on every node so id-uniqueness holds. */
export function duplicateRow( row ) {
	if ( ! isRow( row ) ) return row;
	return {
		...row,
		id: newRowId(),
		columns: ( row.columns || [] ).map( ( col ) => ( {
			...col,
			id: newColumnId(),
			fields: ( col.fields || [] ).map( ( f ) => ( { ...f, id: newFieldId( f.type ) } ) ),
		} ) ),
	};
}

/** Duplicate a single field with a fresh id. Layout nodes are returned as-is. */
export function duplicateField( field ) {
	if ( ! isRealField( field ) ) return field;
	return { ...field, id: newFieldId( field.type ) };
}

/** Build a brand-new section. Sections are leaves - no children. */
export function createSection( title = '' ) {
	return {
		id: newSectionId(),
		type: SECTION_TYPE,
		title: typeof title === 'string' ? title : '',
		description: '',
		settings: { style: DEFAULT_SECTION_STYLE },
	};
}

/** Duplicate a section with a fresh id. */
export function duplicateSection( section ) {
	if ( ! isSection( section ) ) return section;
	return { ...section, id: newSectionId() };
}

/** Build a brand-new step with default settings and an empty fields array. */
export function createStep( title = '' ) {
	return {
		id: newStepId(),
		type: STEP_TYPE,
		title: typeof title === 'string' ? title : '',
		description: '',
		settings: {
			button_next:     DEFAULT_STEP_BUTTON_NEXT,
			button_previous: DEFAULT_STEP_BUTTON_PREVIOUS,
		},
		fields: [],
	};
}

/**
 * Duplicate a step with fresh ids on every node so id-uniqueness
 * holds. Recursively re-ids any nested rows/columns/fields too.
 */
export function duplicateStep( step ) {
	if ( ! isStep( step ) ) return step;
	const children = ( step.fields || [] ).map( ( child ) => {
		if ( isRow( child ) ) return duplicateRow( child );
		if ( isRealField( child ) ) return { ...child, id: newFieldId( child.type ) };
		return child;
	} );
	return {
		...step,
		id: newStepId(),
		fields: children,
	};
}

/** Spawn a single empty column. Used by createRow + normalize. */
export function createColumn() {
	return { id: newColumnId(), fields: [] };
}

/** Public alias of makeRow for callers that prefer the createX naming. */
export const createRow = makeRow;

/**
 * Normalize a column to canonical shape.
 *
 * - id: filled if missing
 * - fields: defaulted to []
 * - column.type === 'column' is OK, but stripped on output (columns
 *   are layout-only; an explicit type is purely informational and
 *   isn't part of the canonical shape)
 * - width: passthrough (string or number; not rendered today, but
 *   stored for future responsive width support)
 * - nested rows inside column.fields are dropped on normalize
 *   (validator also rejects them - this is the lenient read path)
 */
export function normalizeColumn( col ) {
	if ( ! col || typeof col !== 'object' ) {
		return createColumn();
	}
	const fields = Array.isArray( col.fields ) ? col.fields.filter( ( f ) => ! isRow( f ) ) : [];
	const next = {
		id: typeof col.id === 'string' && col.id ? col.id : newColumnId(),
		fields,
	};
	if ( col.width !== undefined ) next.width = col.width;
	return next;
}

/**
 * Normalize a row to canonical shape, accepting legacy / alt input
 * variants and folding them into row.settings:
 *
 *   row.layout.columns   -> row.settings.columns
 *   row.layout.gap       -> row.settings.gap
 *   row.layout.alignment -> row.settings.alignment   (passthrough)
 *   row.gap              -> row.settings.gap
 *   row.columns (number) -> spawns N empty columns
 *
 * After folding, settings.columns is clamped to MIN/MAX_COLUMNS and
 * the columns array is grown/shrunk to match (using the existing
 * resizeRow merge-into-last-column rule so no field is ever dropped).
 */
export function normalizeRow( row ) {
	if ( ! row || typeof row !== 'object' || row.type !== ROW_TYPE ) return row;

	const layout = ( row.layout && typeof row.layout === 'object' ) ? row.layout : {};
	const settingsIn = ( row.settings && typeof row.settings === 'object' ) ? row.settings : {};

	const desired = clampColumnCount(
		settingsIn.columns
		?? layout.columns
		?? ( typeof row.columns === 'number' ? row.columns : null )
		?? ( Array.isArray( row.columns ) ? row.columns.length : 1 )
	);

	const gapCandidate = settingsIn.gap ?? layout.gap ?? row.gap ?? DEFAULT_GAP;
	const gap = VALID_GAPS.includes( gapCandidate ) ? gapCandidate : DEFAULT_GAP;

	const settings = { columns: desired, gap };
	const alignment = settingsIn.alignment ?? layout.alignment;
	if ( alignment ) settings.alignment = alignment;

	const rawColumns = Array.isArray( row.columns )
		? row.columns.map( normalizeColumn )
		: [];

	const next = { ...row, type: ROW_TYPE, settings, columns: rawColumns };
	// Drop the alternate keys so the canonical shape is the only one
	// that hits storage/validation.
	delete next.layout;
	delete next.gap;

	return resizeRow( next, desired );
}

/**
 * Normalize the top-level form_schema. Flat fields pass through;
 * row nodes get normalized; non-object junk is filtered out.
 *
 * Pure - returns a new object; the input is not mutated.
 */
export function normalizeFormSchema( schema ) {
	const fieldsIn = ( schema && Array.isArray( schema.fields ) ) ? schema.fields : [];
	const fields = [];
	for ( const node of fieldsIn ) {
		if ( ! node || typeof node !== 'object' ) continue;
		if ( isStep( node ) ) {
			fields.push( normalizeStep( node ) );
			continue;
		}
		if ( isRow( node ) ) {
			fields.push( normalizeRow( node ) );
			continue;
		}
		if ( isSection( node ) ) {
			fields.push( normalizeSection( node ) );
			continue;
		}
		if ( isRealField( node ) ) {
			fields.push( node );
			continue;
		}
		// Anything else (orphan column, malformed garbage) is dropped.
	}
	return { ...( schema || {} ), fields };
}

/**
 * Normalize a section to canonical shape. Drops any unexpected
 * fields[]/columns[] (sections are leaves) and clamps style to a
 * known value.
 */
export function normalizeSection( section ) {
	if ( ! section || typeof section !== 'object' ) {
		return createSection();
	}
	const settingsIn = ( section.settings && typeof section.settings === 'object' ) ? section.settings : {};
	const styleRaw   = typeof settingsIn.style === 'string' ? settingsIn.style : DEFAULT_SECTION_STYLE;
	const style      = VALID_SECTION_STYLES.includes( styleRaw ) ? styleRaw : DEFAULT_SECTION_STYLE;
	return {
		id: ( typeof section.id === 'string' && section.id ) ? section.id : newSectionId(),
		type: SECTION_TYPE,
		title: typeof section.title === 'string' ? section.title : '',
		description: typeof section.description === 'string' ? section.description : '',
		settings: { style },
	};
}

/**
 * Normalize a step to canonical shape.
 *
 * - id: filled if missing
 * - title / description: defaulted to ''
 * - settings.{button_next, button_previous}: defaulted from constants
 * - fields[]: real fields and rows pass through (rows get
 *   normalizeRow); nested steps and orphan columns are dropped on
 *   lenient read (validator rejects them on save).
 */
export function normalizeStep( step ) {
	if ( ! step || typeof step !== 'object' ) {
		return createStep();
	}
	const settingsIn = ( step.settings && typeof step.settings === 'object' ) ? step.settings : {};
	const settings = {
		button_next:     ( typeof settingsIn.button_next === 'string' && settingsIn.button_next !== '' )
			? settingsIn.button_next
			: DEFAULT_STEP_BUTTON_NEXT,
		button_previous: ( typeof settingsIn.button_previous === 'string' && settingsIn.button_previous !== '' )
			? settingsIn.button_previous
			: DEFAULT_STEP_BUTTON_PREVIOUS,
	};
	const childrenIn = Array.isArray( step.fields ) ? step.fields : [];
	const children = [];
	for ( const child of childrenIn ) {
		if ( ! child || typeof child !== 'object' ) continue;
		if ( isStep( child ) ) continue; // no nested steps
		if ( isRow( child ) ) {
			children.push( normalizeRow( child ) );
			continue;
		}
		if ( isSection( child ) ) {
			children.push( normalizeSection( child ) );
			continue;
		}
		if ( isRealField( child ) ) {
			children.push( child );
			continue;
		}
	}
	return {
		id: ( typeof step.id === 'string' && step.id ) ? step.id : newStepId(),
		type: STEP_TYPE,
		title: typeof step.title === 'string' ? step.title : '',
		description: typeof step.description === 'string' ? step.description : '',
		settings,
		fields: children,
	};
}

/**
 * Move a field across columns within a row (or top-level <-> column).
 *
 * `from` / `to` shape: { kind: 'top' | 'col', rowIndex?, colIndex?, index }
 * Identical to the moveField helper inside FieldList.js - exported
 * here so the operation is reusable from non-DnD callers (e.g. future
 * keyboard shortcuts or a Pro reorganize action).
 */
export function moveFieldBetweenColumns( nodes, from, to ) {
	const next = JSON.parse( JSON.stringify( nodes ) );
	let pulled = null;
	if ( from.kind === 'top' ) {
		pulled = next.splice( from.index, 1 )[ 0 ];
	} else {
		const col = next[ from.rowIndex ]?.columns?.[ from.colIndex ];
		if ( ! col ) return nodes;
		pulled = col.fields.splice( from.index, 1 )[ 0 ];
	}
	if ( ! pulled ) return nodes;
	if ( to.kind === 'top' ) {
		next.splice( to.index, 0, pulled );
	} else {
		const col = next[ to.rowIndex ]?.columns?.[ to.colIndex ];
		if ( ! col ) return nodes;
		const dest = Math.max( 0, Math.min( to.index, col.fields.length ) );
		col.fields.splice( dest, 0, pulled );
	}
	return next;
}

/** Public ID generators with the canonical names requested upstream. */
export const generateRowId     = newRowId;
export const generateColumnId  = newColumnId;
export const generateStepId    = newStepId;
export const generateSectionId = newSectionId;
export const generateFieldId   = newFieldId;

/* ─── ID uniqueness toolkit (single source of truth) ──────────────
 *
 * Used by:
 *   - newField()                 (FieldTypes.js)
 *   - Builder.js field/row/step/section/AI/template creation paths
 *   - duplicateField/Row/Step    (above in this file)
 *   - load + before-save repair  (Builder.js)
 *
 * Why a centralised toolkit: prior to 0.2.0 the builder had two
 * generators — `defaultIdForType()` in FieldTypes.js produced
 * label-derived ids ("rating" → "rating_2") gated only on a
 * stale `existingIds` snapshot, while `newFieldId()` here
 * produced timestamp+counter ids. Two Rating fields created
 * via the palette could both end up with `id="rating"` if the
 * snapshot missed the second add (or just sat in two flow
 * paths), and the server's FormValidator rejected the save.
 *
 * The fix: every creation path uses these helpers, and a final
 * `ensureUniqueIds()` pass on load + before save guarantees no
 * duplicate ever reaches the wire.
 */

/**
 * Recursively collect every id present in a fields tree. Used by
 * makeFieldId / ensureUniqueIds and by the Builder's add-field path
 * to detect (and now repair) duplicates pre-emptively.
 *
 * Walks: top-level fields, row.columns[].fields, step.fields[],
 * step.fields[]→row.columns[]→fields. Sections are leaves.
 */
export function collectIds( nodes ) {
	const ids = new Set();
	const visit = ( arr ) => {
		if ( ! Array.isArray( arr ) ) return;
		for ( const n of arr ) {
			if ( ! n || typeof n !== 'object' ) continue;
			if ( n.id ) ids.add( n.id );
			if ( n.type === ROW_TYPE && Array.isArray( n.columns ) ) {
				for ( const col of n.columns ) {
					if ( col && col.id ) ids.add( col.id );
					if ( col && Array.isArray( col.fields ) ) visit( col.fields );
				}
				continue;
			}
			if ( n.type === STEP_TYPE && Array.isArray( n.fields ) ) {
				visit( n.fields );
			}
		}
	};
	visit( nodes );
	return ids;
}

/**
 * Pick a unique field id for `type`, defending against an
 * accidental collision with `existing` (Set or Array). The
 * underlying `newFieldId(type)` is monotonic + per-millisecond
 * unique so a collision is essentially impossible — but the
 * defence costs nothing and makes the contract obvious.
 */
export function makeFieldId( type, existing ) {
	const seen = existing instanceof Set
		? existing
		: new Set( Array.isArray( existing ) ? existing : [] );
	let id;
	do {
		id = newFieldId( type );
	} while ( seen.has( id ) );
	seen.add( id );
	return id;
}

/**
 * Repair a fields tree so every node has a unique, valid id.
 *
 * Rules:
 *   - Missing id → generate one based on the node's type.
 *   - Duplicate id (already seen earlier in the walk) → replace
 *     with a fresh one based on type. The FIRST occurrence keeps
 *     its id; subsequent collisions are renamed.
 *   - Labels, options, settings, conditional logic refs are NOT
 *     touched. (Conditional logic refs may stop matching if a
 *     duplicate downstream id was renamed; that's an existing
 *     edge case we surface but don't auto-rewrite — see brief.)
 *   - When no node needs repair, returns the SAME `nodes`
 *     reference so callers can use `result === input` to detect
 *     a no-op. This avoids fake dirty flips on already-valid
 *     forms.
 *
 * Walks the same shape collectIds does (root, rows.columns.fields,
 * steps.fields, step→row→col→fields, sections as leaves).
 */
export function ensureUniqueIds( nodes ) {
	if ( ! Array.isArray( nodes ) ) return nodes;

	const seen = new Set();
	let changed = false;

	const repairNode = ( node ) => {
		if ( ! node || typeof node !== 'object' ) return node;
		const type = String( node.type || '' );
		const needsId = ! node.id || seen.has( node.id );

		let nextNode = node;

		if ( needsId ) {
			let prefix;
			if ( type === ROW_TYPE )         prefix = 'row';
			else if ( type === COLUMN_TYPE ) prefix = 'col';
			else if ( type === STEP_TYPE )   prefix = 'step';
			else if ( type === SECTION_TYPE ) prefix = 'section';
			else                              prefix = ( type || 'field' ).replace( /[^a-z0-9]+/gi, '' ).toLowerCase() || 'field';

			let fresh;
			do { fresh = token( prefix ); } while ( seen.has( fresh ) );
			nextNode = { ...node, id: fresh };
			changed = true;
		}
		seen.add( nextNode.id );

		if ( type === ROW_TYPE && Array.isArray( nextNode.columns ) ) {
			let columnsChanged = false;
			const nextColumns = nextNode.columns.map( ( col ) => {
				if ( ! col || typeof col !== 'object' ) return col;
				let nextCol = col;
				if ( ! col.id || seen.has( col.id ) ) {
					let fresh;
					do { fresh = token( 'col' ); } while ( seen.has( fresh ) );
					nextCol = { ...col, id: fresh };
					columnsChanged = true;
					changed = true;
				}
				seen.add( nextCol.id );
				if ( Array.isArray( col.fields ) ) {
					const repairedChildren = col.fields.map( repairNode );
					if ( repairedChildren.some( ( child, i ) => child !== col.fields[ i ] ) ) {
						nextCol = { ...nextCol, fields: repairedChildren };
						columnsChanged = true;
					}
				}
				return nextCol;
			} );
			if ( columnsChanged ) {
				nextNode = { ...nextNode, columns: nextColumns };
			}
		} else if ( type === STEP_TYPE && Array.isArray( nextNode.fields ) ) {
			const repairedChildren = nextNode.fields.map( repairNode );
			if ( repairedChildren.some( ( child, i ) => child !== nextNode.fields[ i ] ) ) {
				nextNode = { ...nextNode, fields: repairedChildren };
			}
		}

		return nextNode;
	};

	const repaired = nodes.map( repairNode );
	return changed ? repaired : nodes;
}

// ─────────────────────────────────────────────────────────────────────
// Auto-slugged option / row / column values
// ─────────────────────────────────────────────────────────────────────
//
// Used by every "label → value" option editor in BuilderInspector:
// Radio / Checkboxes / Select / Poll options, Quiz options, Matrix
// rows + columns. The contract is:
//
//   - As the user types a label, the value mirrors it as a sluggified
//     copy AS LONG AS the user hasn't manually edited the value.
//   - Once the user customises the value, label edits stop touching it.
//
// We don't store a "manually edited" flag in the saved schema (would
// pollute the wire shape). Instead we INFER auto-vs-manual at edit
// time by comparing the current value to slugify(previousLabel),
// optionally with the dedup `_N` suffix. False positives are limited
// to the corner case where the user manually typed a value that
// happens to exactly equal the slug of the prior label — and even
// then the failure mode is benign (the next label edit just retypes
// the value via slugify, matching what the user would have wanted
// for a still-auto value).

/**
 * Slug rules (deliberately conservative — matches what the PHP
 * sanitizer + the AI normaliser already produce so the round-trip is
 * stable):
 *
 *   - lowercase, trim
 *   - any run of non-[a-z0-9_] characters → single underscore
 *   - collapse multiple underscores to one
 *   - strip leading + trailing underscores
 *   - empty input → empty string (caller supplies the fallback)
 *
 * Examples:
 *   "Very Unsatisfied"      → "very_unsatisfied"
 *   "Company Overall"       → "company_overall"
 *   "Buy from us again"     → "buy_from_us_again"
 *   "  Option   1  "        → "option_1"
 *   "Service & Support"     → "service_support"
 *   "₹ Price / Value"       → "price_value"
 *   ""                      → ""
 *
 * @param {string} label
 * @returns {string}
 */
export function slugifyValue( label ) {
	if ( label === undefined || label === null ) return '';
	const s = String( label )
		.toLowerCase()
		.trim()
		.replace( /[^a-z0-9_]+/g, '_' )
		.replace( /_+/g, '_' )
		.replace( /^_+|_+$/g, '' );
	return s;
}

/**
 * Pick a value that doesn't collide with anything in `siblingValues`.
 * Falls back to `fallbackPrefix + "_1"` when `desired` is empty.
 * Increments a suffix counter (`_2`, `_3`, …) until unique.
 *
 * `siblingValues` should be the values of EVERY sibling EXCEPT the
 * one being edited — otherwise the editor would always treat its own
 * current value as a collision.
 *
 * @param {string}   desired
 * @param {string[]} siblingValues
 * @param {string}   fallbackPrefix  e.g. "option", "row", "column"
 * @returns {string}
 */
export function ensureUniqueValue( desired, siblingValues, fallbackPrefix ) {
	const others = new Set( ( siblingValues || [] ).map( ( v ) => String( v || '' ) ) );
	let candidate = desired && desired !== '' ? desired : `${ fallbackPrefix }_1`;
	if ( ! others.has( candidate ) ) return candidate;
	let n = 2;
	while ( others.has( `${ candidate }_${ n }` ) ) n += 1;
	return `${ candidate }_${ n }`;
}

/**
 * True when `currentValue` looks like it was auto-derived from
 * `previousLabel`. Empty current value is treated as auto so the very
 * first keystroke after adding a new option still wires the slug
 * through.
 *
 * Match shape:
 *   1. exact match against slugify(previousLabel)
 *   2. exact match plus dedup suffix: <slug>_2, <slug>_3, …
 *
 * @param {string} currentValue
 * @param {string} previousLabel
 * @returns {boolean}
 */
// ─────────────────────────────────────────────────────────────────────
// Repeater field helpers
// ─────────────────────────────────────────────────────────────────────
//
// Kept in lockstep with FormValidator::REPEATER_ALLOWED_CHILD_TYPES /
// REPEATER_BLOCKED_CHILD_TYPES on the PHP side. Any change here is a
// coordinated change with the validator AND the cloud's
// SchemaNormalizer repeater-children allowlist (Repeater Commit 6).

/** Hard ceiling on repeater max_items. Mirrors
 *  FormValidator::REPEATER_MAX_ITEMS. */
export const REPEATER_MAX_ITEMS = 20;

/** Child types accepted inside a Repeater in v1. */
export const REPEATER_ALLOWED_CHILD_TYPES = Object.freeze( [
	'text',
	'email',
	'phone',
	'number',
	'url',
	'textarea',
	'select',
	'radio',
	'checkbox',
	'checkboxes',
	'date',
	'file',
] );

/** Child types explicitly rejected inside a Repeater. */
export const REPEATER_BLOCKED_CHILD_TYPES = Object.freeze( [
	'repeater',
	'matrix',
	'row',
	'step',
	'section',
	'column',
	'poll',
	'quiz_question',
] );

/** True when the given schema node is a Repeater. */
export function isRepeater( node ) {
	return !! ( node && typeof node === 'object' && node.type === 'repeater' );
}

export function isAutoGeneratedValue( currentValue, previousLabel ) {
	const v = currentValue === undefined || currentValue === null ? '' : String( currentValue );
	if ( v === '' ) return true;
	const base = slugifyValue( previousLabel );
	if ( base === '' ) return false;
	if ( v === base ) return true;
	const escaped = base.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	return new RegExp( `^${ escaped }_\\d+$` ).test( v );
}
