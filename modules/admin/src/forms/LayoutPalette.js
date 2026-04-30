/**
 * Sidebar palette for layout containers.
 *
 * Only one creator chip: "Row". Spawns a 1-column row; the user picks
 * 1 / 2 / 3 / 4 columns from the segmented control inside the row's
 * own toolbar. Having both sidebar chips AND the row toolbar was
 * duplicated UI and confused users about where the canonical column
 * control lives.
 *
 * "Section" stays as a coming-soon placeholder - layout-only sections
 * are a future feature; the chip is visual only and never mutates the
 * schema.
 */

import { __ } from '@wordpress/i18n';

export default function LayoutPalette( { onAddRow } ) {
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
