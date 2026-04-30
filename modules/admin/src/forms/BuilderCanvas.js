/**
 * Center canvas for the 3-panel builder.
 *
 * Wraps FieldList (which owns dnd-kit + row/column rendering) and
 * surfaces a premium empty state when the schema has no nodes. The
 * empty state contains the form-type chooser tile grid - a soft entry
 * point for the future template / AI flows. Today only the "Blank
 * form" tile is interactive (it spawns a default text field via
 * `onAddBlankStarter`).
 *
 * Selection: clicking the canvas background clears the inspector
 * selection; clicking a node bubbles up via FieldList -> FieldRow /
 * RowBlock -> Builder.
 */

import { __ } from '@wordpress/i18n';
import FieldList from './FieldList';
import FormTypeChooser from './FormTypeChooser';

export default function BuilderCanvas( {
	nodes,
	onChange,
	selectedId,
	onSelect,
	selectedColumnId,
	onSelectColumn,
	selectedStepId,
	onSelectStep,
	onAddBlankStarter,
	onAddMultistepStarter,
} ) {
	const isEmpty = ! Array.isArray( nodes ) || nodes.length === 0;

	const clearSelection = () => {
		onSelect( null );
		if ( typeof onSelectColumn === 'function' ) onSelectColumn( null );
		if ( typeof onSelectStep === 'function' )   onSelectStep( null );
	};

	return (
		<div className="nf-builder-canvas-shell" onClick={ clearSelection }>
			{ isEmpty ? (
				<div className="nf-builder-canvas-empty" onClick={ ( e ) => e.stopPropagation() }>
					<p className="nf-builder-canvas-empty__title">
						{ __( 'Start with a field, layout row, or template', 'neural-forms-lite' ) }
					</p>
					<p className="nf-builder-canvas-empty__hint">
						{ __( 'Drop a basic field from the left sidebar, or pick a template below to get going.', 'neural-forms-lite' ) }
					</p>
					<FormTypeChooser
						onPickBlank={ onAddBlankStarter }
						onPickMultistep={ onAddMultistepStarter }
					/>
				</div>
			) : (
				<div onClick={ ( e ) => e.stopPropagation() }>
					<FieldList
						nodes={ nodes }
						onChange={ onChange }
						selectedId={ selectedId }
						onSelect={ onSelect }
						selectedColumnId={ selectedColumnId }
						onSelectColumn={ onSelectColumn }
						selectedStepId={ selectedStepId }
						onSelectStep={ onSelectStep }
					/>
				</div>
			) }
		</div>
	);
}
