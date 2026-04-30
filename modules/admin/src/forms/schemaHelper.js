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
export const MIN_COLUMNS  = 1;
export const MAX_COLUMNS  = 4;
export const VALID_GAPS   = [ 'none', 'small', 'medium', 'large' ];
export const DEFAULT_GAP  = 'medium';

export const DEFAULT_STEP_BUTTON_NEXT     = 'Next';
export const DEFAULT_STEP_BUTTON_PREVIOUS = 'Back';

let _idCounter = 0;
function token( prefix ) {
	_idCounter += 1;
	return prefix + '_' + Date.now().toString( 36 ) + _idCounter.toString( 36 );
}

export const newRowId    = () => token( 'row' );
export const newColumnId = () => token( 'col' );
export const newStepId   = () => token( 'step' );
export const newFieldId  = ( type ) => token( ( type || 'field' ).replace( /[^a-z0-9]+/gi, '' ).toLowerCase() );

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
	return isRow( node ) || isColumn( node ) || isStep( node );
}

/**
 * True when a node represents a real input field that produces an
 * entry value. Anything with `type` set and not equal to "row",
 * "column", or "step" qualifies. Used by validators / submission
 * flatteners to know what counts as user data vs. structural
 * scaffolding.
 */
export function isRealField( node ) {
	if ( ! node || typeof node !== 'object' ) return false;
	if ( ! node.type ) return false;
	return node.type !== ROW_TYPE
		&& node.type !== COLUMN_TYPE
		&& node.type !== STEP_TYPE;
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
		if ( isRealField( node ) ) {
			fields.push( node );
			continue;
		}
		// Anything else (orphan column, malformed garbage) is dropped.
	}
	return { ...( schema || {} ), fields };
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
export const generateRowId    = newRowId;
export const generateColumnId = newColumnId;
export const generateStepId   = newStepId;
export const generateFieldId  = newFieldId;
