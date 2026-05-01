/**
 * Change-template modal.
 *
 * Reuses FormTypeChooser in mode="modal" so the catalog source of
 * truth stays one component. The modal owns the dialog chrome
 * (backdrop, panel, close button) and the Cancel action; picking a
 * tile calls back into Builder.applyTemplate which itself prompts
 * for confirmation before clobbering the canvas.
 */

import { __ } from '@wordpress/i18n';
import FormTypeChooser from './FormTypeChooser';

export default function BuilderTemplateModal( { open, onApplyTemplate, onCancel } ) {
	if ( ! open ) return null;

	return (
		<div className="nf-template-modal" role="dialog" aria-modal="true" aria-label={ __( 'Change template', 'neural-forms-lite' ) }>
			<div className="nf-template-modal__backdrop" onClick={ onCancel } />
			<div className="nf-template-modal__panel">
				<header className="nf-template-modal__header">
					<button
						type="button"
						className="nf-template-modal__close"
						onClick={ onCancel }
						aria-label={ __( 'Close', 'neural-forms-lite' ) }
					>×</button>
				</header>
				<div className="nf-template-modal__body">
					<FormTypeChooser
						mode="modal"
						onApplyTemplate={ ( id ) => {
							if ( typeof onApplyTemplate === 'function' ) onApplyTemplate( id );
						} }
					/>
				</div>
				<footer className="nf-template-modal__footer">
					<button
						type="button"
						className="neural-forms-btn"
						onClick={ onCancel }
					>{ __( 'Cancel', 'neural-forms-lite' ) }</button>
				</footer>
			</div>
		</div>
	);
}
