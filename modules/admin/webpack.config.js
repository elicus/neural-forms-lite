/**
 * Webpack config for the Neural Forms Lite admin bundle.
 *
 * Extends @wordpress/scripts's default config to declare TWO entry points:
 *
 *   index.js - the React admin SPA (Forms / Entries / Stats / Settings)
 *   block.js - the Gutenberg block ("neural-forms/form")
 *
 * Both emit a paired index.asset.php / block.asset.php via the
 * DependencyExtractionWebpackPlugin so AdminPanel.php and BlockHandler.php
 * can read the WordPress dependency list + version hash without having to
 * hardcode them.
 *
 * Output: modules/admin/build/{index,block}.{js,css}
 *
 * Source layout expected:
 *   src/index.js         - admin SPA entry (mounts into #neural-forms-app)
 *   src/block-index.js   - block entry (renamed to "block" via the entries map below)
 *   src/index.css        - admin styles (BEM under #neural-forms-app)
 *
 * Run `npm run build` from this directory or `npm run build` from the
 * plugin root (which proxies via the workspace shortcut).
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path           = require( 'path' );

module.exports = {
	...defaultConfig,

	entry: {
		index: path.resolve( __dirname, 'src/index.js' ),
		block: path.resolve( __dirname, 'src/block-index.js' ),
	},

	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		filename: '[name].js',
	},
};
