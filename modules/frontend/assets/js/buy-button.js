/* Neural Forms — Buy button (1.2.0 part B). Updates the live total and
 * posts the quick purchase to /forms/{id}/buy, then follows the checkout
 * redirect. No dependencies. */
( function () {
	'use strict';
	function fmt( minor, cur, exp ) {
		var sym = { USD: '$', EUR: '€', GBP: '£', INR: '₹', AUD: 'A$', CAD: 'C$', NZD: 'NZ$', SGD: 'S$', JPY: '¥', ZAR: 'R', BRL: 'R$', MXN: 'MX$' }[ cur ] || ( cur + ' ' );
		var abs = Math.abs( minor ), p = Math.pow( 10, exp ), whole = Math.floor( abs / p ), frac = String( abs % p );
		while ( frac.length < exp ) { frac = '0' + frac; }
		return ( minor < 0 ? '-' : '' ) + sym + String( whole ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' ) + ( exp ? '.' + frac : '' );
	}
	function init( form ) {
		if ( form.__nfBuy ) { return; }
		form.__nfBuy = true;
		var unit = parseInt( form.getAttribute( 'data-unit' ), 10 ) || 0;
		var cur = form.getAttribute( 'data-currency' ) || 'USD';
		var exp = parseInt( form.getAttribute( 'data-exponent' ), 10 );
		if ( isNaN( exp ) ) { exp = 2; }
		var qtyInput = form.querySelector( 'input[name="qty"]' );
		var total = form.querySelector( '[data-buy-total]' );
		var msg = form.querySelector( '.neural-forms-buy__msg' );
		var btn = form.querySelector( '.neural-forms-buy__btn' );
		function qty() {
			var v = parseInt( qtyInput ? qtyInput.value : '1', 10 );
			if ( isNaN( v ) || v < 1 ) { v = 1; }
			if ( qtyInput && qtyInput.max && v > parseInt( qtyInput.max, 10 ) ) { v = parseInt( qtyInput.max, 10 ); }
			return v;
		}
		function refresh() { if ( total ) { total.textContent = '· ' + fmt( unit * qty(), cur, exp ); } }
		if ( qtyInput ) { qtyInput.addEventListener( 'input', refresh ); qtyInput.addEventListener( 'change', refresh ); }
		refresh();
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			if ( form.classList.contains( 'is-busy' ) ) { return; }
			var email = form.querySelector( 'input[name="email"]' );
			if ( email && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email.value.trim() ) ) {
				msg.textContent = form.getAttribute( 'data-msg-email' ) || 'Please enter a valid email address.';
				msg.className = 'neural-forms-buy__msg neural-forms-form__global-error';
				email.focus();
				return;
			}
			var payload = {};
			// textarea: reCAPTCHA injects its token as <textarea name="g-recaptcha-response">.
			Array.prototype.forEach.call( form.querySelectorAll( 'input, textarea' ), function ( i ) { if ( i.name ) { payload[ i.name ] = i.value; } } );
			payload.qty = qty();
			form.classList.add( 'is-busy' );
			btn.disabled = true;
			msg.textContent = '';
			msg.className = 'neural-forms-buy__msg';
			fetch( form.getAttribute( 'data-endpoint' ), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify( payload ) } )
				.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, json: j }; } ); } )
				.then( function ( res ) {
					if ( res.ok && res.json && res.json.outcome && res.json.outcome.mode === 'redirect' && res.json.outcome.url ) {
						msg.textContent = form.getAttribute( 'data-msg-redirect' ) || 'Taking you to the secure checkout…';
						if ( /^(https?:\/\/|\/)/i.test( String( res.json.outcome.url ) ) ) {
							window.location.assign( res.json.outcome.url );
						}
						return;
					}
					var text = ( res.json && ( res.json.payment_error || res.json.message ) ) || ( res.json && res.json.message ) || 'Something went wrong. Please try again.';
					msg.textContent = text;
					msg.className = 'neural-forms-buy__msg ' + ( res.ok ? 'neural-forms-form__success' : 'neural-forms-form__global-error' );
					form.classList.remove( 'is-busy' );
					btn.disabled = false;
				} )
				.catch( function () {
					msg.textContent = 'Network error. Please try again.';
					msg.className = 'neural-forms-buy__msg neural-forms-form__global-error';
					form.classList.remove( 'is-busy' );
					btn.disabled = false;
				} );
		} );
	}
	function boot() { Array.prototype.forEach.call( document.querySelectorAll( 'form[data-neural-forms-buy]' ), init ); }
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', boot ); } else { boot(); }
	document.addEventListener( 'neuralforms:buy:refresh', boot );
}() );
