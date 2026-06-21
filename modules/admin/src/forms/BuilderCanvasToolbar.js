/**
 * Compact toolbar above the canvas.
 *
 * Surfaces the active template (or "Custom layout" when nothing was
 * applied), the form's embed shortcode + Copy button (once the form
 * has been saved and has an id), and one-click access to swap or
 * clear the builder content. Visible only when the canvas has at
 * least one field/row/step — the empty state shows the starter
 * screen instead.
 *
 * Stateless: parent (Builder.js) owns the change/clear flows and
 * supplies the live form id (0 for unsaved drafts).
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { templateLabel } from './formTemplates';

export default function BuilderCanvasToolbar( { templateId, formId, onChangeTemplate, onClear } ) {
	const label = templateLabel( templateId );
	const display = label
		? __( 'Template:', 'neural-forms-lite' ) + ' ' + label
		: __( 'Custom layout', 'neural-forms-lite' );

	const numericId = Number( formId ) || 0;

	return (
		<div className="nf-canvas-toolbar" onClick={ ( e ) => e.stopPropagation() }>
			<span className="nf-canvas-toolbar__label">{ display }</span>

			{ /* Shortcode pill — id-based for stability (slugs change /
			    duplicate; ids don't). For unsaved forms (formId<=0)
			    we render a muted hint instead of an invalid id="0"
			    snippet. */ }
			{ numericId > 0 ? (
				<ShortcodePill formId={ numericId } />
			) : (
				<span className="nf-canvas-toolbar__shortcode-hint">
					{ __( 'Save form to get shortcode', 'neural-forms-lite' ) }
				</span>
			) }

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

/**
 * Read-only shortcode display + Copy button. Local-state-only —
 * does NOT call back into Builder, so copying never marks the form
 * dirty (the form payload is unchanged by a copy interaction).
 *
 * Falls back to a hidden-textarea + execCommand('copy') path when
 * navigator.clipboard is unavailable (insecure context, or older
 * browsers). Both paths are wrapped in try/catch so a clipboard
 * failure doesn't throw a console error onto the user.
 */
function ShortcodePill( { formId } ) {
	const shortcode = `[neural-forms id="${ formId }"]`;
	const [ copied, setCopied ] = useState( false );

	const onCopy = async () => {
		try {
			if ( typeof navigator !== 'undefined' && navigator.clipboard ) {
				await navigator.clipboard.writeText( shortcode );
				setCopied( true );
				setTimeout( () => setCopied( false ), 1500 );
				return;
			}
			throw new Error( 'no-clipboard-api' );
		} catch ( _e ) {
			// Legacy fallback. We intentionally don't rethrow — copy
			// is a convenience, not a correctness operation.
			try {
				const ta = document.createElement( 'textarea' );
				ta.value = shortcode;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'absolute';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
				setCopied( true );
				setTimeout( () => setCopied( false ), 1500 );
			} catch ( _ignored ) { /* give up silently */ }
		}
	};

	return (
		<div className="nf-canvas-toolbar__shortcode" role="group" aria-label={ __( 'Form shortcode', 'neural-forms-lite' ) }>
			<input
				type="text"
				className="nf-canvas-toolbar__shortcode-input"
				readOnly
				value={ shortcode }
				onFocus={ ( e ) => e.target.select() }
				onClick={ ( e ) => e.target.select() }
				aria-label={ __( 'Shortcode for this form', 'neural-forms-lite' ) }
			/>
			<button
				type="button"
				className={ 'neural-forms-btn nf-canvas-toolbar__btn nf-canvas-toolbar__shortcode-copy' + ( copied ? ' is-copied' : '' ) }
				onClick={ onCopy }
				aria-label={ copied
					? __( 'Shortcode copied', 'neural-forms-lite' )
					: __( 'Copy shortcode', 'neural-forms-lite' ) }
			>
				{ copied
					? __( 'Copied', 'neural-forms-lite' )
					: __( 'Copy', 'neural-forms-lite' ) }
			</button>
		</div>
	);
}
