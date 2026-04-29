/**
 * The split-column toggle shown in the top-right corner of every builder
 * field row. Two-segment button group: "Full" (single rectangle) and
 * "Half" (two rectangles). Click "Half" to mark this field as half-width
 * - paired with the next half-width field by the renderer.
 *
 * Stays a stateless visual control. The parent FieldRow owns the field's
 * width value and supplies onChange.
 */

import { __ } from '@wordpress/i18n';

export default function ColumnToggle( { value, onChange } ) {
	const isHalf = value === 'half';

	return (
		<div
			className="nf-builder-field__column-toggle"
			role="group"
			aria-label={ __( 'Field width', 'neural-forms-lite' ) }
		>
			<button
				type="button"
				className={
					'nf-builder-field__column-btn' +
					( ! isHalf ? ' is-active' : '' )
				}
				onClick={ () => onChange( 'full' ) }
				aria-pressed={ ! isHalf }
				aria-label={ __( 'Full width', 'neural-forms-lite' ) }
				title={ __( 'Full width', 'neural-forms-lite' ) }
			>
				<span className="nf-builder-field__column-glyph nf-builder-field__column-glyph--full" />
			</button>
			<button
				type="button"
				className={
					'nf-builder-field__column-btn' +
					( isHalf ? ' is-active' : '' )
				}
				onClick={ () => onChange( 'half' ) }
				aria-pressed={ isHalf }
				aria-label={ __( 'Half width', 'neural-forms-lite' ) }
				title={ __( 'Half width', 'neural-forms-lite' ) }
			>
				<span className="nf-builder-field__column-glyph nf-builder-field__column-glyph--half">
					<span className="nf-builder-field__column-glyph-cell" />
					<span className="nf-builder-field__column-glyph-cell" />
				</span>
			</button>
		</div>
	);
}
