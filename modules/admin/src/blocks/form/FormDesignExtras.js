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
	PricingPanel,
	SubmitButtonPanel,
	WelcomeScreenPanel,
	MessagesPanel,
	AdvancedPanel,
} from '../shared/DesignPanels';

export default function FormDesignExtras( { attributes, setAttributes, formHasWelcomeScreen = false } ) {
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
			<PricingPanel         a={ a } set={ set } />
			<SubmitButtonPanel    a={ a } set={ set } />
			{ formHasWelcomeScreen && a.showWelcomeScreen !== false && <WelcomeScreenPanel a={ a } set={ set } /> }
			<MessagesPanel        a={ a } set={ set } />
			<AdvancedPanel        a={ a } set={ set } />
		</>
	);
}
