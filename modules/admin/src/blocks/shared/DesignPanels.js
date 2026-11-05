import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { BUTTON_ICONS } from './buttonIcons';
import {
	useSettings,
	FontSizePicker,
	LineHeightControl,
	MediaUpload,
	MediaUploadCheck,
	__experimentalFontAppearanceControl  as FontAppearanceControl,
	__experimentalLetterSpacingControl   as LetterSpacingControl,
	__experimentalTextTransformControl   as TextTransformControl,
	__experimentalTextDecorationControl  as TextDecorationControl,
	__experimentalSpacingSizesControl    as SpacingSizesControl,
} from '@wordpress/block-editor';
import {
	BaseControl,
	PanelBody, ColorPalette, GradientPicker,
	Button, TabPanel, SelectControl, ToggleControl,
	TextControl, BoxControl, BorderBoxControl,
	__experimentalUnitControl          as UnitControl,
	__experimentalToggleGroupControl   as ToggleGroupControl,
	__experimentalToggleGroupControlOption     as ToggleGroupControlOption,
	__experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
	Icon, SVG, Path,
	__experimentalToolsPanel           as ToolsPanel,
	__experimentalToolsPanelItem       as ToolsPanelItem,
} from '@wordpress/components';

// ─── Primitive helpers ────────────────────────────────────────────────────────

export function Sep() {
	return <hr style={{ margin: '12px 0', border: 'none', borderTop: '1px solid #e0e0e0' }} />;
}

export function Gap() {
	return <div style={{ marginTop: 16 }} />;
}

function BorderBox( { label, value, onChange, enableAlpha } ) {
	const isEmpty = ! value || Object.keys( value ).length === 0
		|| ( ! value.color && ! value.width && ! value.style
			&& ! value.top && ! value.right && ! value.bottom && ! value.left );
	return (
		<div style={{ position: 'relative' }}>
			{ ! isEmpty && (
				<Button
					variant="secondary"
					isSmall
					onClick={ () => onChange( {} ) }
					style={{ position: 'absolute', top: '-2px', right: 0, zIndex: 1 }}
				>
					{ __( 'Reset', 'neural-forms-lite' ) }
				</Button>
			) }
			<BorderBoxControl label={ label } value={ value || {} } onChange={ onChange } enableAlpha={ enableAlpha } />
		</div>
	);
}

// ─── Reusable Dimensions helpers ─────────────────────────────────────────────

// Padding only — wraps one SpacingSizesControl in ToolsPanel+ToolsPanelItem.
// topKey/rightKey/bottomKey/leftKey are the exact attribute key strings.
function PaddingDimensions( { topKey, rightKey, bottomKey, leftKey, a, set } ) {
	if ( ! SpacingSizesControl || ! ToolsPanel ) return null;
	const val = k => a[ k ] || undefined;
	const hasValue = !! ( a[ topKey ] || a[ rightKey ] || a[ bottomKey ] || a[ leftKey ] );
	return (
		<ToolsPanel
			label={ __( 'Dimensions', 'neural-forms-lite' ) }
			resetAll={ () => set( { [ topKey ]: '', [ rightKey ]: '', [ bottomKey ]: '', [ leftKey ]: '' } ) }
		>
			<ToolsPanelItem
				label={ __( 'Padding', 'neural-forms-lite' ) }
				hasValue={ () => hasValue }
				onDeselect={ () => set( { [ topKey ]: '', [ rightKey ]: '', [ bottomKey ]: '', [ leftKey ]: '' } ) }
				isShownByDefault
			>
				<SpacingSizesControl
					label={ __( 'Padding', 'neural-forms-lite' ) }
					values={ { top: val( topKey ), right: val( rightKey ), bottom: val( bottomKey ), left: val( leftKey ) } }
					onChange={ v => set( { [ topKey ]: v.top ?? '', [ rightKey ]: v.right ?? '', [ bottomKey ]: v.bottom ?? '', [ leftKey ]: v.left ?? '' } ) }
					sides={ [ 'top', 'right', 'bottom', 'left' ] }
					allowReset
				/>
			</ToolsPanelItem>
		</ToolsPanel>
	);
}

// Padding + Margin — wraps two SpacingSizesControls in one ToolsPanel.
export function PaddingMarginDimensions( { pTop, pRight, pBottom, pLeft, mTop, mRight, mBottom, mLeft, a, set } ) {
	if ( ! SpacingSizesControl || ! ToolsPanel ) return null;
	const val = k => a[ k ] || undefined;
	const padHasValue  = !! ( a[ pTop ] || a[ pRight ] || a[ pBottom ] || a[ pLeft ] );
	const margHasValue = !! ( a[ mTop ] || a[ mRight ] || a[ mBottom ] || a[ mLeft ] );
	return (
		<ToolsPanel
			label={ __( 'Dimensions', 'neural-forms-lite' ) }
			resetAll={ () => set( { [ pTop ]: '', [ pRight ]: '', [ pBottom ]: '', [ pLeft ]: '', [ mTop ]: '', [ mRight ]: '', [ mBottom ]: '', [ mLeft ]: '' } ) }
		>
			<ToolsPanelItem
				label={ __( 'Padding', 'neural-forms-lite' ) }
				hasValue={ () => padHasValue }
				onDeselect={ () => set( { [ pTop ]: '', [ pRight ]: '', [ pBottom ]: '', [ pLeft ]: '' } ) }
				isShownByDefault
			>
				<SpacingSizesControl
					label={ __( 'Padding', 'neural-forms-lite' ) }
					values={ { top: val( pTop ), right: val( pRight ), bottom: val( pBottom ), left: val( pLeft ) } }
					onChange={ v => set( { [ pTop ]: v.top ?? '', [ pRight ]: v.right ?? '', [ pBottom ]: v.bottom ?? '', [ pLeft ]: v.left ?? '' } ) }
					sides={ [ 'top', 'right', 'bottom', 'left' ] }
					allowReset
				/>
			</ToolsPanelItem>
			<ToolsPanelItem
				label={ __( 'Margin', 'neural-forms-lite' ) }
				hasValue={ () => margHasValue }
				onDeselect={ () => set( { [ mTop ]: '', [ mRight ]: '', [ mBottom ]: '', [ mLeft ]: '' } ) }
				isShownByDefault
			>
				<SpacingSizesControl
					label={ __( 'Margin', 'neural-forms-lite' ) }
					values={ { top: val( mTop ), right: val( mRight ), bottom: val( mBottom ), left: val( mLeft ) } }
					onChange={ v => set( { [ mTop ]: v.top ?? '', [ mRight ]: v.right ?? '', [ mBottom ]: v.bottom ?? '', [ mLeft ]: v.left ?? '' } ) }
					sides={ [ 'top', 'right', 'bottom', 'left' ] }
					allowReset
				/>
			</ToolsPanelItem>
		</ToolsPanel>
	);
}

// Typography — wraps 6 native WP controls in ToolsPanel+ToolsPanelItem.
// FontFamily is intentionally kept outside (use FontFamilySelect above this component).
function TypographyPanel( { pfx, a, set } ) {
	if ( ! ToolsPanel ) return null;
	const fontValue = {
		fontStyle:  a[ pfx + 'FontStyle' ]  || '',
		fontWeight: a[ pfx + 'FontWeight' ] || '',
	};
	return (
		<ToolsPanel
			className="nf-typo-panel"
			label={ __( 'Typography', 'neural-forms-lite' ) }
			resetAll={ () => set( {
				[ pfx + 'FontSize' ]:       '',
				[ pfx + 'FontStyle' ]:      '',
				[ pfx + 'FontWeight' ]:     '',
				[ pfx + 'LetterSpacing' ]:  '',
				[ pfx + 'TextDecoration' ]: '',
				[ pfx + 'TextTransform' ]:  '',
				[ pfx + 'LineHeight' ]:     '',
			} ) }
		>
			{ FontSizePicker && (
				<ToolsPanelItem
					label={ __( 'Font Size', 'neural-forms-lite' ) }
					hasValue={ () => !! a[ pfx + 'FontSize' ] }
					onDeselect={ () => set( { [ pfx + 'FontSize' ]: '' } ) }
					isShownByDefault
				>
					<FontSizePicker
						value={ a[ pfx + 'FontSize' ] || undefined }
						onChange={ v => set( { [ pfx + 'FontSize' ]: v ?? '' } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</ToolsPanelItem>
			) }
			{ FontAppearanceControl && (
				<ToolsPanelItem
					className="single-column"
					label={ __( 'Appearance', 'neural-forms-lite' ) }
					hasValue={ () => !! ( a[ pfx + 'FontStyle' ] || a[ pfx + 'FontWeight' ] ) }
					onDeselect={ () => set( { [ pfx + 'FontStyle' ]: '', [ pfx + 'FontWeight' ]: '' } ) }
					isShownByDefault
				>
					<FontAppearanceControl
						value={ fontValue }
						onChange={ v => set( { [ pfx + 'FontStyle' ]: v?.fontStyle ?? '', [ pfx + 'FontWeight' ]: v?.fontWeight ?? '' } ) }
						__next40pxDefaultSize
					/>
				</ToolsPanelItem>
			) }
			{ LetterSpacingControl && (
				<ToolsPanelItem
					className="single-column"
					label={ __( 'Letter Spacing', 'neural-forms-lite' ) }
					hasValue={ () => !! a[ pfx + 'LetterSpacing' ] }
					onDeselect={ () => set( { [ pfx + 'LetterSpacing' ]: '' } ) }
					isShownByDefault
				>
					<LetterSpacingControl
						value={ a[ pfx + 'LetterSpacing' ] || '' }
						onChange={ v => set( { [ pfx + 'LetterSpacing' ]: v ?? '' } ) }
						__next40pxDefaultSize
					/>
				</ToolsPanelItem>
			) }
			{ TextDecorationControl && (
				<ToolsPanelItem
					label={ __( 'Decoration', 'neural-forms-lite' ) }
					hasValue={ () => !! a[ pfx + 'TextDecoration' ] }
					onDeselect={ () => set( { [ pfx + 'TextDecoration' ]: '' } ) }
					isShownByDefault
				>
					<TextDecorationControl
						value={ a[ pfx + 'TextDecoration' ] || '' }
						onChange={ v => set( { [ pfx + 'TextDecoration' ]: v ?? '' } ) }
					/>
				</ToolsPanelItem>
			) }
			{ TextTransformControl && (
				<ToolsPanelItem
					label={ __( 'Letter Case', 'neural-forms-lite' ) }
					hasValue={ () => !! a[ pfx + 'TextTransform' ] }
					onDeselect={ () => set( { [ pfx + 'TextTransform' ]: '' } ) }
					isShownByDefault
				>
					<TextTransformControl
						value={ a[ pfx + 'TextTransform' ] || '' }
						onChange={ v => set( { [ pfx + 'TextTransform' ]: v ?? '' } ) }
					/>
				</ToolsPanelItem>
			) }
			{ LineHeightControl && (
				<ToolsPanelItem
					className="single-column"
					label={ __( 'Line Height', 'neural-forms-lite' ) }
					hasValue={ () => !! a[ pfx + 'LineHeight' ] }
					onDeselect={ () => set( { [ pfx + 'LineHeight' ]: '' } ) }
					isShownByDefault
				>
					<LineHeightControl
						value={ a[ pfx + 'LineHeight' ] || '' }
						onChange={ v => set( { [ pfx + 'LineHeight' ]: String( v ?? '' ) } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</ToolsPanelItem>
			) }
		</ToolsPanel>
	);
}

// Alignment — 4-option icon control (left/center/right/justify).
// Pass justify={false} to get 3-option (left/center/right) for positional controls.
function AlignControl( { label, value, onChange, justify = true } ) {
	return (
		<ToggleGroupControl label={ label } value={ value } onChange={ onChange } isBlock __nextHasNoMarginBottom>
			<ToggleGroupControlOptionIcon value="left"    icon={ <Icon icon="editor-alignleft"   /> } label={ __( 'Left',    'neural-forms-lite' ) } />
			<ToggleGroupControlOptionIcon value="center"  icon={ <Icon icon="editor-aligncenter" /> } label={ __( 'Center',  'neural-forms-lite' ) } />
			<ToggleGroupControlOptionIcon value="right"   icon={ <Icon icon="editor-alignright"  /> } label={ __( 'Right',   'neural-forms-lite' ) } />
			{ justify && <ToggleGroupControlOptionIcon value="justify" icon={ <Icon icon="editor-justify" /> } label={ __( 'Justify', 'neural-forms-lite' ) } /> }
		</ToggleGroupControl>
	);
}

export function Clr({ label, value, onChange }) {
	return (
		<BaseControl label={ label } __nextHasNoMarginBottom>
			<ColorPalette value={ value } onChange={ onChange } enableAlpha />
		</BaseControl>
	);
}

export function Grad({ label, value, onChange }) {
	return (
		<BaseControl label={ label } __nextHasNoMarginBottom>
			<GradientPicker value={ value || undefined } onChange={ onChange } />
		</BaseControl>
	);
}

// ─── Constants ────────────────────────────────────────────────────────────────

export const SYSTEM_FONTS = [
	{ label: 'Arial', value: 'Arial, sans-serif' },
	{ label: 'Georgia', value: 'Georgia, serif' },
	{ label: 'Helvetica Neue', value: '"Helvetica Neue", Helvetica, sans-serif' },
	{ label: 'Times New Roman', value: '"Times New Roman", Times, serif' },
	{ label: 'Trebuchet MS', value: '"Trebuchet MS", sans-serif' },
	{ label: 'Verdana', value: 'Verdana, sans-serif' },
	{ label: 'Courier New', value: '"Courier New", monospace' },
	{ label: 'system-ui', value: 'system-ui, sans-serif' },
];

export const FW = [
	{ label: __( 'Default', 'neural-forms-lite' ), value: '' },
	{ label: '300 — Light', value: '300' },
	{ label: '400 — Regular', value: '400' },
	{ label: '500 — Medium', value: '500' },
	{ label: '600 — Semi Bold', value: '600' },
	{ label: '700 — Bold', value: '700' },
	{ label: '800 — Extra Bold', value: '800' },
];

export const EASING_OPTIONS = [
	{ label: __( 'Default', 'neural-forms-lite' ), value: '' },
	{ label: 'ease', value: 'ease' },
	{ label: 'linear', value: 'linear' },
	{ label: 'ease-in', value: 'ease-in' },
	{ label: 'ease-out', value: 'ease-out' },
	{ label: 'ease-in-out', value: 'ease-in-out' },
];

// ─── Typography controls ──────────────────────────────────────────────────────

export function FontFamilySelect( { label, value, onChange } ) {
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

// ─── Shared panels ────────────────────────────────────────────────────────────

export function ContainerPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Container', 'neural-forms-lite' ) } initialOpen={ false }>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel
					initialTabName={ a.bgGradient ? 'gradient' : 'color' }
					tabs={ [
						{ name: 'color',    title: __( 'Color',    'neural-forms-lite' ) },
						{ name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) },
					] }
				>
					{ ( tab ) => tab.name === 'color'
						? <Clr label="" value={ a.bgColor } onChange={ v => set( { bgColor: v ?? '' } ) } />
						: <Grad label="" value={ a.bgGradient } onChange={ v => set( { bgGradient: v ?? '' } ) } />
					}
				</TabPanel>
			</BaseControl>
			<Gap />
			<MediaUploadCheck>
				<MediaUpload
					onSelect={ media => set( { bgImage: media.url } ) }
					allowedTypes={ [ 'image' ] }
					accept="image/*"
					value={ a.bgImage }
					render={ ( { open } ) => (
						<BaseControl label={ __( 'Background Image', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
							{ a.bgImage && (
								<img src={ a.bgImage } alt="" style={ { display: 'block', width: '100%', height: 80, objectFit: 'cover', borderRadius: 4, marginBottom: 6 } } />
							) }
							<Button isPrimary onClick={ e => { e.stopPropagation(); open(); } } __next40pxDefaultSize>
								{ a.bgImage ? __( 'Edit Image', 'neural-forms-lite' ) : __( 'Select Image', 'neural-forms-lite' ) }
							</Button>
							{ a.bgImage && (
								<Button isLink isDestructive onClick={ () => set( { bgImage: '' } ) } style={ { marginLeft: '10px', verticalAlign: 'super' } } __next40pxDefaultSize>
									{ __( 'Remove Image', 'neural-forms-lite' ) }
								</Button>
							) }
						</BaseControl>
					) }
				/>
			</MediaUploadCheck>
			{ a.bgImage && (
				<>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Size', 'neural-forms-lite' ) }
						value={ a.bgImageSize || 'cover' }
						options={ [
							{ value: 'cover',   label: __( 'Cover',           'neural-forms-lite' ) },
							{ value: 'contain', label: __( 'Fit',             'neural-forms-lite' ) },
							{ value: 'initial', label: __( 'Actual Size',     'neural-forms-lite' ) },
							{ value: 'stretch', label: __( 'Stretch to Fill', 'neural-forms-lite' ) },
						] }
						onChange={ v => set( { bgImageSize: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Position', 'neural-forms-lite' ) }
						value={ a.bgImagePosition || 'center' }
						options={ [
							{ value: 'top_left',      label: __( 'Top Left',      'neural-forms-lite' ) },
							{ value: 'top_center',    label: __( 'Top Center',    'neural-forms-lite' ) },
							{ value: 'top_right',     label: __( 'Top Right',     'neural-forms-lite' ) },
							{ value: 'center_left',   label: __( 'Center Left',   'neural-forms-lite' ) },
							{ value: 'center',        label: __( 'Center',        'neural-forms-lite' ) },
							{ value: 'center_right',  label: __( 'Center Right',  'neural-forms-lite' ) },
							{ value: 'bottom_left',   label: __( 'Bottom Left',   'neural-forms-lite' ) },
							{ value: 'bottom_center', label: __( 'Bottom Center', 'neural-forms-lite' ) },
							{ value: 'bottom_right',  label: __( 'Bottom Right',  'neural-forms-lite' ) },
						] }
						onChange={ v => set( { bgImagePosition: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Repeat', 'neural-forms-lite' ) }
						value={ a.bgImageRepeat || 'no-repeat' }
						options={ [
							{ value: 'repeat',    label: __( 'Repeat',                    'neural-forms-lite' ) },
							{ value: 'repeat-x',  label: __( 'Repeat X (horizontal)',     'neural-forms-lite' ) },
							{ value: 'repeat-y',  label: __( 'Repeat Y (vertical)',       'neural-forms-lite' ) },
							{ value: 'space',     label: __( 'Repeat with space between', 'neural-forms-lite' ) },
							{ value: 'round',     label: __( 'Repeat and Stretch',        'neural-forms-lite' ) },
							{ value: 'no-repeat', label: __( 'No Repeat',                'neural-forms-lite' ) },
						] }
						onChange={ v => set( { bgImageRepeat: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Blend', 'neural-forms-lite' ) }
						value={ a.bgImageBlend || 'normal' }
						options={ [
							{ value: 'normal',   label: __( 'Normal',   'neural-forms-lite' ) },
							{ value: 'multiply', label: __( 'Multiply', 'neural-forms-lite' ) },
							{ value: 'screen',   label: __( 'Screen',   'neural-forms-lite' ) },
							{ value: 'overlay',  label: __( 'Overlay',  'neural-forms-lite' ) },
							{ value: 'darken',   label: __( 'Darken',   'neural-forms-lite' ) },
							{ value: 'lighten',  label: __( 'Lighten',  'neural-forms-lite' ) },
						] }
						onChange={ v => set( { bgImageBlend: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
				</>
			) }
			<Sep />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.border || {} } onChange={ v => set( { border: v ?? {} } ) } enableAlpha />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.borderRadiusTL || undefined, right: a.borderRadiusTR || undefined, bottom: a.borderRadiusBR || undefined, left: a.borderRadiusBL || undefined } } onChange={ v => set( { borderRadiusTL: v.top ?? '', borderRadiusTR: v.right ?? '', borderRadiusBR: v.bottom ?? '', borderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<PaddingMarginDimensions
				pTop="paddingTop" pRight="paddingRight" pBottom="paddingBottom" pLeft="paddingLeft"
				mTop="marginTop" mRight="marginRight" mBottom="marginBottom" mLeft="marginLeft"
				a={ a } set={ set }
			/>
			<Sep />
			<UnitControl label={ __( 'Width', 'neural-forms-lite' ) } value={ a.formWidth } onChange={ v => set( { formWidth: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Max Width', 'neural-forms-lite' ) } value={ a.maxWidth } onChange={ v => set( { maxWidth: v ?? '' } ) } />
			<Gap />
			<ToggleGroupControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.formAlign || 'left' } onChange={ v => set( { formAlign: v ?? 'left' } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOptionIcon value="left"    icon={ <Icon icon="editor-alignleft"   /> } label={ __( 'Left',   'neural-forms-lite' ) } />
				<ToggleGroupControlOptionIcon value="center"  icon={ <Icon icon="editor-aligncenter" /> } label={ __( 'Center', 'neural-forms-lite' ) } />
				<ToggleGroupControlOptionIcon value="right"   icon={ <Icon icon="editor-alignright"  /> } label={ __( 'Right',  'neural-forms-lite' ) } />
				<ToggleGroupControlOptionIcon value="stretch" icon={ <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><Path d="M5 4H4v16h1V4zm15 0h-1v16h1V4zM8 12.5h8V11H8v1.5z" /></SVG> } label={ __( 'Full', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Sep />
			<BaseControl label={ __( 'Box Shadow', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<Clr label={ __( 'Shadow Color', 'neural-forms-lite' ) } value={ a.shadowColor } onChange={ v => set( { shadowColor: v ?? '' } ) } />
				<Gap />
				<ToggleGroupControl label={ __( 'Position', 'neural-forms-lite' ) } value={ a.shadowPosition || 'outer' } onChange={ v => set( { shadowPosition: v } ) } isBlock __nextHasNoMarginBottom>
					<ToggleGroupControlOption value="outer" label={ __( 'Outer', 'neural-forms-lite' ) } />
					<ToggleGroupControlOption value="inner" label={ __( 'Inner', 'neural-forms-lite' ) } />
				</ToggleGroupControl>
				<Gap />
				<UnitControl label={ __( 'Horizontal', 'neural-forms-lite' ) } value={ a.shadowH } onChange={ v => set( { shadowH: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Vertical', 'neural-forms-lite' ) } value={ a.shadowV } onChange={ v => set( { shadowV: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Blur', 'neural-forms-lite' ) } value={ a.shadowBlur } onChange={ v => set( { shadowBlur: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Spread', 'neural-forms-lite' ) } value={ a.shadowSpread } onChange={ v => set( { shadowSpread: v ?? '' } ) } />
			</BaseControl>
		</PanelBody>
	);
}

export function TitlePanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Form Title', 'neural-forms-lite' ) } initialOpen={ false }>
			<SelectControl label={ __( 'Heading Level', 'neural-forms-lite' ) } value={ a.titleLevel || 'h2' } options={ [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( h => ( { label: h.toUpperCase(), value: h } ) ) } onChange={ v => set( { titleLevel: v } ) } />
			<AlignControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.titleAlign || 'left' } onChange={ v => set( { titleAlign: v ?? 'left' } ) } />
			<Sep />
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.titleColor } onChange={ v => set( { titleColor: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.titleFontFamily } onChange={ v => set( { titleFontFamily: v } ) } />
			<TypographyPanel pfx="title" a={ a } set={ set } />
			<Sep />
			<PaddingMarginDimensions
				pTop="titlePaddingTop" pRight="titlePaddingRight" pBottom="titlePaddingBottom" pLeft="titlePaddingLeft"
				mTop="titleMarginTop" mRight="titleMarginRight" mBottom="titleMarginBottom" mLeft="titleMarginLeft"
				a={ a } set={ set }
			/>
		</PanelBody>
	);
}

export function LabelsPanel( { a, set } ) {
	if ( ! a.showLabels ) return null;
	return (
		<PanelBody title={ __( 'Labels', 'neural-forms-lite' ) } initialOpen={ false }>
			{ /* Show Labels lives in Settings → Display now; removed
			     from here to avoid a duplicate control. */ }
			<AlignControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a.labelAlign || 'left' } onChange={ v => set( { labelAlign: v ?? 'left' } ) } />
			<Sep />
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.labelColor } onChange={ v => set( { labelColor: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.labelFontFamily } onChange={ v => set( { labelFontFamily: v } ) } />
			<TypographyPanel pfx="label" a={ a } set={ set } />
			<Sep />
			<PaddingMarginDimensions
				pTop="labelPaddingTop" pRight="labelPaddingRight" pBottom="labelPaddingBottom" pLeft="labelPaddingLeft"
				mTop="labelMarginTop" mRight="labelMarginRight" mBottom="labelMarginBottom" mLeft="labelMarginLeft"
				a={ a } set={ set }
			/>
		</PanelBody>
	);
}

export function InputFieldsPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Input Fields', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Text, number, tel, url, date, select fields.', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.fieldBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.fieldBg } onChange={ v => set( { fieldBg: v ?? '' } ) } /> : <Grad label="" value={ a.fieldBgGradient } onChange={ v => set( { fieldBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.fieldHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.fieldHoverBg } onChange={ v => set( { fieldHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.fieldHoverBgGradient } onChange={ v => set( { fieldHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Focus Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.fieldFocusBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.fieldFocusBg } onChange={ v => set( { fieldFocusBg: v ?? '' } ) } /> : <Grad label="" value={ a.fieldFocusBgGradient } onChange={ v => set( { fieldFocusBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.fieldTextColor } onChange={ v => set( { fieldTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Text Color', 'neural-forms-lite' ) } value={ a.fieldFocusTextColor } onChange={ v => set( { fieldFocusTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Placeholder Color', 'neural-forms-lite' ) } value={ a.fieldPlaceholderColor } onChange={ v => set( { fieldPlaceholderColor: v ?? '' } ) } />
			<Sep />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.fieldBorder || {} } onChange={ v => set( { fieldBorder: v ?? {} } ) } enableAlpha />
			<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a.fieldHoverBorderColor } onChange={ v => set( { fieldHoverBorderColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Border Color', 'neural-forms-lite' ) } value={ a.fieldFocusBorderColor } onChange={ v => set( { fieldFocusBorderColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.fieldBorderRadiusTL, right: a.fieldBorderRadiusTR, bottom: a.fieldBorderRadiusBR, left: a.fieldBorderRadiusBL } } onChange={ v => set( { fieldBorderRadiusTL: v.top ?? '', fieldBorderRadiusTR: v.right ?? '', fieldBorderRadiusBR: v.bottom ?? '', fieldBorderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.fieldFontFamily } onChange={ v => set( { fieldFontFamily: v } ) } />
			<TypographyPanel pfx="field" a={ a } set={ set } />
			<AlignControl label={ __( 'Text Align', 'neural-forms-lite' ) } value={ a.fieldTextAlign || 'left' } onChange={ v => set( { fieldTextAlign: v ?? 'left' } ) } />
			<Sep />
			<PaddingDimensions topKey="fieldPaddingTop" rightKey="fieldPaddingRight" bottomKey="fieldPaddingBottom" leftKey="fieldPaddingLeft" a={ a } set={ set } />
			<Gap />
			<UnitControl label={ __( 'Gap Between Fields', 'neural-forms-lite' ) } value={ a.fieldGap } onChange={ v => set( { fieldGap: v ?? '' } ) } />
		</PanelBody>
	);
}

export function TextareaFieldPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Textarea Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Overrides for textarea fields only.', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.textareaBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.textareaBg } onChange={ v => set( { textareaBg: v ?? '' } ) } /> : <Grad label="" value={ a.textareaBgGradient } onChange={ v => set( { textareaBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.textareaHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.textareaHoverBg } onChange={ v => set( { textareaHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.textareaHoverBgGradient } onChange={ v => set( { textareaHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Focus Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.textareaFocusBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.textareaFocusBg } onChange={ v => set( { textareaFocusBg: v ?? '' } ) } /> : <Grad label="" value={ a.textareaFocusBgGradient } onChange={ v => set( { textareaFocusBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.textareaTextColor } onChange={ v => set( { textareaTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Text Color', 'neural-forms-lite' ) } value={ a.textareaFocusTextColor } onChange={ v => set( { textareaFocusTextColor: v ?? '' } ) } />
			<Clr label={ __( 'Placeholder Color', 'neural-forms-lite' ) } value={ a.textareaPlaceholderColor } onChange={ v => set( { textareaPlaceholderColor: v ?? '' } ) } />
			<Sep />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.textareaBorder || {} } onChange={ v => set( { textareaBorder: v ?? {} } ) } enableAlpha />
			<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a.textareaHoverBorderColor } onChange={ v => set( { textareaHoverBorderColor: v ?? '' } ) } />
			<Clr label={ __( 'Focus Border Color', 'neural-forms-lite' ) } value={ a.textareaFocusBorderColor } onChange={ v => set( { textareaFocusBorderColor: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.textareaBorderRadiusTL, right: a.textareaBorderRadiusTR, bottom: a.textareaBorderRadiusBR, left: a.textareaBorderRadiusBL } } onChange={ v => set( { textareaBorderRadiusTL: v.top ?? '', textareaBorderRadiusTR: v.right ?? '', textareaBorderRadiusBR: v.bottom ?? '', textareaBorderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.textareaFontFamily } onChange={ v => set( { textareaFontFamily: v } ) } />
			<TypographyPanel pfx="textarea" a={ a } set={ set } />
			<AlignControl label={ __( 'Text Align', 'neural-forms-lite' ) } value={ a.textareaTextAlign || 'left' } onChange={ v => set( { textareaTextAlign: v ?? 'left' } ) } />
			<Sep />
			<PaddingDimensions topKey="textareaPaddingTop" rightKey="textareaPaddingRight" bottomKey="textareaPaddingBottom" leftKey="textareaPaddingLeft" a={ a } set={ set } />
			<Gap />
			<UnitControl label={ __( 'Min Height', 'neural-forms-lite' ) } value={ a.textareaMinHeight } onChange={ v => set( { textareaMinHeight: v ?? '' } ) } />
		</PanelBody>
	);
}

export function RadioFieldPanel( { a, set } ) {
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

export function CheckboxGroupPanel( { a, set } ) {
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

export function AcceptanceFieldPanel( { a, set } ) {
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

export function RatingFieldPanel( { a, set } ) {
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

// ─── Pricing: quantity label, Calculated field, totals box ──────────────────
// Added 2026-11-05 (owner request): no panel covered the Priced Item's
// "Quantity" label, the Calculated field's displayed value, or the
// Subtotal/Total summary box on any block — a real gap across the whole
// product, not just the Payment Form card. Kept as one compact panel
// (mirrors the depth of RatingFieldPanel / AcceptanceFieldPanel, not the
// bigger Container / Input Fields panels) since these are a handful of
// small, display-only elements, not a full field type.
export function PricingPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Pricing & Totals', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Quantity Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.qtyLabelColor } onChange={ v => set( { qtyLabelColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.qtyLabelFontSize } onChange={ v => set( { qtyLabelFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.qtyLabelFontWeight || '' } options={ FW } onChange={ v => set( { qtyLabelFontWeight: v } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Calculated Field', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.calculatedColor } onChange={ v => set( { calculatedColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.calculatedFontSize } onChange={ v => set( { calculatedFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.calculatedFontWeight || '' } options={ FW } onChange={ v => set( { calculatedFontWeight: v } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Totals Box', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'The Subtotal / Discount / Total summary shown when a form has pricing.', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Background', 'neural-forms-lite' ) } value={ a.totalsBg } onChange={ v => set( { totalsBg: v ?? '' } ) } />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.totalsBorder || {} } onChange={ v => set( { totalsBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.totalsBorderRadius } onChange={ v => set( { totalsBorderRadius: v ?? '' } ) } />
			<Gap />
			<PaddingDimensions topKey="totalsPaddingTop" rightKey="totalsPaddingRight" bottomKey="totalsPaddingBottom" leftKey="totalsPaddingLeft" a={ a } set={ set } />
			<Sep />
			<Clr label={ __( 'Row Label Color', 'neural-forms-lite' ) } value={ a.totalsRowLabelColor } onChange={ v => set( { totalsRowLabelColor: v ?? '' } ) } />
			<Clr label={ __( 'Row Value Color', 'neural-forms-lite' ) } value={ a.totalsRowValueColor } onChange={ v => set( { totalsRowValueColor: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( '"Total" row (bold, overrides the row colors above for that row only).', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Total Row Color', 'neural-forms-lite' ) } value={ a.totalsTotalColor } onChange={ v => set( { totalsTotalColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Total Row Font Size', 'neural-forms-lite' ) } value={ a.totalsTotalFontSize } onChange={ v => set( { totalsTotalFontSize: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Item Price', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'The price shown next to a fixed-price item or each option (e.g. "$9.00").', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.productPriceColor } onChange={ v => set( { productPriceColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.productPriceFontSize } onChange={ v => set( { productPriceFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.productPriceFontWeight || '' } options={ FW } onChange={ v => set( { productPriceFontWeight: v } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Item Line Total', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'The computed running total shown under a priced item (quantity × price).', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.productLineColor } onChange={ v => set( { productLineColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.productLineFontSize } onChange={ v => set( { productLineFontSize: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Item Options (radio / checkboxes)', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'When a Priced Item shows its choices as radio buttons or checkboxes instead of a dropdown.', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Accent Color (radio/checkbox)', 'neural-forms-lite' ) } value={ a.productOptionAccentColor } onChange={ v => set( { productOptionAccentColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Size', 'neural-forms-lite' ) } value={ a.productOptionSize } onChange={ v => set( { productOptionSize: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Option Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.productOptionLabelColor } onChange={ v => set( { productOptionLabelColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.productOptionLabelFontSize } onChange={ v => set( { productOptionLabelFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.productOptionLabelFontWeight || '' } options={ FW } onChange={ v => set( { productOptionLabelFontWeight: v } ) } />
		</PanelBody>
	);
}

export function FileUploadPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'File Upload Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Filename Display Area', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.fileInputBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.fileInputBg } onChange={ v => set( { fileInputBg: v ?? '' } ) } /> : <Grad label="" value={ a.fileInputBgGradient } onChange={ v => set( { fileInputBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.fileInputColor } onChange={ v => set( { fileInputColor: v ?? '' } ) } />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.fileInputBorder || {} } onChange={ v => set( { fileInputBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.fileInputBorderRadius } onChange={ v => set( { fileInputBorderRadius: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fileInputFontSize } onChange={ v => set( { fileInputFontSize: v ?? '' } ) } />
			<Gap />
			<PaddingDimensions topKey="fileInputPaddingTop" rightKey="fileInputPaddingRight" bottomKey="fileInputPaddingBottom" leftKey="fileInputPaddingLeft" a={ a } set={ set } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Browse Button', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.fileBtnBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.fileBtnBg } onChange={ v => set( { fileBtnBg: v ?? '' } ) } /> : <Grad label="" value={ a.fileBtnBgGradient } onChange={ v => set( { fileBtnBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.fileBtnHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.fileBtnHoverBg } onChange={ v => set( { fileBtnHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.fileBtnHoverBgGradient } onChange={ v => set( { fileBtnHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.fileBtnColor } onChange={ v => set( { fileBtnColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fileBtnFontSize } onChange={ v => set( { fileBtnFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.fileBtnFontWeight || '' } options={ FW } onChange={ v => set( { fileBtnFontWeight: v } ) } />
			<Gap />
			<PaddingDimensions topKey="fileBtnPaddingTop" rightKey="fileBtnPaddingRight" bottomKey="fileBtnPaddingBottom" leftKey="fileBtnPaddingLeft" a={ a } set={ set } />
			<Gap />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.fileBtnBorder || {} } onChange={ v => set( { fileBtnBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.fileBtnBorderRadius } onChange={ v => set( { fileBtnBorderRadius: v ?? '' } ) } />
		</PanelBody>
	);
}

// ─── Button icon helpers ──────────────────────────────────────────────────────

/**
 * Visual grid of SVG icon buttons. Clicking a selected icon de-selects it
 * (returns ''); clicking a different icon selects it. Entirely static SVG
 * content — dangerouslySetInnerHTML is safe because BUTTON_ICONS is a
 * hardcoded module constant, not user input.
 */
function BtnIconPicker( { value, onChange } ) {
	return (
		<BaseControl label={ __( 'Icon', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
			<div style={ { display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 4, marginTop: 6 } }>
				{ BUTTON_ICONS.map( ( { id, label, svg } ) => (
					<button
						key={ id }
						type="button"
						title={ label }
						aria-pressed={ value === id }
						onClick={ () => onChange( value === id ? '' : id ) }
						style={ {
							padding:         6,
							border:          value === id ? '2px solid #007cba' : '1px solid #ddd',
							borderRadius:    4,
							background:      value === id ? '#e8f4fb' : 'transparent',
							cursor:          'pointer',
							display:         'flex',
							alignItems:      'center',
							justifyContent:  'center',
							color:           '#1e1e1e',
							width:           '100%',
							aspectRatio:     '1',
						} }
						// eslint-disable-next-line react/no-danger
						dangerouslySetInnerHTML={ { __html: svg } }
					/>
				) ) }
			</div>
		</BaseControl>
	);
}

/**
 * Icon toggle + picker + position + hover-reveal controls.
 * Used inline for the submit button (via BtnControls) and for each
 * nav button (prev/next) in NavButtonsPanel.
 *
 * @param {string}   enabledKey   attribute key for the boolean toggle
 * @param {string}   iconKey      attribute key for the icon id string
 * @param {string}   positionKey  attribute key for 'before'|'after'
 * @param {string}   hoverKey     attribute key for the hover-reveal boolean
 * @param {string}   defaultPos   default position ('after' for submit/next, 'before' for prev)
 */
function BtnIconSection( { enabledKey, iconKey, positionKey, hoverKey, defaultPos = 'after', a, set } ) {
	return (
		<>
			<ToggleControl
				label={ __( 'Use Icon on Button', 'neural-forms-lite' ) }
				checked={ !! a[ enabledKey ] }
				onChange={ v => set( { [ enabledKey ]: v } ) }
				__nextHasNoMarginBottom
			/>
			{ !! a[ enabledKey ] && (
				<>
					<Gap />
					<BtnIconPicker
						value={ a[ iconKey ] || '' }
						onChange={ v => set( { [ iconKey ]: v } ) }
					/>
					<Gap />
					<ToggleGroupControl
						label={ __( 'Icon Position', 'neural-forms-lite' ) }
						value={ a[ positionKey ] || defaultPos }
						onChange={ v => set( { [ positionKey ]: v || defaultPos } ) }
						isBlock
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="before" label={ __( 'Before', 'neural-forms-lite' ) } />
						<ToggleGroupControlOption value="after"  label={ __( 'After',  'neural-forms-lite' ) } />
					</ToggleGroupControl>
					<Gap />
					<ToggleControl
						label={ __( 'Show Icon on Hover', 'neural-forms-lite' ) }
						help={ __( 'Icon slides in when the button is hovered.', 'neural-forms-lite' ) }
						checked={ !! a[ hoverKey ] }
						onChange={ v => set( { [ hoverKey ]: v } ) }
						__nextHasNoMarginBottom
					/>
				</>
			) }
		</>
	);
}

// ─── Button controls ──────────────────────────────────────────────────────────

// Generic button controls parameterised by prefix (btn / navBtn).
// showAlign=false   — nav buttons are positioned together, not individually aligned.
// showIcons=true    — renders BtnIconSection (submit button, single-prefix attrs).
// iconsContent=<jsx> — renders arbitrary JSX at the same position (nav buttons, whose
//                      prev/next attrs don't fit the single-prefix pattern).
// Both showIcons and iconsContent land between Typography and Padding in the Normal tab.
export function BtnControls( { pfx, a, set, showAlign = true, showFullWidth = true, showIcons = false, iconsContent = null } ) {
	const [ btnTab, setBtnTab ] = useState( 'normal' );
	const fullWidthKey = pfx + 'FullWidth';
	return (
		<>
			{ showAlign && (
				<AlignControl label={ __( 'Alignment', 'neural-forms-lite' ) } value={ a[ pfx + 'Align' ] || 'left' } onChange={ v => set( { [ pfx + 'Align' ]: v ?? 'left' } ) } justify={ false } />
			) }
			{ showFullWidth && (
				<ToggleControl label={ __( 'Full Width', 'neural-forms-lite' ) } checked={ !!a[ fullWidthKey ] } onChange={ v => set( { [ fullWidthKey ]: v } ) } />
			) }
			<Sep />

			{ /* ── Normal / Hover state switcher ── */ }
			<ToggleGroupControl value={ btnTab } onChange={ v => setBtnTab( v || 'normal' ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="normal" label={ __( 'Normal', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="hover"  label={ __( 'Hover',  'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Gap />

			{ /* ── Normal state ── */ }
			{ btnTab === 'normal' && (
				<>
					<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
						<TabPanel initialTabName={ a[ pfx + 'BgGradient' ] ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
							{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a[ pfx + 'BgColor' ] } onChange={ v => set( { [ pfx + 'BgColor' ]: v ?? '' } ) } /> : <Grad label="" value={ a[ pfx + 'BgGradient' ] } onChange={ v => set( { [ pfx + 'BgGradient' ]: v ?? '' } ) } /> }
						</TabPanel>
					</BaseControl>
					<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a[ pfx + 'TextColor' ] } onChange={ v => set( { [ pfx + 'TextColor' ]: v ?? '' } ) } />
					<Sep />
					<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a[ pfx + 'Border' ] || {} } onChange={ v => set( { [ pfx + 'Border' ]: v ?? {} } ) } enableAlpha />
					<Gap />
					<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a[ pfx + 'RadiusTL' ] || undefined, right: a[ pfx + 'RadiusTR' ] || undefined, bottom: a[ pfx + 'RadiusBR' ] || undefined, left: a[ pfx + 'RadiusBL' ] || undefined } } onChange={ v => set( { [ pfx + 'RadiusTL' ]: v.top ?? '', [ pfx + 'RadiusTR' ]: v.right ?? '', [ pfx + 'RadiusBR' ]: v.bottom ?? '', [ pfx + 'RadiusBL' ]: v.left ?? '' } ) } />
					<Sep />
					<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a[ pfx + 'FontFamily' ] } onChange={ v => set( { [ pfx + 'FontFamily' ]: v } ) } />
					<TypographyPanel pfx={ pfx } a={ a } set={ set } />
					<Sep />
					{ showIcons && (
						<>
							<BtnIconSection
								enabledKey={ pfx + 'IconEnabled' }
								iconKey={ pfx + 'Icon' }
								positionKey={ pfx + 'IconPosition' }
								hoverKey={ pfx + 'IconShowOnHover' }
								defaultPos="after"
								a={ a }
								set={ set }
							/>
							<Sep />
						</>
					) }
					{ iconsContent && (
						<>
							{ iconsContent }
							<Sep />
						</>
					) }
					<PaddingDimensions topKey={ pfx + 'PaddingTop' } rightKey={ pfx + 'PaddingRight' } bottomKey={ pfx + 'PaddingBottom' } leftKey={ pfx + 'PaddingLeft' } a={ a } set={ set } />
					<Sep />
					<UnitControl label={ __( 'Transition Duration', 'neural-forms-lite' ) } value={ a[ pfx + 'TransitionDuration' ] } onChange={ v => set( { [ pfx + 'TransitionDuration' ]: v ?? '' } ) } units={ [ { value: 's', label: 's', default: 0, step: 0.1 }, { value: 'ms', label: 'ms', default: 0, step: 10 } ] } />
					<SelectControl label={ __( 'Transition Easing', 'neural-forms-lite' ) } value={ a[ pfx + 'TransitionEasing' ] || '' } options={ EASING_OPTIONS } onChange={ v => set( { [ pfx + 'TransitionEasing' ]: v } ) } />
				</>
			) }

			{ /* ── Hover state ── */ }
			{ btnTab === 'hover' && (
				<>
					<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
						<TabPanel initialTabName={ a[ pfx + 'HoverBgGradient' ] ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
							{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a[ pfx + 'HoverBg' ] } onChange={ v => set( { [ pfx + 'HoverBg' ]: v ?? '' } ) } /> : <Grad label="" value={ a[ pfx + 'HoverBgGradient' ] } onChange={ v => set( { [ pfx + 'HoverBgGradient' ]: v ?? '' } ) } /> }
						</TabPanel>
					</BaseControl>
					<Clr label={ __( 'Hover Text Color', 'neural-forms-lite' ) } value={ a[ pfx + 'HoverColor' ] } onChange={ v => set( { [ pfx + 'HoverColor' ]: v ?? '' } ) } />
					<Sep />
					<Clr label={ __( 'Hover Border Color', 'neural-forms-lite' ) } value={ a[ pfx + 'HoverBorderColor' ] } onChange={ v => set( { [ pfx + 'HoverBorderColor' ]: v ?? '' } ) } />
				</>
			) }
		</>
	);
}

export function SubmitButtonPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Submit Button', 'neural-forms-lite' ) } initialOpen={ false }>
			<BtnControls pfx="btn" a={ a } set={ set } showIcons={ true } />
		</PanelBody>
	);
}

export function NavButtonsPanel( { a, set } ) {
	const navIconsContent = (
		<>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Previous Button Icon', 'neural-forms-lite' ) }</p>
			<BtnIconSection
				enabledKey="navBtnPrevIconEnabled"
				iconKey="navBtnPrevIcon"
				positionKey="navBtnPrevIconPosition"
				hoverKey="navBtnPrevIconShowOnHover"
				defaultPos="before"
				a={ a }
				set={ set }
			/>
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Next Button Icon', 'neural-forms-lite' ) }</p>
			<BtnIconSection
				enabledKey="navBtnNextIconEnabled"
				iconKey="navBtnNextIcon"
				positionKey="navBtnNextIconPosition"
				hoverKey="navBtnNextIconShowOnHover"
				defaultPos="after"
				a={ a }
				set={ set }
			/>
		</>
	);
	return (
		<PanelBody title={ __( 'Prev & Next Buttons', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles apply to both Previous and Next buttons.', 'neural-forms-lite' ) }</p>
			<BtnControls pfx="navBtn" a={ a } set={ set } showAlign={ false } iconsContent={ navIconsContent } />
		</PanelBody>
	);
}

export function MessagesPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Messages', 'neural-forms-lite' ) } initialOpen={ false }>
			{ a.successMsgEnabled !== false && (
				<>
					<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Success Message', 'neural-forms-lite' ) }</p>
					<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
					<TabPanel initialTabName={ a.successMsgBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
						{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.successMsgBg } onChange={ v => set( { successMsgBg: v ?? '' } ) } /> : <Grad label="" value={ a.successMsgBgGradient } onChange={ v => set( { successMsgBgGradient: v ?? '' } ) } /> }
					</TabPanel>
				</BaseControl>
					<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.successMsgColor } onChange={ v => set( { successMsgColor: v ?? '' } ) } />
					<Gap />
					<PaddingDimensions topKey="successMsgPaddingTop" rightKey="successMsgPaddingRight" bottomKey="successMsgPaddingBottom" leftKey="successMsgPaddingLeft" a={ a } set={ set } />
					<Sep />
				</>
			) }
			{ a.errorMsgEnabled !== false && (
				<>
					<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Error Message', 'neural-forms-lite' ) }</p>
					<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
					<TabPanel initialTabName={ a.errorMsgBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
						{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.errorMsgBg } onChange={ v => set( { errorMsgBg: v ?? '' } ) } /> : <Grad label="" value={ a.errorMsgBgGradient } onChange={ v => set( { errorMsgBgGradient: v ?? '' } ) } /> }
					</TabPanel>
				</BaseControl>
					<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.errorMsgColor } onChange={ v => set( { errorMsgColor: v ?? '' } ) } />
					<Gap />
					<PaddingDimensions topKey="errorMsgPaddingTop" rightKey="errorMsgPaddingRight" bottomKey="errorMsgPaddingBottom" leftKey="errorMsgPaddingLeft" a={ a } set={ set } />
					<Sep />
				</>
			) }
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Field Error Message', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Inline message shown below each invalid field.', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.fieldErrorMsgColor } onChange={ v => set( { fieldErrorMsgColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.fieldErrorMsgFontSize } onChange={ v => set( { fieldErrorMsgFontSize: v ?? '' } ) } />
		</PanelBody>
	);
}

// ─── Repeater field panel (Pro-gated) ────────────────────────────────────────

export function RepeaterFieldPanel( { a, set } ) {
	if ( ! window.neuralFormsBlockCaps?.fieldRepeater ) return null;
	return (
		<PanelBody title={ __( 'Repeater Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles apply to repeater field rows and action buttons.', 'neural-forms-lite' ) }</p>

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Container', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.repeaterContainerBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.repeaterContainerBg } onChange={ v => set( { repeaterContainerBg: v ?? '' } ) } /> : <Grad label="" value={ a.repeaterContainerBgGradient } onChange={ v => set( { repeaterContainerBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.repeaterContainerBorder || {} } onChange={ v => set( { repeaterContainerBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.repeaterContainerBorderRadius } onChange={ v => set( { repeaterContainerBorderRadius: v ?? '' } ) } />
			<Gap />
			<PaddingDimensions topKey="repeaterContainerPaddingTop" rightKey="repeaterContainerPaddingRight" bottomKey="repeaterContainerPaddingBottom" leftKey="repeaterContainerPaddingLeft" a={ a } set={ set } />
			<Gap />
			<UnitControl label={ __( 'List to Add Button Gap', 'neural-forms-lite' ) } value={ a.repeaterListAddGap } onChange={ v => set( { repeaterListAddGap: v ?? '' } ) } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Row Style', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.repeaterRowBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.repeaterRowBg } onChange={ v => set( { repeaterRowBg: v ?? '' } ) } /> : <Grad label="" value={ a.repeaterRowBgGradient } onChange={ v => set( { repeaterRowBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.repeaterRowHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.repeaterRowHoverBg } onChange={ v => set( { repeaterRowHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.repeaterRowHoverBgGradient } onChange={ v => set( { repeaterRowHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.repeaterRowBorder || {} } onChange={ v => set( { repeaterRowBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.repeaterRowBorderRadius } onChange={ v => set( { repeaterRowBorderRadius: v ?? '' } ) } />
			<Gap />
			<PaddingDimensions topKey="repeaterRowPaddingTop" rightKey="repeaterRowPaddingRight" bottomKey="repeaterRowPaddingBottom" leftKey="repeaterRowPaddingLeft" a={ a } set={ set } />
			<Gap />
			<UnitControl label={ __( 'Gap Between Rows', 'neural-forms-lite' ) } value={ a.repeaterRowGap } onChange={ v => set( { repeaterRowGap: v ?? '' } ) } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Add Row Button', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.repeaterAddBtnBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.repeaterAddBtnBg } onChange={ v => set( { repeaterAddBtnBg: v ?? '' } ) } /> : <Grad label="" value={ a.repeaterAddBtnBgGradient } onChange={ v => set( { repeaterAddBtnBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.repeaterAddBtnHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.repeaterAddBtnHoverBg } onChange={ v => set( { repeaterAddBtnHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.repeaterAddBtnHoverBgGradient } onChange={ v => set( { repeaterAddBtnHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.repeaterAddBtnColor } onChange={ v => set( { repeaterAddBtnColor: v ?? '' } ) } />
			<Clr label={ __( 'Hover Text Color', 'neural-forms-lite' ) } value={ a.repeaterAddBtnHoverColor } onChange={ v => set( { repeaterAddBtnHoverColor: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.repeaterAddBtnFontSize } onChange={ v => set( { repeaterAddBtnFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.repeaterAddBtnFontWeight || '' } options={ FW } onChange={ v => set( { repeaterAddBtnFontWeight: v } ) } />
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.repeaterAddBtnBorder || {} } onChange={ v => set( { repeaterAddBtnBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.repeaterAddBtnBorderRadius } onChange={ v => set( { repeaterAddBtnBorderRadius: v ?? '' } ) } />
			<Gap />
			<PaddingDimensions topKey="repeaterAddBtnPaddingTop" rightKey="repeaterAddBtnPaddingRight" bottomKey="repeaterAddBtnPaddingBottom" leftKey="repeaterAddBtnPaddingLeft" a={ a } set={ set } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Remove Button', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.repeaterRemoveBtnColor } onChange={ v => set( { repeaterRemoveBtnColor: v ?? '' } ) } />
			<Clr label={ __( 'Text Hover Color', 'neural-forms-lite' ) } value={ a.repeaterRemoveBtnHoverColor } onChange={ v => set( { repeaterRemoveBtnHoverColor: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.repeaterRemoveBtnSize } onChange={ v => set( { repeaterRemoveBtnSize: v ?? '' } ) } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Row Number', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.repeaterItemNumColor } onChange={ v => set( { repeaterItemNumColor: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.repeaterItemNumFontSize } onChange={ v => set( { repeaterItemNumFontSize: v ?? '' } ) } />
		</PanelBody>
	);
}

// ─── Matrix field panel (Pro-gated) ──────────────────────────────────────────

export function MatrixFieldPanel( { a, set } ) {
	if ( ! window.neuralFormsBlockCaps?.fieldMatrix ) return null;
	return (
		<PanelBody title={ __( 'Matrix Field', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Styles apply to the matrix/grid table, headers, and cells.', 'neural-forms-lite' ) }</p>

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Table', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Border Color', 'neural-forms-lite' ) } value={ a.matrixBorderColor } onChange={ v => set( { matrixBorderColor: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Border Width', 'neural-forms-lite' ) } value={ a.matrixBorderWidth } onChange={ v => set( { matrixBorderWidth: v ?? '' } ) } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Column Headers', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixColHeaderBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixColHeaderBg } onChange={ v => set( { matrixColHeaderBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixColHeaderBgGradient } onChange={ v => set( { matrixColHeaderBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.matrixColHeaderColor } onChange={ v => set( { matrixColHeaderColor: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.matrixColHeaderFontSize } onChange={ v => set( { matrixColHeaderFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.matrixColHeaderFontWeight || '' } options={ FW } onChange={ v => set( { matrixColHeaderFontWeight: v } ) } />
			<Gap />
			<PaddingDimensions topKey="matrixColHeaderPaddingTop" rightKey="matrixColHeaderPaddingRight" bottomKey="matrixColHeaderPaddingBottom" leftKey="matrixColHeaderPaddingLeft" a={ a } set={ set } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Row Headers', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixRowHeaderBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixRowHeaderBg } onChange={ v => set( { matrixRowHeaderBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixRowHeaderBgGradient } onChange={ v => set( { matrixRowHeaderBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.matrixRowHeaderColor } onChange={ v => set( { matrixRowHeaderColor: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.matrixRowHeaderFontSize } onChange={ v => set( { matrixRowHeaderFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.matrixRowHeaderFontWeight || '' } options={ FW } onChange={ v => set( { matrixRowHeaderFontWeight: v } ) } />

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Cells', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixCellBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixCellBg } onChange={ v => set( { matrixCellBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixCellBgGradient } onChange={ v => set( { matrixCellBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixCellHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixCellHoverBg } onChange={ v => set( { matrixCellHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixCellHoverBgGradient } onChange={ v => set( { matrixCellHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Selected Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixCellCheckedBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixCellCheckedBg } onChange={ v => set( { matrixCellCheckedBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixCellCheckedBgGradient } onChange={ v => set( { matrixCellCheckedBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.matrixCellBorder || {} } onChange={ v => set( { matrixCellBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<PaddingDimensions topKey="matrixCellPaddingTop" rightKey="matrixCellPaddingRight" bottomKey="matrixCellPaddingBottom" leftKey="matrixCellPaddingLeft" a={ a } set={ set } />
			<Gap />
			<Clr label={ __( 'Radio Accent Color', 'neural-forms-lite' ) } value={ a.matrixAccentColor } onChange={ v => set( { matrixAccentColor: v ?? '' } ) } />
			<Gap />
			<BaseControl label={ __( 'Row Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixRowBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixRowBg } onChange={ v => set( { matrixRowBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixRowBgGradient } onChange={ v => set( { matrixRowBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Gap />
			<BaseControl label={ __( 'Alternating Row Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.matrixAltRowBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.matrixAltRowBg } onChange={ v => set( { matrixAltRowBg: v ?? '' } ) } /> : <Grad label="" value={ a.matrixAltRowBgGradient } onChange={ v => set( { matrixAltRowBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
		</PanelBody>
	);
}

// ─── Advanced panel (identical across all blocks) ─────────────────────────────

export function WelcomeScreenPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Welcome Screen', 'neural-forms-lite' ) } initialOpen={ false }>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.welcomeBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.welcomeBg } onChange={ v => set( { welcomeBg: v ?? '' } ) } /> : <Grad label="" value={ a.welcomeBgGradient } onChange={ v => set( { welcomeBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<Gap />
			<MediaUploadCheck>
				<MediaUpload
					onSelect={ media => set( { welcomeBgImage: media.url } ) }
					allowedTypes={ [ 'image' ] }
					accept="image/*"
					value={ a.welcomeBgImage }
					render={ ( { open } ) => (
						<BaseControl label={ __( 'Background Image', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
							{ a.welcomeBgImage && (
								<img src={ a.welcomeBgImage } alt="" style={ { display: 'block', width: '100%', height: 80, objectFit: 'cover', borderRadius: 4, marginBottom: 6 } } />
							) }
							<Button isPrimary onClick={ e => { e.stopPropagation(); open(); } } __next40pxDefaultSize>
								{ a.welcomeBgImage ? __( 'Edit Image', 'neural-forms-lite' ) : __( 'Select Image', 'neural-forms-lite' ) }
							</Button>
							{ a.welcomeBgImage && (
								<Button isLink isDestructive onClick={ () => set( { welcomeBgImage: '' } ) } style={ { marginLeft: '10px', verticalAlign: 'super' } } __next40pxDefaultSize>
									{ __( 'Remove Image', 'neural-forms-lite' ) }
								</Button>
							) }
						</BaseControl>
					) }
				/>
			</MediaUploadCheck>
			{ a.welcomeBgImage && (
				<>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Size', 'neural-forms-lite' ) }
						value={ a.welcomeBgImageSize || 'cover' }
						options={ [
							{ value: 'cover',   label: __( 'Cover',           'neural-forms-lite' ) },
							{ value: 'contain', label: __( 'Fit',             'neural-forms-lite' ) },
							{ value: 'initial', label: __( 'Actual Size',     'neural-forms-lite' ) },
							{ value: 'stretch', label: __( 'Stretch to Fill', 'neural-forms-lite' ) },
						] }
						onChange={ v => set( { welcomeBgImageSize: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Position', 'neural-forms-lite' ) }
						value={ a.welcomeBgImagePosition || 'center' }
						options={ [
							{ value: 'top_left',      label: __( 'Top Left',      'neural-forms-lite' ) },
							{ value: 'top_center',    label: __( 'Top Center',    'neural-forms-lite' ) },
							{ value: 'top_right',     label: __( 'Top Right',     'neural-forms-lite' ) },
							{ value: 'center_left',   label: __( 'Center Left',   'neural-forms-lite' ) },
							{ value: 'center',        label: __( 'Center',        'neural-forms-lite' ) },
							{ value: 'center_right',  label: __( 'Center Right',  'neural-forms-lite' ) },
							{ value: 'bottom_left',   label: __( 'Bottom Left',   'neural-forms-lite' ) },
							{ value: 'bottom_center', label: __( 'Bottom Center', 'neural-forms-lite' ) },
							{ value: 'bottom_right',  label: __( 'Bottom Right',  'neural-forms-lite' ) },
						] }
						onChange={ v => set( { welcomeBgImagePosition: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Repeat', 'neural-forms-lite' ) }
						value={ a.welcomeBgImageRepeat || 'no-repeat' }
						options={ [
							{ value: 'repeat',    label: __( 'Repeat',                    'neural-forms-lite' ) },
							{ value: 'repeat-x',  label: __( 'Repeat X (horizontal)',     'neural-forms-lite' ) },
							{ value: 'repeat-y',  label: __( 'Repeat Y (vertical)',       'neural-forms-lite' ) },
							{ value: 'space',     label: __( 'Repeat with space between', 'neural-forms-lite' ) },
							{ value: 'round',     label: __( 'Repeat and Stretch',        'neural-forms-lite' ) },
							{ value: 'no-repeat', label: __( 'No Repeat',                'neural-forms-lite' ) },
						] }
						onChange={ v => set( { welcomeBgImageRepeat: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Blend', 'neural-forms-lite' ) }
						value={ a.welcomeBgImageBlend || 'normal' }
						options={ [
							{ value: 'normal',   label: __( 'Normal',   'neural-forms-lite' ) },
							{ value: 'multiply', label: __( 'Multiply', 'neural-forms-lite' ) },
							{ value: 'screen',   label: __( 'Screen',   'neural-forms-lite' ) },
							{ value: 'overlay',  label: __( 'Overlay',  'neural-forms-lite' ) },
							{ value: 'darken',   label: __( 'Darken',   'neural-forms-lite' ) },
							{ value: 'lighten',  label: __( 'Lighten',  'neural-forms-lite' ) },
						] }
						onChange={ v => set( { welcomeBgImageBlend: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
				</>
			) }
			<Gap />
			<ToggleControl
				label={ __( 'Full Height', 'neural-forms-lite' ) }
				help={ __( 'Stretch the welcome screen to fill the viewport height.', 'neural-forms-lite' ) }
				checked={ !! a.welcomeFullHeight }
				onChange={ v => set( { welcomeFullHeight: !! v } ) }
			/>
			<Sep />

			<BorderBox label={ __( 'Border', 'neural-forms-lite' ) } value={ a.welcomeBorder || {} } onChange={ v => set( { welcomeBorder: v ?? {} } ) } enableAlpha />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.welcomeBorderRadiusTL || undefined, right: a.welcomeBorderRadiusTR || undefined, bottom: a.welcomeBorderRadiusBR || undefined, left: a.welcomeBorderRadiusBL || undefined } } onChange={ v => set( { welcomeBorderRadiusTL: v.top ?? '', welcomeBorderRadiusTR: v.right ?? '', welcomeBorderRadiusBR: v.bottom ?? '', welcomeBorderRadiusBL: v.left ?? '' } ) } />
			<Sep />

			<PaddingMarginDimensions
				pTop="welcomePaddingTop" pRight="welcomePaddingRight" pBottom="welcomePaddingBottom" pLeft="welcomePaddingLeft"
				mTop="welcomeMarginTop" mRight="welcomeMarginRight" mBottom="welcomeMarginBottom" mLeft="welcomeMarginLeft"
				a={ a } set={ set }
			/>
			<Sep />

			<AlignControl label={ __( 'Content Alignment', 'neural-forms-lite' ) } value={ a.welcomeAlign || 'left' } onChange={ v => set( { welcomeAlign: v ?? 'left' } ) } justify={ false } />
			<Sep />

			<BaseControl label={ __( 'Box Shadow', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<Clr label={ __( 'Shadow Color', 'neural-forms-lite' ) } value={ a.welcomeShadowColor } onChange={ v => set( { welcomeShadowColor: v ?? '' } ) } />
				<Gap />
				<ToggleGroupControl label={ __( 'Position', 'neural-forms-lite' ) } value={ a.welcomeShadowPosition || 'outer' } onChange={ v => set( { welcomeShadowPosition: v } ) } isBlock __nextHasNoMarginBottom>
					<ToggleGroupControlOption value="outer" label={ __( 'Outer', 'neural-forms-lite' ) } />
					<ToggleGroupControlOption value="inner" label={ __( 'Inner', 'neural-forms-lite' ) } />
				</ToggleGroupControl>
				<Gap />
				<UnitControl label={ __( 'Horizontal', 'neural-forms-lite' ) } value={ a.welcomeShadowH } onChange={ v => set( { welcomeShadowH: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Vertical', 'neural-forms-lite' ) } value={ a.welcomeShadowV } onChange={ v => set( { welcomeShadowV: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Blur', 'neural-forms-lite' ) } value={ a.welcomeShadowBlur } onChange={ v => set( { welcomeShadowBlur: v ?? '' } ) } />
				<Gap />
				<UnitControl label={ __( 'Spread', 'neural-forms-lite' ) } value={ a.welcomeShadowSpread } onChange={ v => set( { welcomeShadowSpread: v ?? '' } ) } />
			</BaseControl>
			<Sep />

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Title', 'neural-forms-lite' ) }</p>
			<SelectControl label={ __( 'Heading Level', 'neural-forms-lite' ) } value={ a.welcomeTitleLevel || 'h2' } options={ [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( h => ( { label: h.toUpperCase(), value: h } ) ) } onChange={ v => set( { welcomeTitleLevel: v } ) } />
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.welcomeTitleColor } onChange={ v => set( { welcomeTitleColor: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.welcomeTitleFontFamily } onChange={ v => set( { welcomeTitleFontFamily: v } ) } />
			<TypographyPanel pfx="welcomeTitle" a={ a } set={ set } />
			<Sep />

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Description', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.welcomeDescColor } onChange={ v => set( { welcomeDescColor: v ?? '' } ) } />
			<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.welcomeDescFontFamily } onChange={ v => set( { welcomeDescFontFamily: v } ) } />
			<TypographyPanel pfx="welcomeDesc" a={ a } set={ set } />
			<Gap />
			<UnitControl label={ __( 'Margin Top', 'neural-forms-lite' ) } value={ a.welcomeDescMarginTop } onChange={ v => set( { welcomeDescMarginTop: v ?? '' } ) } />
			<Sep />

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Button', 'neural-forms-lite' ) }</p>
			<BtnControls pfx="welcomeBtn" a={ a } set={ set } showAlign={ false } showFullWidth={ false } showIcons={ true } />
			<Gap />
			<UnitControl label={ __( 'Margin Top', 'neural-forms-lite' ) } value={ a.welcomeBtnMarginTop } onChange={ v => set( { welcomeBtnMarginTop: v ?? '' } ) } />
		</PanelBody>
	);
}

export function AdvancedPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Advanced', 'neural-forms-lite' ) } initialOpen={ false }>
			<TextControl label={ __( 'Extra CSS Class', 'neural-forms-lite' ) } value={ a.extraClass || '' } onChange={ v => set( { extraClass: v } ) } help={ __( 'Space-separated class names.', 'neural-forms-lite' ) } />
		</PanelBody>
	);
}
