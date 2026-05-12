/**
 * Builder Settings → Integrations section.
 *
 * Wraps the per-provider mapping panels behind a sub-tablist.
 * Today only HubSpot is wired; the tab structure is in place so
 * future providers (Zapier per-form delivery, Mailchimp, Google
 * Forms response sync, …) can land without restructuring.
 *
 * State plumbing:
 *   - `settings`    Top-level form.settings blob (read).
 *   - `onChange`    Mutator that threads back to the builder's
 *                   normal save flow. We never POST from here.
 *   - `fields`      The form's flat schema (form.form_schema.fields).
 *                   Required so HubSpot's mapping dropdown can
 *                   populate with the form's own input fields.
 *
 * The HubSpot panel reads + writes only
 * settings.integrations.hubspot. Other integrations would mount
 * sibling subtabs and target settings.integrations.<provider>.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import HubSpotMappingPanel from './HubSpotMappingPanel';

const PROVIDERS = [
	{
		id:    'hubspot',
		label: 'HubSpot',
		icon:  'H',
	},
];

export default function IntegrationsSection( { settings, onChange, fields } ) {
	const [ activeId, setActiveId ] = useState( PROVIDERS[ 0 ].id );

	const integrations = ( settings && typeof settings === 'object' && settings.integrations && typeof settings.integrations === 'object' )
		? settings.integrations
		: {};
	const hubspot = ( integrations.hubspot && typeof integrations.hubspot === 'object' )
		? integrations.hubspot
		: { enabled: false, hubspot_form_id: '', hubspot_form_name: '', field_map: {} };

	const setHubspot = ( next ) => {
		onChange( {
			...( settings || {} ),
			integrations: {
				...integrations,
				hubspot: { ...hubspot, ...next },
			},
		} );
	};

	// Capability snapshot — same source the global Integrations
	// page consults. Pro's Unlocker flips this to true; on Lite
	// (or Pro deactivated) it's false and the panel renders the
	// upsell.
	const caps = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.plugin?.capabilities ) || {};
	const capUnlocked = !! caps.integrations;

	const active = PROVIDERS.find( ( p ) => p.id === activeId ) || PROVIDERS[ 0 ];

	return (
		<div className="nf-form-settings__integrations">
			<div className="nf-form-settings__integrations-tablist" role="tablist" aria-label={ __( 'Integration providers', 'neural-forms-lite' ) }>
				{ PROVIDERS.map( ( p ) => {
					const selected = p.id === activeId;
					return (
						<button
							key={ p.id }
							type="button"
							role="tab"
							aria-selected={ selected }
							className={ 'nf-form-settings__integrations-tab' + ( selected ? ' is-active' : '' ) }
							onClick={ () => setActiveId( p.id ) }
						>
							<span className="nf-form-settings__integrations-mark" aria-hidden="true">{ p.icon }</span>
							<span>{ p.label }</span>
						</button>
					);
				} ) }
			</div>

			<div className="nf-form-settings__integrations-panel" role="tabpanel">
				{ active.id === 'hubspot' && (
					<HubSpotMappingPanel
						fields={ fields }
						hubspot={ hubspot }
						onChange={ setHubspot }
						capUnlocked={ capUnlocked }
					/>
				) }
			</div>
		</div>
	);
}
