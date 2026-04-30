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
import PreviewModal from './PreviewModal';
import { newField } from './FieldTypes';
import { makeRow } from './schemaHelper';

function collectIds( nodes ) {
	const ids = [];
	const walk = ( arr ) => {
		for ( const n of arr ) {
			if ( ! n ) continue;
			if ( n.type === 'row' ) {
				ids.push( n.id );
				for ( const c of ( n.columns || [] ) ) {
					ids.push( c.id );
					for ( const f of ( c.fields || [] ) ) ids.push( f.id );
				}
				continue;
			}
			ids.push( n.id );
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
	const [ tab,             setTab             ] = useState( 'build' );
	const [ selectedId,      setSelectedId      ] = useState( null );
	const [ selectedColumnId, setSelectedColumnId ] = useState( null );
	const [ previewOpen,     setPreviewOpen     ] = useState( false );

	// Selecting a column and selecting a node are mutually exclusive in
	// the inspector: column-selected disables the node inspector, and
	// vice versa. Wrap the setters so callers don't have to remember.
	const selectNode = ( id ) => {
		setSelectedId( id );
		if ( id !== null ) setSelectedColumnId( null );
	};
	const selectColumn = ( id ) => {
		setSelectedColumnId( id );
		if ( id !== null ) setSelectedId( null );
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

	// Locate which row contains a given column id, returning [rowIdx, colIdx]
	// or null if the column id is not in the current schema. Used to route
	// "add field" clicks into the right column.
	const locateColumn = ( colId ) => {
		if ( ! colId ) return null;
		for ( let i = 0; i < fields.length; i += 1 ) {
			const n = fields[ i ];
			if ( ! n || n.type !== 'row' ) continue;
			const cols = Array.isArray( n.columns ) ? n.columns : [];
			for ( let c = 0; c < cols.length; c += 1 ) {
				if ( cols[ c ]?.id === colId ) return [ i, c ];
			}
		}
		return null;
	};

	const addField = ( type ) => {
		// If a column is currently selected, drop the new field INTO that
		// column rather than appending to the root. The id pool is the
		// full schema so generated ids stay unique across roots + columns.
		const next = newField( type, collectIds( fields ) );

		const target = locateColumn( selectedColumnId );
		if ( target ) {
			const [ rowIdx, colIdx ] = target;
			const nextFields = fields.slice();
			const row = { ...nextFields[ rowIdx ] };
			const cols = row.columns.slice();
			cols[ colIdx ] = {
				...cols[ colIdx ],
				fields: [ ...( cols[ colIdx ].fields || [] ), next ],
			};
			row.columns = cols;
			nextFields[ rowIdx ] = row;
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
		setFields( [ ...fields, row ] );
		selectNode( row.id );
	};

	const addBlankStarter = () => {
		// Empty-canvas "Blank form" tile spawns the default text field
		// so the user always lands on something editable.
		addField( 'text' );
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
			/>

			{ safeTab === 'build' && (
				<div className="nf-builder-body">
					<BuilderSidebar onAddField={ addField } onAddRow={ addRow } />
					<main className="nf-builder-main">
						<BuilderCanvas
							nodes={ fields }
							onChange={ setFields }
							selectedId={ selectedId }
							onSelect={ selectNode }
							selectedColumnId={ selectedColumnId }
							onSelectColumn={ selectColumn }
							onAddBlankStarter={ addBlankStarter }
						/>
					</main>
					<BuilderInspector
						nodes={ fields }
						selectedId={ selectedId }
						selectedColumnId={ selectedColumnId }
						onChange={ setFields }
					/>
				</div>
			) }

			{ safeTab === 'settings' && (
				<div className="nf-builder-body nf-builder-body--settings">
					<FormSettings
						settings={ form.settings || {} }
						onChange={ setSettings }
					/>
				</div>
			) }

			<PreviewModal
				open={ previewOpen }
				form={ form }
				onClose={ () => setPreviewOpen( false ) }
			/>
		</div>
	);
}
