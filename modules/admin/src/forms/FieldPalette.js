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
	'rating',
	'poll',
	'file',
	'hidden',
];

// Conditional Logic is NOT in this list. It already shipped in
// Neural Forms Lite v1.0.0 (frontend serialization in
// FormRenderer.php and server enforcement in SubmitEndpoint.php),
// and the new inspector exposes its real editor. Listing it here
// would be inaccurate and would block users from a feature they
// already have.
const LOCKED_FIELDS = [
	{ id: 'quiz',      label: 'Quiz Question' },
	{ id: 'signature', label: 'Signature' },
];

export default function FieldPalette( { onAddField } ) {
	const basics = FIELD_TYPES.filter( ( t ) => BASIC_TYPES.includes( t.value ) );

	return (
		<>
			<section className="nf-palette__section">
				<h3 className="nf-palette__title">{ __( 'Basic Fields', 'neural-forms-lite' ) }</h3>
				<div className="nf-palette__grid">
					{ basics.map( ( t ) => (
						<button
							key={ t.value }
							type="button"
							className="nf-palette__chip"
							onClick={ () => onAddField( t.value ) }
							title={ sprintf(
								/* translators: %s: field type label */
								__( 'Add %s field', 'neural-forms-lite' ),
								t.label
							) }
						>
							<span className="nf-palette__chip-label">{ t.label }</span>
						</button>
					) ) }
				</div>
			</section>

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
		</>
	);
}
