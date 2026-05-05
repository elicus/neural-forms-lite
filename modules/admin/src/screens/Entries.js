/**
 * Compatibility shim — the rich Entries screen now lives in
 * src/entries/EntriesScreen.js. This file is kept so the legacy
 * Router.js (which imports from ./screens/Entries) keeps building
 * if anyone runs the deprecated full bundle. The split entries
 * bundle (build:entries) imports the new module directly.
 */

export { default } from '../entries/EntriesScreen';
