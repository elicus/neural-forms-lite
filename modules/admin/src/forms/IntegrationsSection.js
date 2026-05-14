/**
 * Builder Settings → Integrations section.
 *
 * Wraps the per-provider mapping panels behind a sub-tablist.
 * Currently four providers are wired: HubSpot, Google Forms,
 * WhatsApp, Mailchimp. Future providers (Zapier per-form delivery,
 * Google Forms response sync, …) can slot in without restructuring.
 *
 * State plumbing:
 *   - `settings`    Top-level form.settings blob (read).
 *   - `onChange`    Mutator that threads back to the builder's
 *                   normal save flow. We never POST from here.
 *   - `fields`      The form's flat schema (form.form_schema.fields).
 *                   Each provider panel that needs to populate a
 *                   mapping dropdown reads from this.
 *
 * Each provider panel reads + writes only its own slice of
 * settings.integrations.<provider>. The FormSettingsSanitizer (Lite)
 * whitelists per-provider shapes and strips global / secret /
 * telemetry keys on every save.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import HubSpotMappingPanel from './HubSpotMappingPanel';
import GoogleFormsFormPanel from './GoogleFormsFormPanel';
import WhatsAppFormPanel from './WhatsAppFormPanel';
import MailchimpFormPanel from './MailchimpFormPanel';

const PROVIDERS = [
	{
		id:    'hubspot',
		label: 'HubSpot',
		icon:  'H',
	},
	{
		id:    'google_forms',
		label: 'Google Forms',
		icon:  'G',
	},
	{
		id:    'whatsapp',
		label: 'WhatsApp',
		icon:  'W',
	},
	{
		id:    'mailchimp',
		label: 'Mailchimp',
		icon:  'M',
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
	const googleForms = ( integrations.google_forms && typeof integrations.google_forms === 'object' )
		? integrations.google_forms
		: {
			enabled:           false,
			mode:              '',
			google_form_id:    '',
			google_form_title: '',
			google_form_url:   '',
			imported_at:       '',
			sync_responses:    false,
		};
	const whatsapp = ( integrations.whatsapp && typeof integrations.whatsapp === 'object' )
		? integrations.whatsapp
		: {
			enabled:           false,
			recipients:        [],
			trigger:           'entry_created',
			template_name:     'neural_forms_entry_alert',
			template_language: 'en_US',
			send_when:         'all_entries',
			include_summary:   true,
		};
	const mailchimp = ( integrations.mailchimp && typeof integrations.mailchimp === 'object' )
		? integrations.mailchimp
		: {
			enabled:         false,
			audience_id:     '',
			audience_name:   '',
			email_field:     '',
			status:          'subscribed',
			update_existing: true,
			tags:            [],
			merge_map:       {},
		};

	const setHubspot = ( next ) => {
		onChange( {
			...( settings || {} ),
			integrations: {
				...integrations,
				hubspot: { ...hubspot, ...next },
			},
		} );
	};
	const setGoogleForms = ( next ) => {
		onChange( {
			...( settings || {} ),
			integrations: {
				...integrations,
				google_forms: { ...googleForms, ...next },
			},
		} );
	};
	const setWhatsapp = ( next ) => {
		onChange( {
			...( settings || {} ),
			integrations: {
				...integrations,
				whatsapp: { ...whatsapp, ...next },
			},
		} );
	};
	const setMailchimp = ( next ) => {
		onChange( {
			...( settings || {} ),
			integrations: {
				...integrations,
				mailchimp: { ...mailchimp, ...next },
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
				{ active.id === 'google_forms' && (
					<GoogleFormsFormPanel
						google={ googleForms }
						onChange={ setGoogleForms }
						capUnlocked={ capUnlocked }
					/>
				) }
				{ active.id === 'whatsapp' && (
					<WhatsAppFormPanel
						whatsapp={ whatsapp }
						onChange={ setWhatsapp }
						capUnlocked={ capUnlocked }
						formSettings={ settings }
					/>
				) }
				{ active.id === 'mailchimp' && (
					<MailchimpFormPanel
						mailchimp={ mailchimp }
						onChange={ setMailchimp }
						fields={ fields }
						capUnlocked={ capUnlocked }
					/>
				) }
			</div>
		</div>
	);
}
