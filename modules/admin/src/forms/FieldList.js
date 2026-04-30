/**
 * Top-level form-builder canvas.
 *
 * Renders a vertical sortable list of nodes. Each node is either:
 *   - a layout row (RowBlock) with column drop zones inside it; or
 *   - a flat field (FieldRow) at the top level.
 *
 * Drag-and-drop handles three movements:
 *   1. Reorder a top-level row (or flat field) within the canvas.
 *   2. Reorder a field inside a column.
 *   3. Move a field across columns (drop into a different column drop zone)
 *      or out of/into the top-level stream.
 */

import {
	DndContext,
	PointerSensor,
	KeyboardSensor,
	useSensor,
	useSensors,
	pointerWithin,
} from '@dnd-kit/core';
import {
	SortableContext,
	verticalListSortingStrategy,
	sortableKeyboardCoordinates,
} from '@dnd-kit/sortable';
import FieldRow from './FieldRow';
import RowBlock from './RowBlock';
import { isRow, resizeRow, duplicateRow } from './schemaHelper';

function moveField( nodes, from, to ) {
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

function findFieldPosition( nodes, fieldId ) {
	for ( let i = 0; i < nodes.length; i += 1 ) {
		const node = nodes[ i ];
		if ( ! node ) continue;
		if ( ! isRow( node ) ) {
			if ( node.id === fieldId ) return { kind: 'top', index: i };
			continue;
		}
		const cols = node.columns || [];
		for ( let c = 0; c < cols.length; c += 1 ) {
			const fields = cols[ c ].fields || [];
			for ( let f = 0; f < fields.length; f += 1 ) {
				if ( fields[ f ].id === fieldId ) {
					return { kind: 'col', rowIndex: i, colIndex: c, index: f };
				}
			}
		}
	}
	return null;
}

export default function FieldList( { nodes, onChange } ) {
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } )
	);

	const setNode = ( index, next ) => {
		const copy = nodes.slice();
		copy[ index ] = next;
		onChange( copy );
	};

	const removeNode = ( index ) => {
		const copy = nodes.slice();
		copy.splice( index, 1 );
		onChange( copy );
	};

	const moveTopLevel = ( from, to ) => {
		if ( to < 0 || to >= nodes.length ) return;
		const copy = nodes.slice();
		const [ pulled ] = copy.splice( from, 1 );
		copy.splice( to, 0, pulled );
		onChange( copy );
	};

	const handleDragEnd = ( event ) => {
		const { active, over } = event;
		if ( ! over || active.id === over.id ) return;

		const activeKind = active.data?.current?.kind;

		// Row reorder at top level.
		if ( activeKind === 'row' ) {
			const fromIdx = nodes.findIndex( ( n ) => n.id === active.id );
			const toIdx   = nodes.findIndex( ( n ) => n.id === over.id );
			if ( fromIdx === -1 || toIdx === -1 ) return;
			moveTopLevel( fromIdx, toIdx );
			return;
		}

		const from = findFieldPosition( nodes, active.id );
		if ( ! from ) return;

		// Drop on a column drop-zone (id "col:rowId:colIdx").
		if ( typeof over.id === 'string' && over.id.startsWith( 'col:' ) ) {
			const [ , rowIdMaybe, colIdxStr ] = over.id.split( ':' );
			const rowIndex = nodes.findIndex( ( n ) => n.id === rowIdMaybe );
			const colIndex = parseInt( colIdxStr, 10 );
			if ( rowIndex === -1 || Number.isNaN( colIndex ) ) return;
			const destFields = nodes[ rowIndex ].columns[ colIndex ].fields || [];
			onChange( moveField( nodes, from, {
				kind: 'col',
				rowIndex,
				colIndex,
				index: destFields.length,
			} ) );
			return;
		}

		const to = findFieldPosition( nodes, over.id );
		if ( ! to ) {
			// Drop on a row body without hitting a column zone -> append
			// to the row's first column.
			const rowIndex = nodes.findIndex( ( n ) => n.id === over.id );
			if ( rowIndex !== -1 && isRow( nodes[ rowIndex ] ) ) {
				const destFields = nodes[ rowIndex ].columns[ 0 ].fields || [];
				onChange( moveField( nodes, from, {
					kind: 'col',
					rowIndex,
					colIndex: 0,
					index: destFields.length,
				} ) );
			}
			return;
		}
		onChange( moveField( nodes, from, to ) );
	};

	const topIds = nodes.map( ( n ) => n.id );

	const renderField = ( field, ctx ) => (
		<FieldRow
			key={ field.id }
			field={ field }
			onChange={ ( next ) => {
				if ( ctx?.rowId ) {
					const rowIndex = nodes.findIndex( ( n ) => n.id === ctx.rowId );
					if ( rowIndex === -1 ) return;
					const row = nodes[ rowIndex ];
					const cols = row.columns.slice();
					const fields = cols[ ctx.colIndex ].fields.slice();
					const fIdx = fields.findIndex( ( f ) => f.id === field.id );
					if ( fIdx === -1 ) return;
					fields[ fIdx ] = next;
					cols[ ctx.colIndex ] = { ...cols[ ctx.colIndex ], fields };
					setNode( rowIndex, { ...row, columns: cols } );
					return;
				}
				const idx = nodes.findIndex( ( n ) => n.id === field.id );
				if ( idx === -1 ) return;
				setNode( idx, next );
			} }
			onRemove={ () => {
				if ( ctx?.rowId ) {
					const rowIndex = nodes.findIndex( ( n ) => n.id === ctx.rowId );
					if ( rowIndex === -1 ) return;
					const row = nodes[ rowIndex ];
					const cols = row.columns.slice();
					cols[ ctx.colIndex ] = {
						...cols[ ctx.colIndex ],
						fields: cols[ ctx.colIndex ].fields.filter( ( f ) => f.id !== field.id ),
					};
					setNode( rowIndex, { ...row, columns: cols } );
					return;
				}
				const idx = nodes.findIndex( ( n ) => n.id === field.id );
				if ( idx !== -1 ) removeNode( idx );
			} }
		/>
	);

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ pointerWithin }
			onDragEnd={ handleDragEnd }
		>
			<SortableContext items={ topIds } strategy={ verticalListSortingStrategy }>
				<div className="nf-builder-canvas">
					{ nodes.map( ( node, idx ) => {
						if ( isRow( node ) ) {
							return (
								<RowBlock
									key={ node.id }
									row={ node }
									canMoveUp={ idx > 0 }
									canMoveDown={ idx < nodes.length - 1 }
									onMoveUp={ () => moveTopLevel( idx, idx - 1 ) }
									onMoveDown={ () => moveTopLevel( idx, idx + 1 ) }
									onDelete={ () => removeNode( idx ) }
									onDuplicate={ () => {
										const copy = nodes.slice();
										copy.splice( idx + 1, 0, duplicateRow( node ) );
										onChange( copy );
									} }
									onChange={ ( cmd ) => {
										if ( cmd.type === 'resize' ) {
											setNode( idx, resizeRow( node, cmd.nextCount ) );
										} else if ( cmd.type === 'patch' ) {
											setNode( idx, { ...node, ...cmd.patch } );
										}
									} }
									renderField={ renderField }
								/>
							);
						}
						return renderField( node, null );
					} ) }
				</div>
			</SortableContext>
		</DndContext>
	);
}
