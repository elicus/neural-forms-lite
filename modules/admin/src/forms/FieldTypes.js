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
	{ value: 'quiz_question', label: 'Quiz'          },
	{ value: 'repeater',      label: 'Repeater'      },
	{ value: 'product',       label: 'Priced Item'   },
	{ value: 'calculated',    label: 'Calculated'    },
	{ value: 'file',          label: 'File Upload'   },
	{ value: 'hidden',        label: 'Hidden'        },
	// 1.3.0
	{ value: 'name',          label: 'Name'          },
	{ value: 'address',       label: 'Address'       },
	{ value: 'time',          label: 'Time'          },
	{ value: 'password',      label: 'Password'      },
	{ value: 'signature',     label: 'Signature'     },
	{ value: 'html',          label: 'Content'       },
];

/**
 * 1.3.0 Name / Address parts in display order — mirrors
 * ExtraFields::name_parts() / address_parts(). [ key, default label, required-when-field-required ]
 */
export function extraParts( field ) {
	const s = ( field && field.settings && typeof field.settings === 'object' ) ? field.settings : {};
	let parts = [];
	if ( field && field.type === 'name' ) {
		parts.push( [ 'first', __( 'First name', 'neural-forms-lite' ), true ] );
		if ( s.format === 'first_middle_last' ) parts.push( [ 'middle', __( 'Middle name', 'neural-forms-lite' ), false ] );
		parts.push( [ 'last', __( 'Last name', 'neural-forms-lite' ), true ] );
	} else if ( field && field.type === 'address' ) {
		parts.push( [ 'line1', __( 'Street address', 'neural-forms-lite' ), true ] );
		if ( s.show_line2 !== false ) parts.push( [ 'line2', __( 'Address line 2', 'neural-forms-lite' ), false ] );
		parts.push( [ 'city', __( 'City', 'neural-forms-lite' ), true ] );
		parts.push( [ 'state', __( 'State / Province / Region', 'neural-forms-lite' ), false ] );
		parts.push( [ 'postal', __( 'ZIP / Postal code', 'neural-forms-lite' ), false ] );
		if ( s.show_country !== false ) parts.push( [ 'country', __( 'Country', 'neural-forms-lite' ), true ] );
	}
	const custom = ( s.sublabels && typeof s.sublabels === 'object' ) ? s.sublabels : {};
	return parts.map( ( [ k, l, r ] ) => [ k, ( typeof custom[ k ] === 'string' && custom[ k ].trim() ) ? custom[ k ].trim() : l, r ] );
}

import { __ } from '@wordpress/i18n';
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

	if ( type === 'phone' || type === 'tel' ) {
		// Country picker is always rendered on phone fields — the only
		// setting users can change is the default country. Seed it to
		// US; admins switch via the inspector dropdown.
		base.settings = {
			default_country: 'US',
		};
	}

	if ( type === 'repeater' ) {
		base.label    = 'Repeater';
		base.required = false;
		base.settings = {
			min_items:           1,
			max_items:           5,
			columns:             1,
			add_button_label:    'Add item',
			remove_button_label: 'Remove',
		};
		// fields[] is the child-field TEMPLATE. Each rendered iteration
		// on the frontend stamps these templates with a per-index id
		// prefix. Starts empty so the inspector can guide the user to
		// add their first child field; the full child DnD experience
		// lands in Commit 2.
		base.fields = [];
	}

	if ( type === 'product' ) {
		base.label    = 'Priced Item';
		base.required = false;
		base.pricing  = {
			mode:       'options',
			price:      '',
			display:    'select',
			quantity:   { enabled: false, min: 1, max: 10, default: 1 },
			min_amount: '',
			max_amount: '',
		};
		base.options = [
			{ value: 'option_1', label: 'Option 1', price: '0' },
			{ value: 'option_2', label: 'Option 2', price: '0' },
		];
	}

	if ( type === 'calculated' ) {
		base.label      = 'Total';
		base.required   = false;
		base.expression = '';
		base.format     = 'currency';
		base.decimals   = 2;
	}

	if ( type === 'matrix' ) {
		base.label    = 'Matrix';
		base.required = false;
		base.settings = {
			selection_type: 'radio',
			rows: [
				{ label: 'Row 1', value: 'row_1' },
				{ label: 'Row 2', value: 'row_2' },
				{ label: 'Row 3', value: 'row_3' },
			],
			columns: [
				{ label: 'Option 1', value: 'option_1', score: 1 },
				{ label: 'Option 2', value: 'option_2', score: 2 },
				{ label: 'Option 3', value: 'option_3', score: 3 },
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

	// 1.3.0
	if ( type === 'name' ) {
		base.label    = 'Name';
		base.settings = { format: 'first_last' };
	}
	if ( type === 'address' ) {
		base.label    = 'Address';
		base.settings = { show_line2: true, show_country: true, default_country: '' };
	}
	if ( type === 'password' ) {
		base.label    = 'Password';
		base.settings = { min_length: 8, confirm: false };
	}
	if ( type === 'signature' ) {
		base.label = 'Signature';
	}
	if ( type === 'html' ) {
		base.label   = 'Content';
		base.content = '<p>Add your text here.</p>';
		delete base.required;
		delete base.placeholder;
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
