/**
 * Read-only field preview.
 *
 * Renders the canvas-side approximation of what the user's field will
 * look like on the frontend. Every input is readOnly / disabled so:
 *   - clicks do not steal focus from the surrounding canvas card,
 *   - typing in the canvas is impossible (editing happens in the
 *     right-side inspector only),
 *   - a misclick on a select / checkbox does not silently mutate
 *     anything.
 *
 * The switch covers every type in FieldTypes.FIELD_TYPES.
 */

import { __ } from '@wordpress/i18n';

function readOnlyText( { field, type, classMod = '' } ) {
	return (
		<input
			type={ type }
			className={ 'nf-preview__input' + classMod }
			placeholder={ field.placeholder || '' }
			defaultValue={ typeof field.default_value === 'string' ? field.default_value : '' }
			readOnly
			tabIndex={ -1 }
			aria-hidden="true"
		/>
	);
}

function readOnlyTextarea( { field } ) {
	return (
		<textarea
			className="nf-preview__textarea"
			rows={ Number( field.rows ) > 0 ? Number( field.rows ) : 3 }
			placeholder={ field.placeholder || '' }
			defaultValue={ typeof field.default_value === 'string' ? field.default_value : '' }
			readOnly
			tabIndex={ -1 }
			aria-hidden="true"
		/>
	);
}

function readOnlySelect( { field } ) {
	const options = Array.isArray( field.options ) ? field.options : [];
	return (
		<select className="nf-preview__select" disabled aria-hidden="true">
			<option>{ __( 'Select...', 'neural-forms-lite' ) }</option>
			{ options.map( ( opt, i ) => (
				<option key={ i }>{ opt?.label || opt?.value || '' }</option>
			) ) }
		</select>
	);
}

function readOnlyChoiceList( { field, inputType } ) {
	const options = Array.isArray( field.options ) ? field.options : [];
	if ( options.length === 0 ) {
		return (
			<p className="nf-preview__hint">
				{ __( 'No options yet — add some in the inspector.', 'neural-forms-lite' ) }
			</p>
		);
	}
	return (
		<div className="nf-preview__choices">
			{ options.map( ( opt, i ) => (
				<label key={ i } className="nf-preview__choice">
					<input type={ inputType } disabled tabIndex={ -1 } aria-hidden="true" />
					<span>{ opt?.label || opt?.value || '' }</span>
				</label>
			) ) }
		</div>
	);
}

function readOnlyCheckbox( { field } ) {
	return (
		<label className="nf-preview__choice">
			<input type="checkbox" disabled tabIndex={ -1 } aria-hidden="true" />
			<span>{ field.label || '' }</span>
		</label>
	);
}

function readOnlyAcceptance( { field } ) {
	return (
		<label className="nf-preview__choice">
			<input type="checkbox" disabled tabIndex={ -1 } aria-hidden="true" />
			<span>{ field.label || __( 'I accept', 'neural-forms-lite' ) }</span>
		</label>
	);
}

function readOnlyRange( { field } ) {
	const min  = field.min !== undefined && field.min !== '' ? Number( field.min ) : 0;
	const max  = field.max !== undefined && field.max !== '' ? Number( field.max ) : 100;
	const step = field.step !== undefined && field.step !== '' ? Number( field.step ) : 1;
	const def  = field.default_value !== undefined && field.default_value !== '' ? Number( field.default_value ) : min;
	return (
		<div className="nf-preview__range">
			<input
				type="range"
				min={ min }
				max={ max }
				step={ step }
				defaultValue={ def }
				disabled
				tabIndex={ -1 }
				aria-hidden="true"
			/>
			<span className="nf-preview__range-value">{ String( def ) }</span>
		</div>
	);
}

function readOnlyFile( { field } ) {
	const max = Number( field.max_file_size ) > 0 ? Number( field.max_file_size ) : 5;
	const accept = Array.isArray( field.allowed_types ) && field.allowed_types.length > 0
		? field.allowed_types.join( ', ' )
		: '';
	return (
		<div className="nf-preview__file">
			<span className="nf-preview__file-cta">{ __( 'Choose file…', 'neural-forms-lite' ) }</span>
			<span className="nf-preview__file-meta">
				{ accept ? `${ accept } · ` : '' }{ `${ __( 'Max', 'neural-forms-lite' ) } ${ max } MB` }
			</span>
		</div>
	);
}

function readOnlyHidden( { field } ) {
	return (
		<div className="nf-preview__hidden">
			<span className="nf-preview__hidden-key">{ field.id || __( '(no id)', 'neural-forms-lite' ) }</span>
			<span className="nf-preview__hidden-value">
				{ field.default_value !== undefined && field.default_value !== ''
					? String( field.default_value )
					: __( '(empty)', 'neural-forms-lite' ) }
			</span>
		</div>
	);
}

export default function FieldPreview( { field } ) {
	if ( ! field || typeof field !== 'object' ) return null;
	switch ( field.type ) {
		case 'email':      return readOnlyText( { field, type: 'email' } );
		case 'phone':
		case 'tel':        return readOnlyText( { field, type: 'tel' } );
		case 'url':        return readOnlyText( { field, type: 'url' } );
		case 'number':     return readOnlyText( { field, type: 'number' } );
		case 'date':       return readOnlyText( { field, type: 'date' } );
		case 'textarea':   return readOnlyTextarea( { field } );
		case 'select':     return readOnlySelect( { field } );
		case 'radio':      return readOnlyChoiceList( { field, inputType: 'radio' } );
		case 'checkboxes': return readOnlyChoiceList( { field, inputType: 'checkbox' } );
		case 'checkbox':   return readOnlyCheckbox( { field } );
		case 'acceptance': return readOnlyAcceptance( { field } );
		case 'range':      return readOnlyRange( { field } );
		case 'file':       return readOnlyFile( { field } );
		case 'hidden':     return readOnlyHidden( { field } );
		case 'text':
		default:           return readOnlyText( { field, type: 'text' } );
	}
}
