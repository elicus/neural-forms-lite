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

import { useEffect, useState, useMemo } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, ToggleControl, Spinner, Notice, ExternalLink } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import api from '../../api';
import { detectFormType, matchesBlockType, typeLabel } from './formType';

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

export default function FormSelector( {
	attributes, setAttributes, blockKind, blockName, extras = null, settingsExtras = null,
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

					{ ! loading && (
						<ToggleControl
							label={ __( 'Show Field Labels', 'neural-forms-lite' ) }
							checked={ !! attributes.showLabels }
							onChange={ ( v ) => setAttributes( { showLabels: !! v } ) }
							help={ __( 'When off, each field shows only its placeholder. Turn on to render a visible label above every input.', 'neural-forms-lite' ) }
						/>
					) }

					{ ! loading && (
						<TextControl
							label={ __( 'Submit Button Text', 'neural-forms-lite' ) }
							value={ attributes.submitButtonText ?? 'Submit' }
							onChange={ ( v ) => setAttributes( { submitButtonText: v } ) }
							help={ __( 'Leave empty to render an empty submit button — useful when you style it as icon-only via theme CSS.', 'neural-forms-lite' ) }
						/>
					) }

					{ ! loading && settingsExtras }

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
			</InspectorControls>

			{ extras && (
				<InspectorControls group="styles">
					{ extras }
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
