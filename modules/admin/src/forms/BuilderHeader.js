/**
 * Top header bar for the form builder.
 *
 * Holds: Back link, editable form name + status pill, Build/Preview/
 * Settings tab switcher (BuilderModeTabs), and the right-side action
 * cluster (Preview button, Save button + saved-indicator).
 *
 * Stateless - all state lives in Builder.js.
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
	saved,
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
				{ saved && (
					<span className="nf-builder-header__saved" aria-live="polite">
						{ __( 'Saved', 'neural-forms-lite' ) }
					</span>
				) }
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
					className="neural-forms-btn neural-forms-btn--primary"
					onClick={ onSave }
					disabled={ saving }
				>
					{ saving ? __( 'Saving...', 'neural-forms-lite' ) : __( 'Save', 'neural-forms-lite' ) }
				</button>
			</div>
		</header>
	);
}
