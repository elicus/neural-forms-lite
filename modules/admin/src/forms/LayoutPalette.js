/**
 * Sidebar palette for layout containers.
 *
 * Three chips: Row, Step, Section. Each is BOTH clickable (existing
 * behaviour) AND draggable. The drag is wired via @dnd-kit's
 * useDraggable; the top-level BuilderDndProvider's drop handler picks
 * up the palette payload and creates the appropriate layout node at
 * the dropped location.
 *
 * Click → uses the legacy onAdd* callbacks (selection-aware insert).
 * Drag → creates at the dropped location regardless of selection.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useDraggable } from '@dnd-kit/core';

function LayoutChip( { layoutType, label, onClickAdd } ) {
	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: 'palette:layout:' + layoutType,
		data: {
			source:      'palette',
			paletteType: 'layout',
			layoutType,
			label,
		},
	} );
	return (
		<button
			ref={ setNodeRef }
			type="button"
			className={ 'nf-palette__chip nf-palette__chip--layout' + ( isDragging ? ' is-dragging' : '' ) }
			onClick={ onClickAdd }
			title={ sprintf(
				/* translators: %s: layout chip label */
				__( 'Click to add, or drag to place: %s', 'neural-forms-lite' ),
				label
			) }
			{ ...listeners }
			{ ...attributes }
		>
			<span className="nf-palette__chip-label">{ label }</span>
		</button>
	);
}

export default function LayoutPalette( { onAddRow, onAddStep, onAddSection } ) {
	return (
		<section className="nf-palette__section">
			<h3 className="nf-palette__title">{ __( 'Layout', 'neural-forms-lite' ) }</h3>
			<div className="nf-palette__grid">
				<LayoutChip
					layoutType="row"
					label={ __( 'Row', 'neural-forms-lite' ) }
					onClickAdd={ () => onAddRow && onAddRow( 1 ) }
				/>
				<LayoutChip
					layoutType="step"
					label={ __( 'Step', 'neural-forms-lite' ) }
					onClickAdd={ () => onAddStep && onAddStep() }
				/>
				<LayoutChip
					layoutType="section"
					label={ __( 'Section', 'neural-forms-lite' ) }
					onClickAdd={ () => onAddSection && onAddSection() }
				/>
			</div>
		</section>
	);
}
