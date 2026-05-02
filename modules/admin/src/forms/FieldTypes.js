/**
 * Field-type catalog mirrored from PHP FieldRegistry.
 *
 * Single source of truth on the JS side for the type picker dropdown
 * and the type '->-> default-config mapping when a new field is added.
 */

export const FIELD_TYPES = [
	{ value: 'text',       label: 'Text'        },
	{ value: 'email',      label: 'Email'       },
	{ value: 'textarea',   label: 'Textarea'    },
	{ value: 'select',     label: 'Select'      },
	{ value: 'checkbox',   label: 'Checkbox'    },
	{ value: 'number',     label: 'Number'      },
	{ value: 'phone',      label: 'Phone'       },
	{ value: 'url',        label: 'URL'         },
	{ value: 'date',       label: 'Date'        },
	{ value: 'acceptance', label: 'Acceptance'  },
	{ value: 'radio',      label: 'Radio'       },
	{ value: 'checkboxes', label: 'Checkboxes'  },
	{ value: 'range',      label: 'Range'       },
	{ value: 'rating',        label: 'Rating'        },
	{ value: 'poll',          label: 'Poll'          },
	{ value: 'quiz_question', label: 'Quiz Question' },
	{ value: 'file',          label: 'File Upload'   },
	{ value: 'hidden',        label: 'Hidden'        },
];

export const TYPES_THAT_PAIR = FIELD_TYPES
	.filter( ( t ) => t.value !== 'hidden' )
	.map( ( t ) => t.value );

/**
 * Generate a unique-ish field id from a type. The form builder appends
 * a numeric suffix when the chosen id collides with an existing field
 * (FormValidator enforces uniqueness on save, so this is just a UX
 * convenience that avoids the 422).
 */
export function defaultIdForType( type, existingIds = [] ) {
	const base = type === 'tel' ? 'phone' : type;
	let candidate = base;
	let n = 2;
	while ( existingIds.includes( candidate ) ) {
		candidate = `${ base }_${ n }`;
		n += 1;
	}
	return candidate;
}

/** Default skeleton for a newly-added field of a given type. */
export function newField( type, existingIds = [] ) {
	const id    = defaultIdForType( type, existingIds );
	const label = type[ 0 ].toUpperCase() + type.slice( 1 );

	const base = {
		id,
		type,
		label,
		placeholder: '',
		required: false,
		width: 'full',
	};

	if ( type === 'select' || type === 'radio' || type === 'checkboxes' ) {
		base.options = [
			{ value: 'option_1', label: 'Option 1' },
			{ value: 'option_2', label: 'Option 2' },
		];
	}

	if ( type === 'textarea' ) {
		base.rows = 3;
	}

	if ( type === 'rating' ) {
		base.stars = 5;
	}

	if ( type === 'poll' ) {
		base.label    = 'Poll';
		base.required = false;
		base.options  = [
			{ value: 'option_1', label: 'Option 1' },
			{ value: 'option_2', label: 'Option 2' },
		];
		base.settings = {
			results_visibility: 'after_vote',
			chart_type:         'bar',
			allow_multiple:     false,
			randomize_options:  false,
		};
	}

	if ( type === 'quiz_question' ) {
		base.label    = 'Quiz Question';
		base.required = true;
		base.options  = [
			{ value: 'option_1', label: 'Option 1', is_correct: false },
			{ value: 'option_2', label: 'Option 2', is_correct: true  },
		];
		base.settings = {
			points:              1,
			explanation:         '',
			show_correct_answer: 'after_submit',
			randomize_options:   false,
		};
	}

	return base;
}
