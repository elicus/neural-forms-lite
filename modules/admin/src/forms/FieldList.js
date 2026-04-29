/**
 * Sortable list of FieldRow items.
 *
 * Wraps the rows in @dnd-kit's DndContext + SortableContext so dragging
 * a field by its handle reorders the underlying array. Reordering across
 * "columns" is not a separate dimension - pairing is implicit from the
 * sequential order plus each field's `width` attribute. So the dnd model
 * stays a single vertical list while the renderer (PHP) and the builder
 * preview (CSS) both fold consecutive halves into a row.
 */

import {
	DndContext,
	PointerSensor,
	KeyboardSensor,
	useSensor,
	useSensors,
	closestCenter,
} from '@dnd-kit/core';
import {
	SortableContext,
	arrayMove,
	verticalListSortingStrategy,
	sortableKeyboardCoordinates,
} from '@dnd-kit/sortable';
import FieldRow from './FieldRow';

export default function FieldList( { fields, onChange } ) {
	const sensors = useSensors(
		useSensor( PointerSensor, {
			// Small activation distance so a click on the handle still
			// registers as a click without immediately starting a drag.
			activationConstraint: { distance: 4 },
		} ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	const handleDragEnd = ( event ) => {
		const { active, over } = event;
		if ( ! over || active.id === over.id ) { return; }

		const oldIndex = fields.findIndex( ( f ) => f.id === active.id );
		const newIndex = fields.findIndex( ( f ) => f.id === over.id );
		if ( oldIndex === -1 || newIndex === -1 ) { return; }

		onChange( arrayMove( fields, oldIndex, newIndex ) );
	};

	const setField = ( next ) => {
		onChange( fields.map( ( f ) => ( f.id === next.id ? next : f ) ) );
	};

	const removeField = ( id ) => {
		onChange( fields.filter( ( f ) => f.id !== id ) );
	};

	const moveField = ( from, to ) => {
		if ( to < 0 || to >= fields.length ) { return; }
		onChange( arrayMove( fields, from, to ) );
	};

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ closestCenter }
			onDragEnd={ handleDragEnd }
		>
			<SortableContext
				items={ fields.map( ( f ) => f.id ) }
				strategy={ verticalListSortingStrategy }
			>
				<div className="nf-builder-field-list">
					{ fields.map( ( field, idx ) => (
						<FieldRow
							key={ field.id }
							field={ field }
							index={ idx }
							total={ fields.length }
							onChange={ setField }
							onRemove={ removeField }
							onMove={ moveField }
						/>
					) ) }
				</div>
			</SortableContext>
		</DndContext>
	);
}
