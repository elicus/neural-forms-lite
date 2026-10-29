/**
 * Editor for neural-forms/buy-button (1.2.0 part B). Picks a form that
 * takes payments, the Priced Item (and option) to sell, quantity and
 * copy; previews through ServerSideRender (BuyButton::render).
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { ContainerPanel, LabelsPanel, InputFieldsPanel, SubmitButtonPanel, MessagesPanel, AdvancedPanel } from '../shared/DesignPanels';
import { PanelBody, SelectControl, TextControl, TextareaControl, ToggleControl, RangeControl, Notice, Spinner } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import api from '../../api';

let cache = null;

export default function BuyButtonEdit( { attributes, setAttributes } ) {
	const [ catalogue, setCatalogue ] = useState( cache );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		if ( cache ) return;
		api( '/buy-catalogue' ).then( ( res ) => { cache = res; setCatalogue( res ); } ).catch( ( e ) => setError( e?.message || __( 'Could not load forms.', 'neural-forms-lite' ) ) );
	}, [] );
	const forms   = catalogue?.forms || [];
	const form    = forms.find( ( f ) => f.id === attributes.formId );
	const product = form ? ( form.products.find( ( p ) => p.id === attributes.fieldId ) || form.products[ 0 ] ) : null;
	const blockProps = useBlockProps();
	const set = ( patch ) => setAttributes( patch );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'What to sell', 'neural-forms-lite' ) } initialOpen>
					{ catalogue === null && ! error && <Spinner /> }
					{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
					{ catalogue && catalogue.unlocked === false && <Notice status="warning" isDismissible={ false }>{ __( 'Payments need Neural Forms Pro. The card renders only when the form takes payments.', 'neural-forms-lite' ) }</Notice> }
					{ catalogue && forms.length === 0 && <Notice status="info" isDismissible={ false }>{ __( 'No form with a Priced Item yet. Add one in the form builder and turn on Form settings › Payments.', 'neural-forms-lite' ) }</Notice> }
					<SelectControl
						label={ __( 'Form', 'neural-forms-lite' ) }
						value={ String( attributes.formId || 0 ) }
						options={ [ { label: __( '— Select a form —', 'neural-forms-lite' ), value: '0' }, ...forms.map( ( f ) => ( { label: f.name + ( f.payments ? '' : ' — ' + __( 'payments off', 'neural-forms-lite' ) ), value: String( f.id ) } ) ) ] }
						onChange={ ( v ) => { const f = forms.find( ( x ) => x.id === Number( v ) ); set( { formId: Number( v ), fieldId: f?.products[ 0 ]?.id || '', choice: '' } ); } }
					/>
					{ form && ! form.payments && <Notice status="warning" isDismissible={ false }>{ __( 'This form does not take payments yet. Turn it on under Form settings › Payments.', 'neural-forms-lite' ) }</Notice> }
					{ form && form.products.length > 1 && (
						<SelectControl label={ __( 'Priced Item', 'neural-forms-lite' ) } value={ attributes.fieldId || form.products[ 0 ].id } options={ form.products.map( ( p ) => ( { label: p.label, value: p.id } ) ) } onChange={ ( v ) => set( { fieldId: v, choice: '' } ) } />
					) }
					{ product && product.mode === 'options' && (
						<SelectControl label={ __( 'Option to sell', 'neural-forms-lite' ) } value={ attributes.choice || product.options[ 0 ]?.value || '' } options={ product.options.map( ( o ) => ( { label: `${ o.label } — ${ o.price } ${ form.currency }`, value: o.value } ) ) } onChange={ ( v ) => set( { choice: v } ) } />
					) }
					{ product && product.mode === 'fixed' && <p className="components-base-control__help">{ __( 'Price:', 'neural-forms-lite' ) } { product.price } { form.currency }</p> }
					{ product && product.quantity && (
						<>
							<ToggleControl label={ __( 'Visitor can change the quantity', 'neural-forms-lite' ) } checked={ attributes.allowQuantity !== false } onChange={ ( v ) => set( { allowQuantity: v } ) } />
							<RangeControl label={ __( 'Default quantity', 'neural-forms-lite' ) } min={ 1 } max={ 20 } value={ attributes.quantity || 1 } onChange={ ( v ) => set( { quantity: v } ) } />
						</>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Card', 'neural-forms-lite' ) } initialOpen={ false }>
					<ToggleControl label={ __( 'Show item name', 'neural-forms-lite' ) } checked={ attributes.showTitle !== false } onChange={ ( v ) => set( { showTitle: v } ) } />
					<TextControl label={ __( 'Item name override', 'neural-forms-lite' ) } value={ attributes.title } onChange={ ( v ) => set( { title: v } ) } />
					<TextareaControl label={ __( 'Description', 'neural-forms-lite' ) } value={ attributes.description } onChange={ ( v ) => set( { description: v } ) } />
					<ToggleControl label={ __( 'Show price', 'neural-forms-lite' ) } checked={ attributes.showPrice !== false } onChange={ ( v ) => set( { showPrice: v } ) } />
					<ToggleControl label={ __( 'Ask for the buyer’s name', 'neural-forms-lite' ) } checked={ attributes.collectName !== false } onChange={ ( v ) => set( { collectName: v } ) } />
					<TextControl label={ __( 'Button label', 'neural-forms-lite' ) } value={ attributes.label } placeholder={ __( 'Buy now', 'neural-forms-lite' ) } onChange={ ( v ) => set( { label: v } ) } />
					<SelectControl label={ __( 'Width', 'neural-forms-lite' ) } value={ attributes.width || 'auto' } options={ [ { label: __( 'Card (max 420px)', 'neural-forms-lite' ), value: 'auto' }, { label: __( 'Full width', 'neural-forms-lite' ), value: 'full' } ] } onChange={ ( v ) => set( { width: v } ) } />
					<SelectControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ attributes.align || 'left' } options={ [ { label: __( 'Left', 'neural-forms-lite' ), value: 'left' }, { label: __( 'Center', 'neural-forms-lite' ), value: 'center' }, { label: __( 'Right', 'neural-forms-lite' ), value: 'right' } ] } onChange={ ( v ) => set( { align: v } ) } />
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="styles">
				<ContainerPanel    a={ attributes } set={ setAttributes } />
				<LabelsPanel       a={ attributes } set={ setAttributes } />
				<InputFieldsPanel  a={ attributes } set={ setAttributes } />
				<SubmitButtonPanel a={ attributes } set={ setAttributes } />
				<MessagesPanel     a={ attributes } set={ setAttributes } />
				<AdvancedPanel     a={ attributes } set={ setAttributes } />
			</InspectorControls>
			{ attributes.formId ? (
				<ServerSideRender block="neural-forms/buy-button" attributes={ attributes } httpMethod="POST" />
			) : (
				<div className="neural-forms-buy neural-forms-buy--notice">{ __( 'Payment form: choose a form that takes payments in the block settings.', 'neural-forms-lite' ) }</div>
			) }
		</div>
	);
}
