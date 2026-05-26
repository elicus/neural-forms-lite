import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __experimentalUnitControl as UnitControl } from '@wordpress/components';
import {
	ContainerPanel,
	TitlePanel,
	LabelsPanel,
	InputFieldsPanel,
	TextareaFieldPanel,
	RadioFieldPanel,
	CheckboxGroupPanel,
	AcceptanceFieldPanel,
	RatingFieldPanel,
	FileUploadPanel,
	RepeaterFieldPanel,
	MatrixFieldPanel,
	NavButtonsPanel,
	SubmitButtonPanel,
	MessagesPanel,
	AdvancedPanel,
	Sep, Gap, Clr, FW,
} from '../shared/DesignPanels';

// ─── Step Layout panel (MultiStep-only) ──────────────────────────────────────

const BORDER_STYLE_OPTIONS = [
	{ value: 'solid',  label: 'Solid'  },
	{ value: 'dashed', label: 'Dashed' },
	{ value: 'dotted', label: 'Dotted' },
	{ value: 'double', label: 'Double' },
];

function StepLayoutPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Step Layout', 'neural-forms-lite' ) } initialOpen={ false }>
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Title', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepTitleColor } onChange={ v => set( { stepTitleColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.stepTitleFontSize } onChange={ v => set( { stepTitleFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.stepTitleFontWeight || '' } options={ FW } onChange={ v => set( { stepTitleFontWeight: v } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Description', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepDescColor } onChange={ v => set( { stepDescColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.stepDescFontSize } onChange={ v => set( { stepDescFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.stepDescFontWeight || '' } options={ FW } onChange={ v => set( { stepDescFontWeight: v } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Separator', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Top border shown between steps (not on the first step).', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepSepColor } onChange={ v => set( { stepSepColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Width', 'neural-forms-lite' ) } value={ a.stepSepWidth } onChange={ v => set( { stepSepWidth: v ?? '' } ) } />
			<SelectControl label={ __( 'Style', 'neural-forms-lite' ) } value={ a.stepSepStyle || '' } options={ BORDER_STYLE_OPTIONS } onChange={ v => set( { stepSepStyle: v } ) } />
		</PanelBody>
	);
}

// ─── Step Indicator panels (MultiStep-only) ──────────────────────────────────

function ProgressBarPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Progress Bar', 'neural-forms-lite' ) } initialOpen={ false }>
			<Clr label={ __( 'Fill Color', 'neural-forms-lite' ) } value={ a.stepBarFillColor } onChange={ v => set( { stepBarFillColor: v ?? '' } ) } />
			<Clr label={ __( 'Track Color', 'neural-forms-lite' ) } value={ a.stepBarTrackColor } onChange={ v => set( { stepBarTrackColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Bar Height', 'neural-forms-lite' ) } value={ a.stepBarHeight } onChange={ v => set( { stepBarHeight: v ?? '' } ) } />
			<Gap />
			<UnitControl label={ __( 'Bar Border Radius', 'neural-forms-lite' ) } value={ a.stepBarRadius } onChange={ v => set( { stepBarRadius: v ?? '' } ) } />
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Progress Text', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepBarTextColor } onChange={ v => set( { stepBarTextColor: v ?? '' } ) } />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.stepBarTextFontSize } onChange={ v => set( { stepBarTextFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.stepBarTextFontWeight || '' } options={ FW } onChange={ v => set( { stepBarTextFontWeight: v } ) } />
		</PanelBody>
	);
}

function StepNumbersPanel( { a, set } ) {
	return (
		<PanelBody title={ __( 'Step Numbers', 'neural-forms-lite' ) } initialOpen={ false }>
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

			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Label', 'neural-forms-lite' ) }</p>
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Active', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepActiveLabelColor } onChange={ v => set( { stepActiveLabelColor: v ?? '' } ) } />
			<Gap />
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Completed', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepCompleteLabelColor } onChange={ v => set( { stepCompleteLabelColor: v ?? '' } ) } />
			<Gap />
			<p style={ { fontSize: 11, color: '#6b7280', margin: '0 0 8px' } }>{ __( 'Inactive', 'neural-forms-lite' ) }</p>
			<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepLabelColor } onChange={ v => set( { stepLabelColor: v ?? '' } ) } />
			<Sep />
			<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.stepLabelFontSize } onChange={ v => set( { stepLabelFontSize: v ?? '' } ) } />
			<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.stepLabelFontWeight || '' } options={ FW } onChange={ v => set( { stepLabelFontWeight: v } ) } />
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
			<RepeaterFieldPanel   a={ a } set={ set } />
			<MatrixFieldPanel     a={ a } set={ set } />
			{ a.progressIndicatorOverride !== 'none' && a.progressIndicatorOverride !== 'numbers' && <ProgressBarPanel  a={ a } set={ set } /> }
			{ a.progressIndicatorOverride !== 'none' && a.progressIndicatorOverride !== 'bar'     && <StepNumbersPanel a={ a } set={ set } /> }
			<StepLayoutPanel      a={ a } set={ set } />
			<NavButtonsPanel      a={ a } set={ set } />
			<SubmitButtonPanel    a={ a } set={ set } />
			<MessagesPanel        a={ a } set={ set } />
			<AdvancedPanel        a={ a } set={ set } />
		</>
	);
}
