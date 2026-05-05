/**
 * Empty-canvas starter screen.
 *
 * Calm three-tier layout:
 *
 *   1. Primary row: the four most-used starters as compact cards.
 *      Blank / Contact / Appointment / Multi-step Contact.
 *   2. Secondary row under "More templates": the remaining four real
 *      templates, same compact card shape.
 *   3. Footer line: a single muted sentence listing the locked
 *      future templates. No large cards for upcoming features.
 *
 * The full TEMPLATE_CATALOG metadata is still consumed below so each
 * tile reads its title / description / field-or-step summary from
 * one source of truth.
 *
 * Builder.js owns the apply-template flow (confirm overwrite, set
 * name, mutate form_schema). This component is pure presentation.
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { TEMPLATE_CATALOG, templateSummary } from './formTemplates';

// Tier groupings. The catalog itself stays catalog-shaped; the order
// here decides which tiles surface where without changing the source.
//
// Primary row keeps the four most-used starters.
// Secondary row covers everything else that ships unlocked, ordered
// roughly by category (Sales/Customer → Marketing/Engagement →
// Interactive → Multi-step). Quiz / Poll / Survey / Multi-step
// Appointment are all surfaced here now that they're implemented.
const PRIMARY_IDS = [
	'blank',
	'contact',
	'appointment',
	'multistep-contact',
];

const SECONDARY_IDS = [
	'quote',
	'feedback',
	'testimonial',
	'newsletter',
	'survey',
	'poll',
	'quiz',
	'multistep-appointment',
];

function meta( id ) {
	const summary = templateSummary( id );
	if ( ! summary ) return '';
	if ( summary.steps ) {
		return sprintf(
			/* translators: %d: step count */
			_n( '%d step', '%d steps', summary.steps, 'neural-forms-lite' ),
			summary.steps
		);
	}
	if ( summary.fields === 0 ) return '';
	return sprintf(
		/* translators: %d: field count */
		_n( '%d field', '%d fields', summary.fields, 'neural-forms-lite' ),
		summary.fields
	);
}

function findTemplate( id ) {
	return TEMPLATE_CATALOG.find( ( t ) => t.id === id );
}

function TemplateCard( { template, onClick } ) {
	const summary = meta( template.id );
	return (
		<button
			type="button"
			className="nf-starter__card"
			onClick={ () => onClick( template.id ) }
			title={ template.description || '' }
		>
			<span className="nf-starter__card-title">{ template.label }</span>
			{ template.description && (
				<span className="nf-starter__card-desc">{ template.description }</span>
			) }
			{ summary && <span className="nf-starter__card-meta">{ summary }</span> }
		</button>
	);
}

/**
 * @param {object} props
 * @param {(id:string)=>void} props.onApplyTemplate  Called with the picked id.
 * @param {'inline'|'modal'} [props.mode='inline']   Layout variant.
 *   'inline' is the empty-canvas starter screen.
 *   'modal'  swaps the heading copy + uses a dialog-friendly title.
 * @param {string} [props.heading]                   Override the header title.
 * @param {string} [props.subtitle]                  Override the subtitle.
 */
export default function FormTypeChooser( { onApplyTemplate, mode = 'inline', heading, subtitle } ) {
	const handlePick = ( id ) => {
		if ( typeof onApplyTemplate === 'function' ) onApplyTemplate( id );
	};

	const primary   = PRIMARY_IDS.map( findTemplate ).filter( Boolean );
	const secondary = SECONDARY_IDS.map( findTemplate ).filter( Boolean );

	// Footer copy is split by reason for the lock so the line stays
	// accurate as features ship:
	//   * `available: 'pro-modal'` items (AI Generated Form) point
	//     at the existing Pro entry point on the Forms list — they
	//     are already shippable, just not as a static template.
	//   * Everything else still locked is genuinely upcoming and
	//     gets the literal "coming soon" sentence.
	const upcoming = TEMPLATE_CATALOG
		.filter( ( t ) => t.locked && t.available !== 'pro-modal' )
		.map( ( t ) => t.label );
	const proAvailable = TEMPLATE_CATALOG
		.filter( ( t ) => t.locked && t.available === 'pro-modal' )
		.map( ( t ) => t.label );

	const title = heading || (
		mode === 'modal'
			? __( 'Change template', 'neural-forms-lite' )
			: __( 'Start building your form', 'neural-forms-lite' )
	);
	const sub = subtitle || (
		mode === 'modal'
			? __( 'Pick a new template. Your fields and layout will be replaced; form settings stay the same.', 'neural-forms-lite' )
			: __( 'Choose a template or start from scratch.', 'neural-forms-lite' )
	);

	return (
		<div className={ 'nf-starter' + ( mode === 'modal' ? ' nf-starter--modal' : '' ) }>
			<header className="nf-starter__header">
				<h3 className="nf-starter__title">{ title }</h3>
				<p className="nf-starter__subtitle">{ sub }</p>
			</header>

			<div className="nf-starter__row">
				{ primary.map( ( t ) => (
					<TemplateCard key={ t.id } template={ t } onClick={ handlePick } />
				) ) }
			</div>

			{ secondary.length > 0 && (
				<>
					<h4 className="nf-starter__row-title">{ __( 'More templates', 'neural-forms-lite' ) }</h4>
					<div className="nf-starter__row nf-starter__row--secondary">
						{ secondary.map( ( t ) => (
							<TemplateCard key={ t.id } template={ t } onClick={ handlePick } />
						) ) }
					</div>
				</>
			) }

			{ upcoming.length > 0 && (
				<p className="nf-starter__footer">
					{ sprintf(
						/* translators: %s: comma-separated list of upcoming template names */
						_n(
							'%s template is coming soon.',
							'%s templates are coming soon.',
							upcoming.length,
							'neural-forms-lite'
						),
						upcoming.join( ', ' )
					) }
				</p>
			) }
			{ proAvailable.length > 0 && (
				<p className="nf-starter__footer">
					{ sprintf(
						/* translators: %s: feature label, e.g. "AI Generated Form" */
						__( '%s is available with Neural Forms Pro from the Forms list.', 'neural-forms-lite' ),
						proAvailable.join( ', ' )
					) }
				</p>
			) }
		</div>
	);
}
