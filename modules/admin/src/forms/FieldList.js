/**
 * Top-level form-builder canvas.
 *
 * Renders the field tree. The DndContext (sensors, collision strategy,
 * drag handlers, DragOverlay) lives one level up in BuilderDnd.js so
 * sidebar palette chips can be dragged into the canvas. This file
 * still owns the outer <SortableContext> for top-level node ids and
 * the per-step / per-column inner SortableContexts.
 */

import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import FieldRow from './FieldRow';
import RowBlock from './RowBlock';
import StepBlock from './StepBlock';
import SectionBlock from './SectionBlock';
import { isRow, isStep, isSection, resizeRow, duplicateRow, duplicateField, duplicateStep, duplicateSection } from './schemaHelper';

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

	const topIds = nodes.map( ( n ) => n.id );

	const renderField = ( field, ctx ) => {
		// A section inside a column should render as a SectionBlock,
		// not a FieldRow. Mutations target the parent row's column
		// fields[] - same surface fields use.
		if ( isSection( field ) ) {
			const dropFromCol = () => {
				if ( ! ctx?.rowId ) return;
				const rowIndex = nodes.findIndex( ( n ) => n.id === ctx.rowId );
				if ( rowIndex === -1 ) return;
				const row = nodes[ rowIndex ];
				const cols = row.columns.slice();
				cols[ ctx.colIndex ] = {
					...cols[ ctx.colIndex ],
					fields: cols[ ctx.colIndex ].fields.filter( ( f ) => f.id !== field.id ),
				};
				setNode( rowIndex, { ...row, columns: cols } );
			};
			const dupAfterInCol = () => {
				if ( ! ctx?.rowId ) return;
				const rowIndex = nodes.findIndex( ( n ) => n.id === ctx.rowId );
				if ( rowIndex === -1 ) return;
				const row = nodes[ rowIndex ];
				const cols = row.columns.slice();
				const colFields = cols[ ctx.colIndex ].fields.slice();
				const idx = colFields.findIndex( ( f ) => f.id === field.id );
				if ( idx === -1 ) return;
				colFields.splice( idx + 1, 0, duplicateSection( field ) );
				cols[ ctx.colIndex ] = { ...cols[ ctx.colIndex ], fields: colFields };
				setNode( rowIndex, { ...row, columns: cols } );
			};
			return (
				<SectionBlock
					key={ field.id }
					section={ field }
					isSelected={ field.id === selectedId }
					onSelect={ () => select( field.id ) }
					onDelete={ ctx?.rowId ? dropFromCol : undefined }
					onDuplicate={ ctx?.rowId ? dupAfterInCol : undefined }
				/>
			);
		}

		return (
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
	};

	return (
		<SortableContext items={ topIds } strategy={ verticalListSortingStrategy }>
			<div className="nf-builder-canvas">
				{ nodes.length > 0 && (
					<p className="nf-builder-canvas__tip" aria-live="polite">
						{ /* Translators kept inline so the tip stays terse. */ }
						<span className="nf-builder-canvas__tip-icon" aria-hidden="true">⋮⋮</span>
						<span>{ 'Tip: drag fields by the grip handle on the left.' }</span>
					</p>
				) }
				{ nodes.map( ( node, idx ) => {
						if ( isSection( node ) ) {
							return (
								<SectionBlock
									key={ node.id }
									section={ node }
									isSelected={ node.id === selectedId }
									onSelect={ () => select( node.id ) }
									canMoveUp={ idx > 0 }
									canMoveDown={ idx < nodes.length - 1 }
									onMoveUp={ () => moveTopLevel( idx, idx - 1 ) }
									onMoveDown={ () => moveTopLevel( idx, idx + 1 ) }
									onDelete={ () => removeNode( idx ) }
									onDuplicate={ () => {
										const copy = nodes.slice();
										copy.splice( idx + 1, 0, duplicateSection( node ) );
										onChange( copy );
									} }
								/>
							);
						}
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
	moveCtx,
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
	const stepRenderField = ( field, ctx ) => {
		// Sections inside a step's row.column.fields render as a
		// SectionBlock, not a FieldRow. Mutations go through the
		// step's row ctx.
		if ( isSection( field ) ) {
			const dropInStepRowCol = () => {
				if ( ! ctx?.rowId ) return;
				const rowChildIdx = children.findIndex( ( c ) => c.id === ctx.rowId );
				if ( rowChildIdx === -1 ) return;
				const row = children[ rowChildIdx ];
				const cols = row.columns.slice();
				cols[ ctx.colIndex ] = {
					...cols[ ctx.colIndex ],
					fields: cols[ ctx.colIndex ].fields.filter( ( f ) => f.id !== field.id ),
				};
				setChild( rowChildIdx, { ...row, columns: cols } );
			};
			const dupInStepRowCol = () => {
				if ( ! ctx?.rowId ) return;
				const rowChildIdx = children.findIndex( ( c ) => c.id === ctx.rowId );
				if ( rowChildIdx === -1 ) return;
				const row = children[ rowChildIdx ];
				const cols = row.columns.slice();
				const colFields = cols[ ctx.colIndex ].fields.slice();
				const idx = colFields.findIndex( ( f ) => f.id === field.id );
				if ( idx === -1 ) return;
				colFields.splice( idx + 1, 0, duplicateSection( field ) );
				cols[ ctx.colIndex ] = { ...cols[ ctx.colIndex ], fields: colFields };
				setChild( rowChildIdx, { ...row, columns: cols } );
			};
			return (
				<SectionBlock
					key={ field.id }
					section={ field }
					isSelected={ field.id === selectedId }
					onSelect={ () => select( field.id ) }
					onDelete={ ctx?.rowId ? dropInStepRowCol : undefined }
					onDuplicate={ ctx?.rowId ? dupInStepRowCol : undefined }
				/>
			);
		}

		return (
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
	};

	const stepChildIds = children.map( ( c ) => c && c.id ).filter( Boolean );

	return (
		<SortableContext items={ stepChildIds } strategy={ verticalListSortingStrategy }>
			{ children.map( ( child, idx ) => {
				if ( isSection( child ) ) {
					return (
						<SectionBlock
							key={ child.id }
							section={ child }
							isSelected={ child.id === selectedId }
							onSelect={ () => select( child.id ) }
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
							onDuplicate={ () => insertAfter( idx, duplicateSection( child ) ) }
						/>
					);
				}
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
		</SortableContext>
	);
}
