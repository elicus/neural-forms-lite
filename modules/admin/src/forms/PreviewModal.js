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

/* ----------------------------------------------------------------- */
/* Fieldset / legend reset for grouped fields                         */
/* ----------------------------------------------------------------- */
/* All group wrappers in the frontend (radio, checkbox, rating, poll, */
/* quiz) render as <fieldset> with a <legend> for accessibility. The  */
/* browser default styles a fieldset with a 2px groove border and    */
/* breaks the top border to fit the legend mid-stream, which reads as */
/* an ugly debug outline in the preview iframe. Frontend form.css     */
/* zeroes them; mirror those resets here so preview matches.          */
.neural-forms-form__radio-group,
.neural-forms-form__checkbox-group,
.neural-forms-form__rating-group,
.neural-forms-form__poll,
.neural-forms-form__quiz {
  border: 0;
  margin: 0;
  padding: 0;
  min-inline-size: 0;
}
.neural-forms-form__radio-group > legend,
.neural-forms-form__checkbox-group > legend,
.neural-forms-form__rating-group > legend,
.neural-forms-form__poll > legend,
.neural-forms-form__quiz > legend {
  display: block;
  width: 100%;
  padding: 0;
  margin-bottom: 6px;
  font-weight: 600;
  font-size: 13px;
  color: inherit;
  float: none;
}
.neural-forms-form__quiz-options,
.neural-forms-form__poll-options {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.neural-forms-form__quiz-option,
.neural-forms-form__poll-option {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  margin: 0;
}
.neural-forms-form__quiz-input,
.neural-forms-form__poll-input { accent-color: #15db95; cursor: pointer; }

/* ----------------------------------------------------------------- */
/* Welcome Screen (preview-iframe twin of frontend form.css)          */
/* ----------------------------------------------------------------- */
/* Kept in sync with the Welcome Screen rules in                     */
/* modules/frontend/assets/css/form.css. Duplicated here intentionally */
/* because the preview iframe is sandbox="" and cannot pull form.css. */
/* */
/* IMPORTANT: the frontend hides the form body until Start is clicked */
/* (CSS rule keyed on the wrap's data-neural-forms-has-welcome). The */
/* preview iframe is sandbox="" with no allow-scripts, so the JS that */
/* removes the flag on Start click can't run — applying the hide rule */
/* would leave the admin stuck looking at the welcome screen with no */
/* way to see the rest of the form. We OMIT the hide rule here so the */
/* preview stacks the welcome screen + form body together — admins see */
/* every piece of the form's content at once, which is the actual goal */
/* of the preview ("does my form look right?"). */
.neural-forms-form__welcome {
  background: transparent;
  color: inherit;
  border-radius: 12px;
  padding: 32px 24px;
  margin-bottom: 16px;
}
.neural-forms-form__welcome--align-left   { text-align: left;   }
.neural-forms-form__welcome--align-center { text-align: center; }
.neural-forms-form__welcome--align-right  { text-align: right;  }
.neural-forms-form__welcome-inner { max-width: 560px; margin: 0 auto; }
.neural-forms-form__welcome--align-left  .neural-forms-form__welcome-inner { margin-left: 0;  }
.neural-forms-form__welcome--align-right .neural-forms-form__welcome-inner { margin-right: 0; }
.neural-forms-form__welcome-title {
  margin: 0 0 12px;
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  color: inherit;
}
.neural-forms-form__welcome-desc {
  margin: 0 0 24px;
  font-size: 16px;
  line-height: 1.55;
  color: inherit;
  opacity: 0.85;
}
.neural-forms-form__welcome-btn {
  display: inline-block;
  background: #080f5b;
  color: #ffffff;
  border: 0;
  border-radius: 6px;
  padding: 12px 28px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
}
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
.neural-forms-form__progress { margin: 0 0 16px; padding: 0; list-style: none; }
.neural-forms-form__progress--numbers { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.neural-forms-form__progress-item {
  display: inline-flex; align-items: center; gap: 6px; color: #6b7280; font-size: 13px;
}
.neural-forms-form__progress-num {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 24px; height: 24px; padding: 0 7px; border-radius: 999px;
  background: #f3f4f6; color: #6b7280; font-size: 12px; font-weight: 600;
}
.neural-forms-form__progress-item.is-active .neural-forms-form__progress-num { background: #080f5b; color: #fff; }
.neural-forms-form__progress-item.is-active { color: #080f5b; font-weight: 600; }
.neural-forms-form__progress-bar-track {
  width: 100%; height: 6px; background: rgba(8,15,91,0.10);
  border-radius: 999px; overflow: hidden;
}
.neural-forms-form__progress-bar-fill { height: 100%; background: #080f5b; }
.neural-forms-form__progress-text { margin: 6px 0 0; color: #4b5563; font-size: 12px; font-weight: 500; }
@media (max-width: 768px) { .neural-forms-form__row { grid-template-columns: 1fr !important; } }

/* ----------------------------------------------------------------- */
/* WordPress visually-hidden helper                                   */
/* ----------------------------------------------------------------- */
/* The form renderer adds a <span class="screen-reader-text"> inside */
/* every matrix cell label so AT users hear                          */
/*   "<field label> — <row label> — <column label>"                  */
/* On a real WP page this class is hidden by the theme / core. The   */
/* preview iframe is sandboxed and loads NO external stylesheet, so  */
/* without this rule the accessibility text renders as visible body  */
/* text and squeezes every matrix cell out of alignment.             */
.screen-reader-text {
  border: 0;
  clip: rect(1px, 1px, 1px, 1px);
  -webkit-clip-path: inset(50%);
  clip-path: inset(50%);
  height: 1px;
  margin: -1px;
  overflow: hidden;
  padding: 0;
  position: absolute;
  width: 1px;
  word-wrap: normal !important;
}

/* ----------------------------------------------------------------- */
/* Matrix field (preview-iframe twin of frontend form.css)            */
/* ----------------------------------------------------------------- */
/* Kept in sync with modules/frontend/assets/css/form.css's          */
/* .neural-forms-matrix block. We duplicate here intentionally       */
/* because the iframe is sandbox="" and cannot pull form.css.        */
.neural-forms-matrix { border: 0; padding: 0; margin: 0; }
.neural-forms-matrix__scroll {
  width: 100%;
  overflow-x: auto;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  background: #fff;
  -webkit-overflow-scrolling: touch;
}
.neural-forms-matrix__table {
  border-collapse: collapse;
  width: 100%;
  font-size: 14px;
  color: #1f2937;
}
.neural-forms-matrix__table th,
.neural-forms-matrix__table td {
  padding: 10px 12px;
  border-bottom: 1px solid #f1f5f9;
  text-align: center;
  vertical-align: middle;
}
.neural-forms-matrix__table tbody tr:last-child th,
.neural-forms-matrix__table tbody tr:last-child td { border-bottom: 0; }
.neural-forms-matrix__corner { background: #f9fafb; }
.neural-forms-matrix__col {
  font-weight: 600;
  color: #374151;
  background: #f9fafb;
  white-space: nowrap;
}
.neural-forms-matrix__row-label {
  font-weight: 500;
  text-align: left;
  color: #374151;
  background: #f9fafb;
  white-space: nowrap;
  position: sticky;
  left: 0;
  z-index: 1;
}
.neural-forms-matrix__cell { min-width: 64px; }
.neural-forms-matrix__cell-label {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  margin: 0;
  cursor: pointer;
}
.neural-forms-matrix__input {
  cursor: pointer;
  accent-color: #080f5b;
}
@media ( max-width: 600px ) {
  .neural-forms-matrix__table { font-size: 13px; }
  .neural-forms-matrix__table th,
  .neural-forms-matrix__table td { padding: 8px 10px; }
}

/* ----------------------------------------------------------------- */
/* Repeater field (preview-iframe twin of frontend form.css)          */
/* ----------------------------------------------------------------- */
/* Kept in sync with modules/frontend/assets/css/form.css's          */
/* .neural-forms-repeater* block. Duplicated here intentionally —     */
/* the iframe is sandbox="" and cannot pull form.css. The list-style */
/* + padding-inline-start resets suppress the browser's native ::    */
/* marker on the <ol>/<li> so the only visible item label is the    */
/* .__item-num span emitted by FormRenderer.                         */
.neural-forms-repeater {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 12px;
  margin: 0;
  background: #fcfcfd;
}
.neural-forms-repeater__list {
  list-style: none !important;
  margin: 0;
  padding: 0;
  padding-inline-start: 0 !important;
  display: grid;
  gap: 12px;
}
.neural-forms-repeater__item {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 12px;
  list-style: none !important;
}
.neural-forms-repeater__item-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
  gap: 8px;
}
.neural-forms-repeater__item-num {
  font-size: 14px;
  font-weight: 600;
  color: #374151;
  line-height: 1;
}
.neural-forms-repeater__remove {
  background: transparent;
  color: #b91c1c;
  border: 1px solid #fecaca;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
.neural-forms-repeater__remove:hover { background: #fef2f2; }
.neural-forms-repeater__remove[hidden] { display: none; }
.neural-forms-repeater__grid { display: grid; gap: 10px; }
.neural-forms-repeater__grid--cols-1 { grid-template-columns: 1fr; }
.neural-forms-repeater__grid--cols-2 { grid-template-columns: 1fr 1fr; }
.neural-forms-repeater__grid--cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.neural-forms-repeater__grid--cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
.neural-forms-repeater__child { min-width: 0; margin-bottom: 0; }
.neural-forms-repeater__add {
  margin-top: 12px;
  background: #fff;
  color: #080f5b;
  border: 1px solid #080f5b;
  border-radius: 6px;
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
}
.neural-forms-repeater__add:hover { background: #080f5b; color: #fff; }
.neural-forms-repeater__add[hidden] { display: none; }
.neural-forms-repeater__notice {
  margin: 0;
  padding: 10px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 6px;
  color: #92400e;
  font-size: 13px;
}
@media ( max-width: 600px ) {
  .neural-forms-repeater__grid { grid-template-columns: 1fr !important; }
}
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
