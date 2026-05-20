/**
 * Sidebar palette for adding basic fields.
 *
 * The catalog is sourced from forms/FieldTypes (single source of
 * truth shared with the inspector type-picker). Click a chip to
 * append a new field of that type to the schema.
 *
 * Pro / future field types appear as locked chips lower down (multi-
 * step, quiz, poll, rating, conditional). They are visual only - they
 * never produce schema changes today.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useDraggable } from '@dnd-kit/core';
import { FIELD_TYPES } from './FieldTypes';

// Full supported field catalog as exposed in the sidebar. Slugs come
// straight from FIELD_TYPES (single source of truth shared with the
// inspector's type-picker) and every slug here has matching renderer +
// validator + sanitizer support already in 1.0.0. Order is intentional:
// text/email/textarea up top (most-used contact form bits), then numeric
// + phone + url + picker + choice families, then specialty types
// (acceptance, range, file, hidden) at the end.
//
// Note on "Telephone": there is no separate "telephone" type - "Phone"
// IS the telephone field. The frontend renderer accepts `tel` as an
// alias of `phone` for backward compatibility, but the canonical slug
// in the builder is `phone`. Adding a duplicate chip would just create
// two buttons that spawn the same field.
const BASIC_TYPES = [
	'text',
	'email',
	'textarea',
	'number',
	'phone',
	'url',
	'select',
	'radio',
	'checkbox',
	'checkboxes',
	'date',
	'acceptance',
	'range',
	'file',
	'hidden',
];

// Survey-style choice fields. Matrix is gated by the
// `field_matrix` capability — Lite-only installs see it as a
// locked chip; Pro flips the capability and it becomes draggable
// like any other field.
const SURVEY_TYPES = [
	'rating',
	'poll',
	'quiz_question',
	'matrix',
];

// Advanced / Pro-only field types. Repeater is gated by
// `field_repeater`; the chip renders locked unless Pro flips the
// capability filter. The "Advanced" section is rendered below
// Survey so Pro-only items stay visually grouped together.
const ADVANCED_TYPES = [
	'repeater',
];

// Capability keys mirror the PHP Capabilities:: constants. When
// `window.neuralFormsAdmin.plugin.capabilities[CAP]` is false the
// chip renders disabled with a Pro badge.
const MATRIX_CAP   = 'field_matrix';
const REPEATER_CAP = 'field_repeater';

// Conditional Logic is NOT in this list. It already shipped in
// Neural Forms Lite v1.0.0 (frontend serialization in
// FormRenderer.php and server enforcement in SubmitEndpoint.php),
// and the new inspector exposes its real editor. Listing it here
// would be inaccurate and would block users from a feature they
// already have.
const LOCKED_FIELDS = [];

function readCaps() {
	if ( typeof window === 'undefined' ) return {};
	return ( window.neuralFormsAdmin?.plugin?.capabilities ) || {};
}

/**
 * Single draggable chip. Click → adds the field. Drag → top-level
 * BuilderDndProvider handles the drop and inserts at the target.
 */
function FieldChip( { type, label, onAddField } ) {
	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: 'palette:field:' + type,
		data: {
			source:      'palette',
			paletteType: 'field',
			fieldType:   type,
			label,
		},
	} );
	return (
		<button
			ref={ setNodeRef }
			type="button"
			className={ 'nf-palette__chip' + ( isDragging ? ' is-dragging' : '' ) }
			onClick={ () => onAddField( type ) }
			title={ sprintf(
				/* translators: %s: field type label */
				__( 'Click to add, or drag to place: %s', 'neural-forms-lite' ),
				label
			) }
			{ ...listeners }
			{ ...attributes }
		>
			<span className="nf-palette__chip-label">{ label }</span>
		</button>
	);
}

/**
 * Read-only Pro chip. Shown for field types whose capability is
 * locked in this install (e.g. Matrix when Pro is not active).
 * Click is a no-op — drag handlers are intentionally omitted so
 * the chip can never produce a schema mutation.
 */
function LockedProChip( { label } ) {
	return (
		<button
			type="button"
			className="nf-palette__chip nf-palette__chip--locked"
			disabled
			title={ __( 'Available in Neural Forms Pro', 'neural-forms-lite' ) }
		>
			<span className="nf-palette__chip-label">{ label }</span>
			<span className="nf-palette__chip-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
		</button>
	);
}

// Per-section components. Split out so BuilderSidebar can interleave
// the Generate-with-AI card between Basic and Survey instead of
// rendering all three sections in a single block at the top.

export function BasicFieldsPalette( { onAddField } ) {
	const basics = FIELD_TYPES.filter( ( t ) => BASIC_TYPES.includes( t.value ) );
	return (
		<section className="nf-palette__section">
			<h3 className="nf-palette__title">{ __( 'Basic Fields', 'neural-forms-lite' ) }</h3>
			<p className="nf-palette__hint">
				{ __( 'Click to add, drag to place.', 'neural-forms-lite' ) }
			</p>
			<div className="nf-palette__grid">
				{ basics.map( ( t ) => (
					<FieldChip
						key={ t.value }
						type={ t.value }
						label={ t.label }
						onAddField={ onAddField }
					/>
				) ) }
			</div>
		</section>
	);
}

export function SurveyFieldsPalette( { onAddField } ) {
	const surveys = FIELD_TYPES.filter( ( t ) => SURVEY_TYPES.includes( t.value ) );
	const caps    = readCaps();
	const matrixUnlocked = caps[ MATRIX_CAP ] === true;
	return (
		<section className="nf-palette__section">
			<h3 className="nf-palette__title">{ __( 'Survey Fields', 'neural-forms-lite' ) }</h3>
			<div className="nf-palette__grid">
				{ surveys.map( ( t ) => {
					if ( t.value === 'matrix' && ! matrixUnlocked ) {
						return <LockedProChip key={ t.value } label={ t.label } />;
					}
					return (
						<FieldChip
							key={ t.value }
							type={ t.value }
							label={ t.label }
							onAddField={ onAddField }
						/>
					);
				} ) }
			</div>
		</section>
	);
}

export function AdvancedFieldsPalette( { onAddField } ) {
	const advanced = FIELD_TYPES.filter( ( t ) => ADVANCED_TYPES.includes( t.value ) );
	const caps     = readCaps();
	const repeaterUnlocked = caps[ REPEATER_CAP ] === true;
	return (
		<>
			<section className="nf-palette__section">
				<h3 className="nf-palette__title">{ __( 'Advanced Fields', 'neural-forms-lite' ) }</h3>
				<div className="nf-palette__grid">
					{ advanced.map( ( t ) => {
						if ( t.value === 'repeater' && ! repeaterUnlocked ) {
							return <LockedProChip key={ t.value } label={ t.label } />;
						}
						return (
							<FieldChip
								key={ t.value }
								type={ t.value }
								label={ t.label }
								onAddField={ onAddField }
							/>
						);
					} ) }
				</div>
			</section>

			{ LOCKED_FIELDS.length > 0 && (
				<section className="nf-palette__section">
					<h3 className="nf-palette__title">{ __( 'Coming soon', 'neural-forms-lite' ) }</h3>
					<div className="nf-palette__grid">
						{ LOCKED_FIELDS.map( ( f ) => (
							<button
								key={ f.id }
								type="button"
								className="nf-palette__chip nf-palette__chip--locked"
								disabled
								title={ __( 'Available in a future release', 'neural-forms-lite' ) }
							>
								<span className="nf-palette__chip-label">{ f.label }</span>
								<span className="nf-palette__chip-badge">{ __( 'Soon', 'neural-forms-lite' ) }</span>
							</button>
						) ) }
					</div>
				</section>
			) }
		</>
	);
}

// Legacy default export kept for any caller that still imports the
// monolithic palette (search-and-replace can land in follow-up). Renders
// all three sections in their original order — no AI card interleaved.
export default function FieldPalette( { onAddField } ) {
	return (
		<>
			<BasicFieldsPalette    onAddField={ onAddField } />
			<SurveyFieldsPalette   onAddField={ onAddField } />
			<AdvancedFieldsPalette onAddField={ onAddField } />
		</>
	);
}
