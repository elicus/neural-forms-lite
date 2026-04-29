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
	const base = ( liteUrl || '/wp-json/neural-forms/v1' ).replace( /\/$/, '' );
	return base + ( path.startsWith( '/' ) ? path : '/' + path );
}

export default function request( path, options = {} ) {
	return apiFetch( {
		path: resolvePath( path ),
		...options,
	} );
}
