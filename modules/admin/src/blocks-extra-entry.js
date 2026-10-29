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
import { SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import baseMetadata          from '../../frontend/block/block.json';
import FormSelector          from './blocks/shared/FormSelector';
import FormDesignExtras      from './blocks/form/FormDesignExtras';
import PollDesignExtras      from './blocks/poll/PollDesignExtras';
import QuizDesignExtras      from './blocks/quiz/QuizDesignExtras';
import MultiStepDesignExtras from './blocks/multistep/MultiStepDesignExtras';
import BuyButtonEdit         from './blocks/buy/BuyButtonEdit';
import buyMetadata           from '../../frontend/blocks/buy-button/block.json';

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
		div[data-wp-component="BorderBoxControl"] > label {
			margin-bottom: 12px;
			display: block;
		}
		div[data-wp-component="BorderBoxControl"] {
			margin-bottom: 16px;
		}
		div[data-wp-component="BorderBoxControl"] div[data-wp-component="HStack"],
		div[data-wp-component="BoxControl"] div[data-wp-component="HStack"] {
			align-items: center !important;
		}
		.components-h-stack .components-base-control {
			margin-bottom: 0 !important;
		}
		.components-h-stack .components-range-control {
			margin-left: 10px;
		}
		.block-editor-line-height-control .components-input-control__container {
			width: 100%;
		}
		.components-panel__body .components-tools-panel {
			border-top: none !important;
			padding: 0 !important;
		}
		.nf-typo-panel.components-tools-panel {
			padding: 10px 0 !important;
		}
		.nf-typo-panel .single-column {
			grid-column: span 1;
		}
		.nf-typo-panel .single-column .components-input-control__container {
			width: 100%;
		}
		.nf-typo-panel .components-font-size-picker__controls {
			margin-bottom: 0;
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
 * Renders in the Display panel, between Show Field Labels and Submit
 * Button Text. Bound to `attributes.progressIndicatorOverride`, which
 * BlockHandler::render forwards into FormRenderer as
 * `progress_indicator_override`. Empty string means "use the form's
 * own saved setting" — admins still configure the default inside the
 * form builder; this control just lets one specific embed override it
 * without editing the form.
 *
 * Surface label is "Step Progress" rather than the internal
 * "Progress Indicator" — clearer to non-technical authors, and
 * covers all three option values (bar, numbers, none) without
 * implying it only swaps the bar variant.
 */
function MultiStepSettingsExtrasTop( { attributes, setAttributes } ) {
	// Coerce legacy '' (old "Use form default") to 'numbers' so existing
	// blocks display the correct option after the option was removed.
	const value        = String( attributes?.progressIndicatorOverride || 'numbers' );
	const hasIndicator = value !== 'none';
	return (
		<>
			<SelectControl
				label={ __( 'Step Progress', 'neural-forms-lite' ) }
				value={ value }
				options={ [
					{ label: __( 'Step numbers', 'neural-forms-lite' ), value: 'numbers' },
					{ label: __( 'Progress bar', 'neural-forms-lite' ), value: 'bar' },
					{ label: __( 'None',         'neural-forms-lite' ), value: 'none' },
				] }
				onChange={ ( v ) => setAttributes( { progressIndicatorOverride: v } ) }
			/>
			{ hasIndicator && (
				<ToggleControl
					label={ __( 'Show Step Titles', 'neural-forms-lite' ) }
					checked={ attributes?.showStepLabels !== false }
					onChange={ ( v ) => setAttributes( { showStepLabels: v } ) }
				/>
			) }
			{ hasIndicator && (
				<ToggleControl
					label={ __( 'Show Step Descriptions', 'neural-forms-lite' ) }
					checked={ attributes?.showStepDescriptions !== false }
					onChange={ ( v ) => setAttributes( { showStepDescriptions: v } ) }
				/>
			) }
		</>
	);
}

/**
 * Pro-gated "Add Row Button Text" control. Hidden when Pro is not active
 * (neuralFormsBlockCaps.fieldRepeater is false) — the repeater field
 * doesn't exist in Lite installs so the override is irrelevant.
 */
function RepeaterAddBtnTextControl( { attributes, setAttributes } ) {
	if ( ! window.neuralFormsBlockCaps?.fieldRepeater ) return null;
	return (
		<TextControl
			label={ __( 'Add Row Button Text', 'neural-forms-lite' ) }
			value={ attributes?.repeaterAddBtnText ?? 'Add item' }
			onChange={ ( v ) => setAttributes( { repeaterAddBtnText: v } ) }
			help={ __( 'Override the repeater field\'s "Add item" button label for this placement.', 'neural-forms-lite' ) }
		/>
	);
}

/**
 * Form block: Settings tab extras — only the repeater add-button text
 * override (Pro-gated). Rendered in the Display panel after Submit
 * Button Text.
 */
function FormSettingsExtrasBottom( props ) {
	return <RepeaterAddBtnTextControl { ...props } />;
}

/**
 * Multi Step block: per-placement Previous / Next button text. Lives
 * AFTER the shared Submit Button Text in the Display panel so all
 * three button-label controls cluster together (Submit / Previous /
 * Next), which matches how an author thinks about them.
 */
function MultiStepSettingsExtrasBottom( { attributes, setAttributes } ) {
	return (
		<>
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
			<RepeaterAddBtnTextControl { ...{ attributes, setAttributes } } />
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
		name:                 'neural-forms/multi-step',
		kind:                 'multistep',
		title:                __( 'Neural Multi Step', 'neural-forms-lite' ),
		description:          __( 'Embed a multi-step Neural Forms form. Picks up forms that contain two or more Step layout blocks.', 'neural-forms-lite' ),
		keywords:             [ __( 'multi-step', 'neural-forms-lite' ), __( 'wizard', 'neural-forms-lite' ), __( 'steps', 'neural-forms-lite' ) ],
		Extras:               MultiStepExtras,
		SettingsExtrasTop:    MultiStepSettingsExtrasTop,
		SettingsExtrasBottom: MultiStepSettingsExtrasBottom,
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

NEW_BLOCKS.forEach( ( { name, kind, title, description, keywords, Extras, SettingsExtrasTop, SettingsExtrasBottom } ) => {
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
				settingsExtrasTop={ SettingsExtrasTop ? <SettingsExtrasTop { ...props } /> : null }
				settingsExtrasBottom={ SettingsExtrasBottom ? <SettingsExtrasBottom { ...props } /> : null }
			/>
		),
		// Dynamic block — server renders via BlockHandler::render.
		save: () => null,
	} );
} );

// 1.2.0 part B: Buy button — its own attribute schema (block.json) and
// its own server render (BuyButton::render), unrelated to the form block.
registerBlockType( buyMetadata.name, {
	...buyMetadata,
	attributes: { ...baseAttrs, ...buyMetadata.attributes },
	title:       __( 'Neural Payment Form', 'neural-forms-lite' ),
	description: __( 'A compact payment form for one priced item: price, quantity, name, email and a Buy now button that goes straight to checkout.', 'neural-forms-lite' ),
	edit: BuyButtonEdit,
	save: () => null,
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
	// Capture icon / description from the block.js registration before
	// unregistering so the re-registered block keeps the original visuals
	// in the inserter. Title is hardcoded here (NOT pulled from `existing`)
	// because the protected build/block.js bundle baked in the old
	// "Neural Forms Form" string at compile time, and updating block.json
	// alone can't reach it. Re-asserting the title here is the canonical
	// override.
	const existing = wp.blocks.getBlockType( 'neural-forms/form' );

	unregisterBlockType( 'neural-forms/form' );

	registerBlockType( 'neural-forms/form', {
		apiVersion:  3,
		title:       __( 'Neural Forms', 'neural-forms-lite' ),
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
				settingsExtrasBottom={ <FormSettingsExtrasBottom { ...props } /> }
			/>
		),
		save: () => null,
	} );
} );
