/**
 * Build / Settings tab switcher.
 *
 * Lives inside BuilderHeader. Stateless - parent owns the active tab.
 * Preview is reached via the dedicated "Preview" action button in the
 * header (which opens the modal); having a Preview tab AND a Preview
 * button was a duplicated control, so the tab was removed.
 */

import { __ } from '@wordpress/i18n';

const TABS = [
	{ id: 'build',    label: 'Build'    },
	{ id: 'settings', label: 'Settings' },
];

export default function BuilderModeTabs( { active, onChange } ) {
	const current = TABS.find( ( t ) => t.id === active ) ? active : 'build';
	return (
		<nav className="nf-builder-header__tabs" role="tablist">
			{ TABS.map( ( t ) => (
				<button
					key={ t.id }
					type="button"
					role="tab"
					aria-selected={ current === t.id }
					className={ 'nf-builder-header__tab' + ( current === t.id ? ' is-active' : '' ) }
					onClick={ () => onChange( t.id ) }
				>
					{ __( t.label, 'neural-forms-lite' ) }
				</button>
			) ) }
		</nav>
	);
}
