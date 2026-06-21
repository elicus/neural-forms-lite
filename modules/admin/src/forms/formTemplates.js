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

/**
 * Canonical Rating factory — mirrors FieldTypes.js newField('rating')
 * which only sets `stars: 5` on top of the shared base. Templates
 * MUST use this rather than fake-rendering a select with "5 Stars /
 * 4 Stars / …" — that path was deprecated when the dedicated
 * RatingField component shipped.
 */
const rating = ( label = 'Rating', opts = {} ) => ( {
	id:       fieldIdFromLabel( label, 'rating' ),
	type:     'rating',
	label,
	required: !! opts.required,
	width:    opts.width || 'full',
	stars:    opts.stars || 5,
} );

/**
 * Canonical Poll factory — matches FieldTypes.js newField('poll')
 * exactly so PollField + PollResultsPanel render the template-
 * generated form identically to one built field-by-field in the UI.
 *
 * Default settings are the same Lite defaults that newField() ships:
 *   results_visibility: 'after_vote'
 *   chart_type: 'bar'
 *   allow_multiple: false
 *   randomize_options: false
 */
const poll = ( label, options, opts = {} ) => ( {
	id:       fieldIdFromLabel( label, 'poll' ),
	type:     'poll',
	label,
	required: opts.required !== undefined ? !! opts.required : false,
	width:    opts.width || 'full',
	options:  options.map( ( o ) =>
		typeof o === 'string'
			? { value: o.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ), label: o }
			: o
	),
	settings: {
		results_visibility: opts.results_visibility ?? 'after_vote',
		chart_type:         opts.chart_type         ?? 'bar',
		allow_multiple:     !! opts.allow_multiple,
		randomize_options:  !! opts.randomize_options,
	},
} );

/**
 * Canonical Quiz Question factory. Each option is `{value, label,
 * is_correct}`; exactly one option must carry is_correct=true per
 * the Phase-3 frozen quiz schema. Template authors flag the correct
 * option by passing { correct: '<value-or-label>' } in opts; the
 * builder resolves that to is_correct on the matching option.
 */
const quizQuestion = ( label, options, opts = {} ) => {
	const built = options.map( ( o ) => {
		const obj = typeof o === 'string'
			? { value: o.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ), label: o }
			: { ...o };
		obj.is_correct = false;
		return obj;
	} );
	const correctMatch = String( opts.correct ?? '' ).toLowerCase();
	let any_correct = false;
	for ( const o of built ) {
		if (
			o.value.toLowerCase() === correctMatch ||
			String( o.label ).toLowerCase() === correctMatch
		) {
			o.is_correct = true;
			any_correct = true;
			break;
		}
	}
	// Defensive: if the caller didn't flag a correct answer (typo or
	// missing `correct`), fall back to the first option so the schema
	// stays valid — exactly one is_correct=true per question.
	if ( ! any_correct && built.length > 0 ) {
		built[ 0 ].is_correct = true;
	}
	return {
		id:       fieldIdFromLabel( label, 'quizquestion' ),
		type:     'quiz_question',
		label,
		required: opts.required !== undefined ? !! opts.required : true,
		width:    opts.width || 'full',
		options:  built,
		settings: {
			points:              opts.points              ?? 1,
			explanation:         opts.explanation         ?? '',
			show_correct_answer: opts.show_correct_answer ?? 'after_submit',
			randomize_options:   !! opts.randomize_options,
		},
	};
};

const radio = ( label, options, opts = {} ) => ( {
	id:       fieldIdFromLabel( label, 'radio' ),
	type:     'radio',
	label,
	required: !! opts.required,
	width:    opts.width || 'full',
	options:  options.map( ( o ) =>
		typeof o === 'string'
			? { value: o.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ), label: o }
			: o
	),
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
		// Updated from the deprecated `select` "5 Stars / 4 Stars …"
		// pattern to the canonical RatingField. Other fields kept
		// the same so existing users picking Feedback get the same
		// data-shape they expect.
		name:   'Feedback',
		fields: [
			text(     'Name' ),
			email(    'Email' ),
			rating(   'Overall rating', { required: true } ),
			textarea( 'What did you like?', { rows: 3 } ),
			textarea( 'What can we improve?', { required: true, rows: 4 } ),
		],
	} ),

	testimonial: () => ( {
		name:   'Testimonial',
		fields: [
			text(       'Name',                                       { required: true } ),
			email(      'Email' ),
			text(       'Company / Website' ),
			rating(     'Rating' ),
			textarea(   'Testimonial',                                { required: true, rows: 4 } ),
			acceptance( 'I agree to share this testimonial publicly.' ),
		],
	} ),

	survey: () => ( {
		name:   'Survey',
		fields: [
			text(     'Name' ),
			email(    'Email' ),
			rating(   'Overall experience', { required: true } ),
			radio(    'How did you hear about us?',
				[ 'Search engine', 'Social media', 'Friend or colleague', 'Advertisement', 'Other' ]
			),
			textarea( 'Any suggestions?', { rows: 4 } ),
		],
	} ),

	poll: () => ( {
		// Single-question poll — keeps it deliberately minimal so
		// the Poll smart folder + analytics show a clean signal.
		// Template authors who need lead capture can add an email
		// field after applying the template.
		name:   'Poll',
		fields: [
			poll(
				'Which feature should we build next?',
				[ 'More templates', 'Better analytics', 'AI form generation' ],
				{ required: true }
			),
		],
	} ),

	quiz: () => ( {
		// Two-question scored quiz using the Phase-3 frozen quiz
		// schema. Form-level settings.quiz drives pass/fail logic
		// + tier-bucketed result messages.
		name:   'Quiz',
		fields: [
			quizQuestion(
				'What is the capital of India?',
				[ 'Mumbai', 'New Delhi', 'Jaipur' ],
				{ correct: 'New Delhi', explanation: 'New Delhi is the capital of India.', points: 1 }
			),
			quizQuestion(
				'Which planet is known as the Red Planet?',
				[ 'Earth', 'Mars', 'Venus' ],
				{ correct: 'Mars', explanation: 'Mars appears red because of iron-oxide dust on its surface.', points: 1 }
			),
		],
		settings: {
			quiz: {
				show_pass_fail: true,
				passing_score:  70,
				result_messages: [
					{ min: 0,  max: 49,  message: 'Keep practicing.' },
					{ min: 50, max: 79,  message: 'Good attempt.'    },
					{ min: 80, max: 100, message: 'Excellent!'       },
				],
			},
		},
	} ),

	'multistep-appointment': () => {
		const step1 = createStep( 'Contact Details' );
		step1.description = 'How can we reach you?';
		step1.fields = [
			text(  'Full Name', { required: true } ),
			email( 'Email',     { required: true } ),
			phone( 'Phone',     { required: true } ),
		];

		const step2 = createStep( 'Appointment Details' );
		step2.description = 'When would you like to come in?';
		step2.fields = [
			date(     'Preferred Date',  { required: true } ),
			select(   'Service Needed',
				[ 'Consultation', 'Treatment', 'Follow-up', 'Other' ],
				{ required: true }
			),
			textarea( 'Notes for the team', { rows: 3 } ),
		];

		return {
			name:     'Multi-step Appointment Form',
			fields:   [ step1, step2 ],
			settings: {
				multistep: { progress_indicator: 'numbers' },
			},
		};
	},

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
	{ id: 'blank',                  label: 'Blank Form',                description: 'Start from scratch.',                                            category: 'Basic',       locked: false },
	{ id: 'contact',                label: 'Contact Form',              description: 'Name, email, phone, message.',                                   category: 'Basic',       locked: false },
	{ id: 'appointment',            label: 'Appointment Form',          description: 'Bookings with preferred date and time.',                         category: 'Service',     locked: false },
	{ id: 'quote',                  label: 'Quote Request',             description: 'Service + budget + project brief.',                              category: 'Sales',       locked: false },
	{ id: 'feedback',               label: 'Feedback',                  description: 'Star rating and free-text comments.',                            category: 'Customer',    locked: false },
	{ id: 'testimonial',            label: 'Testimonial',               description: 'Customer name, rating, story, consent.',                         category: 'Customer',    locked: false },
	{ id: 'newsletter',             label: 'Newsletter Signup',         description: 'Email + opt-in checkbox.',                                       category: 'Marketing',   locked: false },
	{ id: 'survey',                 label: 'Survey',                    description: 'Rating + source + open-ended feedback.',                         category: 'Engagement',  locked: false },
	{ id: 'poll',                   label: 'Poll Form',                 description: 'Single-question poll with live results.',                        category: 'Interactive', locked: false },
	{ id: 'quiz',                   label: 'Quiz Form',                 description: 'Auto-scored questions with pass / fail messaging.',              category: 'Interactive', locked: false },
	{ id: 'multistep-contact',      label: 'Multi-step Contact',        description: 'Two-step contact form with progress indicator.',                 category: 'Multi-step',  locked: false },
	{ id: 'multistep-appointment',  label: 'Multi-step Appointment',    description: 'Two-step appointment booking with contact + service + date.',    category: 'Multi-step',  locked: false },

	// Future templates — still locked. Payment is genuinely upcoming;
	// AI generated forms are now available via the "Create Form with
	// AI" entry point on the Forms list (Pro), so we leave the AI
	// catalog entry locked as a discovery hint and let the FormType
	// chooser footer phrase it accurately.
	{ id: 'payment',                label: 'Payment Form',              description: 'Collect payments with Stripe / PayPal.',                         category: 'Pro',         locked: true,  pro: true },
	{ id: 'ai',                     label: 'AI Generated Form',         description: 'Describe what you need and Neural Forms drafts it for you.',     category: 'Pro',         locked: true,  pro: true, available: 'pro-modal' },
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
