/**
 * Coupon codes (1.3.0) — JS mirror of PriceEngine's coupon maths.
 * Runs computePricing() from form-submit.js on the cases written by
 * tests/CouponsTest.php (run that first).
 *
 *   . ~/.nvm/nvm.sh && nvm exec 18 node tests/CouponsTest.js
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const src = fs.readFileSync( path.join( __dirname, '../modules/frontend/assets/js/form-submit.js' ), 'utf8' );
const cases = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures/coupons.client.json' ), 'utf8' ) );
function extract( name ) {
	const m = new RegExp( 'function\\s+' + name + '\\s*\\(' ).exec( src );
	let i = src.indexOf( '{', m.index ), depth = 0;
	for ( ; i < src.length; i++ ) { if ( src[ i ] === '{' ) depth++; else if ( src[ i ] === '}' ) { depth--; if ( depth === 0 ) break; } }
	return src.slice( m.index, i + 1 );
}
const fe = new Function( [ 'evaluateCondition', 'normalizeConditional', 'evaluateConditionTree', 'nfPercentOf', 'nfToMinor', 'nfFormatMinor', 'nfEvalExpression', 'computePricing' ].map( extract ).join( '\n' ) + '\nreturn { computePricing };' )();
let passed = 0, failed = 0;
for ( const c of cases ) {
	const r = fe.computePricing( c.cfg, ( id ) => ( id === 'plan' ? { qty: c.qty } : '' ), () => false );
	const got = { subtotal: r.subtotal, discount: r.discount ? r.discount.amount : 0, coupon: r.coupon ? r.coupon.amount : 0, coupon_error: !! r.coupon_error, total: r.total, due_now: r.due_now };
	if ( JSON.stringify( got ) === JSON.stringify( c.expected ) ) { passed++; console.log( 'PASS  ' + c.name ); }
	else { failed++; console.log( 'FAIL  ' + c.name + '\n       expected ' + JSON.stringify( c.expected ) + '\n       actual   ' + JSON.stringify( got ) ); }
}
console.log( '\nCouponsTest.js: ' + passed + ' passed, ' + failed + ' failed' );
process.exit( failed ? 1 : 0 );
