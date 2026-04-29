/**
 * Single-field row in the form builder.
 *
 * Layout matches the screenshot:
 *   [drag handle] [label input] [placeholder input] [type select] [Required] ['->‘] ['->(ok)] [Ã(x)]
 *   [conditional logic toggle ............................................] [column toggle]
 *
 * Drag is wired through @dnd-kit/sortable. The drag handle is the only
 * activator so clicking inside an input doesn't accidentally start a drag.
 *
 * Conditional-logic editor and options editor for select/radio/checkboxes
 * are stubbed for now - see TODO comments. The column-width toggle is the
 * 1.1.0 feature this scaffold focuses on.
 */

import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';
import { FIELD_TYPES } from './FieldTypes';
import ColumnToggle from './ColumnToggle';

export default function FieldRow( {
	field,
	index,
	total,
	onChange,
	onRemove,
	onMove,
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

	const set = ( patch ) => onChange( { ...field, ...patch } );

	const isHidden = field.type === 'hidden';

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={
				'nf-builder-field' +
				( field.width === 'half' ? ' nf-builder-field--half' : '' ) +
				( isDragging ? ' is-dragging' : '' )
			}
		>
			{ /* Drag handle - only this element starts a drag. */ }
			<button
				type="button"
				className="nf-builder-field__handle"
				aria-label={ __( 'Drag to reorder', 'neural-forms-lite' ) }
				{ ...attributes }
				{ ...listeners }
			>
				<span aria-hidden="true">'‹®'‹®</span>
			</button>

			<div className="nf-builder-field__main">
				<input
					type="text"
					className="nf-builder-field__label"
					value={ field.label || '' }
					onChange={ ( e ) => set( { label: e.target.value } ) }
					placeholder={ __( 'Label', 'neural-forms-lite' ) }
				/>

				<input
					type="text"
					className="nf-builder-field__placeholder"
					value={ field.placeholder || '' }
					onChange={ ( e ) => set( { placeholder: e.target.value } ) }
					placeholder={ __( 'Placeholder', 'neural-forms-lite' ) }
					disabled={ isHidden }
				/>

				<select
					className="nf-builder-field__type"
					value={ field.type }
					onChange={ ( e ) => set( { type: e.target.value } ) }
				>
					{ FIELD_TYPES.map( ( t ) => (
						<option key={ t.value } value={ t.value }>{ t.label }</option>
					) ) }
				</select>

				<label className="nf-builder-field__required">
					<input
						type="checkbox"
						checked={ !! field.required }
						onChange={ ( e ) => set( { required: e.target.checked } ) }
						disabled={ isHidden }
					/>
					{ __( 'Required', 'neural-forms-lite' ) }
				</label>

				<button
					type="button"
					className="nf-builder-field__icon-btn"
					onClick={ () => onMove( index, index - 1 ) }
					disabled={ index === 0 }
					aria-label={ __( 'Move up', 'neural-forms-lite' ) }
					title={ __( 'Move up', 'neural-forms-lite' ) }
				>'->‘</button>

				<button
					type="button"
					className="nf-builder-field__icon-btn"
					onClick={ () => onMove( index, index + 1 ) }
					disabled={ index === total - 1 }
					aria-label={ __( 'Move down', 'neural-forms-lite' ) }
					title={ __( 'Move down', 'neural-forms-lite' ) }
				>'->(ok)</button>

				<button
					type="button"
					className="nf-builder-field__icon-btn nf-builder-field__icon-btn--remove"
					onClick={ () => onRemove( field.id ) }
					aria-label={ __( 'Remove field', 'neural-forms-lite' ) }
					title={ __( 'Remove field', 'neural-forms-lite' ) }
				>Ã(x)</button>
			</div>

			<div className="nf-builder-field__footer">
				{ /* TODO: conditional-logic editor goes here. Stubbed as a
				    visible-but-non-functional toggle so the row layout
				    matches the original 1.0.0 builder UI shown in the
				    screenshots. */ }
				<label className="nf-builder-field__cl-toggle">
					<input
						type="checkbox"
						checked={ !! field.conditional_logic?.enabled }
						onChange={ ( e ) => set( {
							conditional_logic: {
								...( field.conditional_logic || {} ),
								enabled: e.target.checked,
							},
						} ) }
					/>
					{ __( 'Conditional logic', 'neural-forms-lite' ) }
				</label>

				{ ! isHidden && (
					<ColumnToggle
						value={ field.width === 'half' ? 'half' : 'full' }
						onChange={ ( w ) => set( { width: w } ) }
					/>
				) }
			</div>
		</div>
	);
}
