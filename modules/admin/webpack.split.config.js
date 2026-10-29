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
	// Restored in 1.1.0: per-screen Entries bundle (sibling to builder).
	// Lets us ship Entries UI changes without rebuilding the rich
	// byte-protected build/index.js. AdminPanel.php enqueues
	// build/entries.js on the entries screen instead of index.js.
	entries:       'src/entries-entry.js',
	// Phase 1 of refactor/remove-protected-admin-bundle: Forms list
	// is the first screen to migrate off the protected bundle. Mounts
	// at #neural-forms-app, ships its own forms.css, and is enqueued
	// by AdminPanel::enqueue_assets() instead of build/index.js when
	// the admin is on the Forms-list screen.
	forms:         'src/forms-entry.js',
	// Phase 2 of refactor/remove-protected-admin-bundle: Stats screen
	// migrates off the protected bundle. Same pattern as forms — own
	// entry point, own stats.css, enqueued by AdminPanel when on
	// ?page=neural-forms-stats.
	stats:         'src/stats-entry.js',
	// Phase 3 of refactor/remove-protected-admin-bundle: Settings
	// screen migrates off the protected bundle. Same pattern as forms
	// and stats — own entry point, own settings.css, enqueued by
	// AdminPanel when on ?page=neural-forms-settings. Section order
	// matches the live rich UI: Cloud AI / Email / Notifications /
	// CAPTCHA / Privacy. The bundle initialises a runtime-compatible
	// `window.neuralForms.slots` shim so Pro's existing SlotFills
	// (SettingsSpamPanel, SettingsSiteProfile) register and render
	// here without any Pro-side change.
	settings:      'src/settings-entry.js',
	// Phase 4 of refactor/remove-protected-admin-bundle: Import (CF7
	// migration) screen migrates off the protected bundle. Three-state
	// React flow (list → preview → result) over the existing
	// `/migration/cf7/*` REST endpoints. Same enqueue pattern as the
	// other split bundles — its handle aliases `neural-forms-admin`.
	import:        'src/import-entry.js',
	// 1.1.0: Pro-locked Integrations admin page (HubSpot, WhatsApp,
	// Mailchimp, Google Forms). Lite ships the page shell + capability
	// snapshot — the page renders a single Pro-upsell card when
	// Capabilities::INTEGRATIONS is false, and a four-tab "Coming soon"
	// layout when Pro flips it true. No provider wiring yet on either
	// side — the capability gates the UX while providers are built.
	integrations:  'src/integrations-entry.js',
	quotes:        'src/quotes-entry.js',
	payments:      'src/payments-entry.js',
	// Gutenberg form-rendering block — registered via block.json,
	// enqueued by frontend/BlockHandler.php as build/block.js.
	// Carved out of the legacy `wp-scripts build` flow so the block
	// stays rebuildable now that the protected admin bundle is gone.
	block:         'src/block-index.js',
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
