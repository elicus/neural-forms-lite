/**
 * Container-aware drag/drop helpers for the form builder.
 *
 * Locations describe WHERE a node lives in the form_schema.fields tree:
 *
 *   { kind: 'root',     index }
 *   { kind: 'step',     stepId, index }
 *   { kind: 'col',      rowId, colIndex, index }
 *   { kind: 'step-col', stepId, rowId, colIndex, index }
 *
 * The same shape is used for source AND destination locations so the
 * move algorithm is symmetric. Helpers below are pure — they accept
 * a fields array and return a NEW fields array, never mutating the
 * input. Future survey/quiz/conditional containers can extend the
 * `kind` enum without rewriting callers.
 */

import { isRow, isStep } from './schemaHelper';

/**
 * Resolve the actual node object at a given location. Returns null
 * when the path no longer exists (e.g. the location was computed
 * before a re-render dropped a row). Symmetric with findLocation:
 * findLocation gives the location, getNodeAtLocation gives the node.
 *
 * @param {Array}  nodes
 * @param {object} loc
 * @returns {object|null}
 */
export function getNodeAtLocation( nodes, loc ) {
	if ( ! loc ) return null;
	if ( loc.kind === 'root' ) return nodes[ loc.index ] || null;
	if ( loc.kind === 'step' ) {
		const step = nodes.find( ( n ) => n && n.id === loc.stepId );
		return ( step && Array.isArray( step.fields ) ) ? ( step.fields[ loc.index ] || null ) : null;
	}
	if ( loc.kind === 'col' ) {
		const row = nodes.find( ( n ) => n && n.id === loc.rowId );
		const col = row && row.columns && row.columns[ loc.colIndex ];
		return ( col && Array.isArray( col.fields ) ) ? ( col.fields[ loc.index ] || null ) : null;
	}
	if ( loc.kind === 'step-col' ) {
		const step = nodes.find( ( n ) => n && n.id === loc.stepId );
		const row  = step && Array.isArray( step.fields ) && step.fields.find( ( c ) => c && c.id === loc.rowId );
		const col  = row && row.columns && row.columns[ loc.colIndex ];
		return ( col && Array.isArray( col.fields ) ) ? ( col.fields[ loc.index ] || null ) : null;
	}
	return null;
}

/**
 * Walk the form_schema.fields tree to locate a node by id.
 * Returns null when the id isn't found anywhere.
 *
 * Supported nestings (everything else short-circuits / is ignored):
 *   - root level
 *   - row.columns[*].fields[*] at root
 *   - step.fields[*]
 *   - step.fields[* row].columns[*].fields[*]
 *
 * @param {Array}  nodes  form_schema.fields
 * @param {string} id
 * @returns {object|null}
 */
export function findLocation( nodes, id ) {
	for ( let i = 0; i < nodes.length; i++ ) {
		const node = nodes[ i ];
		if ( ! node ) continue;

		if ( node.id === id ) {
			return { kind: 'root', index: i };
		}

		if ( isRow( node ) ) {
			const cols = Array.isArray( node.columns ) ? node.columns : [];
			for ( let c = 0; c < cols.length; c++ ) {
				const fields = Array.isArray( cols[ c ].fields ) ? cols[ c ].fields : [];
				for ( let f = 0; f < fields.length; f++ ) {
					if ( fields[ f ] && fields[ f ].id === id ) {
						return { kind: 'col', rowId: node.id, colIndex: c, index: f };
					}
				}
			}
			continue;
		}

		if ( isStep( node ) ) {
			const children = Array.isArray( node.fields ) ? node.fields : [];
			for ( let s = 0; s < children.length; s++ ) {
				const child = children[ s ];
				if ( ! child ) continue;
				if ( child.id === id ) {
					return { kind: 'step', stepId: node.id, index: s };
				}
				if ( isRow( child ) ) {
					const cols = Array.isArray( child.columns ) ? child.columns : [];
					for ( let c = 0; c < cols.length; c++ ) {
						const fields = Array.isArray( cols[ c ].fields ) ? cols[ c ].fields : [];
						for ( let f = 0; f < fields.length; f++ ) {
							if ( fields[ f ] && fields[ f ].id === id ) {
								return { kind: 'step-col', stepId: node.id, rowId: child.id, colIndex: c, index: f };
							}
						}
					}
				}
			}
		}
	}
	return null;
}

/**
 * Resolve the children array a location points into, given the
 * top-level nodes. Returns null when the path doesn't exist (e.g.
 * the location was computed before a re-render dropped the row).
 *
 * The returned array reference IS the live array — caller must clone
 * before mutating. Used internally by removeAt/insertAt; rarely
 * needed by external callers.
 */
function resolveChildren( nodes, loc ) {
	if ( ! loc ) return null;
	if ( loc.kind === 'root' ) return nodes;
	if ( loc.kind === 'step' ) {
		const step = nodes.find( ( n ) => n && n.id === loc.stepId );
		if ( ! step || ! Array.isArray( step.fields ) ) return null;
		return step.fields;
	}
	if ( loc.kind === 'col' ) {
		const row = nodes.find( ( n ) => n && n.id === loc.rowId );
		if ( ! row || ! Array.isArray( row.columns ) ) return null;
		const col = row.columns[ loc.colIndex ];
		if ( ! col || ! Array.isArray( col.fields ) ) return null;
		return col.fields;
	}
	if ( loc.kind === 'step-col' ) {
		const step = nodes.find( ( n ) => n && n.id === loc.stepId );
		if ( ! step || ! Array.isArray( step.fields ) ) return null;
		const row  = step.fields.find( ( c ) => c && c.id === loc.rowId );
		if ( ! row || ! Array.isArray( row.columns ) ) return null;
		const col  = row.columns[ loc.colIndex ];
		if ( ! col || ! Array.isArray( col.fields ) ) return null;
		return col.fields;
	}
	return null;
}

/**
 * Deep-clone the form_schema.fields array. JSON round-trip is safe
 * here because the schema only carries serialisable values.
 */
function clone( nodes ) {
	return JSON.parse( JSON.stringify( nodes ) );
}

/**
 * Remove the node at `loc` and return:
 *   { nodes: <new fields array>, removed: <node>, removedFromIndex: <int> }
 * On failure returns { nodes: original, removed: null, removedFromIndex: -1 }.
 *
 * Pure: input is not mutated.
 */
export function removeAt( nodes, loc ) {
	if ( ! loc ) return { nodes, removed: null, removedFromIndex: -1 };
	const next = clone( nodes );
	const target = resolveChildren( next, loc );
	if ( ! target ) return { nodes, removed: null, removedFromIndex: -1 };
	const idx = loc.index;
	if ( idx < 0 || idx >= target.length ) return { nodes, removed: null, removedFromIndex: -1 };
	const [ removed ] = target.splice( idx, 1 );
	return { nodes: next, removed, removedFromIndex: idx };
}

/**
 * Insert `node` at `loc.index` inside the container `loc` points at.
 * If the container resolves but `loc.index` exceeds its length, the
 * node is appended (clamp). Returns a new fields array; input
 * unchanged on failure (returns the original).
 */
export function insertAt( nodes, loc, node ) {
	if ( ! loc || ! node ) return nodes;
	const next = clone( nodes );
	const target = resolveChildren( next, loc );
	if ( ! target ) return nodes;
	const idx = Math.max( 0, Math.min( typeof loc.index === 'number' ? loc.index : target.length, target.length ) );
	target.splice( idx, 0, node );
	return next;
}

/**
 * True when `node` (with its `type`) is acceptable inside the target
 * container described by `loc.kind`.
 *
 *   root     accepts: field, row, step, section
 *   step     accepts: field, row, section            (NO step nesting)
 *   col      accepts: field, section                 (no row, no step)
 *   step-col accepts: field, section                 (same as col)
 *
 * @param {object} node  Source node (raw schema shape).
 * @param {object} loc   Destination location with `kind` key.
 */
export function canDropInto( node, loc ) {
	if ( ! node || ! loc ) return false;
	const t = node.type || '';
	const isStepNode = t === 'step';
	const isRowNode  = t === 'row';

	switch ( loc.kind ) {
		case 'root':
			return true;
		case 'step':
			return ! isStepNode;          // No nested steps. Rows + fields + sections OK.
		case 'col':
		case 'step-col':
			return ! isStepNode && ! isRowNode;  // Leaves only inside columns.
		default:
			return false;
	}
}

/**
 * Move a node from `from` to `to`. Pure. Returns a new fields array.
 *
 * Insertion rule (dnd-kit `arrayMove` semantics):
 *
 *   1. Remove the source node from its container.
 *   2. Insert it at `to.index` in the destination container — verbatim,
 *      with NO shift. The destination index is interpreted on the
 *      resultant (already-shrunk-if-same-container) array.
 *
 * Why no shift? When the target is an existing sortable item, `over.id`
 * is that item's id; sortable reorder should land the source AT the
 * target's slot, exactly like the standard sortable arrayMove(from, to)
 * helper. Subtracting 1 for "source before target in same container"
 * (the previous behaviour) produces a no-op when dragging downward
 * onto the very next sibling — the user's "drag works upward but not
 * downward" symptom.
 *
 * Worked examples:
 *   [A, B] · arrayMove(0, 1) → [B, A]    (downward)
 *   [A, B] · arrayMove(1, 0) → [B, A]    (upward)
 *   [A, B, C] · arrayMove(0, 2) → [B, C, A]
 *   [A, B, C] · arrayMove(2, 0) → [C, A, B]
 *
 * Cross-container drops are unaffected: `removeAt` on the source
 * container leaves the destination container's length unchanged, so
 * inserting at `to.index` (which may be `<container>.length` for an
 * "append" target like `step-body:` / `col:`) is always correct.
 *
 * @returns {Array} new fields array; original on failure
 */
export function moveNode( nodes, from, to ) {
	if ( ! from || ! to ) return nodes;

	const removed = removeAt( nodes, from );
	if ( ! removed.removed ) return nodes;

	if ( ! canDropInto( removed.removed, to ) ) return nodes;

	return insertAt( removed.nodes, to, removed.removed );
}

/**
 * True when two locations point at the SAME container.
 * Useful for callers that need to detect a same-container reorder
 * (e.g. for direction-sensitive UI cues). The move algorithm itself
 * does NOT need this distinction — `moveNode` follows arrayMove
 * semantics that work uniformly across same- and cross-container
 * cases.
 */
export function sameContainer( a, b ) {
	if ( ! a || ! b || a.kind !== b.kind ) return false;
	switch ( a.kind ) {
		case 'root':
			return true;
		case 'step':
			return a.stepId === b.stepId;
		case 'col':
			return a.rowId === b.rowId && a.colIndex === b.colIndex;
		case 'step-col':
			return a.stepId === b.stepId && a.rowId === b.rowId && a.colIndex === b.colIndex;
		default:
			return false;
	}
}

/**
 * Parse a dnd-kit `over` target into a destination location for the
 * move algorithm.
 *
 *   - step-body:<stepId>   → { kind:'step', stepId, index: <append> }
 *   - col:<rowId>:<colIdx> → { kind:'col' or 'step-col', ..., index: <append> }
 *   - <node id>            → that node's location (insertion goes BEFORE it)
 *
 * Whether a `col:` drop maps to root-col or step-col is decided by
 * walking the tree to find which step (if any) owns that row id.
 *
 * @param {Array} nodes
 * @param {{ id, data? }} over   dnd-kit over object
 * @returns {object|null}        location, or null when unparseable
 */
export function parseDropTarget( nodes, over ) {
	if ( ! over ) return null;
	const id = String( over.id || '' );

	// Empty-step drop zone.
	if ( id.startsWith( 'step-body:' ) ) {
		const stepId = id.slice( 'step-body:'.length );
		const step   = nodes.find( ( n ) => n && n.id === stepId && isStep( n ) );
		if ( ! step ) return null;
		const len = Array.isArray( step.fields ) ? step.fields.length : 0;
		return { kind: 'step', stepId, index: len };
	}

	// Column drop zone (root OR inside a step).
	if ( id.startsWith( 'col:' ) ) {
		const parts = id.split( ':' );
		const rowId = parts[ 1 ];
		const colIdx = parseInt( parts[ 2 ], 10 );
		if ( ! rowId || Number.isNaN( colIdx ) ) return null;

		// Root row?
		const rootRow = nodes.find( ( n ) => n && n.id === rowId && isRow( n ) );
		if ( rootRow ) {
			const fields = ( rootRow.columns?.[ colIdx ]?.fields ) || [];
			return { kind: 'col', rowId, colIndex: colIdx, index: fields.length };
		}

		// Step-nested row?
		for ( const n of nodes ) {
			if ( ! isStep( n ) ) continue;
			const children = Array.isArray( n.fields ) ? n.fields : [];
			const row = children.find( ( c ) => c && c.id === rowId && isRow( c ) );
			if ( row ) {
				const fields = ( row.columns?.[ colIdx ]?.fields ) || [];
				return { kind: 'step-col', stepId: n.id, rowId, colIndex: colIdx, index: fields.length };
			}
		}
		return null;
	}

	// Otherwise: dropping over an existing node id. Use that node's
	// location as the insertion point (insertion goes BEFORE it).
	return findLocation( nodes, id );
}

/**
 * Enumerate every container the user could move a node INTO,
 * paired with a human-readable label. Powers the "Move" menu —
 * the official, supported way to relocate a node in the builder.
 *
 * Returns an ordered list. Each entry has:
 *   {
 *     key:   stable string id ("root", "step:<id>", "col:<rowId>:<idx>",
 *            "step-col:<stepId>:<rowId>:<idx>"),
 *     label: human-readable, e.g. "Top level", "Step 1: Contact",
 *            "Row 1 · Column 2", "Step 2 · Row 1 · Column 2"
 *     loc:   destination location appropriate for moveNode
 *   }
 *
 * Always "move INTO this container" — i.e. append at the end of the
 * destination's children. Reordering within the same container is
 * a separate concern (currently a TODO until drag/drop is re-enabled).
 *
 * @param {Array}  nodes
 * @param {string} [sourceType] Optional. When set, destinations the
 *                              source can't legally enter are filtered
 *                              out — so a step won't see "Step 1 body"
 *                              as a destination (steps only live at root),
 *                              and a row won't see "Column" destinations.
 */
export function enumerateDestinations( nodes, sourceType ) {
	const out = [];
	const isStepSource = sourceType === 'step';
	const isRowSource  = sourceType === 'row';

	// Top level is always a valid destination.
	out.push( { key: 'root', label: 'Top level', loc: { kind: 'root', index: nodes.length } } );

	// Steps only move at root → no inner destinations for steps.
	if ( isStepSource ) return out;

	let stepNumber = 0;
	let rootRowNumber = 0;
	for ( let i = 0; i < nodes.length; i++ ) {
		const node = nodes[ i ];
		if ( ! node ) continue;

		if ( isStep( node ) ) {
			stepNumber++;
			const stepTitle = node.title ? `${ node.title }` : '';
			const stepLabel = stepTitle ? `Step ${ stepNumber }: ${ stepTitle }` : `Step ${ stepNumber }`;
			const stepFields = Array.isArray( node.fields ) ? node.fields : [];

			// Step body accepts rows AND fields/sections.
			out.push( {
				key: 'step:' + node.id,
				label: `${ stepLabel } body`,
				loc: { kind: 'step', stepId: node.id, index: stepFields.length },
			} );

			// Rows inside this step → expose their columns. Columns
			// only accept fields + sections, not rows or steps.
			if ( ! isRowSource ) {
				let rowIdxInStep = 0;
				for ( let s = 0; s < stepFields.length; s++ ) {
					const inner = stepFields[ s ];
					if ( ! inner || ! isRow( inner ) ) continue;
					rowIdxInStep++;
					const cols = Array.isArray( inner.columns ) ? inner.columns : [];
					for ( let c = 0; c < cols.length; c++ ) {
						const fields = Array.isArray( cols[ c ].fields ) ? cols[ c ].fields : [];
						out.push( {
							key: `step-col:${ node.id }:${ inner.id }:${ c }`,
							label: `${ stepLabel } · Row ${ rowIdxInStep } · Column ${ c + 1 }`,
							loc: { kind: 'step-col', stepId: node.id, rowId: inner.id, colIndex: c, index: fields.length },
						} );
					}
				}
			}
			continue;
		}

		if ( isRow( node ) ) {
			rootRowNumber++;
			// Skip column destinations when source is a row (rows
			// can't nest inside columns).
			if ( isRowSource ) continue;
			const cols = Array.isArray( node.columns ) ? node.columns : [];
			for ( let c = 0; c < cols.length; c++ ) {
				const fields = Array.isArray( cols[ c ].fields ) ? cols[ c ].fields : [];
				out.push( {
					key: `col:${ node.id }:${ c }`,
					label: `Row ${ rootRowNumber } · Column ${ c + 1 }`,
					loc: { kind: 'col', rowId: node.id, colIndex: c, index: fields.length },
				} );
			}
		}
	}
	return out;
}

/**
 * Identify the container that currently HOLDS a given node. Used by
 * the Move menu to mark / disable the entry that matches "this is
 * where you are now".
 *
 * Returns one of the same `key` strings produced by
 * enumerateDestinations(), or null if the node lives at root level
 * (where the matching key is 'root').
 */
export function locateContainerKey( nodes, id ) {
	const loc = findLocation( nodes, id );
	if ( ! loc ) return null;
	if ( loc.kind === 'root' )     return 'root';
	if ( loc.kind === 'step' )     return 'step:' + loc.stepId;
	if ( loc.kind === 'col' )      return `col:${ loc.rowId }:${ loc.colIndex }`;
	if ( loc.kind === 'step-col' ) return `step-col:${ loc.stepId }:${ loc.rowId }:${ loc.colIndex }`;
	return null;
}

/**
 * Refine a destination location based on the source node's type.
 *
 * dnd-kit's collision detection prefers the closest droppable rect.
 * Steps and rows are themselves sortable items occupying their entire
 * card, so dragging a field over a step lands `over.id` on the step's
 * outer id BEFORE the inner step-body droppable wins. Without this
 * refinement, the field would be inserted next to the step at root
 * instead of inside the step's body — the exact "drag does nothing
 * useful" symptom the user reports.
 *
 * Rules (only apply when source is a NON-step, NON-row leaf or a row):
 *   - Source is a row + over is a step → insert into step.fields[].
 *   - Source is a field/section + over is a step → insert into step.fields[].
 *   - Source is a field/section + over is a row → insert into row.col[0].
 *
 * Steps as the source are NOT refined — they only reorder at root,
 * which the caller already validates separately.
 *
 * @param {Array}  nodes
 * @param {object} sourceNode
 * @param {object} to            location returned by parseDropTarget
 * @returns {object}             refined location
 */
export function refineDropTarget( nodes, sourceNode, to ) {
	if ( ! to || ! sourceNode ) return to;
	if ( to.kind !== 'root' ) return to;
	if ( typeof to.index !== 'number' || to.index < 0 || to.index >= nodes.length ) return to;

	const target = nodes[ to.index ];
	if ( ! target ) return to;

	const sType = sourceNode.type || '';
	if ( sType === 'step' ) return to; // Steps only reorder at root.

	// Field / section / row dropped on a step → land in step body.
	if ( isStep( target ) ) {
		const len = Array.isArray( target.fields ) ? target.fields.length : 0;
		return { kind: 'step', stepId: target.id, index: len };
	}

	// Field / section dropped on a row → land in row's first column.
	if ( isRow( target ) && sType !== 'row' ) {
		const fields = target.columns?.[ 0 ]?.fields || [];
		// Discover whether this row lives at root or inside a step.
		// (parseDropTarget already returned root, so it IS a root row.)
		return { kind: 'col', rowId: target.id, colIndex: 0, index: fields.length };
	}

	return to;
}
