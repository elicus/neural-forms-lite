/**
 * CF7 → Neural Forms Import screen.
 *
 * Three-state React flow:
 *   STATE_LIST    — table of CF7 forms (default)
 *   STATE_PREVIEW — preview mapping + warnings before commit
 *   STATE_RESULT  — success / failure summary after import
 *
 * Plus a duplicate-import banner that appears in STATE_PREVIEW when
 * the CF7 form has been imported before; the user can cancel or force
 * a re-import (which calls /import with `force_import: true`).
 *
 * REST endpoints (Lite-owned, in `MigrationEndpoint`):
 *   GET  /migration/cf7/forms
 *   GET  /migration/cf7/preview/{id}
 *   POST /migration/cf7/import/{id}    body: { force_import?: bool }
 *
 * Class namespace: `.neural-forms-import__*` (BEM). Authored CSS lives
 * in `src/import.css` and ships with the per-screen build/import.*
 * bundle. Reuses the shared `--f-*` token system + `.neural-forms-card`
 * + `.neural-forms-button` primitives from index.css that the rich
 * bundle provides; the split bundle includes its own copies of those
 * primitives so the screen renders correctly without index.css.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';

const STATE_LIST    = 'list';
const STATE_PREVIEW = 'preview';
const STATE_RESULT  = 'result';

export default function Import() {
	const cf7Active = !! ( typeof window !== 'undefined'
		&& window.neuralFormsAdmin?.migration?.cf7Active );

	const [ phase,    setPhase    ] = useState( STATE_LIST );
	const [ forms,    setForms    ] = useState( [] );
	const [ message,  setMessage  ] = useState( '' );
	const [ available, setAvailable ] = useState( cf7Active );
	const [ loading,  setLoading  ] = useState( true );
	const [ error,    setError    ] = useState( '' );

	const [ activeId, setActiveId ] = useState( 0 );
	const [ preview,  setPreview  ] = useState( null );

	const [ forceImport, setForceImport ] = useState( false );
	const [ importing,   setImporting   ] = useState( false );
	const [ result,      setResult      ] = useState( null );

	// Initial load — fetch CF7 forms list.
	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		api( '/migration/cf7/forms' )
			.then( ( res ) => {
				if ( cancelled ) return;
				setAvailable( !! res?.available );
				setForms( Array.isArray( res?.forms ) ? res.forms : [] );
				setMessage( res?.message || '' );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || __( 'Failed to load CF7 forms.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [] );

	const goPreview = ( cf7Id ) => {
		setActiveId( cf7Id );
		setPreview( null );
		setError( '' );
		setForceImport( false );
		setLoading( true );
		setPhase( STATE_PREVIEW );

		api( `/migration/cf7/preview/${ cf7Id }` )
			.then( ( res ) => {
				setPreview( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err?.message || __( 'Could not generate preview.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
	};

	const runImport = ( force ) => {
		if ( ! activeId ) return;
		setImporting( true );
		setError( '' );

		api( `/migration/cf7/import/${ activeId }`, {
			method: 'POST',
			data:   { force_import: !! force },
		} )
			.then( ( res ) => {
				setResult( res );
				setImporting( false );
				setPhase( STATE_RESULT );
			} )
			.catch( ( err ) => {
				// 409 duplicate_import response carries the existing imports
				// in `err.data` so the user can cancel or force a re-import.
				if ( err?.code === 'duplicate_import' ) {
					setPreview( ( prev ) => prev ? {
						...prev,
						already_imported: true,
						existing_imports: err?.data?.existing_imports || [],
						duplicate_blocked: true,
					} : prev );
					setImporting( false );
					return;
				}
				setError( err?.message || __( 'Import failed.', 'neural-forms-lite' ) );
				setImporting( false );
			} );
	};

	const backToList = () => {
		setPhase( STATE_LIST );
		setActiveId( 0 );
		setPreview( null );
		setResult( null );
		setForceImport( false );
		setError( '' );
	};

	return (
		<div className="neural-forms-import">
			<header className="neural-forms-import__header">
				<div>
					<h1 className="neural-forms-import__title">
						{ __( 'Import', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-import__desc">
						{ __( 'Migrate forms from Contact Form 7 into Neural Forms.', 'neural-forms-lite' ) }
					</p>
				</div>
			</header>

			{ error && (
				<div className="neural-forms-import__notice neural-forms-import__notice--error">
					{ error }
				</div>
			) }

			{ phase === STATE_LIST && (
				<ListView
					available={ available }
					forms={ forms }
					message={ message }
					loading={ loading }
					onPreview={ goPreview }
				/>
			) }

			{ phase === STATE_PREVIEW && (
				<PreviewView
					preview={ preview }
					loading={ loading }
					importing={ importing }
					onImport={ () => runImport( forceImport ) }
					onForce={ () => { setForceImport( true ); runImport( true ); } }
					onBack={ backToList }
				/>
			) }

			{ phase === STATE_RESULT && (
				<ResultView result={ result } onBack={ backToList } />
			) }
		</div>
	);
}

// ─── List view ──────────────────────────────────────────────────

function ListView( { available, forms, message, loading, onPreview } ) {
	if ( loading ) {
		return (
			<div className="neural-forms-import__loading">
				{ __( 'Loading Contact Form 7 forms…', 'neural-forms-lite' ) }
			</div>
		);
	}

	if ( ! available ) {
		return (
			<div className="neural-forms-card">
				<p className="neural-forms-import__summary">
					{ message || __( 'Contact Form 7 is not installed or not active.', 'neural-forms-lite' ) }
				</p>
				<p className="neural-forms-import__desc" style={ { marginTop: '8px' } }>
					{ __( 'Install and activate Contact Form 7 to import forms.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	if ( forms.length === 0 ) {
		return (
			<div className="neural-forms-card">
				<p className="neural-forms-import__empty">
					{ __( 'No Contact Form 7 forms found.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	return (
		<table className="neural-forms-import__table">
			<thead>
				<tr>
					<th className="neural-forms-import__th">{ __( 'Form', 'neural-forms-lite' ) }</th>
					<th className="neural-forms-import__th">{ __( 'Fields', 'neural-forms-lite' ) }</th>
					<th className="neural-forms-import__th">{ __( 'Field types', 'neural-forms-lite' ) }</th>
					<th className="neural-forms-import__th">{ __( 'Compatibility', 'neural-forms-lite' ) }</th>
					<th className="neural-forms-import__th neural-forms-import__th--actions" />
				</tr>
			</thead>
			<tbody>
				{ forms.map( ( form, idx ) => (
					<tr
						key={ form.id }
						className={
							'neural-forms-import__row'
							+ ( idx === forms.length - 1 ? ' neural-forms-import__row--last' : '' )
						}
					>
						<td className="neural-forms-import__cell">
							<button
								type="button"
								className="neural-forms-import__form-name"
								onClick={ () => onPreview( form.id ) }
							>
								{ form.title || __( '(untitled)', 'neural-forms-lite' ) }
							</button>
						</td>
						<td className="neural-forms-import__cell">
							{ form.field_count }
						</td>
						<td className="neural-forms-import__cell neural-forms-import__types">
							{ ( form.field_types || [] ).join( ', ' ) || '—' }
						</td>
						<td className="neural-forms-import__cell">
							<CompatibilityPill hasUnsupported={ !! form.has_unsupported } />
						</td>
						<td className="neural-forms-import__cell neural-forms-import__cell--actions">
							<button
								type="button"
								className="neural-forms-button neural-forms-button--secondary neural-forms-button--sm"
								onClick={ () => onPreview( form.id ) }
							>
								{ __( 'Preview', 'neural-forms-lite' ) }
							</button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function CompatibilityPill( { hasUnsupported } ) {
	if ( hasUnsupported ) {
		return (
			<span className="neural-forms-status-pill neural-forms-status-pill--archived">
				{ __( 'Partial', 'neural-forms-lite' ) }
			</span>
		);
	}
	return (
		<span className="neural-forms-status-pill neural-forms-status-pill--active">
			{ __( 'Compatible', 'neural-forms-lite' ) }
		</span>
	);
}

// ─── Preview view ───────────────────────────────────────────────

function PreviewView( { preview, loading, importing, onImport, onForce, onBack } ) {
	if ( loading ) {
		return (
			<div className="neural-forms-import__loading">
				{ __( 'Generating preview…', 'neural-forms-lite' ) }
			</div>
		);
	}
	if ( ! preview ) {
		return (
			<div className="neural-forms-import__loading">
				{ __( 'Could not generate preview.', 'neural-forms-lite' ) }
			</div>
		);
	}

	const fieldCount  = preview.field_count ?? 0;
	const fieldsObj   = preview.fields && typeof preview.fields === 'object' ? preview.fields : {};
	const fieldEntries = Object.entries( fieldsObj );
	const skipped     = Array.isArray( preview.skipped )  ? preview.skipped  : [];
	const warnings    = Array.isArray( preview.warnings ) ? preview.warnings : [];
	const showDuplicate = !! preview.already_imported;

	return (
		<div className="neural-forms-import__preview">
			{ showDuplicate && (
				<DuplicateBanner
					imports={ preview.existing_imports || [] }
					formName={ preview.cf7_title }
					onCancel={ onBack }
					onForce={ onForce }
				/>
			) }

			<div className="neural-forms-card">
				<h2 className="neural-forms-card__title">
					{ preview.cf7_title || __( 'Preview', 'neural-forms-lite' ) }
				</h2>
				<p className="neural-forms-import__summary">
					<strong>{ fieldCount }</strong>
					{ ' ' }
					{ fieldCount === 1
						? __( 'field will be imported.', 'neural-forms-lite' )
						: __( 'fields will be imported.', 'neural-forms-lite' ) }
				</p>
			</div>

			{ fieldEntries.length > 0 && (
				<table className="neural-forms-import__table">
					<thead>
						<tr>
							<th className="neural-forms-import__th">{ __( 'Field ID', 'neural-forms-lite' ) }</th>
							<th className="neural-forms-import__th">{ __( 'Type', 'neural-forms-lite' ) }</th>
							<th className="neural-forms-import__th">{ __( 'Label', 'neural-forms-lite' ) }</th>
							<th className="neural-forms-import__th">{ __( 'Required', 'neural-forms-lite' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ fieldEntries.map( ( [ fieldId, field ], idx ) => (
							<tr
								key={ fieldId }
								className={
									'neural-forms-import__row'
									+ ( idx === fieldEntries.length - 1 ? ' neural-forms-import__row--last' : '' )
								}
							>
								<td className="neural-forms-import__cell">
									<code className="neural-forms-import__code">{ fieldId }</code>
								</td>
								<td className="neural-forms-import__cell neural-forms-import__types">
									{ field?.type || '—' }
								</td>
								<td className="neural-forms-import__cell">
									{ field?.label || '—' }
								</td>
								<td className="neural-forms-import__cell">
									{ field?.required ? __( 'Yes', 'neural-forms-lite' ) : '—' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ warnings.length > 0 && (
				<div className="neural-forms-import__notice neural-forms-import__notice--warning">
					<p style={ { margin: 0 } }>
						{ __( 'The imported form may differ from the original CF7 form. Labels are generated from the CF7 tag names (e.g. "your-name" becomes "Your Name"), not from the form\'s own label text — rename them in the builder. Review the field list before publishing.', 'neural-forms-lite' ) }
					</p>
					<ul style={ { margin: '8px 0 0', paddingLeft: '20px' } }>
						{ warnings.map( ( w, i ) => <li key={ i }>{ w }</li> ) }
					</ul>
				</div>
			) }

			{ skipped.length > 0 && (
				<div className="neural-forms-card">
					<h3 className="neural-forms-import__section-title">
						{ __( 'Skipped Fields', 'neural-forms-lite' ) }
					</h3>
					<ul style={ { margin: 0, paddingLeft: '20px', color: 'var(--f-gray-500)', fontSize: '13px', lineHeight: '1.6' } }>
						{ skipped.map( ( s, i ) => (
							<li key={ i }>
								<code className="neural-forms-import__code">
									{ s.name || s.cf7_type || s.tag || '?' }
								</code>
								{ ' — ' }
								{ s.reason || __( 'skipped during import', 'neural-forms-lite' ) }
							</li>
						) ) }
					</ul>
				</div>
			) }

			<div className="neural-forms-import__actions">
				<button
					type="button"
					className="neural-forms-button neural-forms-button--secondary"
					onClick={ onBack }
					disabled={ importing }
				>
					{ __( 'Back', 'neural-forms-lite' ) }
				</button>
				{ preview.can_import && ! showDuplicate && (
					<button
						type="button"
						className="neural-forms-button neural-forms-button--primary"
						onClick={ onImport }
						disabled={ importing }
					>
						{ importing
							? __( 'Importing…', 'neural-forms-lite' )
							: __( 'Import this form', 'neural-forms-lite' ) }
					</button>
				) }
			</div>
		</div>
	);
}

// ─── Duplicate-import banner ────────────────────────────────────

function DuplicateBanner( { imports, formName, onCancel, onForce } ) {
	const first = Array.isArray( imports ) && imports.length > 0 ? imports[ 0 ] : null;

	return (
		<div className="neural-forms-import__duplicate">
			<h3 className="neural-forms-import__section-title">
				{ __( 'This form has already been imported.', 'neural-forms-lite' ) }
			</h3>
			<div className="neural-forms-import__duplicate-body">
				<strong>{ formName }</strong>
				{ ' ' }
				{ __( 'was previously imported as ', 'neural-forms-lite' ) }
				<strong>{ first?.title || first?.form_name || __( '(unknown)', 'neural-forms-lite' ) }</strong>
				{ first?.imported_at && (
					<>
						{ ' (' }
						{ String( first.imported_at ).split( ' ' )[ 0 ] }
						{ ').' }
					</>
				) }
				{ ! first?.imported_at && '.' }
				<br />
				{ __( 'Would you like to import it again? This will create a new copy.', 'neural-forms-lite' ) }
			</div>
			<div className="neural-forms-import__duplicate-actions">
				<button
					type="button"
					className="neural-forms-button neural-forms-button--secondary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'neural-forms-lite' ) }
				</button>
				<button
					type="button"
					className="neural-forms-button neural-forms-button--secondary neural-forms-button--warning"
					onClick={ onForce }
				>
					{ __( 'Import Again', 'neural-forms-lite' ) }
				</button>
			</div>
		</div>
	);
}

// ─── Result view ────────────────────────────────────────────────

function ResultView( { result, onBack } ) {
	if ( ! result ) {
		return (
			<div className="neural-forms-import__loading">
				{ __( 'Loading…', 'neural-forms-lite' ) }
			</div>
		);
	}

	const ok       = !! result.success;
	const formId   = result.form_id;
	const formName = result.form_name || '';
	const skipped  = Array.isArray( result.skipped )  ? result.skipped  : [];
	const warnings = Array.isArray( result.warnings ) ? result.warnings : [];

	const editUrl = formId
		? `admin.php?page=neural-forms&edit=${ formId }`
		: 'admin.php?page=neural-forms';

	return (
		<div className="neural-forms-import__result">
			<div className={
				'neural-forms-import__notice '
				+ ( ok
					? 'neural-forms-import__notice--success'
					: 'neural-forms-import__notice--error' )
			}>
				{ ok
					? __( 'Import successful', 'neural-forms-lite' )
					: __( 'Import failed', 'neural-forms-lite' ) }
				{ formName ? ' — ' : '' }
				{ formName && <strong>{ formName }</strong> }
				{ typeof result.field_count === 'number' && ok && (
					<>
						{ ' ' }
						{ __( 'was imported with ', 'neural-forms-lite' ) }
						{ result.field_count }
						{ ' ' }
						{ __( 'fields.', 'neural-forms-lite' ) }
					</>
				) }
			</div>

			{ warnings.length > 0 && (
				<div className="neural-forms-import__notice neural-forms-import__notice--warning">
					<ul style={ { margin: 0, paddingLeft: '20px' } }>
						{ warnings.map( ( w, i ) => <li key={ i }>{ w }</li> ) }
					</ul>
				</div>
			) }

			{ skipped.length > 0 && (
				<div className="neural-forms-card">
					<h3 className="neural-forms-import__section-title">
						{ __( 'Skipped Fields', 'neural-forms-lite' ) }
					</h3>
					<ul style={ { margin: 0, paddingLeft: '20px', color: 'var(--f-gray-500)', fontSize: '13px' } }>
						{ skipped.map( ( s, i ) => (
							<li key={ i }>
								<code className="neural-forms-import__code">{ s.tag || s.type || '?' }</code>
								{ ' — ' }
								{ s.reason || __( 'skipped during import', 'neural-forms-lite' ) }
							</li>
						) ) }
					</ul>
				</div>
			) }

			<div className="neural-forms-import__actions">
				<button
					type="button"
					className="neural-forms-button neural-forms-button--secondary"
					onClick={ onBack }
				>
					{ __( 'Import another form', 'neural-forms-lite' ) }
				</button>
				{ ok && formId && (
					<a
						className="neural-forms-button neural-forms-button--primary"
						href={ editUrl }
					>
						{ __( 'Edit imported form', 'neural-forms-lite' ) }
					</a>
				) }
			</div>
		</div>
	);
}
