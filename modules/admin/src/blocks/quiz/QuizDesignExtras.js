import { __ } from '@wordpress/i18n';
import {
	PanelBody,
	Button,
	BaseControl,
	TabPanel,
	SelectControl,
	BoxControl, BorderControl,
	__experimentalUnitControl as UnitControl,
	__experimentalToggleGroupControl  as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import {
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	ContainerPanel,
	TitlePanel,
	LabelsPanel,
	InputFieldsPanel,
	TextareaFieldPanel,
	AcceptanceFieldPanel,
	SubmitButtonPanel,
	WelcomeScreenPanel,
	MessagesPanel,
	AdvancedPanel,
	Sep, Gap, Clr, Grad,
	FW,
} from '../shared/DesignPanels';

// ─── Quiz-specific panels ─────────────────────────────────────────────────────

function QuizOptionsPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Quiz Options', 'neural-forms-lite' ) } initialOpen={ false }>
			<ToggleGroupControl label={ __( 'Layout Direction', 'neural-forms-lite' ) } value={ a.quizOptionsDirection || 'vertical' } onChange={ v => set( { quizOptionsDirection: v } ) } isBlock __nextHasNoMarginBottom>
				<ToggleGroupControlOption value="vertical"   label={ __( 'Vertical', 'neural-forms-lite' ) } />
				<ToggleGroupControlOption value="horizontal" label={ __( 'Horizontal', 'neural-forms-lite' ) } />
			</ToggleGroupControl>
			<Gap />
			<UnitControl label={ __( 'Gap Between Options', 'neural-forms-lite' ) } value={ a.quizOptionsGap } onChange={ v => set( { quizOptionsGap: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Option Item Style', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.quizOptionBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.quizOptionBg } onChange={ v => set( { quizOptionBg: v ?? '' } ) } /> : <Grad label="" value={ a.quizOptionBgGradient } onChange={ v => set( { quizOptionBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.quizOptionHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.quizOptionHoverBg } onChange={ v => set( { quizOptionHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.quizOptionHoverBgGradient } onChange={ v => set( { quizOptionHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Selected Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.quizOptionCheckedBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.quizOptionCheckedBg } onChange={ v => set( { quizOptionCheckedBg: v ?? '' } ) } /> : <Grad label="" value={ a.quizOptionCheckedBgGradient } onChange={ v => set( { quizOptionCheckedBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.quizOptionBorderColor, width: a.quizOptionBorderWidth, style: a.quizOptionBorderStyle } } onChange={ v => set( { quizOptionBorderColor: v?.color ?? '', quizOptionBorderWidth: v?.width ?? '', quizOptionBorderStyle: v?.style ?? '' } ) } withSlider />
			<Gap />
			<UnitControl label={ __( 'Border Radius', 'neural-forms-lite' ) } value={ a.quizOptionBorderRadius } onChange={ v => set( { quizOptionBorderRadius: v ?? '' } ) } />
			<Gap />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.quizOptionPaddingTop || undefined, right: a.quizOptionPaddingRight || undefined, bottom: a.quizOptionPaddingBottom || undefined, left: a.quizOptionPaddingLeft || undefined } } onChange={ v => set( { quizOptionPaddingTop: v.top ?? '', quizOptionPaddingRight: v.right ?? '', quizOptionPaddingBottom: v.bottom ?? '', quizOptionPaddingLeft: v.left ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Option Label', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Accent Color (radio dot)', 'neural-forms-lite' ) } value={ a.quizAccentColor } onChange={ v => set( { quizAccentColor: v ?? '' } ) } />
			<Clr label={ __( 'Label Color', 'neural-forms-lite' ) } value={ a.quizOptionColor } onChange={ v => set( { quizOptionColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.quizOptionFontSize } onChange={ v => set( { quizOptionFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.quizOptionFontWeight || '' } options={ FW } onChange={ v => set( { quizOptionFontWeight: v } ) } />
		</PanelBody>
	);
}

function ScoreSummaryPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Score Summary', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 12px' } }>{ __( 'Shown after quiz submission with score and percentage.', 'neural-forms-lite' ) }</p>

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Score Card', 'neural-forms-lite' ) }</p>
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel
					initialTabName={ a.quizSummaryGradient ? 'gradient' : 'color' }
					tabs={ [
						{ name: 'color',    title: __( 'Color',    'neural-forms-lite' ) },
						{ name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) },
					] }
				>
					{ ( tab ) => tab.name === 'color'
						? <Clr label="" value={ a.quizSummaryBg } onChange={ v => set( { quizSummaryBg: v ?? '' } ) } />
						: <Grad label="" value={ a.quizSummaryGradient } onChange={ v => set( { quizSummaryGradient: v ?? '' } ) } />
					}
				</TabPanel>
			</BaseControl>
			<Gap />
			<MediaUploadCheck>
				<MediaUpload
					onSelect={ media => set( { quizSummaryBgImage: media.url } ) }
					allowedTypes={ [ 'image' ] }
					accept="image/*"
					value={ a.quizSummaryBgImage }
					render={ ( { open } ) => (
						<BaseControl label={ __( 'Background Image', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
							{ a.quizSummaryBgImage && (
								<img src={ a.quizSummaryBgImage } alt="" style={ { display: 'block', width: '100%', height: 80, objectFit: 'cover', borderRadius: 4, marginBottom: 6 } } />
							) }
							<Button isPrimary onClick={ e => { e.stopPropagation(); open(); } } __next40pxDefaultSize>
								{ a.quizSummaryBgImage ? __( 'Edit Image', 'neural-forms-lite' ) : __( 'Select Image', 'neural-forms-lite' ) }
							</Button>
							{ a.quizSummaryBgImage && (
								<Button isLink isDestructive onClick={ () => set( { quizSummaryBgImage: '' } ) } style={ { marginLeft: '10px', verticalAlign: 'super' } } __next40pxDefaultSize>
									{ __( 'Remove Image', 'neural-forms-lite' ) }
								</Button>
							) }
						</BaseControl>
					) }
				/>
			</MediaUploadCheck>
			{ a.quizSummaryBgImage && (
				<>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Size', 'neural-forms-lite' ) }
						value={ a.quizSummaryBgSize || 'cover' }
						options={ [
							{ value: 'cover',   label: __( 'Cover',            'neural-forms-lite' ) },
							{ value: 'contain', label: __( 'Fit',              'neural-forms-lite' ) },
							{ value: 'initial', label: __( 'Actual Size',      'neural-forms-lite' ) },
							{ value: 'stretch', label: __( 'Stretch to Fill',  'neural-forms-lite' ) },
						] }
						onChange={ v => set( { quizSummaryBgSize: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Position', 'neural-forms-lite' ) }
						value={ a.quizSummaryBgPosition || 'center' }
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
						onChange={ v => set( { quizSummaryBgPosition: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Repeat', 'neural-forms-lite' ) }
						value={ a.quizSummaryBgRepeat || 'no-repeat' }
						options={ [
							{ value: 'repeat',    label: __( 'Repeat',                    'neural-forms-lite' ) },
							{ value: 'repeat-x',  label: __( 'Repeat X (horizontal)',     'neural-forms-lite' ) },
							{ value: 'repeat-y',  label: __( 'Repeat Y (vertical)',       'neural-forms-lite' ) },
							{ value: 'space',     label: __( 'Repeat with space between', 'neural-forms-lite' ) },
							{ value: 'round',     label: __( 'Repeat and Stretch',        'neural-forms-lite' ) },
							{ value: 'no-repeat', label: __( 'No Repeat',                'neural-forms-lite' ) },
						] }
						onChange={ v => set( { quizSummaryBgRepeat: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
					<Gap />
					<SelectControl
						label={ __( 'Background Image Blend', 'neural-forms-lite' ) }
						value={ a.quizSummaryBgBlend || 'normal' }
						options={ [
							{ value: 'normal',   label: __( 'Normal',   'neural-forms-lite' ) },
							{ value: 'multiply', label: __( 'Multiply', 'neural-forms-lite' ) },
							{ value: 'screen',   label: __( 'Screen',   'neural-forms-lite' ) },
							{ value: 'overlay',  label: __( 'Overlay',  'neural-forms-lite' ) },
							{ value: 'darken',   label: __( 'Darken',   'neural-forms-lite' ) },
							{ value: 'lighten',  label: __( 'Lighten',  'neural-forms-lite' ) },
						] }
						onChange={ v => set( { quizSummaryBgBlend: v } ) }
						__next40pxDefaultSize __nextHasNoMarginBottom
					/>
				</>
			) }
			<Sep />
			<Clr label={ __( 'Text Color', 'neural-forms-lite' ) } value={ a.quizSummaryColor } onChange={ v => set( { quizSummaryColor: v ?? '' } ) } />
			<Sep />
			<BorderControl label={ __( 'Border', 'neural-forms-lite' ) } value={ { color: a.quizSummaryBorderColor, width: a.quizSummaryBorderWidth, style: 'solid' } } onChange={ v => set( { quizSummaryBorderColor: v?.color ?? '', quizSummaryBorderWidth: v?.width ?? '' } ) } withSlider />
			<Gap />
			<BoxControl label={ __( 'Border Radius', 'neural-forms-lite' ) } values={ { top: a.quizSummaryBorderRadiusTL || undefined, right: a.quizSummaryBorderRadiusTR || undefined, bottom: a.quizSummaryBorderRadiusBR || undefined, left: a.quizSummaryBorderRadiusBL || undefined } } onChange={ v => set( { quizSummaryBorderRadiusTL: v.top ?? '', quizSummaryBorderRadiusTR: v.right ?? '', quizSummaryBorderRadiusBR: v.bottom ?? '', quizSummaryBorderRadiusBL: v.left ?? '' } ) } />
			<Sep />
			<BoxControl label={ __( 'Padding', 'neural-forms-lite' ) } values={ { top: a.quizSummaryPaddingTop || undefined, right: a.quizSummaryPaddingRight || undefined, bottom: a.quizSummaryPaddingBottom || undefined, left: a.quizSummaryPaddingLeft || undefined } } onChange={ v => set( { quizSummaryPaddingTop: v.top ?? '', quizSummaryPaddingRight: v.right ?? '', quizSummaryPaddingBottom: v.bottom ?? '', quizSummaryPaddingLeft: v.left ?? '' } ) } />
			<Sep />

			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Score Line', 'neural-forms-lite' ) }</p>
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.quizScoreFontSize } onChange={ v => set( { quizScoreFontSize: v ?? '' } ) } />

		</PanelBody>
	);
}

export default function QuizDesignExtras( { attributes, setAttributes, formHasWelcomeScreen = false } ) {
	const a = attributes, set = setAttributes;
	return (
		<>
			<ContainerPanel       a={ a } set={ set } />
			{ ( a.formTitle || '' ).trim() !== '' && <TitlePanel a={ a } set={ set } /> }
			<LabelsPanel          a={ a } set={ set } />
			<InputFieldsPanel     a={ a } set={ set } />
			<TextareaFieldPanel   a={ a } set={ set } />
			<AcceptanceFieldPanel a={ a } set={ set } />
			<QuizOptionsPanel     a={ a } set={ set } />
			{ a.showQuizResults !== false && <ScoreSummaryPanel a={ a } set={ set } /> }
			<SubmitButtonPanel    a={ a } set={ set } />
			{ formHasWelcomeScreen && a.showWelcomeScreen !== false && <WelcomeScreenPanel a={ a } set={ set } /> }
			<MessagesPanel        a={ a } set={ set } />
			<AdvancedPanel        a={ a } set={ set } />
		</>
	);
}
