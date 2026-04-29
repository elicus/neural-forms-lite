/**
 * Gutenberg block entry - registers the "neural-forms/form" block.
 *
 * Edit component renders ServerSideRender so the editor preview matches
 * what the frontend will output (FormRenderer.php). InspectorControls
 * provides the form picker. The full styling controls described in
 * block.json's attribute list are NOT implemented here - they were part
 * of the original compiled block bundle and are deferred until that
 * source is rebuilt.
 *
 * The block stays usable today: pick a form, see the preview, save.
 * Style attributes still serialize but currently have no UI to set them.
 */

import { registerBlockType }                 from '@wordpress/blocks';
import { useBlockProps, InspectorControls }  from '@wordpress/block-editor';
import { PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState }               from '@wordpress/element';
import ServerSideRender                      from '@wordpress/server-side-render';
import { __ }                                from '@wordpress/i18n';
import metadata                              from '../../frontend/block/block.json';
import api                                    from './api';

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const { formId } = attributes;
	const [ forms,   setForms   ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		let cancelled = false;
		api( '/forms?status=active&per_page=100' )
			.then( ( res ) => {
				if ( cancelled ) return;
				const list = Array.isArray( res?.items ) ? res.items : ( Array.isArray( res ) ? res : [] );
				setForms( list );
				setLoading( false );
			} )
			.catch( () => {
				if ( cancelled ) return;
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [] );

	const options = [
		{ label: __( 'Select a form...', 'neural-forms-lite' ), value: '' },
		...forms.map( ( f ) => ( {
			label: f.name || `#${ f.id }`,
			value: String( f.id ),
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'neural-forms-lite' ) } initialOpen={ true }>
					{ loading ? (
						<Spinner />
					) : (
						<SelectControl
							label={ __( 'Form', 'neural-forms-lite' ) }
							value={ formId ? String( formId ) : '' }
							options={ options }
							onChange={ ( v ) => setAttributes( { formId: v ? parseInt( v, 10 ) : undefined } ) }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			{ ! formId ? (
				<p>{ __( 'Select a form from the sidebar.', 'neural-forms-lite' ) }</p>
			) : (
				<ServerSideRender block={ metadata.name } attributes={ attributes } />
			) }
		</div>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	save: () => null, // dynamic block - render_callback in PHP
} );
