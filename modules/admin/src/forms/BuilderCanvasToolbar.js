/**
 * Compact toolbar above the canvas.
 *
 * Surfaces the active template (or "Custom layout" when nothing was
 * applied) and gives the user one-click access to swap or clear the
 * builder content. Visible only when the canvas has at least one
 * field/row/step - the empty state shows the starter screen instead.
 *
 * Stateless: parent (Builder.js) owns the change/clear flows.
 */

import { __ } from '@wordpress/i18n';
import { templateLabel } from './formTemplates';

export default function BuilderCanvasToolbar( { templateId, onChangeTemplate, onClear } ) {
	const label = templateLabel( templateId );
	const display = label
		? __( 'Template:', 'neural-forms-lite' ) + ' ' + label
		: __( 'Custom layout', 'neural-forms-lite' );

	return (
		<div className="nf-canvas-toolbar" onClick={ ( e ) => e.stopPropagation() }>
			<span className="nf-canvas-toolbar__label">{ display }</span>
			<div className="nf-canvas-toolbar__actions">
				<button
					type="button"
					className="neural-forms-btn nf-canvas-toolbar__btn"
					onClick={ onChangeTemplate }
				>
					{ __( 'Change template', 'neural-forms-lite' ) }
				</button>
				<button
					type="button"
					className="neural-forms-btn nf-canvas-toolbar__btn nf-canvas-toolbar__btn--danger"
					onClick={ onClear }
				>
					{ __( 'Clear form', 'neural-forms-lite' ) }
				</button>
			</div>
		</div>
	);
}
