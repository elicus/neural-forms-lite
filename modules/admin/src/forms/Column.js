/**
 * Column drop-zone inside a layout row.
 *
 * Each column is its own droppable area so dnd-kit knows where a field
 * lands when dragged from another column or from the top-level field
 * stream. Empty columns still render a visible drop hint so the admin
 * can target them with the mouse.
 *
 * Placement model:
 *   - Drag a palette chip onto a column → field drops into that column.
 *   - Click a palette chip in the sidebar → field appends at the
 *     bottom of the form (root level). The previous "selected column
 *     wins for palette clicks" routing made it impossible to add a
 *     standalone field once any row existed, so palette clicks always
 *     go to root now.
 *
 * The column's selection click is kept (it still fires onSelect for
 * any future ColumnInspector content) but no longer changes where
 * palette-added fields land.
 */

import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { __ } from '@wordpress/i18n';

export default function Column( {
	rowId,
	column,
	colIndex,
	registerDropZone,
	renderField,
	isSelected,
	onSelect,
} ) {
	const dropId = `col:${ rowId }:${ colIndex }`;
	const { setNodeRef, isOver } = useDroppable( {
		id: dropId,
		data: { kind: 'column', rowId, colIndex, columnId: column.id },
	} );

	if ( typeof registerDropZone === 'function' ) {
		registerDropZone( dropId, { rowId, colIndex, columnId: column.id } );
	}

	const fields = Array.isArray( column.fields ) ? column.fields : [];
	const ids = fields.map( ( f ) => f.id );

	const selectClick = ( e ) => {
		if ( typeof onSelect !== 'function' ) return;
		const tag = e.target?.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'OPTION' ) {
			return;
		}
		// Only react when the click landed directly on the column
		// chrome - not inside a child field row. Field rows have their
		// own click handler and call stopPropagation, but use a defensive
		// closest() guard so a stray bubble can't accidentally re-select
		// the column.
		if ( e.target?.closest && e.target.closest( '.nf-builder-field' ) ) {
			return;
		}
		e.stopPropagation();
		onSelect();
	};

	return (
		<div
			ref={ setNodeRef }
			className={
				'nf-column'
				+ ( isOver ? ' is-over' : '' )
				+ ( fields.length === 0 ? ' is-empty' : '' )
				+ ( isSelected ? ' is-selected' : '' )
			}
			data-nf-col-id={ column.id }
			onClick={ selectClick }
		>
			<SortableContext items={ ids } strategy={ verticalListSortingStrategy }>
				{ fields.length === 0 && (
					<div className="nf-column__placeholder">
						{ __( 'Drag a field here from the left', 'neural-forms-lite' ) }
					</div>
				) }
				{ fields.map( ( field ) => renderField( field, { rowId, colIndex, columnId: column.id } ) ) }
			</SortableContext>
		</div>
	);
}
