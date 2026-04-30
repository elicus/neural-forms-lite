/**
 * Column drop-zone inside a layout row.
 *
 * Each column is its own droppable area so dnd-kit knows where a field
 * lands when dragged from another column or from the top-level field
 * stream. Empty columns still render a visible drop hint so the admin
 * can target them with the mouse.
 */

import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { __ } from '@wordpress/i18n';

export default function Column( { rowId, column, colIndex, registerDropZone, renderField } ) {
	const dropId = `col:${ rowId }:${ colIndex }`;
	const { setNodeRef, isOver } = useDroppable( {
		id: dropId,
		data: { kind: 'column', rowId, colIndex, columnId: column.id },
	} );

	if ( typeof registerDropZone === 'function' ) {
		registerDropZone( dropId, { rowId, colIndex, columnId: column.id } );
	}

	const fields = Array.isArray( column.fields ) ? column.fields : [];
	const ids    = fields.map( ( f ) => f.id );

	return (
		<div
			ref={ setNodeRef }
			className={ 'nf-column' + ( isOver ? ' is-over' : '' ) + ( fields.length === 0 ? ' is-empty' : '' ) }
			data-nf-col-id={ column.id }
		>
			<SortableContext items={ ids } strategy={ verticalListSortingStrategy }>
				{ fields.length === 0 && (
					<div className="nf-column__placeholder">
						{ __( 'Drop a field here', 'neural-forms-lite' ) }
					</div>
				) }
				{ fields.map( ( field ) => renderField( field, { rowId, colIndex, columnId: column.id } ) ) }
			</SortableContext>
		</div>
	);
}
