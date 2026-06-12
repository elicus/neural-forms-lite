/**
 * Shared form-selector + preview shell used by all 4 Neural Forms
 * blocks. Renders:
 *   - SelectControl filtered to forms matching `blockKind`
 *   - ServerSideRender preview of the chosen form
 *   - Edit / Analytics admin links for the chosen form
 *   - Compatibility warning when the chosen form does NOT match the
 *     current block kind (form was changed elsewhere; saved selection
 *     still renders, just with a non-fatal hint)
 *
 * Also accepts `extras` slot for block-specific Inspector controls
 * (Poll: results-visibility/chart override, Multi-Step: progress
 * indicator override, Quiz: read-only note). Keeps the form-fetch
 * logic in one place across all 4 blocks.
 */

import { useEffect, useState, useMemo, cloneElement, isValidElement } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, ToggleControl, Spinner, Notice, ExternalLink } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import api from '../../api';
import { detectFormType, matchesBlockType, typeLabel } from './formType';
import { hasWelcomeScreen } from '../../forms/schemaHelper';

const EMPTY_COPY = {
	form:      [ __( 'No simple forms found. Create a form first.', 'neural-forms-lite' ) ],
	quiz:      [ __( 'No quiz forms found. Add a Quiz Question field to a form.', 'neural-forms-lite' ) ],
	poll:      [ __( 'No poll forms found. Add a Poll field to a form.', 'neural-forms-lite' ) ],
	multistep: [ __( 'No multi-step forms found. Add Step layout blocks to a form.', 'neural-forms-lite' ) ],
};

const PICK_COPY = {
	form:      __( 'Choose a form.',            'neural-forms-lite' ),
	quiz:      __( 'Choose a quiz form.',       'neural-forms-lite' ),
	poll:      __( 'Choose a poll form.',       'neural-forms-lite' ),
	multistep: __( 'Choose a multi-step form.', 'neural-forms-lite' ),
};

const PANEL_TITLE = {
	form:      __( 'Neural Form',       'neural-forms-lite' ),
	quiz:      __( 'Neural Quiz',       'neural-forms-lite' ),
	poll:      __( 'Neural Poll',       'neural-forms-lite' ),
	multistep: __( 'Neural Multi Step', 'neural-forms-lite' ),
};

// Display panel sits below the Form panel and only opens on demand
// (initialOpen=false) so the form picker remains the focal point when
// the block is first selected — the visitor / author looking at a new
// embed needs to choose a form before any display tweaks matter.
const DISPLAY_PANEL_TITLE = __( 'Display', 'neural-forms-lite' );

export default function FormSelector( {
	attributes, setAttributes, blockKind, blockName, extras = null,
	settingsExtras = null,
	// `settingsExtrasTop` renders BETWEEN Show Field Labels and Submit
	// Button Text. `settingsExtrasBottom` renders AFTER Submit Button
	// Text. Multi Step uses these so the Display panel reads:
	//   Show Field Labels → Progress Bar → Submit → Previous → Next.
	// `settingsExtras` (no suffix) is kept for back-compat with any
	// existing caller and renders at the bottom alongside
	// settingsExtrasBottom — equivalent to the previous behavior.
	settingsExtrasTop = null,
	settingsExtrasBottom = null,
} ) {
	const blockProps = useBlockProps();
	const { formId } = attributes;

	const [ forms,   setForms   ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		let cancelled = false;
		api( '/forms?status=active&per_page=100' )
			.then( ( res ) => {
				if ( cancelled ) return;
				const list = Array.isArray( res?.items ) ? res.items
					: ( Array.isArray( res ) ? res : [] );
				setForms( list );
				setLoading( false );
			} )
			.catch( () => {
				if ( cancelled ) return;
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [] );

	const filteredForms = useMemo(
		() => forms.filter( ( f ) => matchesBlockType( f, blockKind ) ),
		[ forms, blockKind ]
	);

	const selectedForm = useMemo(
		() => forms.find( ( f ) => Number( f.id ) === Number( formId ) ) || null,
		[ forms, formId ]
	);

	// Compatibility check — if a form was previously selected and its
	// fields changed (e.g. an admin removed all quiz_question fields
	// from a form picked via the Neural Quiz block), warn but keep
	// rendering. Never fatal, never hide the form.
	const selectedTypeMatches = selectedForm
		? matchesBlockType( selectedForm, blockKind )
		: true;
	const selectedDetectedType = selectedForm ? detectFormType( selectedForm.form_schema ) : null;

	// Whether the selected form's schema contains a Welcome Screen layout
	// block — drives both the "Show Welcome Screen" toggle below and the
	// Style-tab WelcomeScreenPanel (passed to `extras` via cloneElement).
	const formHasWelcomeScreen = hasWelcomeScreen( selectedForm?.form_schema?.fields );

	const options = [
		{ label: PICK_COPY[ blockKind ] || PICK_COPY.form, value: '' },
		...filteredForms.map( ( f ) => ( {
			label: f.name || `#${ f.id }`,
			value: String( f.id ),
		} ) ),
	];

	// Always include the currently-selected form in the dropdown even
	// when it no longer matches `blockKind` — the user can still see
	// what's selected, the warning explains why it's odd.
	if ( selectedForm && ! filteredForms.find( ( f ) => Number( f.id ) === Number( formId ) ) ) {
		options.push( {
			label: ( selectedForm.name || `#${ selectedForm.id }` ) + ' (' + ( selectedDetectedType || 'unknown' ) + ')',
			value: String( selectedForm.id ),
		} );
	}

	const adminUrl = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.admin?.ajaxUrl )
		? new URL( '../', window.neuralFormsAdmin.admin.ajaxUrl ).pathname
		: '/wp-admin/';
	const editUrl     = formId ? `${ adminUrl }admin.php?page=neural-forms&edit=${ formId }`             : '';
	const analyticsUrl = formId ? `${ adminUrl }admin.php?page=neural-forms-results&form_id=${ formId }` : '';

	return (
		<div { ...blockProps }>
			<InspectorControls>
				{ /*
				   Panel 1 — Form. Owns the form picker, title, type-
				   compatibility warning, and the Edit / Analytics links
				   for the chosen form. This is the panel an author hits
				   first when configuring a new embed, so it stays
				   initialOpen.
				*/ }
				<PanelBody title={ PANEL_TITLE[ blockKind ] || PANEL_TITLE.form } initialOpen={ true }>
					{ loading && <Spinner /> }
					{ ! loading && filteredForms.length === 0 && (
						<Notice status="info" isDismissible={ false }>
							{ EMPTY_COPY[ blockKind ] || EMPTY_COPY.form }
						</Notice>
					) }
					{ ! loading && (
						<SelectControl
							label={ __( 'Select Form', 'neural-forms-lite' ) }
							value={ formId ? String( formId ) : '' }
							options={ options }
							onChange={ ( v ) => setAttributes( { formId: v ? Number( v ) : undefined } ) }
						/>
					) }

					{ ! loading && (
						<TextControl
							label={ __( 'Form Title', 'neural-forms-lite' ) }
							value={ attributes.formTitle || '' }
							onChange={ ( v ) => setAttributes( { formTitle: v } ) }
							help={ __( 'Optional heading rendered above the form. Style it under Styles → Form Title.', 'neural-forms-lite' ) }
						/>
					) }

					{ selectedForm && ! selectedTypeMatches && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This selected form does not match this block type. It will still render, but you may want to choose a matching form.',
								'neural-forms-lite'
							) }
						</Notice>
					) }

					{ false && selectedForm && (
						<p style={ { fontSize: 12, color: '#6b7280', marginTop: 8 } }>
							<strong>{ selectedForm.name }</strong>
							{ selectedForm.slug && (
								<>
									<br />
									<code style={ { fontSize: 11 } }>{ selectedForm.slug }</code>
								</>
							) }
							<br />
							<span>{ sprintf(
								/* translators: %s: detected form-type label */
								__( 'Type: %s', 'neural-forms-lite' ),
								typeLabel( selectedDetectedType )
							) }</span>
						</p>
					) }

					{ formId > 0 && (
						<p style={ { marginTop: 8, fontSize: 12 } }>
							<ExternalLink href={ editUrl }>{ __( 'Edit form', 'neural-forms-lite' ) }</ExternalLink>
							{ blockKind !== 'form' && (
								<>
									{ ' · ' }
									<ExternalLink href={ analyticsUrl }>{ __( 'Analytics', 'neural-forms-lite' ) }</ExternalLink>
								</>
							) }
						</p>
					) }
				</PanelBody>

				{ /*
				   Panel 2 — Display. Owns presentational toggles that
				   don't change WHICH form is rendered, only how it
				   shows. Collapsed by default so the picker stays
				   visually dominant in the sidebar on first open.

				   Quiz + Poll suppress the Show Field Labels toggle
				   because for those blocks the question text IS the
				   label — render_quiz_question / render_poll always
				   emit it regardless of show_labels. Surfacing the
				   toggle there would imply it controls the question
				   text, which it doesn't. We still render the panel
				   on Quiz / Poll so Submit Button Text remains
				   reachable.

				   Multi Step's settingsExtras (Progress Indicator
				   override + Previous / Next button text) is also a
				   display concern, so it ships here under the same
				   panel.
				*/ }
				{ ! loading && (
					<PanelBody title={ DISPLAY_PANEL_TITLE } initialOpen={ false }>
						<ToggleControl
							label={ __( 'Show Field Labels', 'neural-forms-lite' ) }
							checked={ !! attributes.showLabels }
							onChange={ ( v ) => setAttributes( { showLabels: !! v } ) }
							help={ __( 'When off, each field shows only its placeholder. Turn on to render a visible label above every input.', 'neural-forms-lite' ) }
						/>
						<ToggleControl
							label={ __( 'Show Success Message', 'neural-forms-lite' ) }
							checked={ attributes.successMsgEnabled !== false }
							onChange={ ( v ) => setAttributes( { successMsgEnabled: v } ) }
						/>
						<ToggleControl
							label={ __( 'Show Error Message', 'neural-forms-lite' ) }
							checked={ attributes.errorMsgEnabled !== false }
							onChange={ ( v ) => setAttributes( { errorMsgEnabled: v } ) }
						/>
						{ blockKind === 'quiz' && (
							<ToggleControl
								label={ __( 'Show Quiz Results', 'neural-forms-lite' ) }
								help={ __( 'When off, only the success message is shown after submitting.', 'neural-forms-lite' ) }
								checked={ attributes.showQuizResults !== false }
								onChange={ ( v ) => setAttributes( { showQuizResults: v } ) }
							/>
						) }
						{ blockKind === 'poll' && (
							<ToggleControl
								label={ __( 'Show Poll Results', 'neural-forms-lite' ) }
								help={ __( 'When off, only the success message is shown after voting.', 'neural-forms-lite' ) }
								checked={ attributes.showPollResults !== false }
								onChange={ ( v ) => setAttributes( { showPollResults: v } ) }
							/>
						) }
						{ formHasWelcomeScreen && (
							<ToggleControl
								label={ __( 'Show Welcome Screen', 'neural-forms-lite' ) }
								help={ __( 'When off, the form\'s Welcome Screen is skipped and the form body shows immediately.', 'neural-forms-lite' ) }
								checked={ attributes.showWelcomeScreen !== false }
								onChange={ ( v ) => setAttributes( { showWelcomeScreen: v } ) }
							/>
						) }

						{ settingsExtrasTop }

						{ settingsExtrasBottom }
						{ settingsExtras }

						<TextControl
							label={ __( 'Submit Button Text', 'neural-forms-lite' ) }
							value={ attributes.submitButtonText ?? 'Submit' }
							onChange={ ( v ) => setAttributes( { submitButtonText: v } ) }
							help={ __( 'Leave empty to render an empty submit button — useful when you style it as icon-only via theme CSS.', 'neural-forms-lite' ) }
						/>
					</PanelBody>
				) }
			</InspectorControls>

			{ extras && (
				<InspectorControls group="styles">
					{ isValidElement( extras ) ? cloneElement( extras, { formHasWelcomeScreen } ) : extras }
				</InspectorControls>
			) }

			{ formId > 0 ? (
				<ServerSideRender
					block={ blockName }
					attributes={ attributes }
					httpMethod="POST"
					EmptyResponsePlaceholder={ () => (
						<p style={ { padding: 16, color: '#6b7280', fontStyle: 'italic' } }>
							{ __( 'No preview available.', 'neural-forms-lite' ) }
						</p>
					) }
				/>
			) : (
				<div style={ { padding: 24, border: '1px dashed #e5e7eb', borderRadius: 8, color: '#6b7280' } }>
					{ PICK_COPY[ blockKind ] || PICK_COPY.form }
				</div>
			) }
		</div>
	);
}
