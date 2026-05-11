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

function readOnlyRating( { field } ) {
	let stars = Number( field.stars );
	if ( ! Number.isFinite( stars ) || stars < 1 ) stars = 5;
	if ( stars > 10 ) stars = 10;
	stars = Math.round( stars );
	const items = [];
	for ( let i = 0; i < stars; i++ ) {
		items.push( <span key={ i } className="nf-preview__rating-star" aria-hidden="true">{ '★' }</span> );
	}
	return (
		<div className="nf-preview__rating" data-stars={ stars }>
			{ items }
		</div>
	);
}

function readOnlyMatrix( { field } ) {
	// Mini row-by-column grid mirroring what the frontend renderer
	// will produce. Every input is disabled (the builder canvas is
	// read-only — actual editing happens via the inspector). The
	// outer wrapper carries the horizontal scroll so wide matrices
	// stay inside the field card without overflowing the column.
	const settings = field.settings && typeof field.settings === 'object' ? field.settings : {};
	const rows     = Array.isArray( settings.rows ) ? settings.rows : [];
	const cols     = Array.isArray( settings.columns ) ? settings.columns : [];
	const inputType = settings.selection_type === 'checkbox' ? 'checkbox' : 'radio';

	if ( rows.length === 0 || cols.length === 0 ) {
		return (
			<p className="nf-preview__hint">
				{ __( 'Add at least one row and one column in the inspector.', 'neural-forms-lite' ) }
			</p>
		);
	}

	return (
		<div className="nf-preview__matrix-scroll">
			<table className="nf-preview__matrix" aria-hidden="true">
				<thead>
					<tr>
						<th className="nf-preview__matrix-corner" />
						{ cols.map( ( c, ci ) => (
							<th key={ ci } className="nf-preview__matrix-col">
								{ ( c && c.label ) || ( c && c.value ) || '' }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r, ri ) => (
						<tr key={ ri }>
							<th className="nf-preview__matrix-row" scope="row">
								{ ( r && r.label ) || ( r && r.value ) || '' }
							</th>
							{ cols.map( ( _c, ci ) => (
								<td key={ ci } className="nf-preview__matrix-cell">
									<input
										type={ inputType }
										disabled
										tabIndex={ -1 }
										aria-hidden="true"
									/>
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function readOnlyRepeater( { field } ) {
	// Renders a single representative iteration of the repeater (the
	// frontend renderer stamps min(min_items, 1) of these and lets the
	// visitor add more up to max_items). The canvas preview is read-
	// only so we deliberately render ONE iteration regardless of
	// min_items — sizing the canvas to up to 20 dummy rows would
	// drown out everything else in the builder.
	const settings = field.settings && typeof field.settings === 'object' ? field.settings : {};
	const cols     = Number( settings.columns );
	const colsClamped = Number.isFinite( cols ) && cols >= 1 && cols <= 4 ? Math.round( cols ) : 1;
	const min      = Number( settings.min_items );
	const max      = Number( settings.max_items );
	const minStr   = Number.isFinite( min ) ? min : 1;
	const maxStr   = Number.isFinite( max ) ? max : 5;
	const addLabel = typeof settings.add_button_label === 'string' && settings.add_button_label !== ''
		? settings.add_button_label
		: __( 'Add item', 'neural-forms-lite' );
	const removeLabel = typeof settings.remove_button_label === 'string' && settings.remove_button_label !== ''
		? settings.remove_button_label
		: __( 'Remove', 'neural-forms-lite' );
	const children = Array.isArray( field.fields ) ? field.fields : [];

	return (
		<div className="nf-preview__repeater">
			<div className="nf-preview__repeater-meta">
				{ children.length }{ ' ' }{ __( 'child fields', 'neural-forms-lite' ) }{ ' · ' }
				{ minStr }–{ maxStr }{ ' ' }{ __( 'items', 'neural-forms-lite' ) }{ ' · ' }
				{ colsClamped }{ ' ' }{ __( 'column(s)', 'neural-forms-lite' ) }
			</div>

			{ children.length === 0 ? (
				<p className="nf-preview__hint">
					{ __( 'Add child fields in the inspector to populate this repeater.', 'neural-forms-lite' ) }
				</p>
			) : (
				<div className="nf-preview__repeater-item">
					<div className="nf-preview__repeater-item-head">
						<span>{ __( 'Item 1', 'neural-forms-lite' ) }</span>
						<span className="nf-preview__repeater-remove-hint">{ removeLabel }</span>
					</div>
					<div
						className={ 'nf-preview__repeater-grid nf-preview__repeater-grid--cols-' + colsClamped }
					>
						{ children.map( ( child, i ) => (
							<div className="nf-preview__repeater-child" key={ ( child && child.id ) || i }>
								<div className="nf-preview__repeater-child-label">
									{ ( child && child.label ) || ( child && child.id ) || '' }
									{ child && child.required && (
										<span className="nf-preview__repeater-child-required" aria-hidden="true"> *</span>
									) }
								</div>
								<FieldPreview field={ child } />
							</div>
						) ) }
					</div>
				</div>
			) }

			<div className="nf-preview__repeater-add-hint" aria-hidden="true">+ { addLabel }</div>
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
		case 'radio':         return readOnlyChoiceList( { field, inputType: 'radio' } );
		case 'poll':          return readOnlyChoiceList( { field, inputType: 'radio' } );
		case 'quiz_question': return readOnlyChoiceList( { field, inputType: 'radio' } );
		case 'checkboxes':    return readOnlyChoiceList( { field, inputType: 'checkbox' } );
		case 'checkbox':   return readOnlyCheckbox( { field } );
		case 'acceptance': return readOnlyAcceptance( { field } );
		case 'range':      return readOnlyRange( { field } );
		case 'rating':     return readOnlyRating( { field } );
		case 'matrix':     return readOnlyMatrix( { field } );
		case 'repeater':   return readOnlyRepeater( { field } );
		case 'file':       return readOnlyFile( { field } );
		case 'hidden':     return readOnlyHidden( { field } );
		case 'text':
		default:           return readOnlyText( { field, type: 'text' } );
	}
}
