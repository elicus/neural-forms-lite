/**
 * Device-width toggle for the preview iframe.
 *
 * Three preset widths. The actual rendered form HTML is identical;
 * only the iframe's CSS width changes, so the preview reflects the
 * form's responsive breakpoints (Lite collapses multi-col rows below
 * ~768px, this lets the user verify that visually).
 */

import { __ } from '@wordpress/i18n';

export const PREVIEW_WIDTHS = {
	desktop: 960,
	tablet:  720,
	mobile:  375,
};

const DEVICES = [
	{ id: 'desktop', label: 'Desktop' },
	{ id: 'tablet',  label: 'Tablet'  },
	{ id: 'mobile',  label: 'Mobile'  },
];

export default function ResponsivePreviewToggle( { active, onChange } ) {
	return (
		<div className="nf-preview-modal__devices" role="tablist">
			{ DEVICES.map( ( d ) => (
				<button
					key={ d.id }
					type="button"
					role="tab"
					aria-selected={ active === d.id }
					className={ 'nf-preview-modal__device' + ( active === d.id ? ' is-active' : '' ) }
					onClick={ () => onChange( d.id ) }
				>
					{ __( d.label, 'neural-forms-lite' ) }
				</button>
			) ) }
		</div>
	);
}
