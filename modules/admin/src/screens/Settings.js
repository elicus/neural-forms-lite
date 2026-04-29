/**
 * Settings screen - placeholder.
 *
 * Re-implementation needs the full sectioned form per docs/UI_GUIDELINES.md:
 *   1. Neural Forms Cloud AI (key '->-> test '->-> enable '->-> spam)
 *   2. Notifications
 *   3. Email Settings
 *   4. General & Privacy
 *   5. CAPTCHA
 *
 * Plus the SlotFills the contract exposes:
 *   - NeuralForms.SettingsSpamPanel
 *   - NeuralForms.SettingsSiteProfile
 *   - NeuralForms.SettingsIntegrations
 */

import { __ } from '@wordpress/i18n';

export default function Settings() {
	return (
		<div className="neural-forms-screen neural-forms-screen--placeholder">
			<h1>{ __( 'Settings', 'neural-forms-lite' ) }</h1>
			<p>{ __( 'The Settings UI is being rebuilt. Use the REST API directly for now if you need to change values.', 'neural-forms-lite' ) }</p>
		</div>
	);
}
