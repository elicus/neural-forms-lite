/**
 * Templates screen — page wrapper around the shared TemplateLibrary
 * component. All UI / state / fetch / import logic lives in
 * `../templates/TemplateLibrary.js` so the same code powers the
 * Forms-page "Use Template" modal without duplication.
 *
 * BEM root: `.neural-forms-templates` (styles in src/templates.css).
 */

import TemplateLibrary from '../templates/TemplateLibrary';

export default function Templates() {
	return <TemplateLibrary mode="page" />;
}
