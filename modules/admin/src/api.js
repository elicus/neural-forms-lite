/**
 * Thin wrapper around @wordpress/api-fetch.
 *
 * Pre-configures the REST nonce and the Lite namespace so callers can use
 * short-form paths like '/forms' instead of repeating '/neural-forms/v1/...'
 * everywhere.
 *
 * For Pro / cross-namespace calls, pass an absolute path that already
 * includes its namespace (e.g. '/neural-forms-pro/v1/site-profile') -
 * the rootURLMiddleware passes those through unmodified.
 */

import apiFetch from '@wordpress/api-fetch';

const data    = window.neuralFormsAdmin || {};
const nonce   = data.api?.nonce ?? '';
const liteUrl = data.api?.url   ?? '';
const rootUrl = data.api?.root  ?? '';

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

if ( rootUrl ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( rootUrl ) );
}

/**
 * Resolve a path against the Lite namespace unless it already starts with
 * '/neural-forms-pro/' or '/wp/' or another absolute namespace prefix.
 *
 * Returns a RELATIVE path (e.g. '/neural-forms/v1/forms'). apiFetch's
 * createRootURLMiddleware prepends the REST root to produce the final
 * URL. Returning an absolute URL here would collide with the middleware
 * and produce a double-prefixed URL that yields rest_no_route.
 */
function resolvePath( path ) {
	if ( typeof path !== 'string' || path === '' ) {
		return path;
	}
	if ( path.startsWith( 'http' ) || path.startsWith( '/wp-json' ) ) {
		return path;
	}
	if ( path.startsWith( '/neural-forms/' ) || path.startsWith( '/neural-forms-pro/' ) || path.startsWith( '/wp/' ) ) {
		return path;
	}
	// liteUrl is intentionally NOT used as a prefix - it's the full
	// absolute URL (rest_url(NAMESPACE)) which would double-prefix once
	// the rootURL middleware adds rest_url() on top. We only need the
	// namespace fragment here.
	return '/neural-forms/v1' + ( path.startsWith( '/' ) ? path : '/' + path );
}

export default function request( path, options = {} ) {
	return apiFetch( {
		path: resolvePath( path ),
		...options,
	} );
}
