/**
 * Section block on the canvas.
 *
 * Visual divider / heading + optional description. Reads as a leaf
 * layout node in the canvas: no inner drop zone, no field list. The
 * inspector edits title, description, and visual style.
 *
 * Sortable at the top level (and inside step.fields / column.fields)
 * via dnd-kit's useSortable hook, same pattern as RowBlock.
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';

export default function SectionBlock( {
	section,
	onChange, // reserved for future inline-edit; not used today
	onDuplicate,
	onDelete,
	onMoveUp,
	onMoveDown,
	canMoveUp,
	canMoveDown,
	isSelected,
	onSelect,
} ) {
	const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable( {
		id: section.id,
		data: { kind: 'section', type: 'section' },
	} );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	const title = typeof section.title === 'string' ? section.title : '';
	const desc  = typeof section.description === 'string' ? section.description : '';

	const selectClick = ( e ) => {
		if ( typeof onSelect !== 'function' ) return;
		const tag = e.target?.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'OPTION' ) {
			return;
		}
		e.stopPropagation();
		onSelect();
	};

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={
				'nf-section'
				+ ( isDragging ? ' is-dragging' : '' )
				+ ( isSelected ? ' is-selected' : '' )
			}
			data-nf-section-id={ section.id }
			onClick={ selectClick }
		>
			<header className="nf-section__header">
				<button
					type="button"
					ref={ setActivatorNodeRef }
					className="nf-drag-zone nf-drag-zone--section"
					aria-label={ __( 'Drag section', 'neural-forms-lite' ) }
					title={ __( 'Drag to move', 'neural-forms-lite' ) }
					{ ...attributes }
					{ ...listeners }
				>
					<span className="nf-drag-zone__icon" aria-hidden="true">⋮⋮</span>
				</button>

				<span className="nf-section__pill">{ __( 'Section', 'neural-forms-lite' ) }</span>

				<span className="nf-section__title">
					{ title || __( '(untitled section)', 'neural-forms-lite' ) }
				</span>

				<div className="nf-section__actions">
					{ typeof onMoveUp === 'function' && (
						<button
							type="button"
							className="nf-section__icon-btn"
							onClick={ ( e ) => { e.stopPropagation(); onMoveUp(); } }
							disabled={ ! canMoveUp }
							aria-label={ __( 'Move section up', 'neural-forms-lite' ) }
							title={ __( 'Move section up', 'neural-forms-lite' ) }
						>↑</button>
					) }
					{ typeof onMoveDown === 'function' && (
						<button
							type="button"
							className="nf-section__icon-btn"
							onClick={ ( e ) => { e.stopPropagation(); onMoveDown(); } }
							disabled={ ! canMoveDown }
							aria-label={ __( 'Move section down', 'neural-forms-lite' ) }
							title={ __( 'Move section down', 'neural-forms-lite' ) }
						>↓</button>
					) }
					{ typeof onDuplicate === 'function' && (
						<button
							type="button"
							className="nf-section__icon-btn"
							onClick={ ( e ) => { e.stopPropagation(); onDuplicate(); } }
							aria-label={ __( 'Duplicate section', 'neural-forms-lite' ) }
							title={ __( 'Duplicate section', 'neural-forms-lite' ) }
						>⎘</button>
					) }
					{ typeof onDelete === 'function' && (
						<button
							type="button"
							className="nf-section__icon-btn nf-section__icon-btn--danger"
							onClick={ ( e ) => { e.stopPropagation(); onDelete(); } }
							aria-label={ __( 'Delete section', 'neural-forms-lite' ) }
							title={ __( 'Delete section', 'neural-forms-lite' ) }
						>×</button>
					) }
				</div>
			</header>

			{ desc && <p className="nf-section__description">{ desc }</p> }
			{ ! title && ! desc && (
				<p className="nf-section__hint">
					{ __( 'Add a title or description in the inspector to describe this section.', 'neural-forms-lite' ) }
				</p>
			) }
		</div>
	);
}
