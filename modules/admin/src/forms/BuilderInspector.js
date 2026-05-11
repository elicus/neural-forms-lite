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

import { __, sprintf } from '@wordpress/i18n';
import { FIELD_TYPES } from './FieldTypes';
import PollResultsPanel from './PollResultsPanel';
import {
	isRow,
	isStep,
	isSection,
	clampColumnCount,
	resizeRow,
	VALID_GAPS,
	VALID_SECTION_STYLES,
	DEFAULT_SECTION_STYLE,
	MIN_COLUMNS,
	MAX_COLUMNS,
	flattenFields,
} from './schemaHelper';

/**
 * Locate a node by id. Returns a `hit` object that captures enough
 * context for replaceField / replaceRow to write back into the right
 * container without re-walking. Nesting cases handled:
 *
 *   { kind:'field', topIndex, rowIndex:-1 }                       // top-level real field
 *   { kind:'row',   topIndex }                                     // top-level row
 *   { kind:'field', topIndex, rowIndex, colIndex, fieldIndex }     // field in top-level row
 *   { kind:'field', stepIndex, stepChildIndex }                    // field directly in step.fields
 *   { kind:'row',   stepIndex, stepChildIndex }                    // row in step.fields
 *   { kind:'field', stepIndex, stepChildIndex, colIndex, fieldIndex } // field in row in step
 */
function findNode( nodes, id ) {
	if ( ! id || ! Array.isArray( nodes ) ) return null;
	for ( let i = 0; i < nodes.length; i += 1 ) {
		const n = nodes[ i ];
		if ( ! n ) continue;
		if ( isStep( n ) ) {
			const children = Array.isArray( n.fields ) ? n.fields : [];
			for ( let k = 0; k < children.length; k += 1 ) {
				const child = children[ k ];
				if ( ! child ) continue;
				if ( child.id === id ) {
					return {
						node: child,
						kind: isRow( child ) ? 'row' : ( isSection( child ) ? 'section' : 'field' ),
						stepIndex: i,
						stepChildIndex: k,
						topIndex: -1,
						rowIndex: -1,
						colIndex: -1,
						fieldIndex: -1,
					};
				}
				if ( isRow( child ) ) {
					const cols = child.columns || [];
					for ( let c = 0; c < cols.length; c += 1 ) {
						const fields = cols[ c ]?.fields || [];
						for ( let f = 0; f < fields.length; f += 1 ) {
							if ( fields[ f ]?.id === id ) {
								return {
									node: fields[ f ],
									kind: 'field',
									stepIndex: i,
									stepChildIndex: k,
									topIndex: -1,
									rowIndex: -1,
									colIndex: c,
									fieldIndex: f,
								};
							}
						}
					}
				}
			}
			continue;
		}
		if ( n.id === id ) {
			return {
				node: n,
				kind: isRow( n ) ? 'row' : ( isSection( n ) ? 'section' : 'field' ),
				stepIndex: -1,
				stepChildIndex: -1,
				topIndex: i,
				rowIndex: -1,
				colIndex: -1,
				fieldIndex: -1,
			};
		}
		if ( isRow( n ) ) {
			const cols = n.columns || [];
			for ( let c = 0; c < cols.length; c += 1 ) {
				const fields = cols[ c ]?.fields || [];
				for ( let f = 0; f < fields.length; f += 1 ) {
					if ( fields[ f ]?.id === id ) {
						return {
							node: fields[ f ],
							kind: isSection( fields[ f ] ) ? 'section' : 'field',
							stepIndex: -1,
							stepChildIndex: -1,
							topIndex: i,
							rowIndex: i,
							colIndex: c,
							fieldIndex: f,
						};
					}
				}
			}
		}
	}
	return null;
}

function replaceField( nodes, hit, nextField ) {
	const copy = nodes.slice();

	// Field nested inside a step.
	if ( hit.stepIndex !== -1 ) {
		const step = { ...copy[ hit.stepIndex ] };
		const children = step.fields.slice();
		if ( hit.colIndex === -1 ) {
			// Direct child of step.fields
			children[ hit.stepChildIndex ] = nextField;
		} else {
			// Field in row in step.fields
			const row = { ...children[ hit.stepChildIndex ] };
			const cols = row.columns.slice();
			const fields = cols[ hit.colIndex ].fields.slice();
			fields[ hit.fieldIndex ] = nextField;
			cols[ hit.colIndex ] = { ...cols[ hit.colIndex ], fields };
			row.columns = cols;
			children[ hit.stepChildIndex ] = row;
		}
		step.fields = children;
		copy[ hit.stepIndex ] = step;
		return copy;
	}

	// Top-level real field.
	if ( hit.rowIndex === -1 ) {
		copy[ hit.topIndex ] = nextField;
		return copy;
	}
	// Field in top-level row.
	const row = { ...copy[ hit.rowIndex ] };
	const cols = row.columns.slice();
	const fields = cols[ hit.colIndex ].fields.slice();
	fields[ hit.fieldIndex ] = nextField;
	cols[ hit.colIndex ] = { ...cols[ hit.colIndex ], fields };
	row.columns = cols;
	copy[ hit.rowIndex ] = row;
	return copy;
}

function replaceRow( nodes, hit, nextRow ) {
	const copy = nodes.slice();
	if ( hit.stepIndex !== -1 ) {
		const step = { ...copy[ hit.stepIndex ] };
		const children = step.fields.slice();
		children[ hit.stepChildIndex ] = nextRow;
		step.fields = children;
		copy[ hit.stepIndex ] = step;
		return copy;
	}
	copy[ hit.topIndex ] = nextRow;
	return copy;
}

function FieldInspector( { hit, nodes, onChange, formId } ) {
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

			{ [ 'select', 'radio', 'checkboxes', 'poll' ].includes( f.type ) && (
				<OptionsEditor field={ f } onChange={ set } />
			) }

			{ f.type === 'quiz_question' && (
				<QuizOptionsEditor field={ f } onChange={ set } />
			) }

			{ f.type === 'poll' && (
				<PollSettingsBlock field={ f } onChange={ set } />
			) }

			{ f.type === 'quiz_question' && (
				<QuizSettingsBlock field={ f } onChange={ set } />
			) }

			{ f.type === 'poll' && (
				<PollResultsPanel formId={ formId } fieldId={ f.id } />
			) }

			{ f.type === 'matrix' && (
				<MatrixSettingsBlock field={ f } onChange={ set } />
			) }

			{ f.type === 'rating' && (
				<label className="nf-inspector__field">
					<span className="nf-inspector__label">{ __( 'Number of stars', 'neural-forms-lite' ) }</span>
					<select
						value={ ( () => {
							const n = Number( f.stars );
							if ( ! Number.isFinite( n ) || n < 1 ) return 5;
							if ( n > 10 ) return 10;
							return Math.round( n );
						} )() }
						onChange={ ( e ) => set( { stars: Number( e.target.value ) } ) }
					>
						{ [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ].map( ( n ) => (
							<option key={ n } value={ n }>{ n }</option>
						) ) }
					</select>
				</label>
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
 * Poll-specific settings block.
 *
 * Edits the nested `field.settings` object:
 *   - results_visibility: "after_vote" | "always" | "never"
 *   - chart_type:         "bar" | "percentage"
 *   - randomize_options:  boolean
 *
 * `allow_multiple` is intentionally hidden in v1 (single-choice only)
 * but persisted in the schema so v2 doesn't need a migration.
 */
function PollSettingsBlock( { field, onChange } ) {
	const s = ( field && typeof field.settings === 'object' && field.settings !== null )
		? field.settings
		: {};

	const visibility = [ 'after_vote', 'always', 'never' ].includes( s.results_visibility )
		? s.results_visibility
		: 'after_vote';
	const chartType  = [ 'bar', 'percentage' ].includes( s.chart_type ) ? s.chart_type : 'bar';
	const randomize  = !! s.randomize_options;

	const setSettings = ( patch ) => {
		// Preserve allow_multiple silently (default false) so the
		// schema never loses the key when written from this UI.
		const next = {
			results_visibility: visibility,
			chart_type:         chartType,
			allow_multiple:     !! s.allow_multiple,
			randomize_options:  randomize,
			...patch,
		};
		onChange( { settings: next } );
	};

	return (
		<>
			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Results visibility', 'neural-forms-lite' ) }</span>
				<select
					value={ visibility }
					onChange={ ( e ) => setSettings( { results_visibility: e.target.value } ) }
				>
					<option value="after_vote">{ __( 'After vote', 'neural-forms-lite' ) }</option>
					<option value="always">{ __( 'Always', 'neural-forms-lite' ) }</option>
					<option value="never">{ __( 'Never', 'neural-forms-lite' ) }</option>
				</select>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Chart type', 'neural-forms-lite' ) }</span>
				<select
					value={ chartType }
					onChange={ ( e ) => setSettings( { chart_type: e.target.value } ) }
				>
					<option value="bar">{ __( 'Bar', 'neural-forms-lite' ) }</option>
					<option value="percentage">{ __( 'Percentage', 'neural-forms-lite' ) }</option>
				</select>
			</label>

			<label className="nf-inspector__field nf-inspector__field--inline">
				<input
					type="checkbox"
					checked={ randomize }
					onChange={ ( e ) => setSettings( { randomize_options: e.target.checked } ) }
				/>
				<span>{ __( 'Randomize options', 'neural-forms-lite' ) }</span>
			</label>
		</>
	);
}

/**
 * Quiz Question option editor. Same shape as OptionsEditor but
 * adds a "correct" radio column. Exactly one option may be marked
 * correct at a time — the radio's mutual-exclusion enforces this in
 * the UI; the validator enforces it on save.
 *
 * Schema-save validation rejects quiz schemas without exactly one
 * correct option, so this editor seeds a freshly-added option as
 * NOT correct and lets the user pick.
 */
function QuizOptionsEditor( { field, onChange } ) {
	const options = Array.isArray( field.options ) ? field.options : [];

	const setOptions = ( next ) => onChange( { options: next } );

	const updateAt = ( idx, patch ) => {
		const next = options.slice();
		next[ idx ] = { ...( next[ idx ] || {} ), ...patch };
		setOptions( next );
	};

	const setCorrect = ( idx ) => {
		const next = options.map( ( opt, i ) => ( {
			...( opt || {} ),
			is_correct: i === idx,
		} ) );
		setOptions( next );
	};

	const addOption = () => {
		const n = options.length + 1;
		setOptions( [
			...options,
			{ value: 'option_' + n, label: 'Option ' + n, is_correct: false },
		] );
	};

	const removeAt = ( idx ) => {
		const next = options.slice();
		next.splice( idx, 1 );
		setOptions( next );
	};

	const correctCount = options.filter( ( o ) => o && o.is_correct ).length;
	const correctIndex = options.findIndex( ( o ) => o && o.is_correct );

	const radioName = 'nf-quiz-correct-' + ( field.id || 'q' );

	return (
		<div className="nf-inspector__options nf-inspector__quiz-options">
			<span className="nf-inspector__label">
				{ __( 'Options', 'neural-forms-lite' ) }
			</span>
			<p className="nf-inspector__hint nf-quiz-options__hint">
				{ __( 'Choose the correct answer for scoring.', 'neural-forms-lite' ) }
			</p>
			{ options.length === 0 && (
				<p className="nf-inspector__empty">{ __( 'No options yet.', 'neural-forms-lite' ) }</p>
			) }
			{ options.map( ( opt, i ) => {
				const isCorrect = correctIndex === i;
				const cardClass = 'nf-quiz-option-card'
					+ ( isCorrect ? ' nf-quiz-option-card--correct' : '' );
				const labelInputId = 'nf-quiz-' + ( field.id || 'q' ) + '-' + i + '-label';
				const valueInputId = 'nf-quiz-' + ( field.id || 'q' ) + '-' + i + '-value';
				return (
					<div className={ cardClass } key={ i }>
						<div className="nf-quiz-option-card__top">
							<label className="nf-quiz-option-card__correct">
								<input
									type="radio"
									className="nf-inspector__option-correct"
									name={ radioName }
									checked={ isCorrect }
									onChange={ () => setCorrect( i ) }
								/>
								<span>{ __( 'Correct answer', 'neural-forms-lite' ) }</span>
							</label>
							<button
								type="button"
								className="nf-quiz-option-card__remove"
								onClick={ () => removeAt( i ) }
								aria-label={ __( 'Remove option', 'neural-forms-lite' ) }
								title={ __( 'Remove option', 'neural-forms-lite' ) }
							>×</button>
						</div>

						<label className="nf-quiz-option-card__field" htmlFor={ labelInputId }>
							<span className="nf-quiz-option-card__field-label">{ __( 'Label', 'neural-forms-lite' ) }</span>
							<input
								id={ labelInputId }
								type="text"
								className="nf-inspector__option-label"
								value={ ( opt && opt.label ) || '' }
								onChange={ ( e ) => updateAt( i, { label: e.target.value } ) }
								placeholder={ __( 'Option label shown to respondents', 'neural-forms-lite' ) }
							/>
						</label>

						<label className="nf-quiz-option-card__field" htmlFor={ valueInputId }>
							<span className="nf-quiz-option-card__field-label">{ __( 'Value', 'neural-forms-lite' ) }</span>
							<input
								id={ valueInputId }
								type="text"
								className="nf-inspector__option-value"
								value={ ( opt && opt.value ) || '' }
								onChange={ ( e ) => updateAt( i, { value: e.target.value } ) }
								placeholder={ __( 'option_value', 'neural-forms-lite' ) }
							/>
						</label>
					</div>
				);
			} ) }
			<button
				type="button"
				className="neural-forms-btn"
				onClick={ addOption }
			>+ { __( 'Add option', 'neural-forms-lite' ) }</button>

			{ ( options.length < 2 || correctCount !== 1 ) && (
				<p className="nf-inspector__warning" role="alert">
					{ options.length < 2
						? __( 'Add at least two options.', 'neural-forms-lite' )
						: ( correctCount === 0
							? __( 'Mark one option as correct.', 'neural-forms-lite' )
							: __( 'Only one option can be marked correct.', 'neural-forms-lite' ) ) }
				</p>
			) }
		</div>
	);
}

/**
 * Quiz-specific settings block.
 *
 * Edits the nested `field.settings` object:
 *   - points: int >= 0 (default 1)
 *   - explanation: textarea
 *   - show_correct_answer: "after_submit" | "never"
 *   - randomize_options: boolean
 */
function QuizSettingsBlock( { field, onChange } ) {
	const s = ( field && typeof field.settings === 'object' && field.settings !== null )
		? field.settings
		: {};

	const points = ( () => {
		const n = Number( s.points );
		if ( ! Number.isFinite( n ) || n < 0 ) return 1;
		return Math.round( n );
	} )();
	const showCorrect = [ 'after_submit', 'never' ].includes( s.show_correct_answer )
		? s.show_correct_answer
		: 'after_submit';
	const explanation = typeof s.explanation === 'string' ? s.explanation : '';
	const randomize   = !! s.randomize_options;

	const setSettings = ( patch ) => {
		const next = {
			points,
			explanation,
			show_correct_answer: showCorrect,
			randomize_options:   randomize,
			...patch,
		};
		onChange( { settings: next } );
	};

	return (
		<>
			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Points', 'neural-forms-lite' ) }</span>
				<input
					type="number"
					min="0"
					step="1"
					value={ points }
					onChange={ ( e ) => {
						const v = Number( e.target.value );
						setSettings( { points: ( Number.isFinite( v ) && v >= 0 ) ? Math.round( v ) : 0 } );
					} }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Explanation', 'neural-forms-lite' ) }</span>
				<textarea
					rows={ 3 }
					value={ explanation }
					onChange={ ( e ) => setSettings( { explanation: e.target.value } ) }
					placeholder={ __( 'Optional. Shown after submit when "Show correct answer" is enabled.', 'neural-forms-lite' ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Show correct answer', 'neural-forms-lite' ) }</span>
				<select
					value={ showCorrect }
					onChange={ ( e ) => setSettings( { show_correct_answer: e.target.value } ) }
				>
					<option value="after_submit">{ __( 'After submit', 'neural-forms-lite' ) }</option>
					<option value="never">{ __( 'Never', 'neural-forms-lite' ) }</option>
				</select>
			</label>

			<label className="nf-inspector__field nf-inspector__field--inline">
				<input
					type="checkbox"
					checked={ randomize }
					onChange={ ( e ) => setSettings( { randomize_options: e.target.checked } ) }
				/>
				<span>{ __( 'Randomize options', 'neural-forms-lite' ) }</span>
			</label>
		</>
	);
}

/**
 * Matrix-specific settings block.
 *
 * Edits the nested `field.settings` object:
 *   - selection_type: "radio" (one per row) | "checkbox" (many per row)
 *   - rows:    [{ label, value }, ...]
 *   - columns: [{ label, value, score:int }, ...]
 *
 * Builder UI only — frontend rendering, validation, scoring and
 * email/CSV display arrive in later Matrix commits. Score is
 * persisted now so adding it later doesn't need a migration.
 */
function MatrixSettingsBlock( { field, onChange } ) {
	const s = ( field && typeof field.settings === 'object' && field.settings !== null )
		? field.settings
		: {};

	const selectionType = s.selection_type === 'checkbox' ? 'checkbox' : 'radio';
	const rows = Array.isArray( s.rows ) ? s.rows : [];
	const cols = Array.isArray( s.columns ) ? s.columns : [];

	const writeSettings = ( patch ) => {
		const next = {
			selection_type: selectionType,
			rows,
			columns: cols,
			...patch,
		};
		onChange( { settings: next } );
	};

	const setSelectionType = ( v ) => writeSettings( { selection_type: v === 'checkbox' ? 'checkbox' : 'radio' } );

	// Rows --------------------------------------------------------------
	const updateRow = ( idx, patch ) => {
		const next = rows.slice();
		next[ idx ] = { ...( next[ idx ] || {} ), ...patch };
		writeSettings( { rows: next } );
	};
	const addRow = () => {
		const n = rows.length + 1;
		writeSettings( { rows: [ ...rows, { label: 'Row ' + n, value: 'row_' + n } ] } );
	};
	const removeRow = ( idx ) => {
		const next = rows.slice();
		next.splice( idx, 1 );
		writeSettings( { rows: next } );
	};

	// Columns -----------------------------------------------------------
	const updateCol = ( idx, patch ) => {
		const next = cols.slice();
		next[ idx ] = { ...( next[ idx ] || {} ), ...patch };
		writeSettings( { columns: next } );
	};
	const addCol = () => {
		const n = cols.length + 1;
		writeSettings( {
			columns: [
				...cols,
				{ label: 'Option ' + n, value: 'option_' + n, score: n },
			],
		} );
	};
	const removeCol = ( idx ) => {
		const next = cols.slice();
		next.splice( idx, 1 );
		writeSettings( { columns: next } );
	};

	return (
		<div className="nf-inspector__matrix">
			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Selection type', 'neural-forms-lite' ) }</span>
				<select
					value={ selectionType }
					onChange={ ( e ) => setSelectionType( e.target.value ) }
				>
					<option value="radio">{ __( 'Single choice per row (radio)', 'neural-forms-lite' ) }</option>
					<option value="checkbox">{ __( 'Multiple choices per row (checkbox)', 'neural-forms-lite' ) }</option>
				</select>
			</label>

			<div className="nf-inspector__matrix-group">
				<span className="nf-inspector__label">{ __( 'Rows', 'neural-forms-lite' ) }</span>
				{ rows.length === 0 && (
					<p className="nf-inspector__empty">{ __( 'No rows yet.', 'neural-forms-lite' ) }</p>
				) }
				{ rows.map( ( row, i ) => (
					<div className="nf-inspector__matrix-row-row" key={ i }>
						<input
							type="text"
							className="nf-inspector__option-label"
							value={ ( row && row.label ) || '' }
							onChange={ ( e ) => updateRow( i, { label: e.target.value } ) }
							placeholder={ __( 'Row label', 'neural-forms-lite' ) }
						/>
						<input
							type="text"
							className="nf-inspector__option-value"
							value={ ( row && row.value ) || '' }
							onChange={ ( e ) => updateRow( i, { value: e.target.value } ) }
							placeholder={ __( 'row_value', 'neural-forms-lite' ) }
						/>
						<button
							type="button"
							className="nf-inspector__option-remove"
							onClick={ () => removeRow( i ) }
							aria-label={ __( 'Remove row', 'neural-forms-lite' ) }
							title={ __( 'Remove row', 'neural-forms-lite' ) }
						>×</button>
					</div>
				) ) }
				<button
					type="button"
					className="neural-forms-btn"
					onClick={ addRow }
				>+ { __( 'Add row', 'neural-forms-lite' ) }</button>
			</div>

			<div className="nf-inspector__matrix-group">
				<span className="nf-inspector__label">{ __( 'Columns', 'neural-forms-lite' ) }</span>
				{ cols.length === 0 && (
					<p className="nf-inspector__empty">{ __( 'No columns yet.', 'neural-forms-lite' ) }</p>
				) }
				{ cols.map( ( col, i ) => {
					const scoreNum = Number( col && col.score );
					const scoreVal = Number.isFinite( scoreNum ) ? scoreNum : 0;
					return (
						<div className="nf-inspector__matrix-col-row" key={ i }>
							<input
								type="text"
								className="nf-inspector__option-label"
								value={ ( col && col.label ) || '' }
								onChange={ ( e ) => updateCol( i, { label: e.target.value } ) }
								placeholder={ __( 'Column label', 'neural-forms-lite' ) }
							/>
							<input
								type="text"
								className="nf-inspector__option-value"
								value={ ( col && col.value ) || '' }
								onChange={ ( e ) => updateCol( i, { value: e.target.value } ) }
								placeholder={ __( 'column_value', 'neural-forms-lite' ) }
							/>
							<input
								type="number"
								className="nf-inspector__matrix-score"
								value={ scoreVal }
								onChange={ ( e ) => {
									const n = Number( e.target.value );
									updateCol( i, { score: Number.isFinite( n ) ? n : 0 } );
								} }
								placeholder={ __( 'score', 'neural-forms-lite' ) }
								title={ __( 'Score (optional)', 'neural-forms-lite' ) }
							/>
							<button
								type="button"
								className="nf-inspector__option-remove"
								onClick={ () => removeCol( i ) }
								aria-label={ __( 'Remove column', 'neural-forms-lite' ) }
								title={ __( 'Remove column', 'neural-forms-lite' ) }
							>×</button>
						</div>
					);
				} ) }
				<button
					type="button"
					className="neural-forms-btn"
					onClick={ addCol }
				>+ { __( 'Add column', 'neural-forms-lite' ) }</button>
			</div>
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

	const setColumns = ( next ) => onChange( replaceRow( nodes, hit, resizeRow( row, next ) ) );
	const setGap = ( next ) => onChange( replaceRow( nodes, hit, {
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
	// Reads the Pro-capability flag Lite localizes at boot. When Pro is
	// active (capability true), the left sidebar's AiPromptPanel owns
	// every state of AI generation — render-as-textarea / connect-cloud
	// / activate-license / out-of-credits — so a duplicate card here
	// would either repeat that state or, worse, ship the obsolete
	// "Soon you will be able…" copy that predates the feature shipping.
	const root = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	const caps = ( root.plugin && root.plugin.capabilities ) || {};
	const proInstalled = !! caps.ai_form_generate;

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

			{ /* Pro-only upsell. When Pro is active, the left sidebar
			     AiPromptPanel handles every connect/license/credit/
			     working state — rendering anything here would either
			     duplicate that or contradict it. */ }
			{ ! proInstalled && (
				<div className="nf-inspector__ai-card">
					<h4 className="nf-inspector__ai-title">
						{ __( 'AI form generation', 'neural-forms-lite' ) }
						<span className="nf-inspector__ai-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
					</h4>
					<p className="nf-inspector__ai-hint">
						{ __( 'Generate complete forms from a short prompt with Neural Forms Pro.', 'neural-forms-lite' ) }
					</p>
					<a
						className="neural-forms-btn neural-forms-btn--primary nf-inspector__ai-cta"
						href="https://neuralforms.ai/pricing"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
					</a>
				</div>
			) }
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

function SectionInspector( { hit, nodes, onChange } ) {
	const s = hit.node;
	const settings = ( s.settings && typeof s.settings === 'object' ) ? s.settings : {};
	const styleRaw = typeof settings.style === 'string' ? settings.style : DEFAULT_SECTION_STYLE;
	const style = VALID_SECTION_STYLES.includes( styleRaw ) ? styleRaw : DEFAULT_SECTION_STYLE;

	const set = ( patch ) => onChange( replaceField( nodes, hit, { ...s, ...patch } ) );
	const setStyle = ( next ) => set( { settings: { ...settings, style: next } } );

	return (
		<>
			<h3 className="nf-inspector__title">{ __( 'Section settings', 'neural-forms-lite' ) }</h3>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Title', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ typeof s.title === 'string' ? s.title : '' }
					onChange={ ( e ) => set( { title: e.target.value } ) }
					placeholder={ __( 'e.g. Project Details', 'neural-forms-lite' ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Description', 'neural-forms-lite' ) }</span>
				<textarea
					rows={ 2 }
					value={ typeof s.description === 'string' ? s.description : '' }
					onChange={ ( e ) => set( { description: e.target.value } ) }
					placeholder={ __( 'Optional helper text shown below the title.', 'neural-forms-lite' ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Style', 'neural-forms-lite' ) }</span>
				<select value={ style } onChange={ ( e ) => setStyle( e.target.value ) }>
					<option value="default">{ __( 'Default', 'neural-forms-lite' ) }</option>
					<option value="minimal">{ __( 'Minimal', 'neural-forms-lite' ) }</option>
					<option value="boxed">{ __( 'Boxed', 'neural-forms-lite' ) }</option>
				</select>
			</label>

			<p className="nf-inspector__hint">
				{ __( 'Sections are visual dividers. They never carry input values.', 'neural-forms-lite' ) }
			</p>
		</>
	);
}

function StepInspector( { nodes, stepId, onChange, formSchemaSettings, onChangeFormSchemaSettings } ) {
	const idx = nodes.findIndex( ( n ) => n && n.type === 'step' && n.id === stepId );
	if ( idx === -1 ) return null;
	const step  = nodes[ idx ];
	const steps = nodes.filter( ( n ) => n && n.type === 'step' );
	const stepNumber = steps.findIndex( ( s ) => s.id === stepId ) + 1;

	const settings = ( step.settings && typeof step.settings === 'object' ) ? step.settings : {};
	const buttonNext = typeof settings.button_next === 'string' ? settings.button_next : 'Next';
	const buttonPrev = typeof settings.button_previous === 'string' ? settings.button_previous : 'Back';

	const setStep = ( patch ) => {
		const copy = nodes.slice();
		copy[ idx ] = { ...step, ...patch };
		onChange( copy );
	};
	const setStepSettings = ( patch ) => setStep( { settings: { ...settings, ...patch } } );

	return (
		<>
			<h3 className="nf-inspector__title">{ __( 'Step settings', 'neural-forms-lite' ) }</h3>

			<p className="nf-inspector__step-position">
				{ sprintf(
					/* translators: 1: current step number  2: total steps */
					__( 'Step %1$d of %2$d', 'neural-forms-lite' ),
					stepNumber,
					steps.length
				) }
			</p>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Title', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ typeof step.title === 'string' ? step.title : '' }
					onChange={ ( e ) => setStep( { title: e.target.value } ) }
					placeholder={ __( 'e.g. Contact Details', 'neural-forms-lite' ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Description', 'neural-forms-lite' ) }</span>
				<textarea
					rows={ 2 }
					value={ typeof step.description === 'string' ? step.description : '' }
					onChange={ ( e ) => setStep( { description: e.target.value } ) }
					placeholder={ __( 'Optional helper text shown above this step.', 'neural-forms-lite' ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Next button label', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ buttonNext }
					onChange={ ( e ) => setStepSettings( { button_next: e.target.value } ) }
				/>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Previous button label', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ buttonPrev }
					onChange={ ( e ) => setStepSettings( { button_previous: e.target.value } ) }
				/>
			</label>

			<p className="nf-inspector__hint">
				{ __( 'Steps are layout sections. Only fields inside steps are submitted.', 'neural-forms-lite' ) }
			</p>

			{ /* Form-level progress indicator. Lives on form_schema.settings
			    so the visual setting is part of the schema, not the
			    submission/security/email settings on form.settings. */ }
			<div className="nf-inspector__form-progress">
				<h4 className="nf-inspector__form-progress-title">
					{ __( 'Form progress indicator', 'neural-forms-lite' ) }
				</h4>
				<p className="nf-inspector__form-progress-hint">
					{ __( 'Applies to every step in this form.', 'neural-forms-lite' ) }
				</p>
				<label className="nf-inspector__field">
					<select
						value={ ( () => {
							const ms = ( formSchemaSettings && formSchemaSettings.multistep ) || {};
							const v  = ms.progress_indicator;
							return ( v === 'numbers' || v === 'bar' || v === 'none' ) ? v : 'numbers';
						} )() }
						onChange={ ( e ) => {
							if ( typeof onChangeFormSchemaSettings !== 'function' ) return;
							const ms = ( formSchemaSettings && formSchemaSettings.multistep ) || {};
							onChangeFormSchemaSettings( {
								multistep: { ...ms, progress_indicator: e.target.value },
							} );
						} }
					>
						<option value="numbers">{ __( 'Step numbers', 'neural-forms-lite' ) }</option>
						<option value="bar">{ __( 'Progress bar', 'neural-forms-lite' ) }</option>
						<option value="none">{ __( 'None', 'neural-forms-lite' ) }</option>
					</select>
				</label>
			</div>
		</>
	);
}

export default function BuilderInspector( {
	nodes,
	selectedId,
	selectedColumnId,
	selectedStepId,
	onChange,
	formSchemaSettings,
	onChangeFormSchemaSettings,
	formId,
} ) {
	const hit = findNode( nodes, selectedId );

	return (
		<aside className="nf-inspector" aria-label={ __( 'Selected element settings', 'neural-forms-lite' ) }>
			{ ! hit && ! selectedColumnId && ! selectedStepId && <EmptyInspector /> }
			{ ! hit && selectedColumnId && <ColumnInspector /> }
			{ ! hit && ! selectedColumnId && selectedStepId && (
				<StepInspector
					nodes={ nodes }
					stepId={ selectedStepId }
					onChange={ onChange }
					formSchemaSettings={ formSchemaSettings }
					onChangeFormSchemaSettings={ onChangeFormSchemaSettings }
				/>
			) }
			{ hit && hit.kind === 'row' && (
				<RowInspector hit={ hit } nodes={ nodes } onChange={ onChange } />
			) }
			{ hit && hit.kind === 'section' && (
				<SectionInspector hit={ hit } nodes={ nodes } onChange={ onChange } />
			) }
			{ hit && hit.kind === 'field' && (
				<FieldInspector hit={ hit } nodes={ nodes } onChange={ onChange } formId={ formId } />
			) }
		</aside>
	);
}
