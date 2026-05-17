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

// Consistent top margin wrapper to separate stacked block controls
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

// Native Gutenberg typography controls (Appearance + Letter Spacing + Line Height +
// Text Transform + Text Decoration). Wrapped in a flex-column container so all
// controls have consistent 16px gaps between them regardless of which experimental
// exports are available. Each control is conditionally rendered so the block does
// not crash if the experimental export is unavailable in a given WP version.
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

// Font weight options kept for poll/acceptance controls that don't use native Typo
const FW = [
	{ label: __( 'Default', 'neural-forms-lite' ), value: '' },
	{ label: '300 — Light', value: '300' },
	{ label: '400 — Regular', value: '400' },
	{ label: '500 — Medium', value: '500' },
	{ label: '600 — Semi Bold', value: '600' },
	{ label: '700 — Bold', value: '700' },
	{ label: '800 — Extra Bold', value: '800' },
];

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
			<ToggleControl label={ __( 'Show Labels', 'neural-forms-lite' ) } checked={ !!a.showLabels } onChange={ v => set( { showLabels: v } ) } />
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
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Text, number, tel, url, date, select fields.', 'neural-forms-lite' ) }</p>
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

function PollOptionsPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Poll Options', 'neural-forms-lite' ) } initialOpen={ false }>
			<ToggleGroupControl label={ __( 'Layout Direction', 'neural-forms-lite' ) } value={ a.pollOptionsDirection || 'vertical' } onChange={ v => set( { pollOptionsDirection: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="vertical"   label={ __( 'Vertical', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="horizontal" label={ __( 'Horizontal', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Gap />
			<UnitControl label={ __( 'Gap Between Options', 'neural-forms-lite' ) } value={ a.pollOptionsGap } onChange={ v => set( { pollOptionsGap: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Option Item Style', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.pollOptionBg } onChange={ v => set( { pollOptionBg: v ?? '' } ) } />
			<Clr label={ __( 'Hover Background', 'neural-forms-lite' ) } value={ a.pollOptionHoverBg } onChange={ v => set( { pollOptionHoverBg: v ?? '' } ) } />
			<Clr label={ __( 'Selected Background', 'neural-forms-lite' ) } value={ a.pollOptionCheckedBg } onChange={ v => set( { pollOptionCheckedBg: v ?? '' } ) } />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.pollOptionBorderColor, width: a.pollOptionBorderWidth, style: a.pollOptionBorderStyle } } onChange={ v => set( { pollOptionBorderColor: v?.color ?? '', pollOptionBorderWidth: v?.width ?? '', pollOptionBorderStyle: v?.style ?? '' } ) } withSlider />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.pollOptionBorderRadius } onChange={ v => set( { pollOptionBorderRadius: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.pollOptionPaddingTop, right: a.pollOptionPaddingRight, bottom: a.pollOptionPaddingBottom, left: a.pollOptionPaddingLeft } } onChange={ v => set( { pollOptionPaddingTop: v.top ?? '', pollOptionPaddingRight: v.right ?? '', pollOptionPaddingBottom: v.bottom ?? '', pollOptionPaddingLeft: v.left ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Option Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Accent Color (radio dot)', 'neural-forms-lite' ) } value={ a.pollAccentColor } onChange={ v => set( { pollAccentColor: v ?? '' } ) } />
			<Clr label={ __( 'Label Color', 'neural-forms-lite' ) } value={ a.pollOptionColor } onChange={ v => set( { pollOptionColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.pollOptionFontSize } onChange={ v => set( { pollOptionFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.pollOptionFontWeight || '' } options={ FW } onChange={ v => set( { pollOptionFontWeight: v } ) } />
		</PanelBody>
	);
}

function ResultsBarPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Results Bar', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Shown after voting (or always, per field setting).', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Bar Fill Color', 'neural-forms-lite' ) } value={ a.pollBarColor } onChange={ v => set( { pollBarColor: v ?? '' } ) } />
			<Clr label={ __( 'Bar Fill Color 2 (gradient end)', 'neural-forms-lite' ) } value={ a.pollBarColor2 } onChange={ v => set( { pollBarColor2: v ?? '' } ) } />
			<Clr label={ __( 'Bar Track Color', 'neural-forms-lite' ) } value={ a.pollBarTrackColor } onChange={ v => set( { pollBarTrackColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Bar Height', 'neural-forms-lite' ) } value={ a.pollBarHeight } onChange={ v => set( { pollBarHeight: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Bar Border Radius', 'neural-forms-lite' ) } value={ a.pollBarRadius } onChange={ v => set( { pollBarRadius: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Results Area Margin Top', 'neural-forms-lite' ) } value={ a.pollResultsMarginTop } onChange={ v => set( { pollResultsMarginTop: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Bar Labels', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Option Label Color', 'neural-forms-lite' ) } value={ a.pollResultsLabelColor } onChange={ v => set( { pollResultsLabelColor: v ?? '' } ) } />
			<Clr label={ __( 'Vote Count Color', 'neural-forms-lite' ) } value={ a.pollResultsCountColor } onChange={ v => set( { pollResultsCountColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Results Font Size', 'neural-forms-lite' ) } value={ a.pollResultsFontSize } onChange={ v => set( { pollResultsFontSize: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Total Votes Text', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.pollTotalColor } onChange={ v => set( { pollTotalColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.pollTotalFontSize } onChange={ v => set( { pollTotalFontSize: v ?? '' } ) } />
		</PanelBody>
	);
}

function SubmitButtonPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Submit Button', 'neural-forms-lite' ) } initialOpen={ false }>
			<ToggleGroupControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.btnAlign || 'left' } onChange={ v => set( { btnAlign: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="left"   label={ __( 'Left', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="center" label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="right"  label={ __( 'Right', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<ToggleControl label={ __( 'Full Width', 'neural-forms-lite' ) } checked={ !!a.btnFullWidth } onChange={ v => set( { btnFullWidth: v } ) } />
			<Sep />
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.btnBgColor } onChange={ v => set( { btnBgColor: v ?? '' } ) } />
			<Clr label={ __( 'Hover Background', 'neural-forms-lite' ) } value={ a.btnHoverBg } onChange={ v => set( { btnHoverBg: v ?? '' } ) } />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.btnTextColor } onChange={ v => set( { btnTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Hover Text Color', 'neural-forms-lite' ) } value={ a.btnHoverColor } onChange={ v => set( { btnHoverColor: v ?? '' } ) } />
			<Sep />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.btnBorderColor, width: a.btnBorderWidth, style: a.btnBorderStyle } } onChange={ v => set( { btnBorderColor: v?.color ?? '', btnBorderWidth: v?.width ?? '', btnBorderStyle: v?.style ?? '' } ) } withSlider />
			<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a.btnHoverBorderColor } onChange={ v => set( { btnHoverBorderColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.btnRadiusTL || undefined, right: a.btnRadiusTR || undefined, bottom: a.btnRadiusBR || undefined, left: a.btnRadiusBL || undefined } } onChange={ v => set( { btnRadiusTL: v.top ?? '', btnRadiusTR: v.right ?? '', btnRadiusBR: v.bottom ?? '', btnRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.btnFontSize } onChange={ v => set( { btnFontSize: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.btnFontFamily } onChange={ v => set( { btnFontFamily: v } ) } />
			<Typo pfx="btn" a={ a } set={ set } />
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.btnPaddingTop, right: a.btnPaddingRight, bottom: a.btnPaddingBottom, left: a.btnPaddingLeft } } onChange={ v => set( { btnPaddingTop: v.top ?? '', btnPaddingRight: v.right ?? '', btnPaddingBottom: v.bottom ?? '', btnPaddingLeft: v.left ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Transition Duration', 'neural-forms-lite' ) } value={ a.btnTransitionDuration } onChange={ v => set( { btnTransitionDuration: v ?? '' } ) } units={ [ { value: 's', label: 's', default: 0, step: 0.1 }, { value: 'ms', label: 'ms', default: 0, step: 10 } ] } />
			<SelectControl label={ __( 'Transition Easing', 'neural-forms-lite' ) } value={ a.btnTransitionEasing || '' } options={ [ { label: __( 'Default', 'neural-forms-lite' ), value: '' }, { label: 'ease', value: 'ease' }, { label: 'linear', value: 'linear' }, { label: 'ease-in', value: 'ease-in' }, { label: 'ease-out', value: 'ease-out' }, { label: 'ease-in-out', value: 'ease-in-out' } ] } onChange={ v => set( { btnTransitionEasing: v } ) } />
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

export default function PollDesignExtras( { attributes, setAttributes } ) {
	const a = attributes, set = setAttributes;
	return (
		<>
			<ContainerPanel       a={ a } set={ set } />
			<TitlePanel           a={ a } set={ set } />
			<LabelsPanel          a={ a } set={ set } />
			<InputFieldsPanel     a={ a } set={ set } />
			<TextareaFieldPanel   a={ a } set={ set } />
			<AcceptanceFieldPanel a={ a } set={ set } />
			<PollOptionsPanel     a={ a } set={ set } />
			<ResultsBarPanel      a={ a } set={ set } />
			<SubmitButtonPanel    a={ a } set={ set } />
			<MessagesPanel        a={ a } set={ set } />
			<PanelBody title={ __( 'Advanced', 'neural-forms-lite' ) } initialOpen={ false }>
				<TextControl label={ __( 'Extra CSS Class', 'neural-forms-lite' ) } value={ a.extraClass || '' } onChange={ v => set( { extraClass: v } ) } help={ __( 'Space-separated class names.', 'neural-forms-lite' ) } />
			</PanelBody>
		</>
	);
}
