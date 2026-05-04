/**
 * Custom unsaved-changes confirmation, shown when the user tries to
 * leave the builder via an internal navigation while `isDirty` is
 * true. Replaces the previous window.confirm path. Browser-owned
 * unloads (reload / tab close / back/forward) still fall through to
 * beforeunload's native dialog because we can't intercept those in JS.
 *
 * Three resolutions:
 *   - Save as Draft  → Builder runs save() with statusOverride='draft',
 *                      then on success navigates to the captured URL.
 *   - Discard        → Builder calls guard.confirmNavigate(); listeners
 *                      detach imperatively + window.location flips.
 *   - Stay here      → Builder calls guard.cancelNavigate(); modal closes.
 *
 * Accessibility:
 *   - role="dialog", aria-modal="true"
 *   - aria-labelledby + aria-describedby
 *   - autoFocus on Save as Draft (the safe default)
 *   - Escape key = Stay here
 *
 * NOT a focus trap — the page is about to navigate away in the
 * common case, so a full trap adds complexity for little benefit.
 * Builder.js renders this conditionally, so the modal mounts/unmounts
 * with `open`.
 */

import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function UnsavedChangesModal( {
	open,
	saving,
	error,
	onSaveDraft,
	onDiscard,
	onStay,
} ) {
	const draftBtnRef = useRef( null );

	// Auto-focus the safe default action when the modal opens. Guard
	// against re-focusing while saving — moving focus mid-save would
	// pull the user's keyboard intent off the spinner button. On Esc
	// key we resolve as Stay (mirrors the "harmless cancel" idiom of
	// every modal in the WP admin).
	useEffect( () => {
		if ( ! open ) return undefined;
		if ( draftBtnRef.current && ! saving ) {
			draftBtnRef.current.focus();
		}
		const onKeyDown = ( event ) => {
			if ( event.key === 'Escape' && ! saving ) {
				event.stopPropagation();
				onStay && onStay();
			}
		};
		window.addEventListener( 'keydown', onKeyDown );
		return () => window.removeEventListener( 'keydown', onKeyDown );
	}, [ open, saving, onStay ] );

	if ( ! open ) return null;

	return (
		<div
			className="nf-unsaved-modal__overlay"
			// Click on the dimmed background = Stay here, but only
			// when not actively saving (don't yank away the spinner).
			onClick={ ( e ) => {
				if ( e.target === e.currentTarget && ! saving ) {
					onStay && onStay();
				}
			} }
		>
			<div
				className="nf-unsaved-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="nf-unsaved-modal-title"
				aria-describedby="nf-unsaved-modal-desc"
			>
				<header className="nf-unsaved-modal__head">
					<h2 id="nf-unsaved-modal-title" className="nf-unsaved-modal__title">
						{ __( 'Unsaved changes', 'neural-forms-lite' ) }
					</h2>
				</header>

				<div className="nf-unsaved-modal__body">
					<p id="nf-unsaved-modal-desc" className="nf-unsaved-modal__msg">
						{ __(
							'You have unsaved changes in this form. What would you like to do before leaving?',
							'neural-forms-lite'
						) }
					</p>
					<p className="nf-unsaved-modal__note">
						{ __(
							'If you discard, your latest changes will be lost.',
							'neural-forms-lite'
						) }
					</p>
					{ !! error && (
						<p className="nf-unsaved-modal__error" role="alert">
							{ error }
						</p>
					) }
				</div>

				<footer className="nf-unsaved-modal__actions">
					<button
						type="button"
						className="neural-forms-btn nf-unsaved-modal__btn nf-unsaved-modal__btn--stay"
						onClick={ onStay }
						disabled={ saving }
					>
						{ __( 'Stay here', 'neural-forms-lite' ) }
					</button>
					<button
						type="button"
						className="neural-forms-btn nf-unsaved-modal__btn nf-unsaved-modal__btn--discard"
						onClick={ onDiscard }
						disabled={ saving }
					>
						{ __( 'Discard changes', 'neural-forms-lite' ) }
					</button>
					<button
						ref={ draftBtnRef }
						type="button"
						className="neural-forms-btn neural-forms-btn--primary nf-unsaved-modal__btn nf-unsaved-modal__btn--save"
						onClick={ onSaveDraft }
						disabled={ saving }
					>
						{ saving
							? __( 'Saving draft…', 'neural-forms-lite' )
							: __( 'Save as Draft', 'neural-forms-lite' ) }
					</button>
				</footer>
			</div>
		</div>
	);
}
