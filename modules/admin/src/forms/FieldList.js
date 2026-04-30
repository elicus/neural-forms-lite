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
import StepBlock from './StepBlock';
import { isRow, isStep, resizeRow, duplicateRow, duplicateField, duplicateStep } from './schemaHelper';

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

export default function FieldList( {
	nodes,
	onChange,
	selectedId,
	onSelect,
	selectedColumnId,
	onSelectColumn,
	selectedStepId,
	onSelectStep,
} ) {
	// onSelect / selectedId are optional. When omitted the list behaves
	// exactly like the pre-3-panel canvas: no click-to-select, no
	// highlighted node. Falling back to a no-op keeps existing call sites
	// (and the unit tests for them) working.
	const select = typeof onSelect === 'function' ? onSelect : () => {};
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
			isSelected={ field.id === selectedId }
			onSelect={ () => select( field.id ) }
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
			onDuplicate={ () => {
				const dup = duplicateField( field );
				if ( ctx?.rowId ) {
					const rowIndex = nodes.findIndex( ( n ) => n.id === ctx.rowId );
					if ( rowIndex === -1 ) return;
					const row = nodes[ rowIndex ];
					const cols = row.columns.slice();
					const colFields = cols[ ctx.colIndex ].fields.slice();
					const fIdx = colFields.findIndex( ( f ) => f.id === field.id );
					if ( fIdx === -1 ) return;
					colFields.splice( fIdx + 1, 0, dup );
					cols[ ctx.colIndex ] = { ...cols[ ctx.colIndex ], fields: colFields };
					setNode( rowIndex, { ...row, columns: cols } );
					return;
				}
				const idx = nodes.findIndex( ( n ) => n.id === field.id );
				if ( idx === -1 ) return;
				const copy = nodes.slice();
				copy.splice( idx + 1, 0, dup );
				onChange( copy );
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
						if ( isStep( node ) ) {
							const stepCount = nodes.filter( isStep ).length;
							const stepIndex = nodes.slice( 0, idx ).filter( isStep ).length;
							return (
								<StepBlock
									key={ node.id }
									step={ node }
									stepIndex={ stepIndex }
									stepCount={ stepCount }
									isSelected={ node.id === selectedStepId }
									onSelect={ typeof onSelectStep === 'function' ? () => onSelectStep( node.id ) : undefined }
									canMoveUp={ idx > 0 }
									canMoveDown={ idx < nodes.length - 1 }
									onMoveUp={ () => moveTopLevel( idx, idx - 1 ) }
									onMoveDown={ () => moveTopLevel( idx, idx + 1 ) }
									onDelete={ () => removeNode( idx ) }
									onDuplicate={ () => {
										const copy = nodes.slice();
										copy.splice( idx + 1, 0, duplicateStep( node ) );
										onChange( copy );
									} }
								>
									<StepChildren
										step={ node }
										stepIdx={ idx }
										nodes={ nodes }
										setNode={ setNode }
										selectedId={ selectedId }
										select={ select }
										selectedColumnId={ selectedColumnId }
										onSelectColumn={ onSelectColumn }
									/>
								</StepBlock>
							);
						}
						if ( isRow( node ) ) {
							return (
								<RowBlock
									key={ node.id }
									row={ node }
									isSelected={ node.id === selectedId }
									onSelect={ () => select( node.id ) }
									selectedColumnId={ selectedColumnId }
									onSelectColumn={ onSelectColumn }
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

/**
 * Renders a step's inner children (real fields + rows). Mutations
 * are bound to the parent step's `fields[]` slot via setNode + the
 * step's index in the top-level nodes array. Inner rows still own
 * their column drop zones; columns inside a step row mutate
 * row.columns the usual way (the columns themselves live inside the
 * step.fields[] entry, so updating the row replaces step.fields[i]).
 *
 * dnd-kit cross-step drag is intentionally NOT wired in Phase 2 -
 * only the outer DndContext binds, and reordering inside a step is
 * out of scope. Add/edit/delete/duplicate inside a step all work.
 */
function StepChildren( {
	step,
	stepIdx,
	nodes,
	setNode,
	selectedId,
	select,
	selectedColumnId,
	onSelectColumn,
} ) {
	const children = Array.isArray( step.fields ) ? step.fields : [];

	const setStepFields = ( nextChildren ) => {
		setNode( stepIdx, { ...step, fields: nextChildren } );
	};

	const setChild = ( childIdx, nextChild ) => {
		const copy = children.slice();
		copy[ childIdx ] = nextChild;
		setStepFields( copy );
	};

	const removeChild = ( childIdx ) => {
		setStepFields( children.filter( ( _, i ) => i !== childIdx ) );
	};

	const insertAfter = ( childIdx, child ) => {
		const copy = children.slice();
		copy.splice( childIdx + 1, 0, child );
		setStepFields( copy );
	};

	// Step-bound renderField for real fields directly inside the step,
	// AND for fields inside a row that lives inside the step. ctx.rowId
	// distinguishes the two cases.
	const stepRenderField = ( field, ctx ) => (
		<FieldRow
			key={ field.id }
			field={ field }
			isSelected={ field.id === selectedId }
			onSelect={ () => select( field.id ) }
			onChange={ ( next ) => {
				if ( ctx?.rowId ) {
					const rowChildIdx = children.findIndex( ( c ) => c.id === ctx.rowId );
					if ( rowChildIdx === -1 ) return;
					const row = children[ rowChildIdx ];
					const cols = row.columns.slice();
					const fields = cols[ ctx.colIndex ].fields.slice();
					const fIdx = fields.findIndex( ( f ) => f.id === field.id );
					if ( fIdx === -1 ) return;
					fields[ fIdx ] = next;
					cols[ ctx.colIndex ] = { ...cols[ ctx.colIndex ], fields };
					setChild( rowChildIdx, { ...row, columns: cols } );
					return;
				}
				const idx = children.findIndex( ( c ) => c.id === field.id );
				if ( idx === -1 ) return;
				setChild( idx, next );
			} }
			onRemove={ () => {
				if ( ctx?.rowId ) {
					const rowChildIdx = children.findIndex( ( c ) => c.id === ctx.rowId );
					if ( rowChildIdx === -1 ) return;
					const row = children[ rowChildIdx ];
					const cols = row.columns.slice();
					cols[ ctx.colIndex ] = {
						...cols[ ctx.colIndex ],
						fields: cols[ ctx.colIndex ].fields.filter( ( f ) => f.id !== field.id ),
					};
					setChild( rowChildIdx, { ...row, columns: cols } );
					return;
				}
				const idx = children.findIndex( ( c ) => c.id === field.id );
				if ( idx !== -1 ) removeChild( idx );
			} }
			onDuplicate={ () => {
				const dup = duplicateField( field );
				if ( ctx?.rowId ) {
					const rowChildIdx = children.findIndex( ( c ) => c.id === ctx.rowId );
					if ( rowChildIdx === -1 ) return;
					const row = children[ rowChildIdx ];
					const cols = row.columns.slice();
					const colFields = cols[ ctx.colIndex ].fields.slice();
					const fIdx = colFields.findIndex( ( f ) => f.id === field.id );
					if ( fIdx === -1 ) return;
					colFields.splice( fIdx + 1, 0, dup );
					cols[ ctx.colIndex ] = { ...cols[ ctx.colIndex ], fields: colFields };
					setChild( rowChildIdx, { ...row, columns: cols } );
					return;
				}
				const idx = children.findIndex( ( c ) => c.id === field.id );
				if ( idx === -1 ) return;
				insertAfter( idx, dup );
			} }
		/>
	);

	return (
		<>
			{ children.map( ( child, idx ) => {
				if ( isRow( child ) ) {
					return (
						<RowBlock
							key={ child.id }
							row={ child }
							isSelected={ child.id === selectedId }
							onSelect={ () => select( child.id ) }
							selectedColumnId={ selectedColumnId }
							onSelectColumn={ onSelectColumn }
							canMoveUp={ idx > 0 }
							canMoveDown={ idx < children.length - 1 }
							onMoveUp={ () => {
								if ( idx <= 0 ) return;
								const copy = children.slice();
								const [ pulled ] = copy.splice( idx, 1 );
								copy.splice( idx - 1, 0, pulled );
								setStepFields( copy );
							} }
							onMoveDown={ () => {
								if ( idx >= children.length - 1 ) return;
								const copy = children.slice();
								const [ pulled ] = copy.splice( idx, 1 );
								copy.splice( idx + 1, 0, pulled );
								setStepFields( copy );
							} }
							onDelete={ () => removeChild( idx ) }
							onDuplicate={ () => insertAfter( idx, duplicateRow( child ) ) }
							onChange={ ( cmd ) => {
								if ( cmd.type === 'resize' ) {
									setChild( idx, resizeRow( child, cmd.nextCount ) );
								} else if ( cmd.type === 'patch' ) {
									setChild( idx, { ...child, ...cmd.patch } );
								}
							} }
							renderField={ stepRenderField }
						/>
					);
				}
				return stepRenderField( child, null );
			} ) }
		</>
	);
}
