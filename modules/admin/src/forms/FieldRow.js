/**
 * Field card on the canvas.
 *
 * 1.1.0 redesign: this is now a READ-ONLY preview card. Editing
 * controls (label, placeholder, type, required, default value, help
 * text, width, css class, conditional logic) all live in the right-
 * side BuilderInspector. The canvas should feel like a live form
 * preview, not a mini settings table.
 *
 * Layout of the card:
 *
 *   [drag handle] [Label + required *] [type badge] [duplicate] [delete]
 *   [field preview - readonly approximation of the frontend render]
 *   [help text - if present]
 *
 * The whole card is selectable: clicking anywhere on the chrome
 * (handle, header, preview wrapper, help line) selects the field.
 * Clicks on the small icon buttons (duplicate, delete) take their
 * native action and stopPropagation so they don't also select.
 *
 * The hidden-field type renders a compact "Hidden" badge card
 * instead of an input preview - see FieldPreview.js.
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';
import { FIELD_TYPES } from './FieldTypes';
import FieldPreview from './FieldPreview';

function typeLabel( typeSlug ) {
	const hit = FIELD_TYPES.find( ( t ) => t.value === typeSlug );
	return hit ? hit.label : typeSlug;
}

export default function FieldRow( {
	field,
	onChange,
	onRemove,
	onDuplicate,
	isSelected,
	onSelect,
} ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id: field.id } );

	const style = {
		transform:  CSS.Transform.toString( transform ),
		transition,
		opacity:    isDragging ? 0.5 : 1,
	};

	const isHidden  = field.type === 'hidden';
	const required  = !! field.required;
	const helpText  = typeof field.help_text === 'string' ? field.help_text : '';
	const cssClass  = typeof field.css_class === 'string' ? field.css_class : '';
	const hasLogic  = !! ( field.conditional_logic && field.conditional_logic.enabled );

	const selectClick = ( e ) => {
		if ( typeof onSelect !== 'function' ) return;
		// Native interactive elements keep their behaviour.
		const tag = e.target?.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'OPTION' ) {
			return;
		}
		e.stopPropagation();
		onSelect();
	};

	const handleDuplicate = ( e ) => {
		e.stopPropagation();
		if ( typeof onDuplicate === 'function' ) onDuplicate( field.id );
	};

	const handleDelete = ( e ) => {
		e.stopPropagation();
		if ( typeof onRemove === 'function' ) onRemove( field.id );
	};

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={
				'nf-builder-field nf-card'
				+ ( field.width === 'half' ? ' nf-builder-field--half' : '' )
				+ ( isDragging ? ' is-dragging' : '' )
				+ ( isSelected ? ' is-selected' : '' )
				+ ( isHidden ? ' nf-card--hidden' : '' )
				+ ( cssClass ? ' ' + cssClass : '' )
			}
			onClick={ selectClick }
		>
			<div className="nf-card__header">
				<button
					type="button"
					className="nf-card__handle"
					aria-label={ __( 'Drag to reorder', 'neural-forms-lite' ) }
					title={ __( 'Drag to reorder', 'neural-forms-lite' ) }
					{ ...attributes }
					{ ...listeners }
				>
					<span aria-hidden="true">⋮⋮</span>
				</button>

				<div className="nf-card__label">
					<span className="nf-card__label-text">
						{ field.label || __( '(untitled field)', 'neural-forms-lite' ) }
					</span>
					{ required && ! isHidden && (
						<span className="nf-card__required" aria-label={ __( 'Required', 'neural-forms-lite' ) } title={ __( 'Required', 'neural-forms-lite' ) }>*</span>
					) }
				</div>

				<span className="nf-card__type-badge">{ typeLabel( field.type ) }</span>

				{ hasLogic && (
					<span
						className="nf-card__logic-badge"
						title={ __( 'Conditional logic enabled', 'neural-forms-lite' ) }
						aria-label={ __( 'Conditional logic enabled', 'neural-forms-lite' ) }
					>{ __( 'Logic', 'neural-forms-lite' ) }</span>
				) }

				<div className="nf-card__actions">
					<button
						type="button"
						className="nf-card__icon-btn"
						onClick={ handleDuplicate }
						aria-label={ __( 'Duplicate field', 'neural-forms-lite' ) }
						title={ __( 'Duplicate field', 'neural-forms-lite' ) }
					>⎘</button>
					<button
						type="button"
						className="nf-card__icon-btn nf-card__icon-btn--danger"
						onClick={ handleDelete }
						aria-label={ __( 'Delete field', 'neural-forms-lite' ) }
						title={ __( 'Delete field', 'neural-forms-lite' ) }
					>×</button>
				</div>
			</div>

			<div className="nf-card__preview">
				<FieldPreview field={ field } />
			</div>

			{ helpText && ! isHidden && (
				<p className="nf-card__help">{ helpText }</p>
			) }
		</div>
	);
}
