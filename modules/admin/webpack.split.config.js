/**
 * Per-screen webpack config.
 *
 * Builds a SINGLE screen bundle (e.g. builder.js) without touching any
 * other build artefact in modules/admin/build/.
 *
 * Usage (npm scripts in package.json):
 *   wp-scripts build --config webpack.split.config.js --env screen=builder
 *
 * The default `webpack.config.js` always emits index.js + block.js in
 * one shot - rebuilding from a partial src/ tree would replace the
 * rich 1.0.0 build/index.js with a regressed copy. This split config
 * sidesteps that by:
 *   1. Declaring exactly ONE entry per build (driven by --env screen=).
 *   2. Removing the CleanWebpackPlugin from the wp-scripts default
 *      plugin list, so the output dir is preserved and only the
 *      bundle's own files are written.
 *   3. Leaving DependencyExtractionWebpackPlugin in place so each
 *      bundle still gets its own *.asset.php manifest.
 *
 * Add a screen here, expose it as a build:NAME npm script, and you
 * get an independently-shippable per-screen bundle. Today only
 * `builder` is wired up; forms/entries/settings/stats/admin-shell can
 * be added when their source is ready - listing them upfront would
 * tempt callers into building (and shipping) a regressed bundle.
 */

const path           = require( 'path' );
const defaultConfig  = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );

const SCREENS = {
	builder:       'src/builder-entry.js',
	results:       'src/results-entry.js',
	// Editor entry for the 3 extra Gutenberg blocks added in 0.2.0
	// (quiz / multi-step / poll). Sibling to the pre-existing
	// build/block.js (rich form-block bundle) — see blocks-extra-entry.js
	// for why they're separate.
	'blocks-extra': 'src/blocks-extra-entry.js',
	// Future screens (do NOT enable until their source is restored):
	//   forms:        'src/forms-entry.js',
	//   entries:      'src/entries-entry.js',
	//   settings:     'src/settings-entry.js',
	//   stats:        'src/stats-entry.js',
	//   'admin-shell': 'src/admin-shell-entry.js',
};

module.exports = ( env = {} ) => {
	const screen = String( env.screen || '' ).trim();

	if ( ! screen ) {
		throw new Error(
			'webpack.split.config.js: pass --env screen=<name>. ' +
			'Available: ' + Object.keys( SCREENS ).join( ', ' )
		);
	}

	const entryFile = SCREENS[ screen ];
	if ( ! entryFile ) {
		throw new Error(
			`webpack.split.config.js: unknown screen "${ screen }". ` +
			'Available: ' + Object.keys( SCREENS ).join( ', ' )
		);
	}

	// CleanWebpackPlugin's default cleanOnceBeforeBuildPatterns is
	// '**/*' - it would wipe build/index.js etc. on every per-screen
	// build. Drop it; webpack still overwrites the files it does emit.
	const plugins = ( defaultConfig.plugins || [] ).filter(
		( p ) => ! ( p instanceof CleanWebpackPlugin )
	);

	return {
		...defaultConfig,
		plugins,
		entry: {
			[ screen ]: path.resolve( __dirname, entryFile ),
		},
		output: {
			...defaultConfig.output,
			path: path.resolve( __dirname, 'build' ),
			filename: '[name].js',
			// Belt-and-suspenders: even if a future webpack version
			// adds output.clean by default, force it off here so this
			// config can never wipe sibling bundles.
			clean: false,
		},
	};
};
