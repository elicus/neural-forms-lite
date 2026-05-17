/**
 * Editor entry for the 3 extra Neural Forms blocks (added in 0.2.0):
 *
 *   - neural-forms/quiz
 *   - neural-forms/multi-step
 *   - neural-forms/poll
 *
 * The original `neural-forms/form` block (rich style controls, full
 * Inspector) is deliberately NOT touched here — it lives in
 * `block-index.js` → `build/block.js`, the pre-existing 1.0.0 bundle
 * whose source parity has not been restored. Folding the new blocks
 * into that bundle would mean rebuilding it from a partial src/ tree
 * and shipping a regressed copy of the form block. So this entry
 * compiles into a SIBLING bundle (`build/blocks-extra.js`), and PHP
 * registers each new block against the new editor-script handle
 * `neural-forms-blocks-extra`.
 *
 * All three new blocks share:
 *   - the form block's attribute schema (so the same PHP render
 *     callback can render any of them — see BlockHandler::render)
 *   - the FormSelector edit component (form-picker + ServerSideRender
 *     preview + compatibility warning + Edit/Analytics links)
 *
 * They differ only in:
 *   - block name + title + description + keywords
 *   - the `blockKind` prop passed to FormSelector, which drives the
 *     filter in matchesBlockType (quiz | poll | multistep)
 *   - their Inspector "extras" panel: Multi Step exposes a real
 *     per-placement override (progressIndicatorOverride), while
 *     Quiz and Poll surface read-only helper notes pointing the
 *     author back to the form builder. We intentionally do NOT
 *     invent block-level overrides for settings the renderer can't
 *     yet honour — see the brief.
 */

import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';
import { __ }                from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import baseMetadata          from '../../frontend/block/block.json';
import FormSelector          from './blocks/shared/FormSelector';
import FormDesignExtras      from './blocks/form/FormDesignExtras';
import PollDesignExtras      from './blocks/poll/PollDesignExtras';
import QuizDesignExtras      from './blocks/quiz/QuizDesignExtras';
import MultiStepDesignExtras from './blocks/multistep/MultiStepDesignExtras';

// Inject Inspector-sidebar CSS into the main admin document.
// form.css is loaded as editor_style → it reaches the canvas iframe only
// (WP 6.5+ canvas-in-iframe). The Inspector sidebar lives in the MAIN
// admin document, so styles must be injected here instead.
( function () {
	const s = document.createElement( 'style' );
	s.textContent = `
		fieldset[data-wp-component="BorderControl"] legend,
		fieldset[data-wp-component="BorderControl"] .components-base-control__label {
			margin-bottom: 20px;
		}
		fieldset[data-wp-component="BorderControl"] {
			margin-bottom: 16px;
		}
		.block-editor-line-height-control .components-input-control__container {
			width: 100%;
		}
	`;
	document.head.appendChild( s );
}() );

/**
 * Build a SAME-SHAPE clone of the form block's attribute schema. We
 * want a fresh object per block (defensive — registerBlockType in some
 * Gutenberg versions mutates the passed metadata) and we want every
 * new block to declare the exact attribute set the PHP render callback
 * expects, so future style controls light up identically.
 */
const baseAttrs    = JSON.parse( JSON.stringify( baseMetadata.attributes || {} ) );
const baseSupports = JSON.parse( JSON.stringify( baseMetadata.supports || {} ) );

/**
 * Multi Step extras — full design sidebar via MultiStepDesignExtras.
 * Includes all shared field panels + NavigationButtonsPanel (Prev/Next/Submit)
 * + StepIndicatorPanel (bar + numbers modes).
 */
function MultiStepExtras( props ) {
	return <MultiStepDesignExtras { ...props } />;
}

/**
 * Multi Step block: per-placement progress-indicator override.
 *
 * Renders in the Settings tab (NOT Styles) directly under the Form
 * Title input. Bound to `attributes.progressIndicatorOverride`,
 * which BlockHandler::render forwards into FormRenderer as
 * `progress_indicator_override`. Empty string means "use the form's
 * own saved setting" — admins still configure the default inside
 * the form builder; this control just lets one specific embed
 * override it without editing the form.
 *
 * Option labels match the form-builder dropdown so the two surfaces
 * read consistently.
 */
function MultiStepSettingsExtras( { attributes, setAttributes } ) {
	const value = String( attributes?.progressIndicatorOverride || '' );
	return (
		<>
			<SelectControl
				label={ __( 'Progress Indicator', 'neural-forms-lite' ) }
				value={ value }
				options={ [
					{ label: __( 'Use form default', 'neural-forms-lite' ), value: '' },
					{ label: __( 'Step numbers',     'neural-forms-lite' ), value: 'numbers' },
					{ label: __( 'Progress bar',     'neural-forms-lite' ), value: 'bar' },
					{ label: __( 'None',             'neural-forms-lite' ), value: 'none' },
				] }
				onChange={ ( v ) => setAttributes( { progressIndicatorOverride: v || '' } ) }
				help={ __( 'Override the form\'s saved progress indicator for this placement only.', 'neural-forms-lite' ) }
			/>
			<TextControl
				label={ __( 'Previous Button Text', 'neural-forms-lite' ) }
				value={ attributes?.prevButtonText ?? 'Previous' }
				onChange={ ( v ) => setAttributes( { prevButtonText: v } ) }
				help={ __( 'Leave empty to render an empty Previous button (icon-only support).', 'neural-forms-lite' ) }
			/>
			<TextControl
				label={ __( 'Next Button Text', 'neural-forms-lite' ) }
				value={ attributes?.nextButtonText ?? 'Next' }
				onChange={ ( v ) => setAttributes( { nextButtonText: v } ) }
				help={ __( 'Leave empty to render an empty Next button (icon-only support).', 'neural-forms-lite' ) }
			/>
		</>
	);
}

/**
 * Poll extras — full design settings via PollDesignExtras.
 * Links to builder / analytics are preserved inside ResultsBarPanel.
 */
function PollExtras( props ) {
	return <PollDesignExtras { ...props } />;
}

/**
 * Quiz extras — full design settings via QuizDesignExtras.
 */
function QuizExtras( props ) {
	return <QuizDesignExtras { ...props } />;
}

const NEW_BLOCKS = [
	{
		name:        'neural-forms/quiz',
		kind:        'quiz',
		title:       __( 'Neural Quiz',  'neural-forms-lite' ),
		description: __( 'Embed a Neural Forms quiz on any post or page. Picks up forms with quiz_question fields.', 'neural-forms-lite' ),
		keywords:    [ __( 'quiz', 'neural-forms-lite' ), __( 'questions', 'neural-forms-lite' ), __( 'score', 'neural-forms-lite' ) ],
		Extras:      QuizExtras,
	},
	{
		name:           'neural-forms/multi-step',
		kind:           'multistep',
		title:          __( 'Neural Multi Step', 'neural-forms-lite' ),
		description:    __( 'Embed a multi-step Neural Forms form. Picks up forms that contain two or more Step layout blocks.', 'neural-forms-lite' ),
		keywords:       [ __( 'multi-step', 'neural-forms-lite' ), __( 'wizard', 'neural-forms-lite' ), __( 'steps', 'neural-forms-lite' ) ],
		Extras:         MultiStepExtras,
		SettingsExtras: MultiStepSettingsExtras,
	},
	{
		name:        'neural-forms/poll',
		kind:        'poll',
		title:       __( 'Neural Poll', 'neural-forms-lite' ),
		description: __( 'Embed a Neural Forms poll. Picks up forms that contain a Poll field.', 'neural-forms-lite' ),
		keywords:    [ __( 'poll', 'neural-forms-lite' ), __( 'survey', 'neural-forms-lite' ), __( 'vote', 'neural-forms-lite' ) ],
		Extras:      PollExtras,
	},
];

NEW_BLOCKS.forEach( ( { name, kind, title, description, keywords, Extras, SettingsExtras } ) => {
	registerBlockType( name, {
		apiVersion:  3,
		title,
		description,
		category:    'embed',
		keywords,
		textdomain:  'neural-forms-lite',
		attributes:  baseAttrs,
		supports:    baseSupports,
		edit: ( props ) => (
			<FormSelector
				{ ...props }
				blockKind={ kind }
				blockName={ name }
				extras={ <Extras { ...props } /> }
				settingsExtras={ SettingsExtras ? <SettingsExtras { ...props } /> : null }
			/>
		),
		// Dynamic block — server renders via BlockHandler::render.
		save: () => null,
	} );
} );

/**
 * Replace the neural-forms/form block registered by the protected block.js
 * bundle with an updated version that uses the full FormDesignExtras sidebar.
 * We must unregister first (block.js already ran — it's listed as a dependency
 * of neural-forms-blocks-extra so it's guaranteed to execute before this file)
 * and then re-register with identical metadata so saved post content is not
 * affected. The render callback is server-side (BlockHandler::render) so save()
 * always returns null regardless of which JS bundle registered the block.
 */
function FormExtras( props ) {
	return <FormDesignExtras { ...props } />;
}

wp.domReady( () => {
	// Capture title and icon from the block.js registration before unregistering,
	// so the re-registered block is visually identical in the inserter.
	const existing = wp.blocks.getBlockType( 'neural-forms/form' );

	unregisterBlockType( 'neural-forms/form' );

	registerBlockType( 'neural-forms/form', {
		apiVersion:  3,
		title:       existing?.title,
		icon:        existing?.icon,
		description: existing?.description,
		category:    existing?.category || 'embed',
		keywords:    existing?.keywords || [],
		textdomain:  'neural-forms-lite',
		attributes:  JSON.parse( JSON.stringify( baseMetadata.attributes || {} ) ),
		supports:    JSON.parse( JSON.stringify( baseMetadata.supports   || {} ) ),
		edit: ( props ) => (
			<FormSelector
				{ ...props }
				blockKind="form"
				blockName="neural-forms/form"
				extras={ <FormExtras { ...props } /> }
			/>
		),
		save: () => null,
	} );
} );
