/**
 * Form builder screen - opens when the URL is ?page=neural-forms&edit={id}
 * (or &new=1 for a fresh form).
 *
 * Owns the in-memory schema state. Loads from GET /forms/{id}, lets the
 * user mutate fields via FieldList, and saves via PUT /forms/{id} (or
 * POST /forms for new forms). Everything below the field list (form
 * settings, on-success/on-error config, captcha enable, notifications)
 * is intentionally stubbed for now - see TODO comments. The 1.1.0
 * scope here is the field list + column toggle + dnd.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import FieldList from './FieldList';
import { FIELD_TYPES, newField } from './FieldTypes';

export default function Builder( { formId, isNew } ) {
	const [ form,    setForm    ] = useState( null );
	const [ loading, setLoading ] = useState( ! isNew );
	const [ error,   setError   ] = useState( '' );
	const [ saving,  setSaving  ] = useState( false );
	const [ saved,   setSaved   ] = useState( false );
	const [ addType, setAddType ] = useState( 'text' );

	useEffect( () => {
		if ( isNew ) {
			setForm( {
				name: __( 'Untitled form', 'neural-forms-lite' ),
				slug: '',
				status: 'draft',
				form_schema: {
					fields: [ newField( 'text' ) ],
				},
				settings: {},
			} );
			return;
		}

		let cancelled = false;
		api( `/forms/${ formId }` )
			.then( ( res ) => {
				if ( cancelled ) return;
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

	const addField = () => {
		const existingIds = fields.map( ( f ) => f.id );
		setFields( [ ...fields, newField( addType, existingIds ) ] );
	};

	const save = () => {
		setSaving( true );
		setError( '' );

		const path = isNew ? '/forms' : `/forms/${ formId }`;
		const method = isNew ? 'POST' : 'PUT';

		api( path, { method, data: form } )
			.then( ( res ) => {
				setSaving( false );
				setSaved( true );
				if ( isNew && res?.id ) {
					// Replace the URL so subsequent saves PUT against the new id.
					const url = new URL( window.location.href );
					url.searchParams.delete( 'new' );
					url.searchParams.set( 'edit', String( res.id ) );
					window.history.replaceState( {}, '', url.toString() );
				}
				setTimeout( () => setSaved( false ), 2000 );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setError( err?.message || __( 'Save failed.', 'neural-forms-lite' ) );
			} );
	};

	return (
		<div className="neural-forms-screen neural-forms-screen--builder">
			<header className="neural-forms-screen__header">
				<input
					type="text"
					className="nf-builder__name-input"
					value={ form.name || '' }
					onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) }
				/>
				<div className="nf-builder__actions">
					{ saved && <span className="nf-builder__saved">{ __( 'Saved', 'neural-forms-lite' ) }</span> }
					<button
						type="button"
						className="neural-forms-btn neural-forms-btn--primary"
						onClick={ save }
						disabled={ saving }
					>
						{ saving ? __( 'Saving...', 'neural-forms-lite' ) : __( 'Save', 'neural-forms-lite' ) }
					</button>
				</div>
			</header>

			<section className="nf-builder__section">
				<header className="nf-builder__section-header">
					<h2 className="nf-builder__section-title">{ __( 'Fields', 'neural-forms-lite' ) }</h2>
					<div className="nf-builder__add">
						<select
							value={ addType }
							onChange={ ( e ) => setAddType( e.target.value ) }
							className="nf-builder__add-type"
						>
							{ FIELD_TYPES.map( ( t ) => (
								<option key={ t.value } value={ t.value }>{ t.label }</option>
							) ) }
						</select>
						<button
							type="button"
							className="neural-forms-btn neural-forms-btn--primary"
							onClick={ addField }
						>+ { __( 'Add Field', 'neural-forms-lite' ) }</button>
					</div>
				</header>

				<FieldList fields={ fields } onChange={ setFields } />
			</section>

			{ /* TODO: form settings panel - on-success/on-error, notifications,
			    captcha enable, autoresponder. Out of scope for the 1.1.0
			    column-layout deliverable; existing 1.0.0 settings persist
			    untouched because we PUT the full form object back. */ }
			<section className="nf-builder__section nf-builder__section--placeholder">
				<h2 className="nf-builder__section-title">{ __( 'Form settings', 'neural-forms-lite' ) }</h2>
				<p>{ __( 'Settings UI is being rebuilt. Existing settings are preserved on save.', 'neural-forms-lite' ) }</p>
			</section>
		</div>
	);
}
