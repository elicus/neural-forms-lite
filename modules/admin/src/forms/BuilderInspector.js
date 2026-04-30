/**
 * Right-hand context panel.
 *
 * Renders contextual settings for whatever node is currently selected
 * on the canvas:
 *   - a layout row     '->-> column count + gap
 *   - a flat field     '->-> label / placeholder / type / required / width
 *   - nothing selected '->-> a hint pointing the user at the canvas
 *
 * The inspector mutates the same node by id - it does NOT keep a local
 * copy. That guarantees inline edits on the canvas and edits here stay
 * in sync because both write straight back into the parent schema.
 *
 * NOTE: this is a scaffold pass. Per-type extras (select options,
 * checkbox defaults, file types, conditional logic, etc.) continue to
 * live in the inline FieldRow controls so existing functionality is
 * preserved. Future passes can lift the heavier editors up here.
 */

import { __ } from '@wordpress/i18n';
import { FIELD_TYPES } from './FieldTypes';
import {
	isRow,
	clampColumnCount,
	resizeRow,
	VALID_GAPS,
	MIN_COLUMNS,
	MAX_COLUMNS,
	flattenFields,
} from './schemaHelper';

function findNode( nodes, id ) {
	if ( ! id || ! Array.isArray( nodes ) ) return null;
	for ( let i = 0; i < nodes.length; i += 1 ) {
		const n = nodes[ i ];
		if ( ! n ) continue;
		if ( n.id === id ) return { node: n, kind: isRow( n ) ? 'row' : 'field', topIndex: i, rowIndex: -1, colIndex: -1, fieldIndex: -1 };
		if ( isRow( n ) ) {
			const cols = n.columns || [];
			for ( let c = 0; c < cols.length; c += 1 ) {
				const fields = cols[ c ]?.fields || [];
				for ( let f = 0; f < fields.length; f += 1 ) {
					if ( fields[ f ]?.id === id ) {
						return { node: fields[ f ], kind: 'field', topIndex: i, rowIndex: i, colIndex: c, fieldIndex: f };
					}
				}
			}
		}
	}
	return null;
}

function replaceField( nodes, hit, nextField ) {
	const copy = nodes.slice();
	if ( hit.rowIndex === -1 ) {
		copy[ hit.topIndex ] = nextField;
		return copy;
	}
	const row = { ...copy[ hit.rowIndex ] };
	const cols = row.columns.slice();
	const fields = cols[ hit.colIndex ].fields.slice();
	fields[ hit.fieldIndex ] = nextField;
	cols[ hit.colIndex ] = { ...cols[ hit.colIndex ], fields };
	row.columns = cols;
	copy[ hit.rowIndex ] = row;
	return copy;
}

function replaceRow( nodes, topIndex, nextRow ) {
	const copy = nodes.slice();
	copy[ topIndex ] = nextRow;
	return copy;
}

function FieldInspector( { hit, nodes, onChange } ) {
	const f = hit.node;
	const set = ( patch ) => onChange( replaceField( nodes, hit, { ...f, ...patch } ) );
	const isHidden = f.type === 'hidden';

	return (
		<>
			<h3 className="nf-inspector__title">{ __( 'Field settings', 'neural-forms-lite' ) }</h3>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Label', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ f.label || '' }
					onChange={ ( e ) => set( { label: e.target.value } ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Placeholder', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ f.placeholder || '' }
					onChange={ ( e ) => set( { placeholder: e.target.value } ) }
					disabled={ isHidden }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Field type', 'neural-forms-lite' ) }</span>
				<select
					value={ f.type }
					onChange={ ( e ) => set( { type: e.target.value } ) }
				>
					{ FIELD_TYPES.map( ( t ) => (
						<option key={ t.value } value={ t.value }>{ t.label }</option>
					) ) }
				</select>
			</label>

			<label className="nf-inspector__field nf-inspector__field--inline">
				<input
					type="checkbox"
					checked={ !! f.required }
					onChange={ ( e ) => set( { required: e.target.checked } ) }
					disabled={ isHidden }
				/>
				<span>{ __( 'Required', 'neural-forms-lite' ) }</span>
			</label>

			{ ! isHidden && (
				<label className="nf-inspector__field">
					<span className="nf-inspector__label">{ __( 'Default value', 'neural-forms-lite' ) }</span>
					<input
						type="text"
						value={ typeof f.default_value === 'string' ? f.default_value : '' }
						onChange={ ( e ) => set( { default_value: e.target.value } ) }
					/>
				</label>
			) }

			{ ! isHidden && (
				<label className="nf-inspector__field">
					<span className="nf-inspector__label">{ __( 'Help text', 'neural-forms-lite' ) }</span>
					<textarea
						rows={ 2 }
						value={ typeof f.help_text === 'string' ? f.help_text : '' }
						onChange={ ( e ) => set( { help_text: e.target.value } ) }
					/>
				</label>
			) }

			{ ! isHidden && hit.rowIndex === -1 && (
				<label className="nf-inspector__field">
					<span className="nf-inspector__label">{ __( 'Width', 'neural-forms-lite' ) }</span>
					<select
						value={ f.width === 'half' ? 'half' : 'full' }
						onChange={ ( e ) => set( { width: e.target.value } ) }
					>
						<option value="full">{ __( 'Full width', 'neural-forms-lite' ) }</option>
						<option value="half">{ __( 'Half width', 'neural-forms-lite' ) }</option>
					</select>
				</label>
			) }

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'CSS class', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ typeof f.css_class === 'string' ? f.css_class : '' }
					onChange={ ( e ) => set( { css_class: e.target.value } ) }
					placeholder={ __( 'optional', 'neural-forms-lite' ) }
				/>
			</label>

			{ [ 'select', 'radio', 'checkboxes' ].includes( f.type ) && (
				<OptionsEditor field={ f } onChange={ set } />
			) }

			<ConditionalLogicPanel field={ f } onChange={ set } nodes={ nodes } />
		</>
	);
}

/**
 * Options editor for select / radio / checkboxes.
 *
 * Edits the `field.options[]` array with shape [{ value, label }, ...].
 * Mirrors what FormRenderer reads on the server (it falls back to
 * label-as-value when value is empty, so empty values are tolerated).
 */
function OptionsEditor( { field, onChange } ) {
	const options = Array.isArray( field.options ) ? field.options : [];

	const setOptions = ( next ) => onChange( { options: next } );

	const updateAt = ( idx, patch ) => {
		const next = options.slice();
		next[ idx ] = { ...( next[ idx ] || {} ), ...patch };
		setOptions( next );
	};

	const addOption = () => {
		const n = options.length + 1;
		setOptions( [ ...options, { value: 'option_' + n, label: 'Option ' + n } ] );
	};

	const removeAt = ( idx ) => {
		const next = options.slice();
		next.splice( idx, 1 );
		setOptions( next );
	};

	return (
		<div className="nf-inspector__options">
			<span className="nf-inspector__label">{ __( 'Options', 'neural-forms-lite' ) }</span>
			{ options.length === 0 && (
				<p className="nf-inspector__empty">{ __( 'No options yet.', 'neural-forms-lite' ) }</p>
			) }
			{ options.map( ( opt, i ) => (
				<div className="nf-inspector__option-row" key={ i }>
					<input
						type="text"
						className="nf-inspector__option-label"
						value={ ( opt && opt.label ) || '' }
						onChange={ ( e ) => updateAt( i, { label: e.target.value } ) }
						placeholder={ __( 'Label', 'neural-forms-lite' ) }
					/>
					<input
						type="text"
						className="nf-inspector__option-value"
						value={ ( opt && opt.value ) || '' }
						onChange={ ( e ) => updateAt( i, { value: e.target.value } ) }
						placeholder={ __( 'value', 'neural-forms-lite' ) }
					/>
					<button
						type="button"
						className="nf-inspector__option-remove"
						onClick={ () => removeAt( i ) }
						aria-label={ __( 'Remove option', 'neural-forms-lite' ) }
						title={ __( 'Remove option', 'neural-forms-lite' ) }
					>×</button>
				</div>
			) ) }
			<button
				type="button"
				className="neural-forms-btn"
				onClick={ addOption }
			>+ { __( 'Add option', 'neural-forms-lite' ) }</button>
		</div>
	);
}

/**
 * Conditional Logic editor.
 *
 * Writes the v1.0.0 canonical schema verbatim:
 *
 *   field.conditional_logic = {
 *     enabled:  true,
 *     field:    'source_field_id',
 *     operator: 'equals' | 'not_equals' | 'contains' | 'empty' | 'not_empty',
 *     value:    'comparison'
 *   }
 *
 * Frontend support already exists: FormRenderer.php emits a
 * data-conditional attribute when enabled is true, and form-submit.js
 * (in the rich admin bundle) plus SubmitEndpoint.php both honour the
 * rule. This editor only writes; it does not modify the rendering or
 * submission pipeline.
 *
 * Source field list: walks the FULL schema (root + every column) via
 * flattenFields, so a root-level field can depend on a field nested
 * inside a column. Excludes the current field, hidden fields, and
 * fields without an id.
 */

const CL_OPERATORS = [
	{ value: 'equals',     label: 'equals',          needsValue: true  },
	{ value: 'not_equals', label: 'does not equal',  needsValue: true  },
	{ value: 'contains',   label: 'contains',        needsValue: true  },
	{ value: 'empty',      label: 'is empty',        needsValue: false },
	{ value: 'not_empty',  label: 'is not empty',    needsValue: false },
];

function ConditionalLogicPanel( { field, onChange, nodes } ) {
	const cl = field && typeof field.conditional_logic === 'object' && field.conditional_logic !== null
		? field.conditional_logic
		: {};
	const enabled  = !! cl.enabled;
	const operator = CL_OPERATORS.find( ( o ) => o.value === cl.operator ) ? cl.operator : 'equals';
	const opMeta   = CL_OPERATORS.find( ( o ) => o.value === operator );

	// All real fields, minus the current field. flattenFields already
	// skips rows + columns and only emits leaf input nodes.
	const allLeaves = flattenFields( Array.isArray( nodes ) ? nodes : [] );
	const sources = allLeaves.filter( ( n ) => n && n.id && n.id !== field.id && n.type !== 'hidden' );

	const sourceId = ( typeof cl.field === 'string' && cl.field ) ? cl.field : '';
	const sourceField = sources.find( ( s ) => s.id === sourceId ) || null;

	const setCL = ( patch ) => {
		onChange( { conditional_logic: { ...cl, ...patch } } );
	};

	const toggle = ( on ) => {
		if ( ! on ) {
			// Disabled: keep the rule on the field but flip enabled off.
			// We could remove the key entirely, but keeping it preserves
			// the user's settings if they re-enable.
			onChange( { conditional_logic: { ...cl, enabled: false } } );
			return;
		}
		onChange( {
			conditional_logic: {
				enabled:  true,
				field:    cl.field    || ( sources[ 0 ] && sources[ 0 ].id ) || '',
				operator: cl.operator || 'equals',
				value:    cl.value    !== undefined ? cl.value : '',
			},
		} );
	};

	const sourceOptions = Array.isArray( sourceField?.options ) ? sourceField.options : [];
	const sourceIsChoice = sourceField && [ 'select', 'radio' ].includes( sourceField.type ) && sourceOptions.length > 0;

	return (
		<div className="nf-inspector__cl">
			<h4 className="nf-inspector__cl-title">{ __( 'Conditional Logic', 'neural-forms-lite' ) }</h4>

			<label className="nf-inspector__field nf-inspector__field--inline">
				<input
					type="checkbox"
					checked={ enabled }
					onChange={ ( e ) => toggle( e.target.checked ) }
				/>
				<span>{ __( 'Enable conditional logic', 'neural-forms-lite' ) }</span>
			</label>

			{ enabled && sources.length === 0 && (
				<p className="nf-inspector__cl-hint">
					{ __( 'Add another field to this form to use as the rule source.', 'neural-forms-lite' ) }
				</p>
			) }

			{ enabled && sources.length > 0 && (
				<div className="nf-inspector__cl-rule">
					<p className="nf-inspector__cl-prefix">
						{ __( 'Show this field if', 'neural-forms-lite' ) }
					</p>

					<label className="nf-inspector__field">
						<span className="nf-inspector__label">{ __( 'Field', 'neural-forms-lite' ) }</span>
						<select
							value={ sourceId }
							onChange={ ( e ) => setCL( { field: e.target.value, value: '' } ) }
						>
							{ sources.map( ( s ) => (
								<option key={ s.id } value={ s.id }>
									{ s.label || s.id }
								</option>
							) ) }
						</select>
					</label>

					<label className="nf-inspector__field">
						<span className="nf-inspector__label">{ __( 'Operator', 'neural-forms-lite' ) }</span>
						<select
							value={ operator }
							onChange={ ( e ) => {
								const next = e.target.value;
								const nextMeta = CL_OPERATORS.find( ( o ) => o.value === next );
								// Drop value when switching to an operator that doesn't use it.
								setCL( {
									operator: next,
									value: nextMeta && nextMeta.needsValue ? ( cl.value ?? '' ) : '',
								} );
							} }
						>
							{ CL_OPERATORS.map( ( o ) => (
								<option key={ o.value } value={ o.value }>{ __( o.label, 'neural-forms-lite' ) }</option>
							) ) }
						</select>
					</label>

					{ opMeta && opMeta.needsValue && (
						<label className="nf-inspector__field">
							<span className="nf-inspector__label">{ __( 'Value', 'neural-forms-lite' ) }</span>
							{ sourceIsChoice ? (
								<select
									value={ typeof cl.value === 'string' ? cl.value : '' }
									onChange={ ( e ) => setCL( { value: e.target.value } ) }
								>
									<option value="">{ __( 'Select value...', 'neural-forms-lite' ) }</option>
									{ sourceOptions.map( ( opt, i ) => {
										const v = ( opt && opt.value ) || ( opt && opt.label ) || '';
										return v ? (
											<option key={ i } value={ v }>{ ( opt && opt.label ) || v }</option>
										) : null;
									} ) }
								</select>
							) : (
								<input
									type="text"
									value={ typeof cl.value === 'string' ? cl.value : '' }
									onChange={ ( e ) => setCL( { value: e.target.value } ) }
								/>
							) }
						</label>
					) }
				</div>
			) }
		</div>
	);
}

function RowInspector( { hit, nodes, onChange } ) {
	const row = hit.node;
	const cols = clampColumnCount( row.settings?.columns ?? row.columns?.length ?? 1 );
	const gap  = VALID_GAPS.includes( row.settings?.gap ) ? row.settings.gap : 'medium';

	const setColumns = ( next ) => onChange( replaceRow( nodes, hit.topIndex, resizeRow( row, next ) ) );
	const setGap = ( next ) => onChange( replaceRow( nodes, hit.topIndex, {
		...row,
		settings: { ...( row.settings || {} ), gap: next },
	} ) );

	return (
		<>
			<h3 className="nf-inspector__title">{ __( 'Row settings', 'neural-forms-lite' ) }</h3>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Columns', 'neural-forms-lite' ) }</span>
				<select
					value={ cols }
					onChange={ ( e ) => setColumns( parseInt( e.target.value, 10 ) ) }
				>
					{ Array.from( { length: MAX_COLUMNS - MIN_COLUMNS + 1 }, ( _, i ) => MIN_COLUMNS + i ).map( ( n ) => (
						<option key={ n } value={ n }>{ String( n ) }</option>
					) ) }
				</select>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Gap', 'neural-forms-lite' ) }</span>
				<select value={ gap } onChange={ ( e ) => setGap( e.target.value ) }>
					<option value="none">{ __( 'No gap', 'neural-forms-lite' ) }</option>
					<option value="small">{ __( 'Small', 'neural-forms-lite' ) }</option>
					<option value="medium">{ __( 'Medium', 'neural-forms-lite' ) }</option>
					<option value="large">{ __( 'Large', 'neural-forms-lite' ) }</option>
				</select>
			</label>
		</>
	);
}

function EmptyInspector() {
	return (
		<>
			<h3 className="nf-inspector__title">{ __( 'Inspector', 'neural-forms-lite' ) }</h3>
			<p className="nf-inspector__empty">
				{ __( 'Select a field or layout row to edit its settings.', 'neural-forms-lite' ) }
			</p>

			<div className="nf-inspector__tips">
				<h4 className="nf-inspector__tips-title">{ __( 'Tips', 'neural-forms-lite' ) }</h4>
				<ul className="nf-inspector__tips-list">
					<li>{ __( 'Click a field to edit label, placeholder, default value, and help text.', 'neural-forms-lite' ) }</li>
					<li>{ __( 'Click a row to change its column count or gap.', 'neural-forms-lite' ) }</li>
					<li>{ __( 'Drag the handle on the left of any field or row to reorder.', 'neural-forms-lite' ) }</li>
				</ul>
			</div>

			<div className="nf-inspector__ai-card">
				<h4 className="nf-inspector__ai-title">
					{ __( 'AI form generation', 'neural-forms-lite' ) }
					<span className="nf-inspector__ai-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
				</h4>
				<p className="nf-inspector__ai-hint">
					{ __( 'Soon you will be able to describe the form you want and Neural Forms will draft the fields for you.', 'neural-forms-lite' ) }
				</p>
			</div>
		</>
	);
}

function ColumnInspector() {
	return (
		<>
			<h3 className="nf-inspector__title">{ __( 'Column selected', 'neural-forms-lite' ) }</h3>
			<p className="nf-inspector__empty">
				{ __( 'Choose a field from the left to add it here.', 'neural-forms-lite' ) }
			</p>
			<p className="nf-inspector__hint">
				{ __( 'Click anywhere outside the column to deselect.', 'neural-forms-lite' ) }
			</p>
		</>
	);
}

export default function BuilderInspector( { nodes, selectedId, selectedColumnId, onChange } ) {
	const hit = findNode( nodes, selectedId );

	return (
		<aside className="nf-inspector" aria-label={ __( 'Selected element settings', 'neural-forms-lite' ) }>
			{ ! hit && ! selectedColumnId && <EmptyInspector /> }
			{ ! hit && selectedColumnId && <ColumnInspector /> }
			{ hit && hit.kind === 'row' && (
				<RowInspector hit={ hit } nodes={ nodes } onChange={ onChange } />
			) }
			{ hit && hit.kind === 'field' && (
				<FieldInspector hit={ hit } nodes={ nodes } onChange={ onChange } />
			) }
		</aside>
	);
}
