import { __ } from '@wordpress/i18n';
import {
	useSettings,
	LineHeightControl,
	__experimentalFontAppearanceControl as FontAppearanceControl,
	__experimentalLetterSpacingControl  as LetterSpacingControl,
	__experimentalTextTransformControl  as TextTransformControl,
	__experimentalTextDecorationControl as TextDecorationControl,
} from '@wordpress/block-editor';
import {
	BaseControl,
	PanelBody, ColorPalette, GradientPicker,
	SelectControl, ToggleControl,
	TextControl, BoxControl, BorderControl,
	__experimentalUnitControl         as UnitControl,
	__experimentalToggleGroupControl  as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

function Sep() {
	return <hr style={{ margin: '12px 0', border: 'none', borderTop: '1px solid #e0e0e0' }} />;
}

function Clr({ label, value, onChange }) {
	return (
		<BaseControl label={ label } __nextHasNoMarginBottom>
			<ColorPalette value={ value } onChange={ onChange } />
		</BaseControl>
	);
}

function Grad({ label, value, onChange }) {
	return (
		<BaseControl label={ label } __nextHasNoMarginBottom>
			<GradientPicker value={ value || undefined } onChange={ onChange } />
		</BaseControl>
	);
}

function Gap() {
	return <div style={{ marginTop: 16 }} />;
}

const SYSTEM_FONTS = [
	{ label: 'Arial', value: 'Arial, sans-serif' },
	{ label: 'Georgia', value: 'Georgia, serif' },
	{ label: 'Helvetica Neue', value: '"Helvetica Neue", Helvetica, sans-serif' },
	{ label: 'Times New Roman', value: '"Times New Roman", Times, serif' },
	{ label: 'Trebuchet MS', value: '"Trebuchet MS", sans-serif' },
	{ label: 'Verdana', value: 'Verdana, sans-serif' },
	{ label: 'Courier New', value: '"Courier New", monospace' },
	{ label: 'system-ui', value: 'system-ui, sans-serif' },
];

function FontFamilySelect( { label, value, onChange } ) {
	const [ rawFamilies ] = useSettings( 'typography.fontFamilies' );
	const themeFonts = Array.isArray( rawFamilies )
		? rawFamilies.map( f => ( { label: f.name, value: f.fontFamily } ) )
		: Array.isArray( rawFamilies?.theme )
			? [ ...( rawFamilies.default || [] ), ...( rawFamilies.theme || [] ), ...( rawFamilies.user || [] ) ]
				.map( f => ( { label: f.name, value: f.fontFamily } ) )
			: [];
	const options = [
		{ label: __( 'Default', 'neural-forms-lite' ), value: '' },
		...( themeFonts.length > 0 ? themeFonts : SYSTEM_FONTS ),
	];
	return <SelectControl label={ label } value={ value || '' } options={ options } onChange={ onChange } />;
}

function Typo( { pfx, a, set } ) {
	const fontValue = {
		fontStyle: a[ pfx + 'FontStyle' ] || '',
		fontWeight: a[ pfx + 'FontWeight' ] || '',
	};
	return (
		<>
			{ FontAppearanceControl && (
				<FontAppearanceControl
					value={ fontValue }
					onChange={ v => set( {
						[ pfx + 'FontWeight' ]: v?.fontWeight ?? '',
						[ pfx + 'FontStyle' ]: v?.fontStyle ?? '',
					} ) }
				/>
			) }
			{ LetterSpacingControl && (
				<>
					<Gap />
					<LetterSpacingControl
						value={ a[ pfx + 'LetterSpacing' ] || '' }
						onChange={ v => set( { [ pfx + 'LetterSpacing' ]: v ?? '' } ) }
					/>
				</>
			) }
			{ LineHeightControl && (
				<>
					<Gap />
					<LineHeightControl
						value={ a[ pfx + 'LineHeight' ] || '' }
						onChange={ v => set( { [ pfx + 'LineHeight' ]: String( v ?? '' ) } ) }
					/>
				</>
			) }
			{ TextTransformControl && (
				<>
					<Gap />
					<TextTransformControl
						value={ a[ pfx + 'TextTransform' ] || '' }
						onChange={ v => set( { [ pfx + 'TextTransform' ]: v ?? '' } ) }
					/>
				</>
			) }
			{ TextDecorationControl && (
				<>
					<Gap />
					<TextDecorationControl
						value={ a[ pfx + 'TextDecoration' ] || '' }
						onChange={ v => set( { [ pfx + 'TextDecoration' ]: v ?? '' } ) }
					/>
				</>
			) }
		</>
	);
}

const FW = [
	{ label: __( 'Default', 'neural-forms-lite' ), value: '' },
	{ label: '300 — Light', value: '300' },
	{ label: '400 — Regular', value: '400' },
	{ label: '500 — Medium', value: '500' },
	{ label: '600 — Semi Bold', value: '600' },
	{ label: '700 — Bold', value: '700' },
	{ label: '800 — Extra Bold', value: '800' },
];

// ─── Shared panels (identical to FormDesignExtras) ───────────────────────────

function ContainerPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Container', 'neural-forms-lite' ) } initialOpen={ false }>
			<Clr label={ __( 'Background Color', 'neural-forms-lite' ) } value={ a.bgColor } onChange={ v => set( { bgColor: v ?? '' } ) } />
			<Grad label={ __( 'Background Gradient', 'neural-forms-lite' ) } value={ a.bgGradient } onChange={ v => set( { bgGradient: v ?? '' } ) } />
			<Sep />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.borderColor, width: a.borderWidth, style: a.borderStyle } } onChange={ v => set( { borderColor: v?.color ?? '', borderWidth: v?.width ?? '', borderStyle: v?.style ?? '' } ) } withSlider />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.borderRadiusTL || undefined, right: a.borderRadiusTR || undefined, bottom: a.borderRadiusBR || undefined, left: a.borderRadiusBL || undefined } } onChange={ v => set( { borderRadiusTL: v.top ?? '', borderRadiusTR: v.right ?? '', borderRadiusBR: v.bottom ?? '', borderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.paddingTop || undefined, right: a.paddingRight || undefined, bottom: a.paddingBottom || undefined, left: a.paddingLeft || undefined } } onChange={ v => set( { paddingTop: v.top ?? '', paddingRight: v.right ?? '', paddingBottom: v.bottom ?? '', paddingLeft: v.left ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Margin', 'neural-forms-lite' ) } values={ { top: a.marginTop || undefined, right: a.marginRight || undefined, bottom: a.marginBottom || undefined, left: a.marginLeft || undefined } } onChange={ v => set( { marginTop: v.top ?? '', marginRight: v.right ?? '', marginBottom: v.bottom ?? '', marginLeft: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Width', 'neural-forms-lite' ) } value={ a.formWidth } onChange={ v => set( { formWidth: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Max Width', 'neural-forms-lite' ) } value={ a.maxWidth } onChange={ v => set( { maxWidth: v ?? '' } ) } />
			<Gap />
			<ToggleGroupControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.formAlign || 'left' } onChange={ v => set( { formAlign: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="left"    label={ __( 'Left', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="center"  label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="right"   label={ __( 'Right', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="stretch" label={ __( 'Full', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Sep />
			<BaseControl label={ __( 'Box Shadow', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<UnitControl label={ __( 'Horizontal', 'neural-forms-lite' ) } value={ a.shadowH } onChange={ v => set( { shadowH: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Vertical', 'neural-forms-lite' ) } value={ a.shadowV } onChange={ v => set( { shadowV: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Blur', 'neural-forms-lite' ) } value={ a.shadowBlur } onChange={ v => set( { shadowBlur: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Spread', 'neural-forms-lite' ) } value={ a.shadowSpread } onChange={ v => set( { shadowSpread: v ?? '' } ) } />
			</BaseControl>
			<Clr label={ __( 'Shadow Color', 'neural-forms-lite' ) } value={ a.shadowColor } onChange={ v => set( { shadowColor: v ?? '' } ) } />
		</PanelBody>
	);
}

function TitlePanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Form Title', 'neural-forms-lite' ) } initialOpen={ false }>
			<SelectControl label={ __( 'Heading Level', 'neural-forms-lite' ) } value={ a.titleLevel || 'h2' } options={ [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( h => ( { label: h.toUpperCase(), value: h } ) ) } onChange={ v => set( { titleLevel: v } ) } />
			<ToggleGroupControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.titleAlign || 'left' } onChange={ v => set( { titleAlign: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="left"   label={ __( 'Left', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="center" label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="right"  label={ __( 'Right', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Sep />
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.titleColor } onChange={ v => set( { titleColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.titleFontSize } onChange={ v => set( { titleFontSize: v ?? '' } ) } />
			<Gap />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.titleFontFamily } onChange={ v => set( { titleFontFamily: v } ) } />
			<Typo pfx="title" a={ a } set={ set } />
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.titlePaddingTop || undefined, right: a.titlePaddingRight || undefined, bottom: a.titlePaddingBottom || undefined, left: a.titlePaddingLeft || undefined } } onChange={ v => set( { titlePaddingTop: v.top ?? '', titlePaddingRight: v.right ?? '', titlePaddingBottom: v.bottom ?? '', titlePaddingLeft: v.left ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Margin', 'neural-forms-lite' ) } values={ { top: a.titleMarginTop || undefined, right: a.titleMarginRight || undefined, bottom: a.titleMarginBottom || undefined, left: a.titleMarginLeft || undefined } } onChange={ v => set( { titleMarginTop: v.top ?? '', titleMarginRight: v.right ?? '', titleMarginBottom: v.bottom ?? '', titleMarginLeft: v.left ?? '' } ) } />
		</PanelBody>
	);
}

function LabelsPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Labels', 'neural-forms-lite' ) } initialOpen={ false }>
			{ /* Show Labels lives in Settings → Display now; removed
			     from here to avoid a duplicate control. */ }
			<ToggleGroupControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.labelAlign || 'left' } onChange={ v => set( { labelAlign: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="left"   label={ __( 'Left', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="center" label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="right"  label={ __( 'Right', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Sep />
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.labelColor } onChange={ v => set( { labelColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.labelFontSize } onChange={ v => set( { labelFontSize: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.labelFontFamily } onChange={ v => set( { labelFontFamily: v } ) } />
			<Typo pfx="label" a={ a } set={ set } />
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.labelPaddingTop, right: a.labelPaddingRight, bottom: a.labelPaddingBottom, left: a.labelPaddingLeft } } onChange={ v => set( { labelPaddingTop: v.top ?? '', labelPaddingRight: v.right ?? '', labelPaddingBottom: v.bottom ?? '', labelPaddingLeft: v.left ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Margin', 'neural-forms-lite' ) } values={ { top: a.labelMarginTop, right: a.labelMarginRight, bottom: a.labelMarginBottom, left: a.labelMarginLeft } } onChange={ v => set( { labelMarginTop: v.top ?? '', labelMarginRight: v.right ?? '', labelMarginBottom: v.bottom ?? '', labelMarginLeft: v.left ?? '' } ) } />
		</PanelBody>
	);
}

function InputFieldsPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Input Fields', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Text, number, tel, url, date, email, select fields.', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.fieldBg } onChange={ v => set( { fieldBg: v ?? '' } ) } />
			<Clr label={ __( 'Hover Background', 'neural-forms-lite' ) } value={ a.fieldHoverBg } onChange={ v => set( { fieldHoverBg: v ?? '' } ) } />
			<Clr label={ __( 'Focus Background', 'neural-forms-lite' ) } value={ a.fieldFocusBg } onChange={ v => set( { fieldFocusBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.fieldTextColor } onChange={ v => set( { fieldTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Text Color', 'neural-forms-lite' ) } value={ a.fieldFocusTextColor } onChange={ v => set( { fieldFocusTextColor: v ?? '' } ) } />
			<Sep />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.fieldBorderColor, width: a.fieldBorderWidth, style: a.fieldBorderStyle } } onChange={ v => set( { fieldBorderColor: v?.color ?? '', fieldBorderWidth: v?.width ?? '', fieldBorderStyle: v?.style ?? '' } ) } withSlider />
			<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a.fieldHoverBorderColor } onChange={ v => set( { fieldHoverBorderColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Border Color', 'neural-forms-lite' ) } value={ a.fieldFocusBorderColor } onChange={ v => set( { fieldFocusBorderColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.fieldBorderRadiusTL, right: a.fieldBorderRadiusTR, bottom: a.fieldBorderRadiusBR, left: a.fieldBorderRadiusBL } } onChange={ v => set( { fieldBorderRadiusTL: v.top ?? '', fieldBorderRadiusTR: v.right ?? '', fieldBorderRadiusBR: v.bottom ?? '', fieldBorderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fieldFontSize } onChange={ v => set( { fieldFontSize: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.fieldFontFamily } onChange={ v => set( { fieldFontFamily: v } ) } />
			<Typo pfx="field" a={ a } set={ set } />
			<ToggleGroupControl label={ __( 'Text Align', 'neural-forms-lite' ) } value={ a.fieldTextAlign || 'left' } onChange={ v => set( { fieldTextAlign: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="left"    label={ __( 'Left', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="center"  label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="right"   label={ __( 'Right', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="justify" label={ __( 'Justify', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.fieldPaddingTop, right: a.fieldPaddingRight, bottom: a.fieldPaddingBottom, left: a.fieldPaddingLeft } } onChange={ v => set( { fieldPaddingTop: v.top ?? '', fieldPaddingRight: v.right ?? '', fieldPaddingBottom: v.bottom ?? '', fieldPaddingLeft: v.left ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Gap Between Fields', 'neural-forms-lite' ) } value={ a.fieldGap } onChange={ v => set( { fieldGap: v ?? '' } ) } />
		</PanelBody>
	);
}

function TextareaFieldPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Textarea Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Overrides for textarea fields only.', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.textareaBg } onChange={ v => set( { textareaBg: v ?? '' } ) } />
			<Clr label={ __( 'Hover Background', 'neural-forms-lite' ) } value={ a.textareaHoverBg } onChange={ v => set( { textareaHoverBg: v ?? '' } ) } />
			<Clr label={ __( 'Focus Background', 'neural-forms-lite' ) } value={ a.textareaFocusBg } onChange={ v => set( { textareaFocusBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.textareaTextColor } onChange={ v => set( { textareaTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Text Color', 'neural-forms-lite' ) } value={ a.textareaFocusTextColor } onChange={ v => set( { textareaFocusTextColor: v ?? '' } ) } />
			<Sep />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.textareaBorderColor, width: a.textareaBorderWidth, style: a.textareaBorderStyle } } onChange={ v => set( { textareaBorderColor: v?.color ?? '', textareaBorderWidth: v?.width ?? '', textareaBorderStyle: v?.style ?? '' } ) } withSlider />
			<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a.textareaHoverBorderColor } onChange={ v => set( { textareaHoverBorderColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Border Color', 'neural-forms-lite' ) } value={ a.textareaFocusBorderColor } onChange={ v => set( { textareaFocusBorderColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.textareaBorderRadiusTL, right: a.textareaBorderRadiusTR, bottom: a.textareaBorderRadiusBR, left: a.textareaBorderRadiusBL } } onChange={ v => set( { textareaBorderRadiusTL: v.top ?? '', textareaBorderRadiusTR: v.right ?? '', textareaBorderRadiusBR: v.bottom ?? '', textareaBorderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.textareaFontSize } onChange={ v => set( { textareaFontSize: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.textareaFontFamily } onChange={ v => set( { textareaFontFamily: v } ) } />
			<Typo pfx="textarea" a={ a } set={ set } />
			<ToggleGroupControl label={ __( 'Text Align', 'neural-forms-lite' ) } value={ a.textareaTextAlign || 'left' } onChange={ v => set( { textareaTextAlign: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="left"    label={ __( 'Left', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="center"  label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="right"   label={ __( 'Right', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="justify" label={ __( 'Justify', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.textareaPaddingTop, right: a.textareaPaddingRight, bottom: a.textareaPaddingBottom, left: a.textareaPaddingLeft } } onChange={ v => set( { textareaPaddingTop: v.top ?? '', textareaPaddingRight: v.right ?? '', textareaPaddingBottom: v.bottom ?? '', textareaPaddingLeft: v.left ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Min Height', 'neural-forms-lite' ) } value={ a.textareaMinHeight } onChange={ v => set( { textareaMinHeight: v ?? '' } ) } />
		</PanelBody>
	);
}

function RadioFieldPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Radio Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<ToggleGroupControl label={ __( 'Layout Direction', 'neural-forms-lite' ) } value={ a.radioOptionsDirection || 'vertical' } onChange={ v => set( { radioOptionsDirection: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="vertical"   label={ __( 'Vertical', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="horizontal" label={ __( 'Horizontal', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Gap />
			<UnitControl label={ __( 'Gap Between Options', 'neural-forms-lite' ) } value={ a.radioOptionsGap } onChange={ v => set( { radioOptionsGap: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Radio Option', 'neural-forms-lite' ) }</p>
			<UnitControl label={ __( 'Size', 'neural-forms-lite' ) } value={ a.radioOptionSize } onChange={ v => set( { radioOptionSize: v ?? '' } ) } />
			<Clr label={ __( 'Accent Color (radio dot)', 'neural-forms-lite' ) } value={ a.radioAccentColor } onChange={ v => set( { radioAccentColor: v ?? '' } ) } />
			<Clr label={ __( 'Border Color', 'neural-forms-lite' ) } value={ a.radioOptionBorderColor } onChange={ v => set( { radioOptionBorderColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Border Width', 'neural-forms-lite' ) } value={ a.radioOptionBorderWidth } onChange={ v => set( { radioOptionBorderWidth: v ?? '' } ) } />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.radioOptionBorderRadius } onChange={ v => set( { radioOptionBorderRadius: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.radioOptionColor } onChange={ v => set( { radioOptionColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.radioOptionFontSize } onChange={ v => set( { radioOptionFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.radioOptionFontWeight || '' } options={ FW } onChange={ v => set( { radioOptionFontWeight: v } ) } />
			<Sep />
			<UnitControl label={ __( 'Gap (radio to label)', 'neural-forms-lite' ) } value={ a.radioLabelGap } onChange={ v => set( { radioLabelGap: v ?? '' } ) } />
		</PanelBody>
	);
}

function CheckboxGroupPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Checkbox Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles for multi-option checkbox fields.', 'neural-forms-lite' ) }</p>
			<ToggleGroupControl label={ __( 'Layout Direction', 'neural-forms-lite' ) } value={ a.cbGroupDirection || 'vertical' } onChange={ v => set( { cbGroupDirection: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="vertical"   label={ __( 'Vertical', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="horizontal" label={ __( 'Horizontal', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Gap />
			<UnitControl label={ __( 'Gap Between Items', 'neural-forms-lite' ) } value={ a.cbGroupItemGap } onChange={ v => set( { cbGroupItemGap: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Checkbox', 'neural-forms-lite' ) }</p>
			<UnitControl label={ __( 'Size', 'neural-forms-lite' ) } value={ a.cbGroupSize } onChange={ v => set( { cbGroupSize: v ?? '' } ) } />
			<Clr label={ __( 'Background (unchecked)', 'neural-forms-lite' ) } value={ a.cbGroupBg } onChange={ v => set( { cbGroupBg: v ?? '' } ) } />
			<Clr label={ __( 'Checked Color (accent)', 'neural-forms-lite' ) } value={ a.cbGroupCheckedBg } onChange={ v => set( { cbGroupCheckedBg: v ?? '' } ) } />
			<Clr label={ __( 'Border Color', 'neural-forms-lite' ) } value={ a.cbGroupBorderColor } onChange={ v => set( { cbGroupBorderColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Border Width', 'neural-forms-lite' ) } value={ a.cbGroupBorderWidth } onChange={ v => set( { cbGroupBorderWidth: v ?? '' } ) } />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.cbGroupBorderRadius } onChange={ v => set( { cbGroupBorderRadius: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.cbGroupLabelColor } onChange={ v => set( { cbGroupLabelColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.cbGroupLabelFontSize } onChange={ v => set( { cbGroupLabelFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.cbGroupLabelFontWeight || '' } options={ FW } onChange={ v => set( { cbGroupLabelFontWeight: v } ) } />
			<Sep />
			<UnitControl label={ __( 'Gap (checkbox to label)', 'neural-forms-lite' ) } value={ a.cbGroupGap } onChange={ v => set( { cbGroupGap: v ?? '' } ) } />
		</PanelBody>
	);
}

function AcceptanceFieldPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Acceptance Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles for the acceptance (checkbox) field.', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Checkbox', 'neural-forms-lite' ) }</p>
			<UnitControl label={ __( 'Size', 'neural-forms-lite' ) } value={ a.acceptanceSize } onChange={ v => set( { acceptanceSize: v ?? '' } ) } />
			<Clr label={ __( 'Checked Color (accent)', 'neural-forms-lite' ) } value={ a.acceptanceCheckedBg } onChange={ v => set( { acceptanceCheckedBg: v ?? '' } ) } />
			<Clr label={ __( 'Border Color', 'neural-forms-lite' ) } value={ a.acceptanceBorderColor } onChange={ v => set( { acceptanceBorderColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Border Size', 'neural-forms-lite' ) } value={ a.acceptanceBorderWidth } onChange={ v => set( { acceptanceBorderWidth: v ?? '' } ) } />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.acceptanceBorderRadius } onChange={ v => set( { acceptanceBorderRadius: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.acceptanceLabelColor } onChange={ v => set( { acceptanceLabelColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.acceptanceLabelFontSize } onChange={ v => set( { acceptanceLabelFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.acceptanceLabelFontWeight || '' } options={ FW } onChange={ v => set( { acceptanceLabelFontWeight: v } ) } />
			<Sep />
			<UnitControl label={ __( 'Gap (checkbox to label)', 'neural-forms-lite' ) } value={ a.acceptanceGap } onChange={ v => set( { acceptanceGap: v ?? '' } ) } />
		</PanelBody>
	);
}

function RatingFieldPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Rating Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<UnitControl label={ __( 'Star Size', 'neural-forms-lite' ) } value={ a.ratingStarSize } onChange={ v => set( { ratingStarSize: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Gap Between Stars', 'neural-forms-lite' ) } value={ a.ratingStarGap } onChange={ v => set( { ratingStarGap: v ?? '' } ) } />
			<Sep />
			<Clr label={ __( 'Empty Star Color', 'neural-forms-lite' ) } value={ a.ratingEmptyColor } onChange={ v => set( { ratingEmptyColor: v ?? '' } ) } />
			<Clr label={ __( 'Filled Star Color', 'neural-forms-lite' ) } value={ a.ratingFilledColor } onChange={ v => set( { ratingFilledColor: v ?? '' } ) } />
		</PanelBody>
	);
}

function FileUploadPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'File Upload Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Filename Display Area', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.fileInputBg } onChange={ v => set( { fileInputBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.fileInputColor } onChange={ v => set( { fileInputColor: v ?? '' } ) } />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.fileInputBorderColor, width: a.fileInputBorderWidth, style: a.fileInputBorderStyle } } onChange={ v => set( { fileInputBorderColor: v?.color ?? '', fileInputBorderWidth: v?.width ?? '', fileInputBorderStyle: v?.style ?? '' } ) } withSlider />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.fileInputBorderRadius } onChange={ v => set( { fileInputBorderRadius: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fileInputFontSize } onChange={ v => set( { fileInputFontSize: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.fileInputPaddingTop || undefined, right: a.fileInputPaddingRight || undefined, bottom: a.fileInputPaddingBottom || undefined, left: a.fileInputPaddingLeft || undefined } } onChange={ v => set( { fileInputPaddingTop: v.top ?? '', fileInputPaddingRight: v.right ?? '', fileInputPaddingBottom: v.bottom ?? '', fileInputPaddingLeft: v.left ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Browse Button', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.fileBtnBg } onChange={ v => set( { fileBtnBg: v ?? '' } ) } />
			<Clr label={ __( 'Hover Background', 'neural-forms-lite' ) } value={ a.fileBtnHoverBg } onChange={ v => set( { fileBtnHoverBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.fileBtnColor } onChange={ v => set( { fileBtnColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fileBtnFontSize } onChange={ v => set( { fileBtnFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.fileBtnFontWeight || '' } options={ FW } onChange={ v => set( { fileBtnFontWeight: v } ) } />
			<Gap />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.fileBtnPaddingTop || undefined, right: a.fileBtnPaddingRight || undefined, bottom: a.fileBtnPaddingBottom || undefined, left: a.fileBtnPaddingLeft || undefined } } onChange={ v => set( { fileBtnPaddingTop: v.top ?? '', fileBtnPaddingRight: v.right ?? '', fileBtnPaddingBottom: v.bottom ?? '', fileBtnPaddingLeft: v.left ?? '' } ) } />
			<Gap />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.fileBtnBorderColor, width: a.fileBtnBorderWidth, style: a.fileBtnBorderStyle } } onChange={ v => set( { fileBtnBorderColor: v?.color ?? '', fileBtnBorderWidth: v?.width ?? '', fileBtnBorderStyle: v?.style ?? '' } ) } withSlider />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.fileBtnBorderRadius } onChange={ v => set( { fileBtnBorderRadius: v ?? '' } ) } />
		</PanelBody>
	);
}

// ─── Button panels ────────────────────────────────────────────────────────────

const EASING_OPTIONS = [
	{ label: __( 'Default', 'neural-forms-lite' ), value: '' },
	{ label: 'ease', value: 'ease' },
	{ label: 'linear', value: 'linear' },
	{ label: 'ease-in', value: 'ease-in' },
	{ label: 'ease-out', value: 'ease-out' },
	{ label: 'ease-in-out', value: 'ease-in-out' },
];

function BtnControls( { pfx, a, set, showAlign = true } ) {
	const fullWidthKey = pfx + 'FullWidth';
	return (
		<>
			{ showAlign && (
				<ToggleGroupControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a[ pfx + 'Align' ] || 'left' } onChange={ v => set( { [ pfx + 'Align' ]: v } ) } isBlock __nextHasNoMarginBottom>
					<ToggleGroupControlOption value="left"   label={ __( 'Left', 'neural-forms-lite' ) } />
					<ToggleGroupControlOption value="center" label={ __( 'Center', 'neural-forms-lite' ) } />
					<ToggleGroupControlOption value="right"  label={ __( 'Right', 'neural-forms-lite' ) } />
				</ToggleGroupControl>
			) }
			<ToggleControl label={ __( 'Full Width', 'neural-forms-lite' ) } checked={ !!a[ fullWidthKey ] } onChange={ v => set( { [ fullWidthKey ]: v } ) } />
			<Sep />
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a[ pfx + 'BgColor' ] } onChange={ v => set( { [ pfx + 'BgColor' ]: v ?? '' } ) } />
			<Clr label={ __( 'Hover Background', 'neural-forms-lite' ) } value={ a[ pfx + 'HoverBg' ] } onChange={ v => set( { [ pfx + 'HoverBg' ]: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a[ pfx + 'TextColor' ] } onChange={ v => set( { [ pfx + 'TextColor' ]: v ?? '' } ) } />
			<Clr label={ __( 'Hover Text Color', 'neural-forms-lite' ) } value={ a[ pfx + 'HoverColor' ] } onChange={ v => set( { [ pfx + 'HoverColor' ]: v ?? '' } ) } />
			<Sep />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a[ pfx + 'BorderColor' ], width: a[ pfx + 'BorderWidth' ], style: a[ pfx + 'BorderStyle' ] } } onChange={ v => set( { [ pfx + 'BorderColor' ]: v?.color ?? '', [ pfx + 'BorderWidth' ]: v?.width ?? '', [ pfx + 'BorderStyle' ]: v?.style ?? '' } ) } withSlider />
			<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a[ pfx + 'HoverBorderColor' ] } onChange={ v => set( { [ pfx + 'HoverBorderColor' ]: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a[ pfx + 'RadiusTL' ] || undefined, right: a[ pfx + 'RadiusTR' ] || undefined, bottom: a[ pfx + 'RadiusBR' ] || undefined, left: a[ pfx + 'RadiusBL' ] || undefined } } onChange={ v => set( { [ pfx + 'RadiusTL' ]: v.top ?? '', [ pfx + 'RadiusTR' ]: v.right ?? '', [ pfx + 'RadiusBR' ]: v.bottom ?? '', [ pfx + 'RadiusBL' ]: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a[ pfx + 'FontSize' ] } onChange={ v => set( { [ pfx + 'FontSize' ]: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a[ pfx + 'FontFamily' ] } onChange={ v => set( { [ pfx + 'FontFamily' ]: v } ) } />
			<Typo pfx={ pfx } a={ a } set={ set } />
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a[ pfx + 'PaddingTop' ] || undefined, right: a[ pfx + 'PaddingRight' ] || undefined, bottom: a[ pfx + 'PaddingBottom' ] || undefined, left: a[ pfx + 'PaddingLeft' ] || undefined } } onChange={ v => set( { [ pfx + 'PaddingTop' ]: v.top ?? '', [ pfx + 'PaddingRight' ]: v.right ?? '', [ pfx + 'PaddingBottom' ]: v.bottom ?? '', [ pfx + 'PaddingLeft' ]: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Transition Duration', 'neural-forms-lite' ) } value={ a[ pfx + 'TransitionDuration' ] } onChange={ v => set( { [ pfx + 'TransitionDuration' ]: v ?? '' } ) } units={ [ { value: 's', label: 's', default: 0, step: 0.1 }, { value: 'ms', label: 'ms', default: 0, step: 10 } ] } />
			<SelectControl label={ __( 'Transition Easing', 'neural-forms-lite' ) } value={ a[ pfx + 'TransitionEasing' ] || '' } options={ EASING_OPTIONS } onChange={ v => set( { [ pfx + 'TransitionEasing' ]: v } ) } />
		</>
	);
}

function NavButtonsPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Prev & Next Buttons', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles apply to both Previous and Next buttons.', 'neural-forms-lite' ) }</p>
			<BtnControls pfx="navBtn" a={ a } set={ set } showAlign={ false } />
		</PanelBody>
	);
}

function SubmitButtonPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Submit Button', 'neural-forms-lite' ) } initialOpen={ false }>
			<BtnControls pfx="btn" a={ a } set={ set } />
		</PanelBody>
	);
}

// ─── Step Indicator panel ────────────────────────────────────────────────────

function StepIndicatorPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Step Indicator', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles apply when progress indicator is set to the matching mode.', 'neural-forms-lite' ) }</p>

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Progress Bar', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Fill Color', 'neural-forms-lite' ) } value={ a.stepBarFillColor } onChange={ v => set( { stepBarFillColor: v ?? '' } ) } />
			<Clr label={ __( 'Track Color', 'neural-forms-lite' ) } value={ a.stepBarTrackColor } onChange={ v => set( { stepBarTrackColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Bar Height', 'neural-forms-lite' ) } value={ a.stepBarHeight } onChange={ v => set( { stepBarHeight: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Bar Border Radius', 'neural-forms-lite' ) } value={ a.stepBarRadius } onChange={ v => set( { stepBarRadius: v ?? '' } ) } />

			<Sep />

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Numbers', 'neural-forms-lite' ) }</p>

			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Active Step', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Circle Background', 'neural-forms-lite' ) } value={ a.stepActiveNumBg } onChange={ v => set( { stepActiveNumBg: v ?? '' } ) } />
			<Clr label={ __( 'Circle Text Color', 'neural-forms-lite' ) } value={ a.stepActiveNumColor } onChange={ v => set( { stepActiveNumColor: v ?? '' } ) } />

			<Gap />
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Completed Step', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Circle Background', 'neural-forms-lite' ) } value={ a.stepCompleteNumBg } onChange={ v => set( { stepCompleteNumBg: v ?? '' } ) } />
			<Clr label={ __( 'Circle Text Color', 'neural-forms-lite' ) } value={ a.stepCompleteNumColor } onChange={ v => set( { stepCompleteNumColor: v ?? '' } ) } />

			<Gap />
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Inactive Step', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Circle Background', 'neural-forms-lite' ) } value={ a.stepInactiveNumBg } onChange={ v => set( { stepInactiveNumBg: v ?? '' } ) } />
			<Clr label={ __( 'Circle Text Color', 'neural-forms-lite' ) } value={ a.stepInactiveNumColor } onChange={ v => set( { stepInactiveNumColor: v ?? '' } ) } />

			<Sep />
			<UnitControl label={ __( 'Circle Size', 'neural-forms-lite' ) } value={ a.stepNumSize } onChange={ v => set( { stepNumSize: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Circle Font Size', 'neural-forms-lite' ) } value={ a.stepNumFontSize } onChange={ v => set( { stepNumFontSize: v ?? '' } ) } />
			<Gap />
			<Clr label={ __( 'Connector Color', 'neural-forms-lite' ) } value={ a.stepConnectorColor } onChange={ v => set( { stepConnectorColor: v ?? '' } ) } />
		</PanelBody>
	);
}

function MessagesPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Messages', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Success Message', 'neural-forms-lite' ) }</p>
			<ToggleControl label={ __( 'Show Success Message', 'neural-forms-lite' ) } checked={ a.successMsgEnabled !== false } onChange={ v => set( { successMsgEnabled: v } ) } />
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.successMsgBg } onChange={ v => set( { successMsgBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.successMsgColor } onChange={ v => set( { successMsgColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.successMsgPaddingTop, right: a.successMsgPaddingRight, bottom: a.successMsgPaddingBottom, left: a.successMsgPaddingLeft } } onChange={ v => set( { successMsgPaddingTop: v.top ?? '', successMsgPaddingRight: v.right ?? '', successMsgPaddingBottom: v.bottom ?? '', successMsgPaddingLeft: v.left ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Error Message', 'neural-forms-lite' ) }</p>
			<ToggleControl label={ __( 'Show Error Message', 'neural-forms-lite' ) } checked={ a.errorMsgEnabled !== false } onChange={ v => set( { errorMsgEnabled: v } ) } />
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.errorMsgBg } onChange={ v => set( { errorMsgBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.errorMsgColor } onChange={ v => set( { errorMsgColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.errorMsgPaddingTop, right: a.errorMsgPaddingRight, bottom: a.errorMsgPaddingBottom, left: a.errorMsgPaddingLeft } } onChange={ v => set( { errorMsgPaddingTop: v.top ?? '', errorMsgPaddingRight: v.right ?? '', errorMsgPaddingBottom: v.bottom ?? '', errorMsgPaddingLeft: v.left ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Field Error Message', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Inline message shown below each invalid field.', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.fieldErrorMsgColor } onChange={ v => set( { fieldErrorMsgColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fieldErrorMsgFontSize } onChange={ v => set( { fieldErrorMsgFontSize: v ?? '' } ) } />
		</PanelBody>
	);
}

export default function MultiStepDesignExtras( { attributes, setAttributes } ) {
	const a = attributes, set = setAttributes;
	return (
		<>
			<ContainerPanel       a={ a } set={ set } />
			{ ( a.formTitle || '' ).trim() !== '' && <TitlePanel a={ a } set={ set } /> }
			<LabelsPanel          a={ a } set={ set } />
			<InputFieldsPanel     a={ a } set={ set } />
			<TextareaFieldPanel   a={ a } set={ set } />
			<RadioFieldPanel      a={ a } set={ set } />
			<CheckboxGroupPanel   a={ a } set={ set } />
			<AcceptanceFieldPanel a={ a } set={ set } />
			<RatingFieldPanel     a={ a } set={ set } />
			<FileUploadPanel      a={ a } set={ set } />
			<StepIndicatorPanel   a={ a } set={ set } />
			<NavButtonsPanel      a={ a } set={ set } />
			<SubmitButtonPanel    a={ a } set={ set } />
			<MessagesPanel        a={ a } set={ set } />
			<PanelBody title={ __( 'Advanced', 'neural-forms-lite' ) } initialOpen={ false }>
				<TextControl label={ __( 'Extra CSS Class', 'neural-forms-lite' ) } value={ a.extraClass || '' } onChange={ v => set( { extraClass: v } ) } help={ __( 'Space-separated class names.', 'neural-forms-lite' ) } />
			</PanelBody>
		</>
	);
}
