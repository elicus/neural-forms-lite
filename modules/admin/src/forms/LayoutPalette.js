/**
 * Sidebar palette for layout containers.
 *
 * Three chips: Row, Step, and a coming-soon Section. Row spawns a
 * 1-column row; the user picks 1/2/3/4 columns from the row's own
 * toolbar segmented control. Step spawns a top-level step container
 * - the validator rejects steps anywhere except top-level, so the
 * Builder routes Step adds to root regardless of current selection.
 */

import { __ } from '@wordpress/i18n';

export default function LayoutPalette( { onAddRow, onAddStep } ) {
	return (
		<section className="nf-palette__section">
			<h3 className="nf-palette__title">{ __( 'Layout', 'neural-forms-lite' ) }</h3>
			<div className="nf-palette__grid">
				<button
					type="button"
					className="nf-palette__chip nf-palette__chip--layout"
					onClick={ () => onAddRow( 1 ) }
				>
					<span className="nf-palette__chip-label">{ __( 'Row', 'neural-forms-lite' ) }</span>
				</button>

				<button
					type="button"
					className="nf-palette__chip nf-palette__chip--layout"
					onClick={ () => onAddStep && onAddStep() }
				>
					<span className="nf-palette__chip-label">{ __( 'Step', 'neural-forms-lite' ) }</span>
				</button>

				<button
					type="button"
					className="nf-palette__chip nf-palette__chip--locked"
					disabled
					title={ __( 'Available in a future release', 'neural-forms-lite' ) }
				>
					<span className="nf-palette__chip-label">{ __( 'Section', 'neural-forms-lite' ) }</span>
					<span className="nf-palette__chip-badge">{ __( 'Soon', 'neural-forms-lite' ) }</span>
				</button>
			</div>
		</section>
	);
}
