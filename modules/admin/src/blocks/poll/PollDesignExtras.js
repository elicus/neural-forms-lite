import { __ } from '@wordpress/i18n';
import {
	PanelBody,
	BaseControl,
	TabPanel,
	SelectControl,
	__experimentalUnitControl as UnitControl,
	__experimentalToggleGroupControl  as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	BoxControl, BorderControl,
} from '@wordpress/components';
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

// ─── Poll-specific panels ─────────────────────────────────────────────────────

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
			<BaseControl label={ __( 'Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.pollOptionBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.pollOptionBg } onChange={ v => set( { pollOptionBg: v ?? '' } ) } /> : <Grad label="" value={ a.pollOptionBgGradient } onChange={ v => set( { pollOptionBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Hover Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.pollOptionHoverBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.pollOptionHoverBg } onChange={ v => set( { pollOptionHoverBg: v ?? '' } ) } /> : <Grad label="" value={ a.pollOptionHoverBgGradient } onChange={ v => set( { pollOptionHoverBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
			<BaseControl label={ __( 'Selected Background', 'neural-forms-lite' ) } __nextHasNoMarginBottom>
				<TabPanel initialTabName={ a.pollOptionCheckedBgGradient ? 'gradient' : 'color' } tabs={ [ { name: 'color', title: __( 'Color', 'neural-forms-lite' ) }, { name: 'gradient', title: __( 'Gradient', 'neural-forms-lite' ) } ] }>
					{ ( tab ) => tab.name === 'color' ? <Clr label="" value={ a.pollOptionCheckedBg } onChange={ v => set( { pollOptionCheckedBg: v ?? '' } ) } /> : <Grad label="" value={ a.pollOptionCheckedBgGradient } onChange={ v => set( { pollOptionCheckedBgGradient: v ?? '' } ) } /> }
				</TabPanel>
			</BaseControl>
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

export default function PollDesignExtras( { attributes, setAttributes, formHasWelcomeScreen = false } ) {
	const a = attributes, set = setAttributes;
	return (
		<>
			<ContainerPanel       a={ a } set={ set } />
			{ ( a.formTitle || '' ).trim() !== '' && <TitlePanel a={ a } set={ set } /> }
			<LabelsPanel          a={ a } set={ set } />
			<InputFieldsPanel     a={ a } set={ set } />
			<TextareaFieldPanel   a={ a } set={ set } />
			<AcceptanceFieldPanel a={ a } set={ set } />
			<PollOptionsPanel     a={ a } set={ set } />
			{ a.showPollResults !== false && <ResultsBarPanel a={ a } set={ set } /> }
			<SubmitButtonPanel    a={ a } set={ set } />
			{ formHasWelcomeScreen && a.showWelcomeScreen !== false && <WelcomeScreenPanel a={ a } set={ set } /> }
			<MessagesPanel        a={ a } set={ set } />
			<AdvancedPanel        a={ a } set={ set } />
		</>
	);
}
