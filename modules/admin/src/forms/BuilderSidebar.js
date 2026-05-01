/**
 * Left sidebar for the builder.
 *
 * Composes three palettes, top to bottom:
 *   FieldPalette    Basic fields + locked future fields
 *   LayoutPalette   Row / 2-3-4 columns + locked Section
 *   AiPromptPanel   AI prompt placeholder (Pro)
 *
 * Each palette is its own file so the architecture stays modular as
 * we add Pro field packs / AI generators / template galleries.
 */

import { __ } from '@wordpress/i18n';
import FieldPalette from './FieldPalette';
import LayoutPalette from './LayoutPalette';
import AiPromptPanel from './AiPromptPanel';

export default function BuilderSidebar( { onAddField, onAddRow, onAddStep, onAddSection } ) {
	return (
		<aside className="nf-builder-sidebar" aria-label={ __( 'Add fields and layout', 'neural-forms-lite' ) }>
			<FieldPalette  onAddField={ onAddField } />
			<LayoutPalette onAddRow={ onAddRow } onAddStep={ onAddStep } onAddSection={ onAddSection } />
			<AiPromptPanel />
		</aside>
	);
}
