/**
 * Shared condition engine (1.2.0) — JS side.
 *
 * Lifts normalizeConditional(), evaluateConditionTree() and
 * evaluateCondition() out of modules/frontend/assets/js/form-submit.js
 * (an IIFE that needs a DOM, so the functions are extracted by brace
 * matching and compiled standalone) and runs tests/fixtures/conditions.json
 * against them — the same fixtures tests/ConditionEngineTest.php runs
 * against the PHP engine. Also checks that the builder-side copy in
 * modules/admin/src/forms/schemaHelper.js normalises identically.
 *
 *   . ~/.nvm/nvm.sh && nvm exec 18 node tests/ConditionEngineTest.js
 */
'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );

const root     = path.resolve( __dirname, '..' );
const frontSrc = fs.readFileSync( path.join( root, 'modules/frontend/assets/js/form-submit.js' ), 'utf8' );
const helpSrc  = fs.readFileSync( path.join( root, 'modules/admin/src/forms/schemaHelper.js' ), 'utf8' );
const fixtures = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures/conditions.json' ), 'utf8' ) );

let passed = 0;
let failed = 0;
function check( label, actual, expected ) {
	const ok = JSON.stringify( actual ) === JSON.stringify( expected );
	if ( ok ) {
		passed++;
		console.log( '\x1b[32mPASS\x1b[0m  ' + label );
	} else {
		failed++;
		console.log( '\x1b[31mFAIL\x1b[0m  ' + label );
		console.log( '       expected: ' + JSON.stringify( expected ) );
		console.log( '       actual:   ' + JSON.stringify( actual ) );
	}
}

/** Extract `function name(...) { ... }` by brace matching. */
function extract( src, name ) {
	const re  = new RegExp( '(?:export\\s+)?function\\s+' + name + '\\s*\\(' );
	const m   = re.exec( src );
	if ( ! m ) throw new Error( 'function ' + name + ' not found' );
	const start = m.index;
	let i = src.indexOf( '{', start );
	let depth = 0;
	for ( ; i < src.length; i++ ) {
		if ( src[ i ] === '{' ) depth++;
		else if ( src[ i ] === '}' ) { depth--; if ( depth === 0 ) break; }
	}
	return src.slice( start, i + 1 ).replace( /^export\s+/, '' );
}

const frontend = new Function(
	extract( frontSrc, 'evaluateCondition' ) + '\n' +
	extract( frontSrc, 'normalizeConditional' ) + '\n' +
	extract( frontSrc, 'evaluateConditionTree' ) + '\n' +
	'return { evaluateCondition, normalizeConditional, evaluateConditionTree };'
)();

const builder = new Function(
	extract( helpSrc, 'normalizeConditional' ) + '\nreturn { normalizeConditional };'
)();

/**
 * Mirror what getFieldValue() returns for a given submitted value:
 * checkbox/acceptance → '1' or '', arrays → joined by ',', missing → ''.
 */
function domValue( data, types, id ) {
	if ( ! Object.prototype.hasOwnProperty.call( data, id ) ) return '';
	const v = data[ id ];
	const t = types[ id ] || '';
	if ( t === 'checkbox' || t === 'acceptance' ) {
		return ( v === 1 || v === '1' || v === true ) ? '1' : '';
	}
	if ( Array.isArray( v ) ) return v.map( String ).join( ',' );
	if ( v === null || v === undefined ) return '';
	return String( v );
}

// ── evaluate fixtures ───────────────────────────────────────────────
for ( const c of fixtures.evaluate ) {
	const tree   = frontend.normalizeConditional( c.logic );
	const getter = ( id ) => domValue( c.data, c.types || {}, id );
	check( 'evaluate: ' + c.name, frontend.evaluateConditionTree( tree, getter, null ), c.expected );
}

// ── resolve fixtures (same fixed-point loop as updateConditionalFields) ─
for ( const c of fixtures.resolve ) {
	const types = {};
	const entries = [];
	for ( const f of c.fields ) {
		types[ f.id ] = f.type;
		const cl = f.conditional_logic;
		if ( cl && cl.enabled ) {
			const tree = frontend.normalizeConditional( cl );
			if ( tree ) entries.push( { id: f.id, tree } );
		}
	}
	const hidden = {};
	const getter = ( id ) => domValue( c.data, types, id );
	const unavailable = ( id ) => !! hidden[ id ];
	let changed, guard = 0;
	do {
		changed = false;
		for ( const e of entries ) {
			if ( hidden[ e.id ] ) continue;
			if ( ! frontend.evaluateConditionTree( e.tree, getter, unavailable ) ) {
				hidden[ e.id ] = true;
				changed = true;
			}
		}
	} while ( changed && ++guard < 50 );
	check( 'resolve: ' + c.name, Object.keys( hidden ).sort(), c.expected_hidden.slice().sort() );
}

// ── normaliser parity: frontend vs builder copy ─────────────────────
for ( const c of fixtures.evaluate ) {
	check( 'normalize parity: ' + c.name, builder.normalizeConditional( c.logic ), frontend.normalizeConditional( c.logic ) );
}
check( 'normalize: legacy → single rule', frontend.normalizeConditional( { enabled: true, field: 'a', operator: 'equals', value: 'x' } ),
	{ action: 'show', match: 'all', rules: [ { field: 'a', operator: 'equals', value: 'x' } ], groups: [] } );
check( 'normalize: no usable rule → null', frontend.normalizeConditional( { enabled: true, rules: [ { field: '' } ] } ), null );
check( 'normalize: numeric value stringified', frontend.normalizeConditional( { field: 'n', operator: 'gte', value: 5 } ).rules[ 0 ].value, '5' );

console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
process.exit( failed > 0 ? 1 : 0 );
