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

import FieldList from './FieldList';
import FormTypeChooser from './FormTypeChooser';
import BuilderCanvasToolbar from './BuilderCanvasToolbar';

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
	onApplyTemplate,
	templateId,
	onRequestChangeTemplate,
	onRequestClear,
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
					{ /* The starter screen owns its own heading + subtitle
					    now, so we no longer render the legacy
					    "Start with a field..." message above it - that
					    duplicated copy and made the empty state feel
					    crowded. The .nf-builder-canvas-empty wrapper
					    keeps existing pointer-event semantics. */ }
					<FormTypeChooser
						onApplyTemplate={ ( id ) => {
							// Prefer the unified handler when wired; fall
							// back to the legacy starter callbacks so old
							// consumers keep working until they migrate.
							if ( typeof onApplyTemplate === 'function' ) {
								onApplyTemplate( id );
								return;
							}
							if ( id === 'blank' && typeof onAddBlankStarter === 'function' ) onAddBlankStarter();
							if ( id === 'multistep-contact' && typeof onAddMultistepStarter === 'function' ) onAddMultistepStarter();
						} }
					/>
				</div>
			) : (
				<div onClick={ ( e ) => e.stopPropagation() }>
					<BuilderCanvasToolbar
						templateId={ templateId }
						onChangeTemplate={ onRequestChangeTemplate }
						onClear={ onRequestClear }
					/>
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
