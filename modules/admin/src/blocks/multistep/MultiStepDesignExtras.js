import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, RangeControl } from '@wordpress/components';
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
	WelcomeScreenPanel,
	MessagesPanel,
	AdvancedPanel,
	Sep, Gap, Clr, FW, FontFamilySelect, PaddingMarginDimensions,
} from '../shared/DesignPanels';

// ─── Step Layout panel (MultiStep-only) ──────────────────────────────────────

const TRANSITION_OPTIONS = [
	{ value: 'none',  label: __( 'None',  'neural-forms-lite' ) },
	{ value: 'slide', label: __( 'Slide', 'neural-forms-lite' ) },
	{ value: 'fade',  label: __( 'Fade',  'neural-forms-lite' ) },
	{ value: 'scale', label: __( 'Scale', 'neural-forms-lite' ) },
	{ value: 'flip',  label: __( 'Flip',  'neural-forms-lite' ) },
];

const TRANSITION_DIR_OPTIONS = {
	slide: [
		{ value: 'auto',  label: __( 'Auto (direction-aware)', 'neural-forms-lite' ) },
		{ value: 'right', label: __( 'Right → Left',           'neural-forms-lite' ) },
		{ value: 'left',  label: __( 'Left → Right',           'neural-forms-lite' ) },
		{ value: 'up',    label: __( 'Up → Down',              'neural-forms-lite' ) },
		{ value: 'down',  label: __( 'Down → Up',              'neural-forms-lite' ) },
	],
	fade: [
		{ value: 'auto',  label: __( 'Auto (direction-aware)', 'neural-forms-lite' ) },
		{ value: 'right', label: __( 'Drift Right',            'neural-forms-lite' ) },
		{ value: 'left',  label: __( 'Drift Left',             'neural-forms-lite' ) },
		{ value: 'up',    label: __( 'Drift Up',               'neural-forms-lite' ) },
		{ value: 'down',  label: __( 'Drift Down',             'neural-forms-lite' ) },
	],
	scale: [
		{ value: 'auto', label: __( 'Auto (in on Next, out on Prev)', 'neural-forms-lite' ) },
		{ value: 'in',   label: __( 'Scale In',                       'neural-forms-lite' ) },
		{ value: 'out',  label: __( 'Scale Out',                      'neural-forms-lite' ) },
	],
	flip: [
		{ value: 'auto',       label: __( 'Auto (horizontal)', 'neural-forms-lite' ) },
		{ value: 'horizontal', label: __( 'Horizontal (Y)',    'neural-forms-lite' ) },
		{ value: 'vertical',   label: __( 'Vertical (X)',      'neural-forms-lite' ) },
	],
};

const EASING_OPTIONS = [
	{ value: 'ease',        label: 'Ease'        },
	{ value: 'linear',      label: 'Linear'      },
	{ value: 'ease-in',     label: 'Ease In'     },
	{ value: 'ease-out',    label: 'Ease Out'    },
	{ value: 'ease-in-out', label: 'Ease In-Out' },
];

const BORDER_STYLE_OPTIONS = [
	{ value: 'solid',  label: 'Solid'  },
	{ value: 'dashed', label: 'Dashed' },
	{ value: 'dotted', label: 'Dotted' },
	{ value: 'double', label: 'Double' },
];

function StepLayoutPanel( { a, set } ) {
	const showLabels = a.showStepLabels !== false;
	const showDescs  = a.showStepDescriptions !== false;
	return (
		<PanelBody title={ __( 'Step Layout', 'neural-forms-lite' ) } initialOpen={ false }>
			<PaddingMarginDimensions
				pTop="stepPaddingTop" pRight="stepPaddingRight" pBottom="stepPaddingBottom" pLeft="stepPaddingLeft"
				mTop="stepMarginTop"  mRight="stepMarginRight"  mBottom="stepMarginBottom"  mLeft="stepMarginLeft"
				a={ a } set={ set }
			/>
			<Sep />
			<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Transition', 'neural-forms-lite' ) }</p>
			<SelectControl
				label={ __( 'Animation', 'neural-forms-lite' ) }
				value={ a.stepTransition || 'none' }
				options={ TRANSITION_OPTIONS }
				onChange={ v => set( { stepTransition: v } ) }
			/>
			{ a.stepTransition && a.stepTransition !== 'none' && (
				<>
					<SelectControl
						label={ __( 'Direction', 'neural-forms-lite' ) }
						value={ a.stepTransitionDirection || 'auto' }
						options={ TRANSITION_DIR_OPTIONS[ a.stepTransition ] || [] }
						onChange={ v => set( { stepTransitionDirection: v } ) }
					/>
					<RangeControl
						label={ __( 'Duration (ms)', 'neural-forms-lite' ) }
						value={ parseInt( a.stepTransitionDuration || '350', 10 ) }
						onChange={ v => set( { stepTransitionDuration: String( v ) } ) }
						min={ 100 }
						max={ 1200 }
						step={ 50 }
					/>
					<SelectControl
						label={ __( 'Easing', 'neural-forms-lite' ) }
						value={ a.stepTransitionEasing || 'ease' }
						options={ EASING_OPTIONS }
						onChange={ v => set( { stepTransitionEasing: v } ) }
					/>
				</>
			) }
			<Sep />
			{ showLabels && (
				<>
					<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Title', 'neural-forms-lite' ) }</p>
					<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepTitleColor } onChange={ v => set( { stepTitleColor: v ?? '' } ) } />
					<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.stepTitleFontFamily } onChange={ v => set( { stepTitleFontFamily: v } ) } />
					<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.stepTitleFontSize } onChange={ v => set( { stepTitleFontSize: v ?? '' } ) } />
					<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.stepTitleFontWeight || '' } options={ FW } onChange={ v => set( { stepTitleFontWeight: v } ) } />
					<Sep />
				</>
			) }
			{ showDescs && (
				<>
					<p style={ { fontSize: 11, fontWeight: 600, margin: '4px 0 8px', color: '#1e1e1e' } }>{ __( 'Step Description', 'neural-forms-lite' ) }</p>
					<Clr label={ __( 'Color', 'neural-forms-lite' ) } value={ a.stepDescColor } onChange={ v => set( { stepDescColor: v ?? '' } ) } />
					<FontFamilySelect label={ __( 'Font Family', 'neural-forms-lite' ) } value={ a.stepDescFontFamily } onChange={ v => set( { stepDescFontFamily: v } ) } />
					<UnitControl label={ __( 'Font Size', 'neural-forms-lite' ) } value={ a.stepDescFontSize } onChange={ v => set( { stepDescFontSize: v ?? '' } ) } />
					<SelectControl label={ __( 'Font Weight', 'neural-forms-lite' ) } value={ a.stepDescFontWeight || '' } options={ FW } onChange={ v => set( { stepDescFontWeight: v } ) } />
					<Sep />
				</>
			) }
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

export default function MultiStepDesignExtras( { attributes, setAttributes, formHasWelcomeScreen = false } ) {
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
			{ formHasWelcomeScreen && a.showWelcomeScreen !== false && <WelcomeScreenPanel a={ a } set={ set } /> }
			<MessagesPanel        a={ a } set={ set } />
			<AdvancedPanel        a={ a } set={ set } />
		</>
	);
}
