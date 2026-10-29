/**
 * Left sidebar for the builder.
 *
 * Section order (top → bottom):
 *   1. Basic Fields
 *   2. Survey Fields
 *   3. Advanced Fields
 *   4. Layout
 *
 * Generate-with-AI used to live here but moved to the right Inspector's
 * empty state (BuilderInspector::EmptyInspector) — the left sidebar
 * stays focused on draggable fields / layout blocks, and the AI card
 * surfaces only when no field/row/section/step is selected.
 *
 * Each palette is its own file so the architecture stays modular as
 * we add Pro field packs / template galleries / future creation tools.
 */

import { __ } from '@wordpress/i18n';
import {
	BasicFieldsPalette,
	SurveyFieldsPalette,
	PricingFieldsPalette,
	AdvancedFieldsPalette,
} from './FieldPalette';
import LayoutPalette from './LayoutPalette';

export default function BuilderSidebar( {
	onAddField, onAddRow, onAddStep, onAddSection,
	onAddWelcomeScreen, welcomeScreenPresent,
} ) {
	return (
		<aside className="nf-builder-sidebar" aria-label={ __( 'Add fields and layout', 'neural-forms-lite' ) }>
			<BasicFieldsPalette    onAddField={ onAddField } />
			<SurveyFieldsPalette   onAddField={ onAddField } />
			<PricingFieldsPalette  onAddField={ onAddField } />
			<AdvancedFieldsPalette onAddField={ onAddField } />
			<LayoutPalette
				onAddRow={ onAddRow }
				onAddStep={ onAddStep }
				onAddSection={ onAddSection }
				onAddWelcomeScreen={ onAddWelcomeScreen }
				welcomeScreenPresent={ welcomeScreenPresent }
			/>
		</aside>
	);
}
