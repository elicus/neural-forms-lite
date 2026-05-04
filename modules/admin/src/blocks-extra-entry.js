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

import { registerBlockType } from '@wordpress/blocks';
import { __ }                from '@wordpress/i18n';
import { PanelBody, PanelRow, SelectControl, ExternalLink } from '@wordpress/components';
import baseMetadata          from '../../frontend/block/block.json';
import FormSelector          from './blocks/shared/FormSelector';

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
 * Resolve admin URL the same way FormSelector does — used here for
 * the Edit-form / Analytics anchors inside the block-specific notes.
 */
function adminBase() {
	if ( typeof window !== 'undefined' && window.neuralFormsAdmin?.admin?.ajaxUrl ) {
		return new URL( '../', window.neuralFormsAdmin.admin.ajaxUrl ).pathname;
	}
	return '/wp-admin/';
}

/**
 * Multi Step extras — a real, schema-non-mutating override for the
 * progress-indicator style. The form's own
 * form_schema.settings.multistep.progress_indicator is left untouched;
 * BlockHandler::render forwards the attribute as a render option,
 * FormRenderer::render_form shadows the schema value when the option
 * resolves to one of {numbers, bar, none}. Empty string (default) =
 * "Use form setting".
 */
function MultiStepExtras( { attributes, setAttributes } ) {
	const value = attributes.progressIndicatorOverride || '';
	return (
		<PanelBody title={ __( 'Multi Step', 'neural-forms-lite' ) } initialOpen={ false }>
			<SelectControl
				label={ __( 'Progress indicator', 'neural-forms-lite' ) }
				help={ __(
					'Override only this placement. Does not change the form’s saved setting.',
					'neural-forms-lite'
				) }
				value={ value }
				options={ [
					{ label: __( 'Use form setting', 'neural-forms-lite' ), value: ''        },
					{ label: __( 'Step numbers',     'neural-forms-lite' ), value: 'numbers' },
					{ label: __( 'Progress bar',     'neural-forms-lite' ), value: 'bar'     },
					{ label: __( 'None',             'neural-forms-lite' ), value: 'none'    },
				] }
				onChange={ ( v ) => setAttributes( { progressIndicatorOverride: v || '' } ) }
			/>
			<p style={ { fontSize: 12, color: '#6b7280', marginTop: 8, marginBottom: 0 } }>
				{ __(
					'Step labels, button text and step content are configured in the form builder.',
					'neural-forms-lite'
				) }
			</p>
		</PanelBody>
	);
}

/**
 * Poll extras — read-only notes. The renderer reads results_visibility
 * and chart_type from each Poll FIELD’s settings (per-field, not
 * form-level), so a clean block-level override would have to thread
 * through render_nodes → render_field → render_poll. That’s
 * deferred until the renderer grows a context-options channel; until
 * then we point the author at the form builder rather than ship a
 * SelectControl that does nothing.
 */
function PollExtras( { attributes } ) {
	const formId = attributes.formId;
	const adminUrl = adminBase();
	const editUrl     = formId ? `${ adminUrl }admin.php?page=neural-forms&edit=${ formId }`             : '';
	const analyticsUrl = formId ? `${ adminUrl }admin.php?page=neural-forms-results&form_id=${ formId }` : '';

	return (
		<PanelBody title={ __( 'Poll Results', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 12, color: '#374151', marginTop: 0 } }>
				{ __(
					'Poll result visibility and chart style are configured per Poll field in the form builder.',
					'neural-forms-lite'
				) }
			</p>
			<ul style={ { fontSize: 12, color: '#6b7280', paddingLeft: 18, margin: '0 0 8px' } }>
				<li>{ __( 'Visibility: after vote, always, or never.', 'neural-forms-lite' ) }</li>
				<li>{ __( 'Chart style: bar or percentage.',          'neural-forms-lite' ) }</li>
			</ul>
			{ formId > 0 && (
				<PanelRow>
					<span style={ { fontSize: 12 } }>
						<ExternalLink href={ editUrl }>{ __( 'Edit form', 'neural-forms-lite' ) }</ExternalLink>
						{ ' · ' }
						<ExternalLink href={ analyticsUrl }>{ __( 'Analytics', 'neural-forms-lite' ) }</ExternalLink>
					</span>
				</PanelRow>
			) }
		</PanelBody>
	);
}

/**
 * Quiz extras — read-only notes only. The brief is explicit: do not
 * invent unsupported quiz display overrides. Scoring, correctness
 * visibility and pass/fail copy live on quiz_question fields and
 * form-level settings inside the builder.
 */
function QuizExtras( { attributes } ) {
	const formId = attributes.formId;
	const adminUrl = adminBase();
	const editUrl     = formId ? `${ adminUrl }admin.php?page=neural-forms&edit=${ formId }`             : '';
	const analyticsUrl = formId ? `${ adminUrl }admin.php?page=neural-forms-results&form_id=${ formId }` : '';

	return (
		<PanelBody title={ __( 'Quiz Settings', 'neural-forms-lite' ) } initialOpen={ false }>
			<ul style={ { fontSize: 12, color: '#374151', paddingLeft: 18, margin: '0 0 8px' } }>
				<li>{ __( 'Scoring is configured in the form builder.',                            'neural-forms-lite' ) }</li>
				<li>{ __( 'Correct-answer visibility is set per Quiz Question field.',             'neural-forms-lite' ) }</li>
				<li>{ __( 'Pass / fail thresholds and result messages live in Form Settings.',     'neural-forms-lite' ) }</li>
			</ul>
			{ formId > 0 && (
				<PanelRow>
					<span style={ { fontSize: 12 } }>
						<ExternalLink href={ editUrl }>{ __( 'Edit form', 'neural-forms-lite' ) }</ExternalLink>
						{ ' · ' }
						<ExternalLink href={ analyticsUrl }>{ __( 'Analytics', 'neural-forms-lite' ) }</ExternalLink>
					</span>
				</PanelRow>
			) }
		</PanelBody>
	);
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
		name:        'neural-forms/multi-step',
		kind:        'multistep',
		title:       __( 'Neural Multi Step', 'neural-forms-lite' ),
		description: __( 'Embed a multi-step Neural Forms form. Picks up forms that contain two or more Step layout blocks.', 'neural-forms-lite' ),
		keywords:    [ __( 'multi-step', 'neural-forms-lite' ), __( 'wizard', 'neural-forms-lite' ), __( 'steps', 'neural-forms-lite' ) ],
		Extras:      MultiStepExtras,
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

NEW_BLOCKS.forEach( ( { name, kind, title, description, keywords, Extras } ) => {
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
			/>
		),
		// Dynamic block — server renders via BlockHandler::render.
		save: () => null,
	} );
} );
