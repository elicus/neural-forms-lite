/**
 * Preview overlay for the form builder.
 *
 * Posts the *current, unsaved* schema and settings to the
 * /forms/preview REST endpoint and renders the returned HTML in a
 * sandboxed iframe. The endpoint runs FormValidator first, so a
 * malformed builder state surfaces the same field-level error the
 * user would see on save - they don't have to actually save to find
 * out.
 *
 * The iframe is sandboxed without `allow-scripts` so the preview is
 * purely visual; submitting the form inside the preview is
 * intentionally a no-op.
 *
 * The header carries a Desktop / Tablet / Mobile width toggle so the
 * user can verify the form's responsive collapse without resizing
 * their browser.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import ResponsivePreviewToggle, { PREVIEW_WIDTHS } from './ResponsivePreviewToggle';

export default function PreviewModal( { open, form, onClose } ) {
	const [ html,    setHtml    ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error,   setError   ] = useState( '' );
	const [ device,  setDevice  ] = useState( 'desktop' );

	useEffect( () => {
		if ( ! open ) return undefined;

		let cancelled = false;
		setLoading( true );
		setError( '' );
		setHtml( '' );

		api( '/forms/preview', {
			method: 'POST',
			data: {
				name:        form?.name || '',
				form_schema: form?.form_schema || { fields: [] },
				settings:    form?.settings    || {},
			},
		} )
			.then( ( res ) => {
				if ( cancelled ) return;
				setHtml( typeof res?.html === 'string' ? res.html : '' );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				// eslint-disable-next-line no-console
				if ( err ) console.error( 'Neural Forms /forms/preview failed:', err );
				setError( err?.message || __( 'Preview failed.', 'neural-forms-lite' ) );
				setLoading( false );
			} );

		return () => { cancelled = true; };
	}, [ open, form ] );

	if ( ! open ) return null;

	const width = PREVIEW_WIDTHS[ device ] || PREVIEW_WIDTHS.desktop;

	const srcDoc = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
*, *::before, *::after { box-sizing: border-box; }
body { font: 14px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 28px; color: #1f2937; background: #fff; margin: 0; }
.neural-forms-form-wrap { max-width: 100%; }
.neural-forms-form__title { margin: 0 0 16px; font-size: 22px; }
.neural-forms-form__field { margin-bottom: 14px; }
.neural-forms-form__label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
.neural-forms-form__input, .neural-forms-form__select, .neural-forms-form__textarea {
  width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;
}
.neural-forms-form__textarea { resize: vertical; min-height: 80px; }
.neural-forms-form__submit-wrap { margin-top: 16px; }
.neural-forms-form__submit {
  background: #2271b1; color: #fff; border: 0; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;
}
.neural-forms-form__row { display: grid; gap: 16px; }
.neural-forms-form__row--cols-1 { grid-template-columns: 1fr; }
.neural-forms-form__row--cols-2 { grid-template-columns: 1fr 1fr; }
.neural-forms-form__row--cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.neural-forms-form__row--cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
.neural-forms-form__row--gap-none   { gap: 0; }
.neural-forms-form__row--gap-small  { gap: 8px; }
.neural-forms-form__row--gap-medium { gap: 16px; }
.neural-forms-form__row--gap-large  { gap: 24px; }
.neural-forms-form__field--honeypot { display: none !important; }
.neural-forms-form__required { color: #b91c1c; }
.neural-forms-form__radio-option, .neural-forms-form__checkbox-option { display: flex; align-items: center; gap: 6px; margin: 4px 0; }
.neural-forms-form__file-hint { color: #6b7280; font-size: 12px; margin: 4px 0 0; }
.neural-forms-form__step {
  border-top: 2px solid #080f5b;
  padding: 16px 0 4px;
  margin-bottom: 8px;
}
.neural-forms-form__step:first-of-type { border-top: 0; padding-top: 0; }
.neural-forms-form__step-title {
  margin: 0 0 4px; font-size: 16px; font-weight: 600; color: #080f5b;
}
.neural-forms-form__step-description {
  margin: 0 0 12px; color: #4b5563; font-size: 13px;
}
.neural-forms-form__step-body { margin-bottom: 8px; }
.neural-forms-form__steps-nav {
  display: flex; gap: 8px; margin: 12px 0 8px;
}
.neural-forms-form__step-prev, .neural-forms-form__step-next {
  background: #fff; color: #080f5b; border: 1px solid #080f5b;
  border-radius: 6px; padding: 8px 16px; font-size: 13px; font-weight: 500;
}
.neural-forms-form__step-next { background: #080f5b; color: #fff; margin-left: auto; }
@media (max-width: 768px) { .neural-forms-form__row { grid-template-columns: 1fr !important; } }
</style></head><body>${ html }</body></html>`;

	return (
		<div className="nf-preview-modal" role="dialog" aria-modal="true" aria-label={ __( 'Form preview', 'neural-forms-lite' ) }>
			<div className="nf-preview-modal__backdrop" onClick={ onClose } />
			<div className="nf-preview-modal__panel">
				<header className="nf-preview-modal__header">
					<h2 className="nf-preview-modal__title">{ __( 'Form preview', 'neural-forms-lite' ) }</h2>
					<ResponsivePreviewToggle active={ device } onChange={ setDevice } />
					<button
						type="button"
						className="nf-preview-modal__close"
						onClick={ onClose }
						aria-label={ __( 'Close preview', 'neural-forms-lite' ) }
					>×</button>
				</header>
				<div className="nf-preview-modal__body">
					{ loading && (
						<p className="nf-preview-modal__status">{ __( 'Building preview...', 'neural-forms-lite' ) }</p>
					) }
					{ !! error && (
						<p className="nf-preview-modal__status nf-preview-modal__status--error">{ error }</p>
					) }
					{ ! loading && ! error && (
						<div className="nf-preview-modal__stage">
							<iframe
								className="nf-preview-modal__frame"
								title={ __( 'Form preview', 'neural-forms-lite' ) }
								sandbox=""
								srcDoc={ srcDoc }
								style={ { width: `${ width }px`, maxWidth: '100%' } }
							/>
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
