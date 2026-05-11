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
	{ value: 'matrix',        label: 'Matrix'        },
	{ value: 'poll',          label: 'Poll'          },
	{ value: 'quiz_question', label: 'Quiz Question' },
	{ value: 'file',          label: 'File Upload'   },
	{ value: 'hidden',        label: 'Hidden'        },
];

export const TYPES_THAT_PAIR = FIELD_TYPES
	.filter( ( t ) => t.value !== 'hidden' )
	.map( ( t ) => t.value );

/**
 * Legacy label-derived id generator. Kept exported for back-compat
 * with any external caller that imports it directly, but is NO
 * LONGER USED by `newField()` — that path now routes through
 * `makeFieldId(type, existing)` from schemaHelper.js which uses a
 * monotonic-counter token, guaranteeing uniqueness without relying
 * on a possibly-stale `existingIds` snapshot.
 *
 * The old behaviour ("rating" → "rating_2" → "rating_3") was the
 * source of the duplicate-id save error that this 0.2.0 change
 * fixes: two paths could both miss the second add and both emit
 * `id: "rating"`. See FormValidator's "Duplicate field id" guard.
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

import { makeFieldId } from './schemaHelper';

/** Default skeleton for a newly-added field of a given type. */
export function newField( type, existingIds = [] ) {
	// Routes through the centralised, collision-defended id factory
	// in schemaHelper. existingIds is accepted as an Array OR Set
	// and used purely as a defence — `newFieldId(type)` is already
	// monotonic, so a collision is essentially impossible.
	const id    = makeFieldId( type, existingIds );
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

	if ( type === 'matrix' ) {
		base.label    = 'Matrix';
		base.required = false;
		base.settings = {
			selection_type: 'radio',
			rows: [
				{ value: 'row_1', label: 'Row 1' },
				{ value: 'row_2', label: 'Row 2' },
				{ value: 'row_3', label: 'Row 3' },
			],
			columns: [
				{ value: 'col_1', label: 'Option 1' },
				{ value: 'col_2', label: 'Option 2' },
				{ value: 'col_3', label: 'Option 3' },
			],
		};
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
