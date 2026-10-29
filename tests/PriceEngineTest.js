/**
 * Pricing mirror (1.2.0, part B) — JS side of tests/fixtures/pricing.json.
 *
 * Lifts computePricing() and its helpers out of form-submit.js and runs
 * every fixture with the client config PriceEngine::client_config()
 * emitted into tests/fixtures/pricing.client.json (written by
 * tests/PriceEngineTest.php — run that first).
 *
 *   . ~/.nvm/nvm.sh && nvm exec 18 node tests/PriceEngineTest.js
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const root = path.resolve( __dirname, '..' );
const src  = fs.readFileSync( path.join( root, 'modules/frontend/assets/js/form-submit.js' ), 'utf8' );
const fixtures = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures/pricing.json' ), 'utf8' ) );
const client   = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures/pricing.client.json' ), 'utf8' ) );

let passed = 0, failed = 0;
function check( label, actual, expected ) {
	if ( JSON.stringify( actual ) === JSON.stringify( expected ) ) { passed++; console.log( '\x1b[32mPASS\x1b[0m  ' + label ); }
	else { failed++; console.log( '\x1b[31mFAIL\x1b[0m  ' + label ); console.log( '       expected: ' + JSON.stringify( expected ) ); console.log( '       actual:   ' + JSON.stringify( actual ) ); }
}
function extract( name ) {
	const m = new RegExp( 'function\\s+' + name + '\\s*\\(' ).exec( src );
	if ( ! m ) throw new Error( name + ' not found' );
	let i = src.indexOf( '{', m.index ), depth = 0;
	for ( ; i < src.length; i++ ) { if ( src[ i ] === '{' ) depth++; else if ( src[ i ] === '}' ) { depth--; if ( depth === 0 ) break; } }
	return src.slice( m.index, i + 1 );
}
const fe = new Function( [ 'evaluateCondition', 'normalizeConditional', 'evaluateConditionTree', 'nfPercentOf', 'nfToMinor', 'nfFormatMinor', 'nfEvalExpression', 'computePricing' ].map( extract ).join( '\n' ) + '\nreturn { computePricing, nfEvalExpression, nfToMinor, nfPercentOf, nfFormatMinor };' )();

// ── helpers ──────────────────────────────────────────────────────
check( 'toMinor 1,250.50 @2', fe.nfToMinor( '1,250.50', 2 ), 125050 );
check( 'toMinor 12.345 @2 half-up', fe.nfToMinor( '12.345', 2 ), 1235 );
check( 'toMinor 1005 @0', fe.nfToMinor( '1005', 0 ), 1005 );
check( 'toMinor garbage', fe.nfToMinor( 'ten', 2 ), null );
check( 'percentOf 1005 × 12.5%', fe.nfPercentOf( 1005, 1250 ), 126 );
check( 'format USD', fe.nfFormatMinor( 125050, { exponent: 2, symbol: '$' } ), '$1,250.50' );
check( 'expr precedence', fe.nfEvalExpression( '1 + 2 * 3', () => 0 ), 7 );
check( 'expr if/round', fe.nfEvalExpression( 'round(if({a} > 10, 15, 20) * {a} / 7, 2)', ( id ) => ( { a: 12 }[ id ] ) ), 25.71 );
check( 'expr div by zero → null', fe.nfEvalExpression( '{a} / 0', () => 1 ), null );
check( 'expr bad → null', fe.nfEvalExpression( 'foo(1)', () => 1 ), null );

// ── fixtures through the client config ───────────────────────────
for ( const c of fixtures.cases ) {
	const cfg = ( client.find( ( x ) => x.name === c.name ) || {} ).config;
	if ( ! cfg ) { check( 'client config present: ' + c.name, false, true ); continue; }
	const hidden = new Set( c.hidden || [] );
	const res = fe.computePricing( cfg, ( id ) => c.data[ id ], ( id ) => hidden.has( id ) );
	const calc = {};
	for ( const [ id, v ] of Object.entries( res.calculated ) ) calc[ id ] = v.value;
	const actual = { subtotal: res.subtotal, discount: res.discount ? res.discount.amount : 0, total: res.total, due_now: res.due_now, remaining: res.remaining, lines: res.line_items.length, calculated: calc };
	const expected = { ...c.expected };
	check( 'pricing: ' + c.name, actual, expected );
}
console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
process.exit( failed > 0 ? 1 : 0 );
