/**
 * Form settings › Conversational (1.3.0, Pro cap `conversational_forms`).
 * Stored as form.settings.conversational; sanitised by
 * Frontend\Conversational::sanitize_settings(), rendered by FormRenderer.
 */
import { __ } from '@wordpress/i18n';

const DEFAULTS = { enabled: false, auto_advance: true, show_numbers: true, progress: 'bar', review: false, next_label: '' };

function Toggle( { checked, onChange, title, hint, disabled } ) {
	return (
		<label className="nf-form-settings__field nf-payments-section__toggle">
			<input type="checkbox" checked={ !! checked } disabled={ disabled } onChange={ ( e ) => onChange( e.target.checked ) } />
			<span>
				<strong>{ title }</strong>
				{ hint && <small>{ hint }</small> }
			</span>
		</label>
	);
}

export default function ConversationalSection( { value, onChange } ) {
	const caps = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.plugin?.capabilities ) || {};
	if ( ! caps.conversational_forms ) {
		return (
			<div className="nf-form-settings__pro-note">
				{ __( 'Show one question at a time, Typeform-style: big questions, keyboard shortcuts (Enter for next, A / B / C to pick), automatic next on single-choice answers and a progress bar. Uses your existing fields and logic. Available in Neural Forms Pro.', 'neural-forms-lite' ) }
			</div>
		);
	}

	const v   = { ...DEFAULTS, ...( value || {} ) };
	const set = ( patch ) => onChange( { ...v, ...patch } );
	const off = ! v.enabled;

	return (
		<>
			<Toggle
				checked={ v.enabled }
				onChange={ ( enabled ) => set( { enabled } ) }
				title={ __( 'Show one question per screen', 'neural-forms-lite' ) }
				hint={ __( 'Each field becomes its own screen. Fields placed side by side in a row are asked one after another. Section headings and content blocks get a screen of their own. Steps, conditional logic and validation keep working as before.', 'neural-forms-lite' ) }
			/>
			<Toggle
				checked={ v.auto_advance }
				disabled={ off }
				onChange={ ( auto_advance ) => set( { auto_advance } ) }
				title={ __( 'Go to the next question after a single choice', 'neural-forms-lite' ) }
				hint={ __( 'For radio-button and rating questions. Questions where people type or tick several boxes always wait for OK.', 'neural-forms-lite' ) }
			/>
			<Toggle
				checked={ v.show_numbers }
				disabled={ off }
				onChange={ ( show_numbers ) => set( { show_numbers } ) }
				title={ __( 'Number the questions', 'neural-forms-lite' ) }
			/>
			<Toggle
				checked={ v.review }
				disabled={ off }
				onChange={ ( review ) => set( { review } ) }
				title={ __( 'Show a review screen before submitting', 'neural-forms-lite' ) }
				hint={ __( 'Lists every answer with an Edit link back to its question.', 'neural-forms-lite' ) }
			/>
			<label className="nf-form-settings__field">
				<span>{ __( 'Progress', 'neural-forms-lite' ) }</span>
				<select value={ v.progress } disabled={ off } onChange={ ( e ) => set( { progress: e.target.value } ) }>
					<option value="bar">{ __( 'Progress bar', 'neural-forms-lite' ) }</option>
					<option value="none">{ __( 'Hidden', 'neural-forms-lite' ) }</option>
				</select>
			</label>
			<label className="nf-form-settings__field">
				<span>{ __( 'Next button text', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					maxLength={ 40 }
					value={ v.next_label }
					disabled={ off }
					placeholder={ __( 'OK', 'neural-forms-lite' ) }
					onChange={ ( e ) => set( { next_label: e.target.value } ) }
				/>
			</label>
			<p className="nf-form-settings__hint">
				{ __( 'Tip: add a Welcome Screen to greet people before the first question. The builder preview shows the normal layout; open the page to see the conversational one.', 'neural-forms-lite' ) }
			</p>
		</>
	);
}
