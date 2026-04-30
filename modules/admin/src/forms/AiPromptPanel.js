/**
 * AI prompt-to-form placeholder.
 *
 * Visual-only today. The textarea is enabled so users can already
 * type the prompt they want once the feature ships, but the action
 * button is disabled and labeled as a future Pro feature. When the
 * Pro side wires this up, swap the disabled props for a call to a
 * Pro-registered SlotFill (e.g. `BuilderAiGenerate`) so this stays
 * a pure UX shell on the Lite side.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function AiPromptPanel() {
	const [ prompt, setPrompt ] = useState( '' );

	return (
		<section className="nf-palette__section nf-ai-prompt">
			<h3 className="nf-palette__title">
				{ __( 'Generate with AI', 'neural-forms-lite' ) }
				<span className="nf-palette__title-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
			</h3>
			<p className="nf-ai-prompt__hint">
				{ __( 'Describe the form you want and we will draft the fields.', 'neural-forms-lite' ) }
			</p>
			<textarea
				className="nf-ai-prompt__input"
				rows={ 3 }
				placeholder={ __( 'e.g. Create a dental appointment form with name, email, phone, and preferred date.', 'neural-forms-lite' ) }
				value={ prompt }
				onChange={ ( e ) => setPrompt( e.target.value ) }
			/>
			<button
				type="button"
				className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
				disabled
				title={ __( 'Available with Neural Forms Pro', 'neural-forms-lite' ) }
			>
				{ __( 'Generate form', 'neural-forms-lite' ) }
			</button>
		</section>
	);
}
