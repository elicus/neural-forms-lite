/**
 * Form builder screen - opens when the URL is
 * ?page=neural-forms&edit={id} (or &new=1 for a fresh form).
 *
 * Top-level state owner. Tabs:
 *
 *   build     [BuilderHeader][Sidebar | Canvas | Inspector]
 *   settings  [BuilderHeader][FormSettings full width]
 *
 * The header's Preview button opens the PreviewModal on either tab.
 *
 * IMPORTANT contract (locked by tests/CompatibilityTest.php):
 *   - Loading: GET response stored verbatim. No mutation on open.
 *   - Saving: PUT/POST sends the FULL form object. Settings keys we
 *     don't expose round-trip untouched.
 *   - Row/column nodes never become entry data - that's the schema
 *     pipeline's job (SchemaHelper::flatten_fields server-side,
 *     EntryRepository::strip_layout_keys defense-in-depth).
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import BuilderHeader from './BuilderHeader';
import BuilderSidebar from './BuilderSidebar';
import BuilderCanvas from './BuilderCanvas';
import BuilderInspector from './BuilderInspector';
import FormSettings from './FormSettings';
import BuilderDndProvider from './BuilderDnd';
import PreviewModal from './PreviewModal';
import BuilderTemplateModal from './BuilderTemplateModal';
import { newField } from './FieldTypes';
import { makeRow, createStep, createSection, isStep } from './schemaHelper';
import { buildTemplate } from './formTemplates';

function collectIds( nodes ) {
	const ids = [];
	const walk = ( arr ) => {
		for ( const n of arr ) {
			if ( ! n ) continue;
			if ( n.type === 'step' ) {
				ids.push( n.id );
				walk( n.fields || [] );
				continue;
			}
			if ( n.type === 'row' ) {
				ids.push( n.id );
				for ( const c of ( n.columns || [] ) ) {
					ids.push( c.id );
					for ( const f of ( c.fields || [] ) ) ids.push( f.id );
				}
				continue;
			}
			// section, real field, or anything else with an id at this level.
			if ( n.id ) ids.push( n.id );
		}
	};
	walk( nodes );
	return ids;
}

export default function Builder( { formId, isNew } ) {
	const [ form,         setForm         ] = useState( null );
	const [ loading,      setLoading      ] = useState( ! isNew );
	const [ error,        setError        ] = useState( '' );
	const [ saving,       setSaving       ] = useState( false );
	const [ saved,        setSaved        ] = useState( false );
	const [ tab,              setTab              ] = useState( 'build' );
	const [ selectedId,       setSelectedId       ] = useState( null );
	const [ selectedColumnId, setSelectedColumnId ] = useState( null );
	const [ selectedStepId,   setSelectedStepId   ] = useState( null );
	const [ previewOpen,      setPreviewOpen      ] = useState( false );
	const [ templateModalOpen, setTemplateModalOpen ] = useState( false );

	// Four-way mutually exclusive selection. Selecting any one clears
	// the others. The inspector reads all four to decide which panel
	// (field / row / column / step / empty) to render.
	const selectNode = ( id ) => {
		setSelectedId( id );
		if ( id !== null ) {
			setSelectedColumnId( null );
			setSelectedStepId( null );
		}
	};
	const selectColumn = ( id ) => {
		setSelectedColumnId( id );
		if ( id !== null ) {
			setSelectedId( null );
			setSelectedStepId( null );
		}
	};
	const selectStep = ( id ) => {
		setSelectedStepId( id );
		if ( id !== null ) {
			setSelectedId( null );
			setSelectedColumnId( null );
		}
	};

	// Track the LIVE form id separately from the mount-time `formId`
	// prop. After a successful create-save the URL flips to ?edit=N
	// but `isNew` and `formId` are immutable props, so we cannot rely
	// on them to pick PUT vs POST for a second save. Without this,
	// repeated saves on a new form would POST again and create
	// duplicate rows in wp_neural_forms_forms.
	const [ liveFormId, setLiveFormId ] = useState( formId > 0 ? formId : 0 );

	useEffect( () => {
		if ( isNew ) {
			setForm( {
				name: __( 'Untitled form', 'neural-forms-lite' ),
				slug: '',
				// New builder forms default to active. The user can
				// still flip to draft/archived from the header status
				// dropdown before saving.
				status: 'active',
				form_schema: {
					// Empty fields array so the empty-state chooser tile
					// is the user's first impression. They can spawn the
					// default text field via the "Blank form" tile or
					// pick any field from the left palette.
					fields: [],
				},
				settings: {},
			} );
			return;
		}

		let cancelled = false;
		api( `/forms/${ formId }` )
			.then( ( res ) => {
				if ( cancelled ) return;
				// IMPORTANT: store the response verbatim. Any normalisation
				// here would mutate a v1.0.0 flat schema on open, which
				// CompatibilityTest 1d forbids.
				setForm( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || __( 'Could not load form.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ formId, isNew ] );

	if ( loading ) {
		return <div className="neural-forms-screen">{ __( 'Loading...', 'neural-forms-lite' ) }</div>;
	}
	if ( error ) {
		return <div className="neural-forms-screen neural-forms-screen--error">{ error }</div>;
	}
	if ( ! form ) {
		return null;
	}

	const fields = form.form_schema?.fields || [];

	const setFields = ( next ) => {
		setForm( { ...form, form_schema: { ...form.form_schema, fields: next } } );
		setSaved( false );
	};

	const setSettings = ( nextSettings ) => {
		// Spread existing settings first so any keys we don't expose
		// (autoresponder, sender_email, _previous_status, ...) round-
		// trip untouched. Locked by CompatibilityTest 6a-6i.
		setForm( { ...form, settings: { ...( form.settings || {} ), ...nextSettings } } );
		setSaved( false );
	};

	// Schema-level settings live on form_schema.settings (e.g.
	// multi-step progress indicator). Distinct from form.settings
	// which is reserved for submission/security/email.
	const formSchemaSettings = ( form.form_schema && typeof form.form_schema.settings === 'object' && form.form_schema.settings !== null )
		? form.form_schema.settings
		: {};

	const setFormSchemaSettings = ( patch ) => {
		setForm( {
			...form,
			form_schema: {
				...( form.form_schema || {} ),
				settings: { ...formSchemaSettings, ...patch },
			},
		} );
		setSaved( false );
	};

	// Locate a column by id. Returns one of:
	//   { kind: 'top-row', rowIdx, colIdx }       (row at root)
	//   { kind: 'step-row', stepIdx, rowChildIdx, colIdx }  (row inside step)
	//   null
	const locateColumn = ( colId ) => {
		if ( ! colId ) return null;
		for ( let i = 0; i < fields.length; i += 1 ) {
			const n = fields[ i ];
			if ( ! n ) continue;
			if ( n.type === 'row' ) {
				const cols = Array.isArray( n.columns ) ? n.columns : [];
				for ( let c = 0; c < cols.length; c += 1 ) {
					if ( cols[ c ]?.id === colId ) return { kind: 'top-row', rowIdx: i, colIdx: c };
				}
				continue;
			}
			if ( n.type === 'step' ) {
				const children = Array.isArray( n.fields ) ? n.fields : [];
				for ( let r = 0; r < children.length; r += 1 ) {
					const child = children[ r ];
					if ( ! child || child.type !== 'row' ) continue;
					const cols = Array.isArray( child.columns ) ? child.columns : [];
					for ( let c = 0; c < cols.length; c += 1 ) {
						if ( cols[ c ]?.id === colId ) {
							return { kind: 'step-row', stepIdx: i, rowChildIdx: r, colIdx: c };
						}
					}
				}
			}
		}
		return null;
	};

	const locateStep = ( stepId ) => {
		if ( ! stepId ) return -1;
		return fields.findIndex( ( n ) => n && n.type === 'step' && n.id === stepId );
	};

	const addField = ( type ) => {
		// Selection priority: column wins, then step, otherwise root.
		// The id pool is the FULL schema so generated ids stay unique
		// across roots, steps, rows, and columns.
		const next = newField( type, collectIds( fields ) );

		const colTarget = locateColumn( selectedColumnId );
		if ( colTarget && colTarget.kind === 'top-row' ) {
			const nextFields = fields.slice();
			const row = { ...nextFields[ colTarget.rowIdx ] };
			const cols = row.columns.slice();
			cols[ colTarget.colIdx ] = {
				...cols[ colTarget.colIdx ],
				fields: [ ...( cols[ colTarget.colIdx ].fields || [] ), next ],
			};
			row.columns = cols;
			nextFields[ colTarget.rowIdx ] = row;
			setFields( nextFields );
			selectNode( next.id );
			return;
		}
		if ( colTarget && colTarget.kind === 'step-row' ) {
			const nextFields = fields.slice();
			const step = { ...nextFields[ colTarget.stepIdx ] };
			const children = step.fields.slice();
			const row = { ...children[ colTarget.rowChildIdx ] };
			const cols = row.columns.slice();
			cols[ colTarget.colIdx ] = {
				...cols[ colTarget.colIdx ],
				fields: [ ...( cols[ colTarget.colIdx ].fields || [] ), next ],
			};
			row.columns = cols;
			children[ colTarget.rowChildIdx ] = row;
			step.fields = children;
			nextFields[ colTarget.stepIdx ] = step;
			setFields( nextFields );
			selectNode( next.id );
			return;
		}

		// Step selected, no column? Append into step.fields.
		const stepIdx = locateStep( selectedStepId );
		if ( stepIdx !== -1 ) {
			const nextFields = fields.slice();
			const step = { ...nextFields[ stepIdx ] };
			step.fields = [ ...( step.fields || [] ), next ];
			nextFields[ stepIdx ] = step;
			setFields( nextFields );
			selectNode( next.id );
			return;
		}

		// Default: append to root.
		setFields( [ ...fields, next ] );
		selectNode( next.id );
	};

	const addRow = ( colCount ) => {
		const row = makeRow( colCount );

		// If a step is selected, append the row INTO that step.fields.
		const stepIdx = locateStep( selectedStepId );
		if ( stepIdx !== -1 ) {
			const nextFields = fields.slice();
			const step = { ...nextFields[ stepIdx ] };
			step.fields = [ ...( step.fields || [] ), row ];
			nextFields[ stepIdx ] = step;
			setFields( nextFields );
			selectNode( row.id );
			return;
		}

		// Default: append to root.
		setFields( [ ...fields, row ] );
		selectNode( row.id );
	};

	const addSection = () => {
		// Routing matches addField: column > step > root. Sections
		// are leaf layout nodes - they may live anywhere a field can.
		const next = createSection();

		const colTarget = locateColumn( selectedColumnId );
		if ( colTarget && colTarget.kind === 'top-row' ) {
			const nextFields = fields.slice();
			const row  = { ...nextFields[ colTarget.rowIdx ] };
			const cols = row.columns.slice();
			cols[ colTarget.colIdx ] = {
				...cols[ colTarget.colIdx ],
				fields: [ ...( cols[ colTarget.colIdx ].fields || [] ), next ],
			};
			row.columns = cols;
			nextFields[ colTarget.rowIdx ] = row;
			setFields( nextFields );
			selectNode( next.id );
			return;
		}
		if ( colTarget && colTarget.kind === 'step-row' ) {
			const nextFields = fields.slice();
			const step  = { ...nextFields[ colTarget.stepIdx ] };
			const innerChildren = step.fields.slice();
			const row = { ...innerChildren[ colTarget.rowChildIdx ] };
			const cols = row.columns.slice();
			cols[ colTarget.colIdx ] = {
				...cols[ colTarget.colIdx ],
				fields: [ ...( cols[ colTarget.colIdx ].fields || [] ), next ],
			};
			row.columns = cols;
			innerChildren[ colTarget.rowChildIdx ] = row;
			step.fields = innerChildren;
			nextFields[ colTarget.stepIdx ] = step;
			setFields( nextFields );
			selectNode( next.id );
			return;
		}

		const stepIdx = locateStep( selectedStepId );
		if ( stepIdx !== -1 ) {
			const nextFields = fields.slice();
			const step = { ...nextFields[ stepIdx ] };
			step.fields = [ ...( step.fields || [] ), next ];
			nextFields[ stepIdx ] = step;
			setFields( nextFields );
			selectNode( next.id );
			return;
		}

		setFields( [ ...fields, next ] );
		selectNode( next.id );
	};

	const addStep = () => {
		// Steps live only at the top level, regardless of current
		// selection. The validator rejects nested steps - so even if a
		// step is selected, we always append at root.
		const count = fields.filter( isStep ).length + 1;
		const step  = createStep( 'Step ' + count );
		setFields( [ ...fields, step ] );
		selectStep( step.id );
	};

	const addBlankStarter = () => {
		// Empty-canvas "Blank form" tile spawns the default text field
		// so the user always lands on something editable.
		addField( 'text' );
	};

	const addMultistepStarter = () => {
		// Empty-canvas "Multi-step" tile spawns two empty steps so the
		// user has a tangible starting point. Title defaults match the
		// spec; the user can rename via the inspector.
		const step1 = createStep( 'Step 1' );
		const step2 = createStep( 'Step 2' );
		setFields( [ step1, step2 ] );
		selectStep( step1.id );
	};

	// Resolve the active template id from form_schema.meta.template,
	// falling back to "" when nothing is recorded.
	const currentTemplateId = ( form.form_schema && form.form_schema.meta && typeof form.form_schema.meta.template === 'string' )
		? form.form_schema.meta.template
		: '';

	const applyTemplate = ( templateId ) => {
		// "blank" is special-cased to a single-text starter so the
		// builder always lands on something editable. Same replace
		// flow as any other template: if the canvas has fields,
		// confirm before clobbering.
		if ( templateId === 'blank' ) {
			if ( fields.length > 0 ) {
				// eslint-disable-next-line no-alert
				const ok = window.confirm( __( 'Changing template will replace the current fields and layout. Your form settings will stay unchanged. Continue?', 'neural-forms-lite' ) );
				if ( ! ok ) return;
			}
			const starter = newField( 'text', [] );
			const nextSchemaBlank = {
				...( form.form_schema || {} ),
				fields: [ starter ],
				meta: {
					...( ( form.form_schema && form.form_schema.meta ) || {} ),
					template: 'blank',
				},
			};
			setForm( { ...form, form_schema: nextSchemaBlank } );
			setSelectedId( starter.id );
			setSelectedColumnId( null );
			setSelectedStepId( null );
			setTemplateModalOpen( false );
			setSaved( false );
			return;
		}

		const tpl = buildTemplate( templateId );
		if ( ! tpl ) return; // unknown / locked id - no-op

		// Confirm before clobbering an in-progress canvas. The empty
		// canvas (no fields) is a free pass.
		if ( fields.length > 0 ) {
			// eslint-disable-next-line no-alert
			const ok = window.confirm( __( 'Changing template will replace the current fields and layout. Your form settings will stay unchanged. Continue?', 'neural-forms-lite' ) );
			if ( ! ok ) return;
		}

		// Replace name only when the form name is empty or still the
		// "Untitled form" default - never overwrite a name the user
		// has actually typed.
		const isDefaultName = ! form.name || form.name === __( 'Untitled form', 'neural-forms-lite' );
		const nextName = ( isDefaultName && tpl.name ) ? tpl.name : form.name;

		// form.settings (captcha / on_success / on_error / autoresponder)
		// are LEFT UNTOUCHED. The template only writes form_schema.
		const nextSchema = {
			...( form.form_schema || {} ),
			fields: Array.isArray( tpl.fields ) ? tpl.fields : [],
			meta: {
				...( ( form.form_schema && form.form_schema.meta ) || {} ),
				template: templateId,
			},
		};
		if ( tpl.settings && typeof tpl.settings === 'object' ) {
			nextSchema.settings = {
				...( ( form.form_schema && form.form_schema.settings ) || {} ),
				...tpl.settings,
			};
		}

		setForm( { ...form, name: nextName, form_schema: nextSchema } );
		// Clear selection so the inspector lands on its empty state
		// rather than a stale id from the previous schema.
		setSelectedId( null );
		setSelectedColumnId( null );
		setSelectedStepId( null );
		setTemplateModalOpen( false );
		setSaved( false );
	};

	const clearForm = () => {
		// Empty canvas = nothing to clear; opening the prompt would be
		// pointless.
		if ( fields.length === 0 ) return;
		// eslint-disable-next-line no-alert
		const ok = window.confirm( __( 'Clear all fields and layout from this form? Your form settings will stay unchanged.', 'neural-forms-lite' ) );
		if ( ! ok ) return;

		// Reset form_schema.fields and form_schema.meta.template, but
		// preserve any other schema-level settings the user has
		// configured. form.settings (submission/security/email) is
		// untouched.
		const nextSchema = {
			...( form.form_schema || {} ),
			fields: [],
			meta: {
				...( ( form.form_schema && form.form_schema.meta ) || {} ),
				template: '',
			},
		};
		setForm( { ...form, form_schema: nextSchema } );
		setSelectedId( null );
		setSelectedColumnId( null );
		setSelectedStepId( null );
		setSaved( false );
	};

	const goBack = () => {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'edit' );
		url.searchParams.delete( 'new' );
		window.location.href = url.toString();
	};

	const save = () => {
		setSaving( true );
		setError( '' );

		// liveFormId > 0 means the form has a server-side row already
		// (either loaded with ?edit=N, or just created and we captured
		// the id below). Use PUT in that case; POST only for the very
		// first save of a new form.
		const isCreate = liveFormId === 0;
		const path     = isCreate ? '/forms' : `/forms/${ liveFormId }`;
		const method   = isCreate ? 'POST'   : 'PUT';

		api( path, { method, data: form } )
			.then( ( res ) => {
				setSaving( false );
				setSaved( true );
				// Sync the in-memory form with the server's response
				// so any normalisation (slug uniqueness, status
				// coercion) is reflected immediately. The response
				// shape is the same as GET /forms/{id}.
				if ( res && typeof res === 'object' ) {
					setForm( res );
					if ( isCreate && res.id ) {
						setLiveFormId( Number( res.id ) );
						const url = new URL( window.location.href );
						url.searchParams.delete( 'new' );
						url.searchParams.set( 'edit', String( res.id ) );
						window.history.replaceState( {}, '', url.toString() );
					}
				}
				setTimeout( () => setSaved( false ), 2000 );
			} )
			.catch( ( err ) => {
				// eslint-disable-next-line no-console
				if ( err ) console.error( 'Neural Forms save failed:', err );
				setSaving( false );
				setError( err?.message || __( 'Save failed.', 'neural-forms-lite' ) );
			} );
	};

	// The "preview" tab was removed; coerce any stale value (could come
	// from a leftover state slice, a future bug, or a past version) to
	// "build" so the canvas always renders.
	const safeTab = tab === 'settings' ? 'settings' : 'build';

	return (
		<div className="neural-forms-screen neural-forms-screen--builder nf-builder-shell">
			<BuilderHeader
				name={ form.name }
				status={ form.status }
				onNameChange={ ( v ) => setForm( { ...form, name: v } ) }
				onStatusChange={ ( next ) => {
					// Status is the only top-level field that lives outside
					// form_schema/settings, so updating it must keep both
					// untouched. Setting saved=false also flags the change
					// for the next Save.
					setForm( { ...form, status: next } );
					setSaved( false );
				} }
				activeTab={ safeTab }
				onTabChange={ setTab }
				onPreview={ () => setPreviewOpen( true ) }
				onSave={ save }
				onBack={ goBack }
				saving={ saving }
				saved={ saved }
				formId={ liveFormId }
			/>

			{ safeTab === 'build' && (
				<BuilderDndProvider
					nodes={ fields }
					onChange={ setFields }
					onSelectField={ selectNode }
					onSelectStep={ selectStep }
				>
					<div className="nf-builder-body">
						<BuilderSidebar onAddField={ addField } onAddRow={ addRow } onAddStep={ addStep } onAddSection={ addSection } />
						<main className="nf-builder-main">
							<BuilderCanvas
								nodes={ fields }
								onChange={ setFields }
								selectedId={ selectedId }
								onSelect={ selectNode }
								selectedColumnId={ selectedColumnId }
								onSelectColumn={ selectColumn }
								selectedStepId={ selectedStepId }
								onSelectStep={ selectStep }
								onAddBlankStarter={ addBlankStarter }
								onAddMultistepStarter={ addMultistepStarter }
								onApplyTemplate={ applyTemplate }
								templateId={ currentTemplateId }
								onRequestChangeTemplate={ () => setTemplateModalOpen( true ) }
								onRequestClear={ clearForm }
							/>
						</main>
						<BuilderInspector
							nodes={ fields }
							selectedId={ selectedId }
							selectedColumnId={ selectedColumnId }
							selectedStepId={ selectedStepId }
							onChange={ setFields }
							formSchemaSettings={ formSchemaSettings }
							onChangeFormSchemaSettings={ setFormSchemaSettings }
							formId={ liveFormId }
						/>
					</div>
				</BuilderDndProvider>
			) }

			{ safeTab === 'settings' && (
				<div className="nf-builder-body nf-builder-body--settings">
					<FormSettings
						settings={ form.settings || {} }
						onChange={ setSettings }
						formSchemaSettings={ formSchemaSettings }
						onChangeFormSchemaSettings={ setFormSchemaSettings }
						hasQuiz={ ( () => {
							// Walk top-level fields + step.fields + row.column.fields
							// to detect any quiz_question. Mirror SchemaHelper.flatten_fields.
							const walk = ( nodes ) => {
								for ( let i = 0; i < nodes.length; i++ ) {
									const n = nodes[ i ];
									if ( ! n || typeof n !== 'object' ) continue;
									if ( n.type === 'quiz_question' ) return true;
									if ( n.type === 'step'   && Array.isArray( n.fields ) )  { if ( walk( n.fields ) ) return true; }
									if ( n.type === 'row'    && Array.isArray( n.columns ) ) {
										for ( let c = 0; c < n.columns.length; c++ ) {
											const col = n.columns[ c ];
											if ( col && Array.isArray( col.fields ) && walk( col.fields ) ) return true;
										}
									}
								}
								return false;
							};
							return walk( fields );
						} )() }
					/>
				</div>
			) }

			<PreviewModal
				open={ previewOpen }
				form={ form }
				onClose={ () => setPreviewOpen( false ) }
			/>

			<BuilderTemplateModal
				open={ templateModalOpen }
				onApplyTemplate={ applyTemplate }
				onCancel={ () => setTemplateModalOpen( false ) }
			/>
		</div>
	);
}
