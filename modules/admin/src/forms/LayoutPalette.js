/**
 * Sidebar palette for layout containers.
 *
 * Four chips: Row, Step, Section, Welcome Screen. Each is BOTH
 * clickable (existing behaviour) AND draggable. The drag is wired
 * via @dnd-kit's useDraggable; the top-level BuilderDndProvider's
 * drop handler picks up the palette payload and creates the
 * appropriate layout node at the dropped location.
 *
 * Welcome Screen is special: only ONE per form. When the form
 * already has one, the chip is disabled (greyed out, drag/click
 * suppressed) with a tooltip explaining the constraint.
 *
 * Click → uses the legacy onAdd* callbacks (selection-aware insert).
 * Drag → creates at the dropped location regardless of selection.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useDraggable } from '@dnd-kit/core';

function LayoutChip( { layoutType, label, onClickAdd, disabled, disabledTitle } ) {
	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: 'palette:layout:' + layoutType,
		data: {
			source:      'palette',
			paletteType: 'layout',
			layoutType,
			label,
		},
		disabled: !! disabled,
	} );
	return (
		<button
			ref={ setNodeRef }
			type="button"
			className={
				'nf-palette__chip nf-palette__chip--layout'
				+ ( isDragging ? ' is-dragging' : '' )
				+ ( disabled ? ' is-disabled' : '' )
			}
			onClick={ disabled ? undefined : onClickAdd }
			disabled={ !! disabled }
			title={ disabled
				? ( disabledTitle || label )
				: sprintf(
					/* translators: %s: layout chip label */
					__( 'Click to add, or drag to place: %s', 'neural-forms-lite' ),
					label
				)
			}
			{ ...( disabled ? {} : listeners ) }
			{ ...attributes }
		>
			<span className="nf-palette__chip-label">{ label }</span>
		</button>
	);
}

export default function LayoutPalette( {
	onAddRow,
	onAddStep,
	onAddSection,
	onAddWelcomeScreen,
	welcomeScreenPresent,
} ) {
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
				<LayoutChip
					layoutType="welcome_screen"
					label={ __( 'Welcome Screen', 'neural-forms-lite' ) }
					onClickAdd={ () => onAddWelcomeScreen && onAddWelcomeScreen() }
					disabled={ !! welcomeScreenPresent }
					disabledTitle={ __( 'Only one Welcome Screen per form.', 'neural-forms-lite' ) }
				/>
			</div>
		</section>
	);
}
