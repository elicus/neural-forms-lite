/**
 * Top-level dnd-kit provider for the form builder.
 *
 * Wraps the entire builder body (sidebar + canvas + inspector) with a
 * single DndContext so palette chips can be dragged out of the sidebar
 * and dropped into the canvas (root, step body, column, etc.) — and
 * existing canvas-side drag/drop (reorder, cross-container moves)
 * continues to flow through the same handlers.
 *
 * Active item shapes seen in `event.active.data.current`:
 *
 *   - { kind: 'field' | 'row' | 'step' | 'section', type }
 *       Existing canvas card being dragged. Routed through the
 *       `moveNode` pipeline.
 *
 *   - { source: 'palette', paletteType: 'field', fieldType, label }
 *       Sidebar field chip. Creates a fresh field and inserts at the
 *       drop location.
 *
 *   - { source: 'palette', paletteType: 'layout', layoutType, label }
 *       Sidebar layout chip (row / step / section). Creates the
 *       appropriate node and inserts at the drop location.
 *
 * The custom collision strategy lives here because palette drags
 * still need to land on inner drop zones (step-body / col) instead of
 * outer card sortables.
 */

import { useState, useCallback } from '@wordpress/element';
import {
	DndContext,
	DragOverlay,
	PointerSensor,
	KeyboardSensor,
	useSensor,
	useSensors,
	closestCenter,
	pointerWithin,
	rectIntersection,
} from '@dnd-kit/core';
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import {
	findLocation,
	parseDropTarget,
	refineDropTarget,
	moveNode,
	canDropInto,
	insertAt,
	getNodeAtLocation,
	sameContainer,
} from './dndHelpers';
import { newField } from './FieldTypes';
import { createRow, createStep, createSection, createWelcomeScreen, hasWelcomeScreen } from './schemaHelper';

// Debug — gated on localStorage. Same flag as before.
const DEBUG_DND = ( () => {
	try {
		return typeof window !== 'undefined'
			&& window.localStorage
			&& window.localStorage.getItem( 'nf_debug_dnd' ) === '1';
	} catch ( e ) { return false; }
} )();
function dlog( ...args ) {
	if ( ! DEBUG_DND ) return;
	try { console.log( '[NF DND]', ...args ); } catch ( e ) { /* noop */ }
}

function rectArea( r ) { return r ? ( r.width || 0 ) * ( r.height || 0 ) : Infinity; }
function sortByArea( hits, rects ) {
	return hits.slice().sort( ( a, b ) => rectArea( rects.get( a.id ) ) - rectArea( rects.get( b.id ) ) );
}
function nfCollisionDetection( args ) {
	const activeId = args.active && args.active.id;
	const filterActive = ( hits ) => hits.filter( ( c ) => c.id !== activeId );
	const p = filterActive( pointerWithin( args ) );
	if ( p.length > 0 ) return sortByArea( p, args.droppableRects );
	const r = filterActive( rectIntersection( args ) );
	if ( r.length > 0 ) return sortByArea( r, args.droppableRects );
	return closestCenter( args );
}

/**
 * Wraps `children` in DndContext + DragOverlay. The caller passes
 * the latest `nodes` (form_schema.fields) and a single `onChange`
 * callback that receives the next array. Selection callbacks are
 * optional — when provided, the newly-inserted/moved node is
 * selected after a successful drop.
 */
export default function BuilderDndProvider( {
	nodes,
	onChange,
	onSelectField,
	onSelectStep,
	children,
} ) {
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } )
	);

	const [ activeDrag, setActiveDrag ] = useState( null );

	const onDragStart = useCallback( ( event ) => {
		const data = event.active?.data?.current || null;
		dlog( 'onDragStart', { id: event.active?.id, data } );
		setActiveDrag( {
			id:    event.active?.id,
			data:  data,
			label: ( data && data.label ) || '',
		} );
	}, [] );

	const onDragOver = useCallback( ( event ) => {
		dlog( 'onDragOver', { activeId: event.active?.id, overId: event.over?.id, overData: event.over?.data?.current } );
	}, [] );

	const onDragCancel = useCallback( () => {
		dlog( 'onDragCancel' );
		setActiveDrag( null );
	}, [] );

	const onDragEnd = useCallback( ( event ) => {
		const { active, over } = event;
		dlog( 'onDragEnd', { activeId: active?.id, overId: over?.id, overData: over?.data?.current } );
		setActiveDrag( null );

		if ( ! over ) { dlog( '  bail: no over' ); return; }

		const data = active.data?.current || {};

		// ── Palette drag → CREATE + INSERT ──────────────────────────
		if ( data.source === 'palette' ) {
			let toLoc = parseDropTarget( nodes, over );
			if ( ! toLoc ) {
				// Empty canvas / unknown target → append to root.
				toLoc = { kind: 'root', index: nodes.length };
			}

			let newNode = null;
			if ( data.paletteType === 'field' ) {
				const existingIds = nodes.map( ( n ) => n && n.id ).filter( Boolean );
				newNode = newField( data.fieldType, existingIds );
			} else if ( data.paletteType === 'layout' ) {
				if ( data.layoutType === 'row' )     newNode = createRow( 2 );
				if ( data.layoutType === 'step' )    newNode = createStep();
				if ( data.layoutType === 'section' ) newNode = createSection();
				if ( data.layoutType === 'welcome_screen' ) {
					// One per form. If the dropper bypassed the
					// palette's disabled state (drag-and-drop fast
					// path), reject here so the schema stays valid.
					if ( hasWelcomeScreen( nodes ) ) {
						dlog( '  bail: welcome screen already present' );
						return;
					}
					newNode = createWelcomeScreen();
				}
			}
			if ( ! newNode ) { dlog( '  bail: palette type not recognised' ); return; }

			// Refine root-card targets the same way moveNode-side does.
			toLoc = refineDropTarget( nodes, newNode, toLoc );
			dlog( '  palette destination', toLoc );

			// Steps live only at root.
			if ( newNode.type === 'step' && toLoc.kind !== 'root' ) {
				dlog( '  bail: palette step into non-root' );
				return;
			}

			// Welcome screens are top-level only and always pinned to
			// index 0. Force the drop location regardless of where the
			// user released — the visual semantics are unambiguous
			// (welcome screen is the first thing on the form).
			if ( newNode.type === 'welcome_screen' ) {
				toLoc = { kind: 'root', index: 0 };
			}
			if ( ! canDropInto( newNode, toLoc ) ) {
				dlog( '  bail: palette canDropInto rejected', { sourceType: newNode.type, targetKind: toLoc.kind } );
				return;
			}

			const next = insertAt( nodes, toLoc, newNode );
			if ( next === nodes ) { dlog( '  bail: insertAt identity' ); return; }
			dlog( '  applying palette insert · type =', newNode.type, '· id =', newNode.id );
			onChange( next );

			// Select the newly-inserted node so the inspector shows up.
			if ( newNode.type === 'step' ) {
				if ( typeof onSelectStep === 'function' ) onSelectStep( newNode.id );
			} else {
				if ( typeof onSelectField === 'function' ) onSelectField( newNode.id );
			}
			return;
		}

		// ── Existing canvas drag → MOVE pipeline ────────────────────
		if ( active.id === over.id ) { dlog( '  bail: active === over' ); return; }

		const from = findLocation( nodes, active.id );
		dlog( '  source location', from );
		if ( ! from ) return;
		const sourceNode = getNodeAtLocation( nodes, from );
		dlog( '  source node', sourceNode && { id: sourceNode.id, type: sourceNode.type } );
		if ( ! sourceNode ) return;

		let to = parseDropTarget( nodes, over );
		dlog( '  parsed destination', to );
		if ( ! to ) return;
		to = refineDropTarget( nodes, sourceNode, to );
		dlog( '  refined destination', to );

		if ( sourceNode.type === 'step' && to.kind !== 'root' ) {
			dlog( '  bail: step into non-root' );
			return;
		}
		const allowed = canDropInto( sourceNode, to );
		dlog( '  canDropInto', allowed );
		if ( ! allowed ) return;

		const same = sameContainer( from, to );
		const direction = same
			? ( from.index < to.index ? 'down' : ( from.index > to.index ? 'up' : 'noop' ) )
			: 'cross';
		dlog( '  sameContainer', same, '· direction', direction, '· srcIndex', from.index, '· toIndex', to.index );

		const next = moveNode( nodes, from, to );
		if ( next === nodes ) { dlog( '  bail: moveNode identity' ); return; }
		dlog( '  applying move · final root order', next.map( ( n ) => n && n.id ).filter( Boolean ) );
		onChange( next );
	}, [ nodes, onChange, onSelectField, onSelectStep ] );

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ nfCollisionDetection }
			onDragStart={ onDragStart }
			onDragOver={ onDragOver }
			onDragEnd={ onDragEnd }
			onDragCancel={ onDragCancel }
		>
			{ children }
			<DragOverlay dropAnimation={ null }>
				{ activeDrag ? (
					<div className="nf-drag-overlay">
						{ activeDrag.label || activeDrag.id || '' }
					</div>
				) : null }
			</DragOverlay>
		</DndContext>
	);
}
