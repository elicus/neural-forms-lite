/**
 * Column drop-zone inside a layout row.
 *
 * Each column is its own droppable area so dnd-kit knows where a field
 * lands when dragged from another column or from the top-level field
 * stream. Empty columns still render a visible drop hint so the admin
 * can target them with the mouse.
 *
 * Selection: clicking the column's empty area selects it. Once
 * selected, the field palette in the left sidebar adds new fields
 * into THIS column instead of the root. The selection click only
 * fires on the column chrome (empty zone, padding) - clicks that
 * bubble up from a child field row are caught and ignored so the
 * field stays selected, not the column.
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
						{ isSelected
							? __( 'Selected — choose a field from the left', 'neural-forms-lite' )
							: __( 'Drop a field here, or click to add', 'neural-forms-lite' ) }
					</div>
				) }
				{ fields.map( ( field ) => renderField( field, { rowId, colIndex, columnId: column.id } ) ) }
			</SortableContext>
		</div>
	);
}
