/**
 * Layout step in the form builder.
 *
 * Top-level layout container. A step card has:
 *
 *   header: drag handle, "STEP N" pill, editable title (inline),
 *           optional description, move ↑/↓, duplicate, delete
 *   body:   inner sortable list that accepts real fields and rows
 *           (validator rejects nested steps and orphan columns)
 *
 * Reuses the same dnd-kit useSortable hook as RowBlock so steps can
 * be reordered at the top level. Inner fields/rows are rendered by
 * recursively passing slices down through FieldList's renderer
 * callback - the parent owns mutation; we only emit JSX + click
 * handlers.
 *
 * Selection: clicking the step chrome (handle, header, body
 * background) selects the step. Clicks bubbling up from a child
 * field/row are stopped at the child level so they don't accidentally
 * flip the inspector to step settings.
 */

import { useSortable } from '@dnd-kit/sortable';
import { useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { __, sprintf } from '@wordpress/i18n';

export default function StepBlock( {
	step,
	stepIndex,
	stepCount,
	onChange,
	onDuplicate,
	onDelete,
	onMoveUp,
	onMoveDown,
	canMoveUp,
	canMoveDown,
	isSelected,
	onSelect,
	children,
} ) {
	const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable( {
		id: step.id,
		data: { kind: 'step', type: 'step' },
	} );

	// Empty-step body droppable so fields/rows can be dropped into a
	// step that has no children yet.
	const bodyDrop = useDroppable( {
		id: `step-body:${ step.id }`,
		data: { kind: 'step-body', stepId: step.id },
	} );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	const isEmpty = ! Array.isArray( step.fields ) || step.fields.length === 0;
	const title   = typeof step.title === 'string' ? step.title : '';
	const desc    = typeof step.description === 'string' ? step.description : '';

	const selectClick = ( e ) => {
		if ( typeof onSelect !== 'function' ) return;
		const tag = e.target?.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'OPTION' ) {
			return;
		}
		// Don't reselect if a child field/row was clicked (their own
		// click handler stopPropagation()s, so this is defensive).
		if ( e.target?.closest && (
			e.target.closest( '.nf-builder-field' ) ||
			e.target.closest( '.nf-row' )
		) ) {
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
				'nf-step' +
				( isDragging ? ' is-dragging' : '' ) +
				( isSelected ? ' is-selected' : '' )
			}
			data-nf-step-id={ step.id }
			onClick={ selectClick }
		>
			<header className="nf-step__header">
				<div className="nf-step__group nf-step__group--left">
					<button
						type="button"
						ref={ setActivatorNodeRef }
						className="nf-drag-zone nf-drag-zone--step"
						aria-label={ __( 'Drag step', 'neural-forms-lite' ) }
						title={ __( 'Drag to move', 'neural-forms-lite' ) }
						{ ...attributes }
						{ ...listeners }
					>
						<span className="nf-drag-zone__icon" aria-hidden="true">⋮⋮</span>
					</button>
					<span className="nf-step__pill">
						{ sprintf(
							/* translators: 1: current step number  2: total steps */
							__( 'Step %1$d of %2$d', 'neural-forms-lite' ),
							stepIndex + 1,
							stepCount
						) }
					</span>

					<span className="nf-step__title">
						{ title || __( '(untitled step)', 'neural-forms-lite' ) }
					</span>
				</div>

				<div className="nf-step__group nf-step__group--right">
					<div className="nf-step__actions">
						<button
							type="button"
							className="nf-step__icon-btn"
							onClick={ ( e ) => { e.stopPropagation(); onMoveUp && onMoveUp(); } }
							disabled={ ! canMoveUp }
							aria-label={ __( 'Move step up', 'neural-forms-lite' ) }
							title={ __( 'Move step up', 'neural-forms-lite' ) }
						>↑</button>
						<button
							type="button"
							className="nf-step__icon-btn"
							onClick={ ( e ) => { e.stopPropagation(); onMoveDown && onMoveDown(); } }
							disabled={ ! canMoveDown }
							aria-label={ __( 'Move step down', 'neural-forms-lite' ) }
							title={ __( 'Move step down', 'neural-forms-lite' ) }
						>↓</button>
						<button
							type="button"
							className="nf-step__icon-btn"
							onClick={ ( e ) => { e.stopPropagation(); onDuplicate && onDuplicate(); } }
							aria-label={ __( 'Duplicate step', 'neural-forms-lite' ) }
							title={ __( 'Duplicate step', 'neural-forms-lite' ) }
						>⎘</button>
						<button
							type="button"
							className="nf-step__icon-btn nf-step__icon-btn--danger"
							onClick={ ( e ) => { e.stopPropagation(); onDelete && onDelete(); } }
							aria-label={ __( 'Delete step', 'neural-forms-lite' ) }
							title={ __( 'Delete step', 'neural-forms-lite' ) }
						>×</button>
					</div>
				</div>
			</header>

			{ desc && <p className="nf-step__description">{ desc }</p> }

			<div
				ref={ bodyDrop.setNodeRef }
				className={
					'nf-step__body'
					+ ( bodyDrop.isOver ? ' is-over' : '' )
					+ ( isEmpty ? ' nf-step__body--empty' : '' )
				}
			>
				{ isEmpty && (
					<div className="nf-step__empty">
						{ __( 'Drop a field or row here, or click a chip in the sidebar.', 'neural-forms-lite' ) }
					</div>
				) }
				{ children }
			</div>
		</div>
	);
}
