/**
 * Integrations screen.
 *
 * Pro-locked page. Lite ships the shell (menu entry, this React screen,
 * the per-screen bundle). The actual provider wiring is deliberately
 * deferred — each tab shows a "Coming soon" card while the providers
 * (HubSpot / WhatsApp / Mailchimp / Google Forms) are being built.
 *
 * Render modes:
 *   - Capability `integrations` = false  → single Pro-upsell card.
 *   - Capability `integrations` = true   → four-tab WAI-ARIA tablist
 *     with provider-specific Coming soon cards.
 *
 * Class naming: `.neural-forms-integrations__*` (BEM). All authored
 * CSS in src/integrations.css ships with the per-screen
 * build/integrations.* bundle.
 */

import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const UPGRADE_URL = 'https://neuralforms.ai/pricing';

const PROVIDERS = [
	{
		id:    'hubspot',
		name:  'HubSpot',
		blurb: __( 'Sync new entries directly into your HubSpot CRM as contacts and deals so sales never miss a lead.', 'neural-forms-lite' ),
		icon:  'H',
	},
	{
		id:    'whatsapp',
		name:  'WhatsApp',
		blurb: __( 'Send instant submission alerts to your team — or auto-reply to submitters — through WhatsApp Business.', 'neural-forms-lite' ),
		icon:  'W',
	},
	{
		id:    'mailchimp',
		name:  'Mailchimp',
		blurb: __( 'Add submitters to your Mailchimp audiences automatically, with field-to-merge-tag mapping.', 'neural-forms-lite' ),
		icon:  'M',
	},
	{
		id:    'google_forms',
		name:  __( 'Google Forms', 'neural-forms-lite' ),
		blurb: __( 'Mirror entries into a linked Google Sheet so existing spreadsheet workflows keep working.', 'neural-forms-lite' ),
		icon:  'G',
	},
];

// ─── Icons ──────────────────────────────────────────────────────

function LockIcon() {
	return (
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
			<path d="M7 11V7a5 5 0 0 1 10 0v4" />
		</svg>
	);
}

function HourglassIcon() {
	return (
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M6 2h12" />
			<path d="M6 22h12" />
			<path d="M6 2v4a6 6 0 0 0 12 0V2" />
			<path d="M6 22v-4a6 6 0 0 1 12 0v4" />
		</svg>
	);
}

// ─── Locked (Lite-only) variant ─────────────────────────────────

function LockedView() {
	return (
		<div className="neural-forms-integrations__lock-card" role="region" aria-label={ __( 'Integrations are a Pro feature', 'neural-forms-lite' ) }>
			<div className="neural-forms-integrations__lock-icon" aria-hidden="true">
				<LockIcon />
			</div>
			<div className="neural-forms-integrations__lock-body">
				<div className="neural-forms-integrations__lock-eyebrow">
					<span className="neural-forms-integrations__pro-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
					<span className="neural-forms-integrations__lock-eyebrow-text">{ __( 'Premium feature', 'neural-forms-lite' ) }</span>
				</div>
				<h2 className="neural-forms-integrations__lock-title">
					{ __( 'Integrations are available in Neural Forms Pro', 'neural-forms-lite' ) }
				</h2>
				<p className="neural-forms-integrations__lock-desc">
					{ __( 'Send Neural Forms submissions to the tools you already run on — HubSpot, WhatsApp, Mailchimp, Google Forms, and more. Upgrade to Neural Forms Pro to unlock this page and configure providers.', 'neural-forms-lite' ) }
				</p>
				<ul className="neural-forms-integrations__lock-list">
					{ PROVIDERS.map( ( p ) => (
						<li key={ p.id } className="neural-forms-integrations__lock-list-item">
							<span className="neural-forms-integrations__provider-mark" aria-hidden="true">{ p.icon }</span>
							<span>{ p.name }</span>
						</li>
					) ) }
				</ul>
				<a
					className="neural-forms-integrations__cta"
					href={ UPGRADE_URL }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
				</a>
			</div>
		</div>
	);
}

// ─── Unlocked (Pro) variant ─────────────────────────────────────

function ComingSoonCard( { provider } ) {
	return (
		<div className="neural-forms-integrations__provider-card">
			<div className="neural-forms-integrations__provider-header">
				<span className="neural-forms-integrations__provider-mark neural-forms-integrations__provider-mark--lg" aria-hidden="true">
					{ provider.icon }
				</span>
				<div className="neural-forms-integrations__provider-heading">
					<h2 className="neural-forms-integrations__provider-title">{ provider.name }</h2>
					<p className="neural-forms-integrations__provider-blurb">{ provider.blurb }</p>
				</div>
			</div>

			<div className="neural-forms-integrations__soon-row">
				<span className="neural-forms-integrations__soon-icon" aria-hidden="true">
					<HourglassIcon />
				</span>
				<div className="neural-forms-integrations__soon-body">
					<h3 className="neural-forms-integrations__soon-title">{ __( 'Coming soon', 'neural-forms-lite' ) }</h3>
					<p className="neural-forms-integrations__soon-desc">
						{ __( 'This integration is on the Neural Forms Pro roadmap. Your capability is already unlocked — once the provider ships, configuration will appear right here, no plugin update required.', 'neural-forms-lite' ) }
					</p>
				</div>
			</div>
		</div>
	);
}

function UnlockedView() {
	const [ activeId, setActiveId ] = useState( PROVIDERS[ 0 ].id );

	const onKeyDown = useCallback( ( ev ) => {
		const idx = PROVIDERS.findIndex( ( p ) => p.id === activeId );
		if ( idx === -1 ) return;
		let next = idx;
		if ( ev.key === 'ArrowRight' || ev.key === 'ArrowDown' ) {
			next = ( idx + 1 ) % PROVIDERS.length;
		} else if ( ev.key === 'ArrowLeft' || ev.key === 'ArrowUp' ) {
			next = ( idx - 1 + PROVIDERS.length ) % PROVIDERS.length;
		} else if ( ev.key === 'Home' ) {
			next = 0;
		} else if ( ev.key === 'End' ) {
			next = PROVIDERS.length - 1;
		} else {
			return;
		}
		ev.preventDefault();
		setActiveId( PROVIDERS[ next ].id );
		const tab = document.getElementById( `neural-forms-integrations-tab-${ PROVIDERS[ next ].id }` );
		if ( tab && typeof tab.focus === 'function' ) tab.focus();
	}, [ activeId ] );

	const active = PROVIDERS.find( ( p ) => p.id === activeId ) || PROVIDERS[ 0 ];

	return (
		<>
			<div
				className="neural-forms-integrations__tablist"
				role="tablist"
				aria-label={ __( 'Integration providers', 'neural-forms-lite' ) }
				onKeyDown={ onKeyDown }
			>
				{ PROVIDERS.map( ( p ) => {
					const selected = p.id === activeId;
					return (
						<button
							key={ p.id }
							id={ `neural-forms-integrations-tab-${ p.id }` }
							type="button"
							role="tab"
							aria-selected={ selected }
							aria-controls={ `neural-forms-integrations-panel-${ p.id }` }
							tabIndex={ selected ? 0 : -1 }
							className={
								'neural-forms-integrations__tab'
								+ ( selected ? ' neural-forms-integrations__tab--active' : '' )
							}
							onClick={ () => setActiveId( p.id ) }
						>
							<span className="neural-forms-integrations__provider-mark" aria-hidden="true">{ p.icon }</span>
							<span>{ p.name }</span>
						</button>
					);
				} ) }
			</div>

			<div
				id={ `neural-forms-integrations-panel-${ active.id }` }
				role="tabpanel"
				aria-labelledby={ `neural-forms-integrations-tab-${ active.id }` }
				className="neural-forms-integrations__panel"
				tabIndex={ 0 }
			>
				<ComingSoonCard provider={ active } />
			</div>
		</>
	);
}

// ─── Top-level screen ───────────────────────────────────────────

export default function Integrations() {
	const admin = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	const caps  = ( admin.plugin && admin.plugin.capabilities ) || {};
	const unlocked = !! caps.integrations;

	return (
		<div className="neural-forms-integrations">
			<header className="neural-forms-integrations__header">
				<div className="neural-forms-integrations__header-text">
					<h1 className="neural-forms-integrations__page-title">
						{ __( 'Integrations', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-integrations__page-desc">
						{ __( 'Connect Neural Forms to the tools your team already uses. Configure providers below.', 'neural-forms-lite' ) }
					</p>
				</div>
			</header>

			{ unlocked ? <UnlockedView /> : <LockedView /> }
		</div>
	);
}
