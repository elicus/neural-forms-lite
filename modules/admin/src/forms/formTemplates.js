/**
 * Form templates - starter schemas for the Create Form chooser.
 *
 * Each template is a thunk: calling buildTemplate('contact') returns
 * a freshly-generated schema with unique ids on every node, every
 * time. This guarantees that re-applying a template (or applying it
 * to a form that already has the same id namespace) never produces
 * duplicate ids that would fail FormValidator's id uniqueness check.
 *
 * Schema shape returned:
 *
 *   {
 *     name:     'Contact form',                  // suggested form name
 *     fields:   [ ...form_schema.fields[] ],     // canonical schema
 *     settings: { multistep: { ... } }            // optional schema-level settings
 *   }
 *
 * The Builder is responsible for:
 *   - asking the user to confirm overwrite if the canvas isn't empty,
 *   - merging name/fields/settings into form / form_schema,
 *   - leaving form.settings (captcha / on_success / on_error / etc.)
 *     UNTOUCHED.
 *
 * Future Pro extension point: when @wordpress/hooks ships in this
 * bundle (or via the SlotFill registry exposed on
 * window.neuralForms.slots), Pro can inject extra templates by
 * filtering `neuralForms.builder.templates`. Today we keep it simple:
 * the catalog below is the source of truth.
 */

import {
	createStep,
	createRow,
	newFieldId,
	newRowId,
	newColumnId,
} from './schemaHelper';

/* ---------------------------------------------------------------- */
/* Field factories                                                  */
/* ---------------------------------------------------------------- */

/**
 * Generate a fresh field id from a human-readable label, falling
 * back to the type when the label is empty / unsanitisable. Mirrors
 * the FormValidator FIELD_ID_PATTERN: lowercase a-z, digits, _ only.
 *
 * Reuses newFieldId's collision-avoidance helper for the type seed
 * but adds a label-based prefix so generated ids read sensibly in
 * exports / entries (e.g. "full_name" instead of "field_xxxx").
 */
function fieldIdFromLabel( label, typeSeed ) {
	const slug = String( label || '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' );
	if ( ! slug ) return newFieldId( typeSeed );
	// Suffix a short token so two calls for the same label still produce
	// distinct ids (necessary if the same template is applied twice).
	const tail = Math.random().toString( 36 ).slice( 2, 6 );
	return slug + '_' + tail;
}

const text = ( label, opts = {} ) => ( {
	id:          fieldIdFromLabel( label, 'text' ),
	type:        'text',
	label,
	placeholder: opts.placeholder || '',
	required:    !! opts.required,
	width:       opts.width || 'full',
} );

const email = ( label = 'Email', opts = {} ) => ( {
	id:          fieldIdFromLabel( label, 'email' ),
	type:        'email',
	label,
	placeholder: opts.placeholder || '',
	required:    !! opts.required,
	width:       opts.width || 'full',
} );

const phone = ( label = 'Phone', opts = {} ) => ( {
	id:          fieldIdFromLabel( label, 'phone' ),
	type:        'phone',
	label,
	placeholder: opts.placeholder || '',
	required:    !! opts.required,
	width:       opts.width || 'full',
} );

const textarea = ( label, opts = {} ) => ( {
	id:           fieldIdFromLabel( label, 'textarea' ),
	type:         'textarea',
	label,
	placeholder:  opts.placeholder || '',
	required:     !! opts.required,
	rows:         opts.rows || 3,
	width:        opts.width || 'full',
} );

const date = ( label, opts = {} ) => ( {
	id:       fieldIdFromLabel( label, 'date' ),
	type:     'date',
	label,
	required: !! opts.required,
	width:    opts.width || 'full',
} );

const select = ( label, options, opts = {} ) => ( {
	id:       fieldIdFromLabel( label, 'select' ),
	type:     'select',
	label,
	required: !! opts.required,
	options:  options.map( ( o ) => (
		typeof o === 'string'
			? { value: o.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ), label: o }
			: o
	) ),
	width:    opts.width || 'full',
} );

const acceptance = ( label, opts = {} ) => ( {
	id:       fieldIdFromLabel( label, 'acceptance' ),
	type:     'acceptance',
	label,
	required: !! opts.required,
} );

/* ---------------------------------------------------------------- */
/* Template builders                                                */
/* ---------------------------------------------------------------- */

const builders = {
	blank: () => ( {
		name:   '',
		fields: [],
	} ),

	contact: () => ( {
		name:   'Contact Form',
		fields: [
			text(     'Name',    { required: true } ),
			email(    'Email',   { required: true } ),
			phone(    'Phone' ),
			textarea( 'Message', { required: true, rows: 4 } ),
		],
	} ),

	appointment: () => ( {
		name:   'Appointment Form',
		fields: [
			text(     'Full Name',                    { required: true } ),
			email(    'Email',                        { required: true } ),
			phone(    'Phone',                        { required: true } ),
			date(     'Preferred Date',               { required: true } ),
			text(     'Preferred Time',               { placeholder: 'e.g. 2:30 PM' } ),
			textarea( 'Reason for Appointment',       { rows: 3 } ),
		],
	} ),

	quote: () => ( {
		name:   'Quote Request',
		fields: [
			text(     'Name',  { required: true } ),
			email(    'Email', { required: true } ),
			phone(    'Phone' ),
			select(   'Service Needed',
				[ 'Website Design', 'WordPress Development', 'Maintenance', 'Other' ],
				{ required: true }
			),
			select(   'Budget Range',
				[ 'Under $500', '$500 - $1,000', '$1,000 - $3,000', '$3,000+' ]
			),
			textarea( 'Project Details', { required: true, rows: 4 } ),
		],
	} ),

	feedback: () => ( {
		name:   'Feedback',
		fields: [
			text(     'Name' ),
			email(    'Email' ),
			select(   'Rating', [ 'Excellent', 'Good', 'Average', 'Poor' ] ),
			textarea( 'Feedback', { required: true, rows: 4 } ),
		],
	} ),

	testimonial: () => ( {
		name:   'Testimonial',
		fields: [
			text(       'Name',                          { required: true } ),
			text(       'Company / Website' ),
			select(     'Rating',
				[ '5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star' ]
			),
			textarea(   'Testimonial',                   { required: true, rows: 4 } ),
			acceptance( 'I agree to share this testimonial publicly.' ),
		],
	} ),

	newsletter: () => ( {
		name:   'Newsletter Signup',
		fields: [
			text(       'Name' ),
			email(      'Email',                                  { required: true } ),
			acceptance( 'I agree to receive updates.',            { required: true } ),
		],
	} ),

	'multistep-contact': () => {
		const step1 = createStep( 'Contact Details' );
		step1.description = 'How can we reach you?';
		step1.fields = [
			text(  'Name',  { required: true } ),
			email( 'Email', { required: true } ),
			phone( 'Phone' ),
		];

		const step2 = createStep( 'Your Message' );
		step2.description = 'What can we help you with?';
		step2.fields = [
			text(     'Subject' ),
			textarea( 'Message', { required: true, rows: 4 } ),
		];

		return {
			name:     'Multi-step Contact Form',
			fields:   [ step1, step2 ],
			settings: {
				multistep: { progress_indicator: 'numbers' },
			},
		};
	},
};

/* ---------------------------------------------------------------- */
/* Catalog metadata (UI display)                                    */
/* ---------------------------------------------------------------- */

export const TEMPLATE_CATALOG = [
	{ id: 'blank',             label: 'Blank Form',             description: 'Start from scratch.',                                category: 'Basic',     locked: false },
	{ id: 'contact',           label: 'Contact Form',           description: 'Name, email, phone, message.',                       category: 'Basic',     locked: false },
	{ id: 'appointment',       label: 'Appointment Form',       description: 'Bookings with preferred date and time.',             category: 'Service',   locked: false },
	{ id: 'quote',             label: 'Quote Request',          description: 'Service + budget + project brief.',                  category: 'Sales',     locked: false },
	{ id: 'feedback',          label: 'Feedback',               description: 'Rating and free-text comments.',                     category: 'Customer',  locked: false },
	{ id: 'testimonial',       label: 'Testimonial',            description: 'Customer name, rating, story, consent.',             category: 'Customer',  locked: false },
	{ id: 'newsletter',        label: 'Newsletter Signup',      description: 'Email + opt-in checkbox.',                           category: 'Marketing', locked: false },
	{ id: 'multistep-contact', label: 'Multi-step Contact',     description: 'Two-step contact form with progress indicator.',     category: 'Advanced',  locked: false },

	// Locked future templates - placeholders only.
	{ id: 'quiz',              label: 'Quiz Form',              description: 'Scored questions and answers.',                      category: 'Advanced',  locked: true },
	{ id: 'poll',              label: 'Poll Form',              description: 'Single-question polls with vote tally.',             category: 'Advanced',  locked: true },
	{ id: 'payment',           label: 'Payment Form',           description: 'Collect payments with Stripe / PayPal.',             category: 'Pro',       locked: true,  pro: true },
	{ id: 'ai',                label: 'AI Generated Form',      description: 'Describe what you need and we build it.',            category: 'Pro',       locked: true,  pro: true },
];

/* ---------------------------------------------------------------- */
/* Public API                                                       */
/* ---------------------------------------------------------------- */

/**
 * Build a fresh schema for the given template id. Returns null when
 * the id is unknown or locked.
 *
 * Safe to call repeatedly: every invocation produces unique node ids
 * via newFieldId / newRowId / newColumnId / newStepId.
 */
export function buildTemplate( id ) {
	const meta = TEMPLATE_CATALOG.find( ( t ) => t.id === id );
	if ( ! meta || meta.locked ) return null;
	const fn = builders[ id ];
	if ( typeof fn !== 'function' ) return null;
	return fn();
}

/**
 * Hint at how many fields a template will produce. Used for the tile
 * meta line ("4 fields", "2 steps"). Cheap to compute - just a count
 * of top-level nodes plus, for steps, a sum of their direct field
 * counts. Locked templates get null.
 */
export function templateSummary( id ) {
	const meta = TEMPLATE_CATALOG.find( ( t ) => t.id === id );
	if ( ! meta || meta.locked ) return null;
	const fn = builders[ id ];
	if ( typeof fn !== 'function' ) return null;
	const tpl = fn();
	const fields = Array.isArray( tpl.fields ) ? tpl.fields : [];
	const steps  = fields.filter( ( n ) => n && n.type === 'step' );
	if ( steps.length > 0 ) {
		return { steps: steps.length };
	}
	return { fields: fields.length };
}

// Touch unused helpers so future template variants that need rows
// can pull them straight from this module without re-importing.
export { createRow, newRowId, newColumnId };

/**
 * Friendly label for a stored template id. Returns the label from
 * the catalog when known, falls back to "Custom layout" when the
 * form_schema.meta.template key is missing or unrecognised. Used by
 * the canvas toolbar to surface a "Template: ..." breadcrumb above
 * the field list.
 */
export function templateLabel( id ) {
	const meta = TEMPLATE_CATALOG.find( ( t ) => t.id === id );
	return meta && meta.label ? meta.label : '';
}
