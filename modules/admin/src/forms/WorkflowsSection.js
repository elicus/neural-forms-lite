/**
 * Workflows (1.2.0, Pro) — Form settings › Workflows.
 *
 * When → If → Then. One trigger, optional conditions (shared
 * ConditionEditor over form fields + entry meta), ordered actions from
 * the server-side registry (/workflows/meta), test run (dry run, no
 * side effects) and run history with manual retry.
 *
 * Data lives on the server (neural_forms_workflows), not in the form
 * schema, so this section talks to /neural-forms/v1/workflows directly
 * and needs a saved form id.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import ConditionEditor, { describeConditional } from './ConditionEditor';
import { flattenFields, toEditableConditional } from './schemaHelper';

function readCaps() {
	if ( typeof window === 'undefined' ) return {};
	return ( window.neuralFormsAdmin?.plugin?.capabilities ) || {};
}

function emptyWorkflow( formId, firstAction ) {
	return {
		id: 0,
		form_id: formId,
		name: '',
		description: '',
		enabled: true,
		trigger: 'submission_received',
		conditions: null,
		actions: firstAction ? [ { type: firstAction, settings: {}, continue_on_failure: false } ] : [],
		settings: { on_reanalysis: false, stage_from: '', stage_to: '' },
	};
}

function fmt( s ) {
	if ( ! s ) return '';
	const d = new Date( String( s ).replace( ' ', 'T' ) + ( /Z$|[+-]\d\d:\d\d$/.test( s ) ? '' : 'Z' ) );
	return isNaN( d.getTime() ) ? String( s ) : d.toLocaleString();
}

const STATUS_LABEL = {
	pending: __( 'Pending', 'neural-forms-lite' ), running: __( 'Running', 'neural-forms-lite' ), waiting: __( 'Waiting to retry', 'neural-forms-lite' ),
	done: __( 'Done', 'neural-forms-lite' ), failed: __( 'Failed', 'neural-forms-lite' ), partial: __( 'Partly done', 'neural-forms-lite' ),
	skipped: __( 'Skipped', 'neural-forms-lite' ), cancelled: __( 'Cancelled', 'neural-forms-lite' ), uncertain: __( 'Uncertain', 'neural-forms-lite' ),
};

function StatusPill( { status } ) {
	return <span className={ 'nf-wf__pill nf-wf__pill--' + status }>{ STATUS_LABEL[ status ] || status }</span>;
}

/* ── settings field renderer for action settings ─────────────────── */
function SettingField( { field, value, onChange, meta } ) {
	const common = { id: undefined };
	switch ( field.type ) {
		case 'textarea':
			return <textarea rows={ 4 } value={ value ?? field.default ?? '' } onChange={ ( e ) => onChange( e.target.value ) } { ...common } />;
		case 'number':
			return <input type="number" step="any" value={ value ?? field.default ?? '' } onChange={ ( e ) => onChange( e.target.value ) } />;
		case 'checkbox':
			return <input type="checkbox" checked={ !! value } onChange={ ( e ) => onChange( e.target.checked ) } />;
		case 'user':
			return (
				<select value={ value ? String( value ) : '' } onChange={ ( e ) => onChange( e.target.value ? Number( e.target.value ) : 0 ) }>
					<option value="">{ field.required ? __( 'Select a team member…', 'neural-forms-lite' ) : __( 'Nobody', 'neural-forms-lite' ) }</option>
					{ ( meta.assignees || [] ).map( ( u ) => <option key={ u.id } value={ String( u.id ) }>{ u.name }</option> ) }
				</select>
			);
		case 'stage':
			return (
				<select value={ value || '' } onChange={ ( e ) => onChange( e.target.value ) }>
					<option value="">{ __( 'Select a stage…', 'neural-forms-lite' ) }</option>
					{ ( meta.stages || [] ).map( ( s ) => <option key={ s.id } value={ s.id }>{ s.label }</option> ) }
				</select>
			);
		case 'select':
			return (
				<select value={ value || '' } onChange={ ( e ) => onChange( e.target.value ) }>
					{ ( field.options || [] ).map( ( o ) => <option key={ o.value } value={ o.value }>{ o.label }</option> ) }
				</select>
			);
		default:
			return <input type="text" value={ value ?? field.default ?? '' } onChange={ ( e ) => onChange( e.target.value ) } />;
	}
}

/* ── editor ──────────────────────────────────────────────────────── */
function WorkflowEditor( { formId, initial, meta, sources, onSaved, onCancel } ) {
	const [ wf, setWf ]       = useState( initial );
	const [ busy, setBusy ]   = useState( false );
	const [ error, setError ] = useState( '' );
	const [ test, setTest ]   = useState( null );
	const [ testEntryId, setTestEntryId ] = useState( '' );
	const actionsMeta = meta.actions || [];
	const triggers    = meta.triggers || [];

	const set = ( patch ) => setWf( ( prev ) => ( { ...prev, ...patch } ) );
	const setSettings = ( patch ) => set( { settings: { ...( wf.settings || {} ), ...patch } } );
	const setAction = ( i, patch ) => set( { actions: wf.actions.map( ( a, k ) => ( k === i ? { ...a, ...patch } : a ) ) } );
	const setActionSetting = ( i, key, value ) => setAction( i, { settings: { ...( wf.actions[ i ].settings || {} ), [ key ]: value } } );
	const removeAction = ( i ) => set( { actions: wf.actions.filter( ( _, k ) => k !== i ) } );
	const moveAction = ( i, dir ) => {
		const j = i + dir;
		if ( j < 0 || j >= wf.actions.length ) return;
		const next = wf.actions.slice();
		const t = next[ i ]; next[ i ] = next[ j ]; next[ j ] = t;
		set( { actions: next } );
	};
	const addAction = () => {
		if ( wf.actions.length >= ( meta.limits?.max_actions || 10 ) ) return;
		const first = actionsMeta[ 0 ];
		if ( ! first ) return;
		const defaults = {};
		( first.settings || [] ).forEach( ( f ) => { if ( f.default !== undefined ) defaults[ f.key ] = f.default; } );
		set( { actions: [ ...wf.actions, { type: first.type, settings: defaults, continue_on_failure: false } ] } );
	};
	const changeActionType = ( i, type ) => {
		const am = actionsMeta.find( ( a ) => a.type === type );
		const defaults = {};
		( am?.settings || [] ).forEach( ( f ) => { if ( f.default !== undefined ) defaults[ f.key ] = f.default; } );
		setAction( i, { type, settings: defaults } );
	};

	const payload = () => ( {
		...wf,
		form_id: formId,
		conditions: wf.conditions && toEditableConditional( wf.conditions ).rules.length + toEditableConditional( wf.conditions ).groups.length > 0
			? { ...toEditableConditional( wf.conditions ), enabled: true }
			: null,
	} );

	const save = () => {
		setBusy( true );
		setError( '' );
		const body = payload();
		const req  = wf.id
			? api( `/workflows/${ wf.id }`, { method: 'PUT', data: body } )
			: api( '/workflows', { method: 'POST', data: body } );
		req.then( ( saved ) => { setBusy( false ); onSaved( saved ); } )
			.catch( ( err ) => { setBusy( false ); setError( err?.message || __( 'Could not save the workflow.', 'neural-forms-lite' ) ); } );
	};

	const runTest = () => {
		setBusy( true );
		setError( '' );
		setTest( null );
		const body = { definition: payload(), entry_id: testEntryId ? Number( testEntryId ) : 0 };
		const id   = wf.id || 0;
		// Unsaved workflows are tested through a throwaway POST? No — the test
		// route needs an id. For a new workflow we ask the user to save first.
		if ( ! id ) {
			setBusy( false );
			setError( __( 'Save the workflow once before running a test.', 'neural-forms-lite' ) );
			return;
		}
		api( `/workflows/${ id }/test`, { method: 'POST', data: body } )
			.then( ( res ) => { setBusy( false ); setTest( res ); } )
			.catch( ( err ) => { setBusy( false ); setError( err?.message || __( 'Test failed.', 'neural-forms-lite' ) ); } );
	};

	const triggerMeta = triggers.find( ( t ) => t.id === wf.trigger );

	return (
		<div className="nf-wf-editor">
			<div className="nf-wf-editor__row">
				<label className="nf-form-settings__field nf-wf-editor__grow">
					<span className="nf-form-settings__label">{ __( 'Name', 'neural-forms-lite' ) }</span>
					<input type="text" value={ wf.name } onChange={ ( e ) => set( { name: e.target.value } ) } placeholder={ __( 'e.g. Route qualified web leads', 'neural-forms-lite' ) } />
				</label>
				<label className="nf-form-settings__field nf-wf-editor__toggle">
					<input type="checkbox" checked={ !! wf.enabled } onChange={ ( e ) => set( { enabled: e.target.checked } ) } />
					<span>{ __( 'Enabled', 'neural-forms-lite' ) }</span>
				</label>
			</div>
			<label className="nf-form-settings__field">
				<span className="nf-form-settings__label">{ __( 'Description', 'neural-forms-lite' ) }</span>
				<input type="text" value={ wf.description || '' } onChange={ ( e ) => set( { description: e.target.value } ) } />
			</label>

			<h5 className="nf-wf-editor__step"><span>1</span>{ __( 'When', 'neural-forms-lite' ) }</h5>
			<label className="nf-form-settings__field">
				<select value={ wf.trigger } onChange={ ( e ) => set( { trigger: e.target.value } ) }>
					{ triggers.map( ( t ) => <option key={ t.id } value={ t.id }>{ t.label }</option> ) }
				</select>
				{ triggerMeta && <span className="nf-form-settings__hint">{ triggerMeta.description }</span> }
			</label>
			{ wf.trigger === 'analysis_completed' && (
				<label className="nf-form-settings__field nf-wf-editor__toggle">
					<input type="checkbox" checked={ !! wf.settings?.on_reanalysis } onChange={ ( e ) => setSettings( { on_reanalysis: e.target.checked } ) } />
					<span>{ __( 'Also run when an entry is re-analysed', 'neural-forms-lite' ) }</span>
				</label>
			) }
			{ wf.trigger === 'stage_changed' && (
				<div className="nf-wf-editor__row">
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'From stage', 'neural-forms-lite' ) }</span>
						<select value={ wf.settings?.stage_from || '' } onChange={ ( e ) => setSettings( { stage_from: e.target.value } ) }>
							<option value="">{ __( 'Any', 'neural-forms-lite' ) }</option>
							{ ( meta.stages || [] ).map( ( s ) => <option key={ s.id } value={ s.id }>{ s.label }</option> ) }
						</select>
					</label>
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'To stage', 'neural-forms-lite' ) }</span>
						<select value={ wf.settings?.stage_to || '' } onChange={ ( e ) => setSettings( { stage_to: e.target.value } ) }>
							<option value="">{ __( 'Any', 'neural-forms-lite' ) }</option>
							{ ( meta.stages || [] ).map( ( s ) => <option key={ s.id } value={ s.id }>{ s.label }</option> ) }
						</select>
					</label>
				</div>
			) }

			<h5 className="nf-wf-editor__step"><span>2</span>{ __( 'If', 'neural-forms-lite' ) } <small>{ __( '(optional)', 'neural-forms-lite' ) }</small></h5>
			<ConditionEditor
				value={ { ...toEditableConditional( wf.conditions ), enabled: true } }
				onChange={ ( next ) => set( { conditions: next } ) }
				sources={ sources }
				allowGroups
				actionLabels={ null }
				showEnable={ false }
				emptySourcesHint={ __( 'Add a field to the form first.', 'neural-forms-lite' ) }
			/>
			{ wf.trigger === 'submission_received' && wf.conditions && /ai_(category|priority|score|is_spam)/.test( JSON.stringify( wf.conditions ) ) && (
				<p className="nf-inspector__cl-hint nf-inspector__cl-hint--pro">
					{ __( 'AI results are not available yet when a submission is received. Use the "AI analysis completed" trigger for these conditions.', 'neural-forms-lite' ) }
				</p>
			) }

			<h5 className="nf-wf-editor__step"><span>3</span>{ __( 'Then', 'neural-forms-lite' ) }</h5>
			<div className="nf-wf-actions">
				{ wf.actions.map( ( a, i ) => {
					const am = actionsMeta.find( ( x ) => x.type === a.type );
					return (
						<div className="nf-wf-action" key={ i }>
							<div className="nf-wf-action__head">
								<strong>{ i + 1 }.</strong>
								<select value={ a.type } onChange={ ( e ) => changeActionType( i, e.target.value ) }>
									{ ! am && <option value={ a.type }>{ sprintf( __( '%s (not available)', 'neural-forms-lite' ), a.type ) }</option> }
									{ actionsMeta.map( ( x ) => <option key={ x.type } value={ x.type }>{ x.label }</option> ) }
								</select>
								<button type="button" onClick={ () => moveAction( i, -1 ) } disabled={ i === 0 } aria-label={ __( 'Move up', 'neural-forms-lite' ) }>↑</button>
								<button type="button" onClick={ () => moveAction( i, 1 ) } disabled={ i === wf.actions.length - 1 } aria-label={ __( 'Move down', 'neural-forms-lite' ) }>↓</button>
								<button type="button" onClick={ () => removeAction( i ) } aria-label={ __( 'Remove action', 'neural-forms-lite' ) }>×</button>
							</div>
							{ am && <p className="nf-form-settings__hint">{ am.description }</p> }
							{ ( am?.settings || [] ).map( ( f ) => (
								<label className="nf-inspector__field" key={ f.key }>
									<span className="nf-inspector__label">{ f.label }{ f.required ? ' *' : '' }</span>
									<SettingField field={ f } value={ a.settings?.[ f.key ] } onChange={ ( v ) => setActionSetting( i, f.key, v ) } meta={ meta } />
									{ f.hint && <span className="nf-form-settings__hint">{ f.hint }</span> }
								</label>
							) ) }
							<label className="nf-wf-action__cof">
								<input type="checkbox" checked={ !! a.continue_on_failure } onChange={ ( e ) => setAction( i, { continue_on_failure: e.target.checked } ) } />
								<span>{ __( 'If this action fails, still run the next ones', 'neural-forms-lite' ) }</span>
							</label>
						</div>
					);
				} ) }
			</div>
			<button type="button" className="nf-form-settings__rules-add" onClick={ addAction } disabled={ wf.actions.length >= ( meta.limits?.max_actions || 10 ) }>
				{ __( '+ Add action', 'neural-forms-lite' ) }
			</button>

			{ error && <p className="nf-wf__error" role="alert">{ error }</p> }

			<div className="nf-wf-editor__footer">
				<button type="button" className="nf-wf__btn nf-wf__btn--primary" onClick={ save } disabled={ busy }>
					{ wf.id ? __( 'Save workflow', 'neural-forms-lite' ) : __( 'Create workflow', 'neural-forms-lite' ) }
				</button>
				<button type="button" className="nf-wf__btn" onClick={ onCancel } disabled={ busy }>{ __( 'Back to list', 'neural-forms-lite' ) }</button>
				<span className="nf-wf-editor__test">
					<input type="number" min="1" placeholder={ __( 'Entry # (optional)', 'neural-forms-lite' ) } value={ testEntryId } onChange={ ( e ) => setTestEntryId( e.target.value ) } />
					<button type="button" className="nf-wf__btn" onClick={ runTest } disabled={ busy || ! wf.id } title={ wf.id ? '' : __( 'Save first', 'neural-forms-lite' ) }>
						{ __( 'Test run', 'neural-forms-lite' ) }
					</button>
				</span>
			</div>

			{ test && (
				<div className="nf-wf-test">
					<h5>{ test.sample ? __( 'Test run on sample data', 'neural-forms-lite' ) : sprintf( __( 'Test run on entry #%d', 'neural-forms-lite' ), test.entry_id ) }</h5>
					<p className="nf-form-settings__hint">{ __( 'Nothing was sent, changed or charged. This is what would happen with the current (unsaved) settings.', 'neural-forms-lite' ) }</p>
					<p><strong>{ test.matched ? __( 'Conditions match.', 'neural-forms-lite' ) : __( 'Conditions do not match — no action would run.', 'neural-forms-lite' ) }</strong></p>
					{ test.conditions?.length > 0 && (
						<ul className="nf-wf-test__rules">
							{ test.conditions.map( ( r, i ) => (
								<li key={ i } className={ r.result ? 'is-pass' : 'is-fail' }>
									<code>{ r.field }</code> { r.operator } { r.value !== '' ? <code>{ r.value }</code> : null }
									{ ' — ' }{ sprintf( __( 'actual: %s', 'neural-forms-lite' ), r.actual === null || r.actual === '' ? __( '(empty)', 'neural-forms-lite' ) : r.actual ) }
									{ ' → ' }{ r.result ? __( 'pass', 'neural-forms-lite' ) : __( 'fail', 'neural-forms-lite' ) }
								</li>
							) ) }
						</ul>
					) }
					<ol className="nf-wf-test__actions">
						{ test.planned_actions?.map( ( a ) => (
							<li key={ a.key } className={ a.available ? '' : 'is-fail' }>{ a.description }</li>
						) ) }
					</ol>
				</div>
			) }
		</div>
	);
}

/* ── history ─────────────────────────────────────────────────────── */
function RunHistory( { workflow, onRetried } ) {
	const [ runs, setRuns ]   = useState( null );
	const [ error, setError ] = useState( '' );
	const [ nonce, setNonce ] = useState( 0 );
	useEffect( () => {
		let cancelled = false;
		api( `/workflows/${ workflow.id }/runs?limit=30` ).then( ( r ) => { if ( ! cancelled ) setRuns( Array.isArray( r ) ? r : [] ); } )
			.catch( ( e ) => { if ( ! cancelled ) setError( e?.message || __( 'Could not load history.', 'neural-forms-lite' ) ); } );
		return () => { cancelled = true; };
	}, [ workflow.id, nonce ] );

	const retry = ( actionId ) => {
		api( `/workflow-actions/${ actionId }/retry`, { method: 'POST' } ).then( () => { setNonce( ( n ) => n + 1 ); if ( onRetried ) onRetried(); } ).catch( ( e ) => setError( e?.message || '' ) );
	};

	if ( error ) return <p className="nf-wf__error">{ error }</p>;
	if ( ! runs ) return <p className="nf-form-settings__hint">{ __( 'Loading history…', 'neural-forms-lite' ) }</p>;
	if ( ! runs.length ) return <p className="nf-form-settings__hint">{ __( 'This workflow has not run yet.', 'neural-forms-lite' ) }</p>;

	return (
		<ul className="nf-wf-runs">
			{ runs.map( ( r ) => (
				<li key={ r.id } className="nf-wf-run">
					<div className="nf-wf-run__head">
						<StatusPill status={ r.status } />
						<span>{ sprintf( __( 'Entry #%d', 'neural-forms-lite' ), r.entry_id ) }</span>
						<span className="nf-wf-run__time">{ fmt( r.created_at ) }</span>
						{ r.lineage && <span className="nf-wf-run__lineage" title={ r.lineage }>{ __( 'triggered by a workflow', 'neural-forms-lite' ) }</span> }
					</div>
					{ r.actions?.length > 0 && (
						<ol className="nf-wf-run__actions">
							{ r.actions.map( ( a ) => (
								<li key={ a.id }>
									<StatusPill status={ a.status } />
									<code>{ a.action_type }</code>
									{ a.result && <span className="nf-wf-run__result"> — { a.result }</span> }
									{ [ 'failed', 'uncertain', 'skipped' ].includes( a.status ) && (
										<button type="button" className="nf-wf__btn nf-wf__btn--tiny" onClick={ () => retry( a.id ) }>{ __( 'Retry', 'neural-forms-lite' ) }</button>
									) }
								</li>
							) ) }
						</ol>
					) }
				</li>
			) ) }
		</ul>
	);
}

/* ── section ─────────────────────────────────────────────────────── */
export default function WorkflowsSection( { formId, fields } ) {
	const caps     = readCaps();
	const unlocked = !! caps.workflows;
	const [ meta, setMeta ]   = useState( null );
	const [ list, setList ]   = useState( null );
	const [ error, setError ] = useState( '' );
	const [ editing, setEditing ] = useState( null ); // workflow object or null
	const [ history, setHistory ] = useState( null ); // workflow id

	const sources = useMemo( () => {
		const fieldSources = flattenFields( Array.isArray( fields ) ? fields : [] ).filter( ( n ) => n && n.id && n.type !== 'hidden' );
		const metaSources  = ( meta?.meta_sources || [] ).map( ( m ) => ( { id: m.id, label: m.label, type: m.type, options: m.options } ) );
		return [ ...fieldSources, ...metaSources ];
	}, [ fields, meta ] );

	const reload = () => {
		if ( ! formId ) return;
		api( `/workflows?form_id=${ formId }` ).then( ( r ) => setList( Array.isArray( r ) ? r : [] ) ).catch( ( e ) => setError( e?.message || '' ) );
	};

	useEffect( () => {
		if ( ! unlocked || ! formId ) return;
		api( '/workflows/meta' ).then( setMeta ).catch( ( e ) => setError( e?.message || '' ) );
		reload();
	}, [ unlocked, formId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! unlocked ) {
		return (
			<div className="nf-form-settings__pro-note">
				{ __( 'Workflows let this form act on its own: assign qualified leads to a team member, set a follow-up, notify your team, send a fixed acknowledgement, prepare an AI draft for review or sync to your CRM — all based on the answers. Available in Neural Forms Pro.', 'neural-forms-lite' ) }
			</div>
		);
	}
	if ( ! formId ) {
		return <p className="nf-form-settings__hint">{ __( 'Save the form once, then come back here to add workflows.', 'neural-forms-lite' ) }</p>;
	}

	const toggle = ( w ) => {
		api( `/workflows/${ w.id }`, { method: 'PUT', data: { enabled: ! w.enabled } } ).then( reload ).catch( ( e ) => setError( e?.message || '' ) );
	};
	const remove = ( w ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( __( 'Delete the workflow "%s"? Pending runs are cancelled. Past history is removed.', 'neural-forms-lite' ), w.name ) ) ) return;
		api( `/workflows/${ w.id }`, { method: 'DELETE' } ).then( reload ).catch( ( e ) => setError( e?.message || '' ) );
	};

	if ( editing && meta ) {
		return (
			<WorkflowEditor
				formId={ formId }
				initial={ editing }
				meta={ meta }
				sources={ sources }
				onSaved={ ( saved ) => { setEditing( saved ); reload(); } }
				onCancel={ () => { setEditing( null ); reload(); } }
			/>
		);
	}

	return (
		<div className="nf-wf">
			{ error && <p className="nf-wf__error" role="alert">{ error }</p> }
			{ ! list && <p className="nf-form-settings__hint">{ __( 'Loading workflows…', 'neural-forms-lite' ) }</p> }
			{ list && list.length === 0 && (
				<p className="nf-form-settings__hint">{ __( 'No workflows yet. Example: when a submission is received, if Service is "Website" and Budget ≥ 5000, assign to Sales, send the acknowledgement and set a follow-up for tomorrow.', 'neural-forms-lite' ) }</p>
			) }
			{ list && list.length > 0 && (
				<ul className="nf-wf-list">
					{ list.map( ( w ) => {
						const trig = meta?.triggers?.find( ( t ) => t.id === w.trigger );
						const cond = w.conditions ? describeConditional( w.conditions, sources ) : '';
						return (
							<li key={ w.id } className={ 'nf-wf-item' + ( w.enabled ? '' : ' nf-wf-item--off' ) }>
								<div className="nf-wf-item__main">
									<strong>{ w.name }</strong>
									<span className="nf-wf-item__meta">
										{ trig ? trig.label : w.trigger }
										{ cond ? ` · ${ __( 'if', 'neural-forms-lite' ) } ${ cond }` : '' }
										{ ` · ${ sprintf( __( '%d action(s)', 'neural-forms-lite' ), w.actions.length ) }` }
									</span>
									<span className="nf-wf-item__runs">
										{ w.runs?.total
											? sprintf( __( '%1$d run(s), last %2$s', 'neural-forms-lite' ), w.runs.total, fmt( w.runs.last_run_at ) )
											: __( 'Never run', 'neural-forms-lite' ) }
										{ w.runs?.by_status?.failed ? ` · ${ sprintf( __( '%d failed', 'neural-forms-lite' ), w.runs.by_status.failed ) }` : '' }
										{ w.runs?.by_status?.partial ? ` · ${ sprintf( __( '%d partly done', 'neural-forms-lite' ), w.runs.by_status.partial ) }` : '' }
									</span>
								</div>
								<div className="nf-wf-item__actions">
									<label className="nf-wf-item__toggle">
										<input type="checkbox" checked={ !! w.enabled } onChange={ () => toggle( w ) } />
										<span>{ w.enabled ? __( 'On', 'neural-forms-lite' ) : __( 'Off', 'neural-forms-lite' ) }</span>
									</label>
									<button type="button" className="nf-wf__btn" onClick={ () => setEditing( w ) }>{ __( 'Edit', 'neural-forms-lite' ) }</button>
									<button type="button" className="nf-wf__btn" onClick={ () => setHistory( history === w.id ? null : w.id ) }>{ __( 'History', 'neural-forms-lite' ) }</button>
									<button type="button" className="nf-wf__btn nf-wf__btn--danger" onClick={ () => remove( w ) }>{ __( 'Delete', 'neural-forms-lite' ) }</button>
								</div>
								{ history === w.id && <RunHistory workflow={ w } onRetried={ reload } /> }
							</li>
						);
					} ) }
				</ul>
			) }
			<button type="button" className="nf-form-settings__rules-add" onClick={ () => setEditing( emptyWorkflow( formId, meta?.actions?.[ 0 ]?.type ) ) } disabled={ ! meta }>
				{ __( '+ New workflow', 'neural-forms-lite' ) }
			</button>
		</div>
	);
}
