/**
 * Template Library popup — wraps the shared <TemplateLibrary> in a
 * centered modal panel. Opened from the Forms-list "+ New Form → Use
 * Template" dropdown item (see AdminPanel.php inline injector, which
 * dispatches a `neural-forms:open-template-library` document event).
 *
 * Behavior:
 *   - ESC closes the modal
 *   - Clicking the backdrop closes the modal
 *   - Body scroll is locked while open
 *   - Refresh Catalog button in the modal header triggers the same
 *     refresh path used by the page-mode hero (via imperative handle)
 *
 * The modal does NOT auto-close on import — the user can browse and
 * import multiple templates in one session, matching the spec.
 *
 * Class names are scoped under `.nf-template-modal-*`; the inner
 * library reuses `.neural-forms-templates__*` BEM exactly as on the
 * page so all styling tokens stay shared.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import TemplateLibrary from './TemplateLibrary';

export default function TemplateLibraryModal( { isOpen, onClose } ) {
	const libRef = useRef( null );
	const panelRef = useRef( null );
	// Force re-render after refresh state ticks inside the imperative
	// child so the header button can show "Refreshing…" without lifting
	// the entire state out of TemplateLibrary.
	const [ , setTick ] = useState( 0 );

	useEffect( () => {
		if ( ! isOpen ) return undefined;

		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		}
		document.addEventListener( 'keydown', onKey );

		const previousOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		// Move focus into the panel so screen readers track the new
		// dialog and tab order starts inside it.
		const focusTimer = setTimeout( () => {
			if ( panelRef.current ) {
				const focusable = panelRef.current.querySelector(
					'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
				);
				if ( focusable ) focusable.focus();
				else panelRef.current.focus();
			}
		}, 0 );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.body.style.overflow = previousOverflow;
			clearTimeout( focusTimer );
		};
	}, [ isOpen, onClose ] );

	if ( ! isOpen ) return null;

	const refreshing = libRef.current ? libRef.current.isRefreshing() : false;
	const loading    = libRef.current ? libRef.current.isLoading()    : false;
	const updatedAt  = libRef.current ? libRef.current.getUpdatedAt() : '';

	function handleRefresh() {
		if ( ! libRef.current ) return;
		Promise.resolve( libRef.current.refresh() ).finally( () => setTick( ( n ) => n + 1 ) );
		setTick( ( n ) => n + 1 );
	}

	return (
		<div
			className="nf-template-modal-overlay"
			onClick={ ( e ) => {
				if ( e.target === e.currentTarget ) onClose();
			} }
		>
			<div
				className="nf-template-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="nf-template-modal-title"
				ref={ panelRef }
				tabIndex={ -1 }
			>
				<header className="nf-template-modal-header">
					<div className="nf-template-modal-header__text">
						<h2 id="nf-template-modal-title" className="nf-template-modal-header__title">
							{ __( 'Template Library', 'neural-forms-lite' ) }
						</h2>
						<p className="nf-template-modal-header__subtitle">
							{ __( 'Choose a ready-made template and import it into your site.', 'neural-forms-lite' ) }
						</p>
					</div>
					<div className="nf-template-modal-header__actions">
						{ !! updatedAt && (
							<span className="nf-template-modal-header__updated">
								{ /* translators: %s: catalog updated_at timestamp from the remote catalog file. */ }
								{ __( 'Catalog updated', 'neural-forms-lite' ) }{ ' ' }{ updatedAt }
							</span>
						) }
						<Button
							variant="secondary"
							className="nf-template-modal-header__refresh"
							onClick={ handleRefresh }
							isBusy={ refreshing }
							disabled={ refreshing || loading }
						>
							{ refreshing
								? __( 'Refreshing…', 'neural-forms-lite' )
								: __( 'Refresh Catalog', 'neural-forms-lite' ) }
						</Button>
						<button
							type="button"
							className="nf-template-modal-close"
							onClick={ onClose }
							aria-label={ __( 'Close template library', 'neural-forms-lite' ) }
						>
							<span aria-hidden="true">×</span>
						</button>
					</div>
				</header>

				<div className="nf-template-modal-body">
					<TemplateLibrary
						ref={ libRef }
						mode="modal"
						onImported={ () => setTick( ( n ) => n + 1 ) }
					/>
				</div>
			</div>
		</div>
	);
}
