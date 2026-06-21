/**
 * Form-type detection — schema-driven, walks nested fields the same
 * way SchemaHelper::flatten_fields does on the PHP side.
 *
 * Used by all 4 blocks (neural-forms/form, /quiz, /multi-step, /poll)
 * to filter the form selector to the right subset and to flag
 * incompatible saved selections with a non-fatal warning.
 *
 * Detection rules — first match wins:
 *   quiz_question + poll      → mixed   (treated as quiz for Neural Quiz block)
 *   quiz_question             → quiz
 *   poll                      → poll
 *   step count >= 2           → multistep
 *   otherwise                 → simple
 *
 * NOTE: this is the BLOCK-SELECTOR taxonomy, not the Forms-list badge
 * taxonomy — `FormTypeDetector::detect()` on the PHP side recognises
 * `survey` (rating + open-ended feedback) but has no `multistep`
 * bucket. The two detectors serve different surfaces and intentionally
 * diverge. Walk-the-schema logic (flatten through row/column/step) IS
 * shared in spirit with SchemaHelper::flatten_fields, so adding a new
 * container type here means updating both walkers.
 */

function walkFields( nodes, visit ) {
	if ( ! Array.isArray( nodes ) ) return;
	for ( const node of nodes ) {
		if ( ! node || typeof node !== 'object' ) continue;
		visit( node );
		const t = String( node.type || '' );
		if ( t === 'step' && Array.isArray( node.fields ) ) {
			walkFields( node.fields, visit );
		}
		if ( t === 'row' && Array.isArray( node.columns ) ) {
			for ( const col of node.columns ) {
				if ( col && Array.isArray( col.fields ) ) walkFields( col.fields, visit );
			}
		}
	}
}

export function hasQuiz( schema ) {
	if ( ! schema || typeof schema !== 'object' ) return false;
	let found = false;
	walkFields( schema.fields || [], ( n ) => { if ( n.type === 'quiz_question' ) found = true; } );
	return found;
}

export function hasPoll( schema ) {
	if ( ! schema || typeof schema !== 'object' ) return false;
	let found = false;
	walkFields( schema.fields || [], ( n ) => { if ( n.type === 'poll' ) found = true; } );
	return found;
}

export function stepCount( schema ) {
	if ( ! schema || typeof schema !== 'object' ) return 0;
	const fields = Array.isArray( schema.fields ) ? schema.fields : [];
	// Steps may only appear at the TOP level — match SchemaHelper rule.
	return fields.filter( ( n ) => n && n.type === 'step' ).length;
}

export function hasMultiStep( schema ) {
	return stepCount( schema ) >= 2;
}

export function isSimple( schema ) {
	return ! hasQuiz( schema ) && ! hasPoll( schema ) && ! hasMultiStep( schema );
}

export function detectFormType( schema ) {
	if ( hasQuiz( schema ) && hasPoll( schema ) ) return 'mixed';
	if ( hasQuiz( schema ) )                     return 'quiz';
	if ( hasPoll( schema ) )                     return 'poll';
	if ( hasMultiStep( schema ) )                return 'multistep';
	return 'simple';
}

/**
 * Block-kind → form filter. Mixed forms are routed primarily to the
 * Quiz block (per the brief) but ALSO appear under Poll when the form
 * has poll fields, and under Multi Step when the form has steps.
 */
export function matchesBlockType( form, blockKind ) {
	const schema = form?.form_schema || {};
	switch ( blockKind ) {
		case 'form':       return isSimple( schema );
		case 'quiz':       return hasQuiz( schema );                       // includes mixed
		case 'poll':       return hasPoll( schema );                       // includes mixed
		case 'multistep':  return hasMultiStep( schema );                  // includes quiz/poll multistep
		default:           return true;
	}
}

/** Friendly label for the detected_type badge in the block sidebar. */
export function typeLabel( type ) {
	switch ( type ) {
		case 'quiz':      return 'Quiz';
		case 'poll':      return 'Poll';
		case 'multistep': return 'Multi-step';
		case 'mixed':     return 'Mixed';
		case 'simple':
		default:          return 'Simple';
	}
}
