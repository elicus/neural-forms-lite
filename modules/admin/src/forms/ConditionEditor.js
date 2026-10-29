/**
 * ConditionEditor — the one condition editor (1.2.0).
 *
 * Edits a v2 conditional_logic object:
 *
 *   { enabled, version: 2, action: 'show'|'hide', match: 'all'|'any',
 *     rules: [ { field, operator, value } ], groups: [ { match, rules } ] }
 *
 * Used by the field inspector, the step and section inspectors and the
 * conditional confirmation rules in Form Settings. The owner decides
 * what the action means (show/hide a field, make a step eligible, pick
 * a confirmation); the editor only edits the tree.
 *
 * Props
 *   value        v2 object (toEditableConditional() output or raw)
 *   onChange     ( nextV2Object ) => void
 *   sources      [ { id, label, type, options? } ] fields the rules may reference
 *   allowGroups  render the Pro condition-group editor
 *   actionLabels { show, hide } or null to hide the action select
 *   showEnable   render the "Enable conditional logic" checkbox (default true)
 *   lockedHint   text shown under the editor when groups are locked
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	CONDITION_OPERATORS,
	CONDITION_MAX_RULES,
	CONDITION_MAX_GROUPS,
	toEditableConditional,
} from './schemaHelper';

const NUMERIC_SOURCE_TYPES = [ 'number', 'range', 'rating' ];

function emptyRule( sources ) {
	return { field: ( sources[ 0 ] && sources[ 0 ].id ) || '', operator: 'equals', value: '' };
}

/**
 * Human summary, e.g. "Service equals “Web” and Budget is greater than
 * or equal to “5000”". Groups are wrapped in parentheses.
 */
export function describeConditional( v2, sources ) {
	const cl = toEditableConditional( v2 );
	const one = ( r ) => {
		const src  = sources.find( ( s ) => s.id === r.field );
		const op   = CONDITION_OPERATORS.find( ( o ) => o.value === r.operator );
		const name = src ? ( src.label || src.id ) : sprintf( __( '(missing field %s)', 'neural-forms-lite' ), r.field );
		const opLabel = op ? __( op.label, 'neural-forms-lite' ) : r.operator;
		return op && op.needsValue ? `${ name } ${ opLabel } “${ r.value }”` : `${ name } ${ opLabel }`;
	};
	const joiner = ( m ) => ( m === 'any' ? __( ' or ', 'neural-forms-lite' ) : __( ' and ', 'neural-forms-lite' ) );
	const parts = cl.rules.map( one );
	for ( const g of cl.groups ) {
		if ( g.rules.length ) parts.push( '(' + g.rules.map( one ).join( joiner( g.match ) ) + ')' );
	}
	return parts.join( joiner( cl.match ) );
}

function RuleRow( { rule, index, match, canRemove, sources, onUpdate, onRemove } ) {
	const sourceField     = sources.find( ( s ) => s.id === rule.field ) || null;
	const sourceMissing   = rule.field !== '' && ! sourceField;
	const operator        = CONDITION_OPERATORS.find( ( o ) => o.value === rule.operator ) ? rule.operator : 'equals';
	const opMeta          = CONDITION_OPERATORS.find( ( o ) => o.value === operator );
	const sourceOptions   = Array.isArray( sourceField?.options ) ? sourceField.options : [];
	const sourceIsChoice  = sourceField && [ 'select', 'radio' ].includes( sourceField.type ) && sourceOptions.length > 0;
	const sourceIsNumeric = !! ( sourceField && NUMERIC_SOURCE_TYPES.includes( sourceField.type ) );
	const visibleOperators = CONDITION_OPERATORS.filter( ( op ) => ! op.numericOnly || sourceIsNumeric );

	return (
		<div className="nf-inspector__cl-rule">
			<div className="nf-inspector__cl-rule-head">
				<span className="nf-inspector__cl-prefix">
					{ index === 0
						? __( 'If', 'neural-forms-lite' )
						: ( match === 'any' ? __( 'Or', 'neural-forms-lite' ) : __( 'And', 'neural-forms-lite' ) ) }
				</span>
				{ canRemove && (
					<button
						type="button"
						className="nf-inspector__cl-remove"
						onClick={ onRemove }
						aria-label={ __( 'Remove condition', 'neural-forms-lite' ) }
						title={ __( 'Remove condition', 'neural-forms-lite' ) }
					>
						×
					</button>
				) }
			</div>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Field', 'neural-forms-lite' ) }</span>
				<select
					value={ rule.field }
					onChange={ ( e ) => {
						const nextSourceId  = e.target.value;
						const nextSource    = sources.find( ( s ) => s.id === nextSourceId );
						const nextIsNumeric = !! ( nextSource && NUMERIC_SOURCE_TYPES.includes( nextSource.type ) );
						const nextOperator  = ( opMeta && opMeta.numericOnly && ! nextIsNumeric ) ? 'equals' : operator;
						onUpdate( { field: nextSourceId, value: '', operator: nextOperator } );
					} }
				>
					{ sourceMissing && (
						<option value={ rule.field }>{ sprintf( __( '(missing field %s)', 'neural-forms-lite' ), rule.field ) }</option>
					) }
					{ rule.field === '' && <option value="">{ __( 'Select a field…', 'neural-forms-lite' ) }</option> }
					{ sources.map( ( s ) => (
						<option key={ s.id } value={ s.id }>{ s.label || s.id }</option>
					) ) }
				</select>
			</label>

			<label className="nf-inspector__field">
				<span className="nf-inspector__label">{ __( 'Operator', 'neural-forms-lite' ) }</span>
				<select
					value={ operator }
					onChange={ ( e ) => {
						const next     = e.target.value;
						const nextMeta = CONDITION_OPERATORS.find( ( o ) => o.value === next );
						onUpdate( { operator: next, value: nextMeta && nextMeta.needsValue ? ( rule.value ?? '' ) : '' } );
					} }
				>
					{ visibleOperators.map( ( o ) => (
						<option key={ o.value } value={ o.value }>{ __( o.label, 'neural-forms-lite' ) }</option>
					) ) }
				</select>
			</label>

			{ opMeta && opMeta.needsValue && (
				<label className="nf-inspector__field">
					<span className="nf-inspector__label">{ __( 'Value', 'neural-forms-lite' ) }</span>
					{ sourceIsChoice ? (
						<select
							value={ typeof rule.value === 'string' ? rule.value : '' }
							onChange={ ( e ) => onUpdate( { value: e.target.value } ) }
						>
							<option value="">{ __( 'Select value...', 'neural-forms-lite' ) }</option>
							{ sourceOptions.map( ( opt, i ) => {
								const v = ( opt && opt.value ) || ( opt && opt.label ) || '';
								return v ? <option key={ i } value={ v }>{ ( opt && opt.label ) || v }</option> : null;
							} ) }
						</select>
					) : (
						<input
							type={ opMeta && opMeta.numericOnly ? 'number' : 'text' }
							step={ opMeta && opMeta.numericOnly ? 'any' : undefined }
							value={ typeof rule.value === 'string' ? rule.value : '' }
							onChange={ ( e ) => onUpdate( { value: e.target.value } ) }
						/>
					) }
				</label>
			) }
		</div>
	);
}

function RuleList( { rules, match, sources, onChange, minRules } ) {
	const update = ( i, patch ) => onChange( rules.map( ( r, k ) => ( k === i ? { ...r, ...patch } : r ) ) );
	const remove = ( i ) => onChange( rules.filter( ( _, k ) => k !== i ) );
	const add    = () => { if ( rules.length < CONDITION_MAX_RULES ) onChange( [ ...rules, emptyRule( sources ) ] ); };
	return (
		<>
			<div className="nf-inspector__cl-rules">
				{ rules.map( ( rule, i ) => (
					<RuleRow
						key={ i }
						rule={ rule }
						index={ i }
						match={ match }
						canRemove={ rules.length > ( minRules ?? 1 ) }
						sources={ sources }
						onUpdate={ ( patch ) => update( i, patch ) }
						onRemove={ () => remove( i ) }
					/>
				) ) }
			</div>
			<button
				type="button"
				className="nf-inspector__cl-add"
				onClick={ add }
				disabled={ rules.length >= CONDITION_MAX_RULES }
			>
				{ __( '+ Add condition', 'neural-forms-lite' ) }
			</button>
		</>
	);
}

export default function ConditionEditor( {
	value,
	onChange,
	sources,
	allowGroups = false,
	actionLabels = { show: __( 'Show', 'neural-forms-lite' ), hide: __( 'Hide', 'neural-forms-lite' ) },
	showEnable = true,
	lockedHint = '',
	emptySourcesHint = __( 'Add another field to this form to use as the rule source.', 'neural-forms-lite' ),
} ) {
	const cl      = toEditableConditional( value );
	const enabled = showEnable ? cl.enabled : true;
	const srcs    = Array.isArray( sources ) ? sources : [];

	const write = ( patch ) => onChange( { ...cl, enabled, ...patch } );

	const toggle = ( on ) => {
		if ( ! on ) { onChange( { ...cl, enabled: false } ); return; }
		onChange( { ...cl, enabled: true, rules: cl.rules.length ? cl.rules : [ emptyRule( srcs ) ] } );
	};

	const updateGroup = ( gi, patch ) => write( { groups: cl.groups.map( ( g, k ) => ( k === gi ? { ...g, ...patch } : g ) ) } );
	const removeGroup = ( gi ) => write( { groups: cl.groups.filter( ( _, k ) => k !== gi ) } );
	const addGroup    = () => {
		if ( cl.groups.length >= CONDITION_MAX_GROUPS ) return;
		write( { groups: [ ...cl.groups, { match: 'any', rules: [ emptyRule( srcs ), emptyRule( srcs ) ] } ] } );
	};

	const totalClauses = cl.rules.length + cl.groups.length;

	return (
		<div className="nf-condition-editor">
			{ showEnable && (
				<label className="nf-inspector__field nf-inspector__field--inline">
					<input type="checkbox" checked={ enabled } onChange={ ( e ) => toggle( e.target.checked ) } />
					<span>{ __( 'Enable conditional logic', 'neural-forms-lite' ) }</span>
				</label>
			) }

			{ enabled && srcs.length === 0 && (
				<p className="nf-inspector__cl-hint">{ emptySourcesHint }</p>
			) }

			{ enabled && srcs.length > 0 && (
				<>
					<div className="nf-inspector__cl-head">
						{ actionLabels && (
							<>
								<select
									value={ cl.action }
									onChange={ ( e ) => write( { action: e.target.value === 'hide' ? 'hide' : 'show' } ) }
									aria-label={ __( 'Action', 'neural-forms-lite' ) }
								>
									<option value="show">{ actionLabels.show }</option>
									<option value="hide">{ actionLabels.hide }</option>
								</select>
								<span className="nf-inspector__cl-head-text">{ __( 'when', 'neural-forms-lite' ) }</span>
							</>
						) }
						<select
							value={ cl.match }
							onChange={ ( e ) => write( { match: e.target.value === 'any' ? 'any' : 'all' } ) }
							aria-label={ __( 'Match', 'neural-forms-lite' ) }
							disabled={ totalClauses < 2 }
						>
							<option value="all">{ __( 'all conditions match', 'neural-forms-lite' ) }</option>
							<option value="any">{ __( 'any condition matches', 'neural-forms-lite' ) }</option>
						</select>
					</div>

					<RuleList
						rules={ cl.rules }
						match={ cl.match }
						sources={ srcs }
						onChange={ ( rules ) => write( { rules } ) }
						minRules={ cl.groups.length ? 0 : 1 }
					/>

					{ allowGroups && cl.groups.map( ( g, gi ) => (
						<div className="nf-inspector__cl-group" key={ gi }>
							<div className="nf-inspector__cl-group-head">
								<span className="nf-inspector__cl-prefix">
									{ cl.match === 'any' ? __( 'Or group', 'neural-forms-lite' ) : __( 'And group', 'neural-forms-lite' ) }
								</span>
								<select
									value={ g.match }
									onChange={ ( e ) => updateGroup( gi, { match: e.target.value === 'any' ? 'any' : 'all' } ) }
									aria-label={ __( 'Group match', 'neural-forms-lite' ) }
								>
									<option value="all">{ __( 'all of these', 'neural-forms-lite' ) }</option>
									<option value="any">{ __( 'any of these', 'neural-forms-lite' ) }</option>
								</select>
								<button
									type="button"
									className="nf-inspector__cl-remove"
									onClick={ () => removeGroup( gi ) }
									aria-label={ __( 'Remove group', 'neural-forms-lite' ) }
									title={ __( 'Remove group', 'neural-forms-lite' ) }
								>
									×
								</button>
							</div>
							<RuleList
								rules={ g.rules }
								match={ g.match }
								sources={ srcs }
								onChange={ ( rules ) => updateGroup( gi, { rules } ) }
								minRules={ 1 }
							/>
						</div>
					) ) }

					{ allowGroups && (
						<button
							type="button"
							className="nf-inspector__cl-add nf-inspector__cl-add--group"
							onClick={ addGroup }
							disabled={ cl.groups.length >= CONDITION_MAX_GROUPS }
						>
							{ __( '+ Add condition group', 'neural-forms-lite' ) }
						</button>
					) }

					{ ! allowGroups && cl.groups.length > 0 && (
						<p className="nf-inspector__cl-hint">
							{ sprintf(
								/* translators: %d: number of condition groups */
								__( 'This also has %d condition group(s) that need Neural Forms Pro to edit.', 'neural-forms-lite' ),
								cl.groups.length
							) }
						</p>
					) }

					{ ! allowGroups && lockedHint && (
						<p className="nf-inspector__cl-hint nf-inspector__cl-hint--pro">{ lockedHint }</p>
					) }
				</>
			) }
		</div>
	);
}
