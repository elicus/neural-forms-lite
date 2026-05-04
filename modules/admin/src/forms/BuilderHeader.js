/**
 * Top header bar for the form builder.
 *
 * Holds: Back button, editable form name + status pill, Build/Settings
 * tab switcher (BuilderModeTabs), and the right-side action cluster
 * (Preview, Analytics if saved, Save/Saved/Saving/Retry button).
 *
 * Stateless — all state lives in Builder.js.
 *
 * Save button state machine (driven by props isDirty/saving/saveError):
 *
 *   isDirty=false, saving=false, saveError=''  → "Saved"     disabled  (success-tinted)
 *   isDirty=true,  saving=false, saveError=''  → "Save"      enabled   (primary)
 *   isDirty=*,     saving=true,  saveError=*   → "Saving…"   disabled  (primary)
 *   isDirty=true,  saving=false, saveError!='' → "Retry save" enabled  (primary, alert below)
 *
 * The previous standalone "Saved" label that lived between Analytics
 * and Save is removed in 0.2.0 — Save IS the saved-status indicator.
 */

import { __ } from '@wordpress/i18n';
import BuilderModeTabs from './BuilderModeTabs';

const STATUS_OPTIONS = [
	{ value: 'active',   label: 'Active'   },
	{ value: 'draft',    label: 'Draft'    },
	{ value: 'archived', label: 'Archived' },
];

const VALID_STATUSES = STATUS_OPTIONS.map( ( s ) => s.value );

export default function BuilderHeader( {
	name,
	status,
	onNameChange,
	onStatusChange,
	activeTab,
	onTabChange,
	onPreview,
	onSave,
	onBack,
	saving,
	isDirty,
	saveError,
	formId,
} ) {
	// Results button only renders when the form has been persisted
	// (formId > 0) — the Results screen aggregates saved entries, so
	// an unsaved form has no row to query. Same-tab navigation is
	// intentional (no auto-save, no fetch). The builder mounts inside
	// /wp-admin/, so a relative admin.php URL resolves correctly.
	const showResults = Number( formId ) > 0;
	const resultsHref = showResults
		? 'admin.php?page=neural-forms-results&form_id=' + Number( formId )
		: '';
	const currentStatus = VALID_STATUSES.includes( status ) ? status : 'draft';

	// Resolve the four save-button states. Order matters — saving wins
	// over error (don't flash "Retry" while a fresh attempt is in
	// flight), and error wins over plain dirty (so the user sees what
	// went wrong first). Clean state is the fall-through.
	let saveLabel;
	let saveDisabled;
	let saveModifier;
	if ( saving ) {
		saveLabel    = __( 'Saving…',    'neural-forms-lite' );
		saveDisabled = true;
		saveModifier = '';
	} else if ( saveError && isDirty ) {
		saveLabel    = __( 'Retry save', 'neural-forms-lite' );
		saveDisabled = false;
		saveModifier = ' nf-builder-header__save--retry';
	} else if ( isDirty ) {
		saveLabel    = __( 'Save',       'neural-forms-lite' );
		saveDisabled = false;
		saveModifier = '';
	} else {
		saveLabel    = __( 'Saved',      'neural-forms-lite' );
		saveDisabled = true;
		saveModifier = ' nf-builder-header__save--clean';
	}

	const handleSaveClick = () => {
		// Defensive — the disabled attribute already blocks clicks
		// from mouse and keyboard, but a programmatic .click() or
		// stale event would still reach onClick. Mirror the
		// state-machine guard so a no-op click stays a no-op.
		if ( saveDisabled ) return;
		onSave && onSave();
	};

	return (
		<header className="nf-builder-header">
			<div className="nf-builder-header__left">
				<button
					type="button"
					className="nf-builder-header__back"
					onClick={ onBack }
					aria-label={ __( 'Back to forms list', 'neural-forms-lite' ) }
					title={ __( 'Back to forms', 'neural-forms-lite' ) }
				>
					<span aria-hidden="true">←</span>
				</button>
				<input
					type="text"
					className="nf-builder-header__name"
					value={ name || '' }
					onChange={ ( e ) => onNameChange( e.target.value ) }
					placeholder={ __( 'Form name', 'neural-forms-lite' ) }
					aria-label={ __( 'Form name', 'neural-forms-lite' ) }
				/>
				{ /* Status pill is a real <select> so admins can flip a
				    form between Active / Draft / Archived without leaving
				    the builder. The pill style is preserved via CSS and
				    re-coloured per status via the modifier class. */ }
				<label className="nf-builder-header__status-wrap">
					<span className="screen-reader-text">{ __( 'Form status', 'neural-forms-lite' ) }</span>
					<select
						className={ 'nf-builder-header__status nf-builder-header__status--' + currentStatus }
						value={ currentStatus }
						onChange={ ( e ) => onStatusChange( e.target.value ) }
						aria-label={ __( 'Form status', 'neural-forms-lite' ) }
					>
						{ STATUS_OPTIONS.map( ( s ) => (
							<option key={ s.value } value={ s.value }>
								{ __( s.label, 'neural-forms-lite' ) }
							</option>
						) ) }
					</select>
				</label>
			</div>

			<BuilderModeTabs active={ activeTab } onChange={ onTabChange } />

			<div className="nf-builder-header__actions">
				<button
					type="button"
					className="neural-forms-btn"
					onClick={ onPreview }
				>
					{ __( 'Preview', 'neural-forms-lite' ) }
				</button>
				{ showResults && (
					<a
						className="neural-forms-btn nf-builder-header__results"
						href={ resultsHref }
						title={ __( 'View analytics for this form', 'neural-forms-lite' ) }
						aria-label={ __( 'Analytics', 'neural-forms-lite' ) }
					>
						{ __( 'Analytics', 'neural-forms-lite' ) }
					</a>
				) }
				<button
					type="button"
					className={ 'neural-forms-btn neural-forms-btn--primary nf-builder-header__save' + saveModifier }
					onClick={ handleSaveClick }
					disabled={ saveDisabled }
					aria-disabled={ saveDisabled }
					data-state={ saving ? 'saving' : ( isDirty ? ( saveError ? 'error' : 'dirty' ) : 'clean' ) }
				>
					{ saveLabel }
				</button>
			</div>
			{ /* Inline error sits beneath the action row so it never
			    pushes the button cluster. role="alert" so screen
			    readers announce save failures immediately. */ }
			{ !! saveError && isDirty && ! saving && (
				<p
					className="nf-builder-header__save-error"
					role="alert"
				>
					{ saveError }
				</p>
			) }
		</header>
	);
}
