=== Neural Forms Lite ===
Contributors: elicus
Tags: contact form, ai forms, lead inbox, gutenberg form, spam protection
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI contact form plugin with a built-in lead inbox — auto-summarizes submissions, drafts replies, and handles spam.

== Description ==

Neural Forms Lite is a modern contact form plugin that turns your WordPress site into an AI-powered lead management system. Build forms visually, receive submissions in a clean lead inbox, and let AI summarize each lead, assign priority, and draft a reply — all from your WordPress dashboard.

### [View Demo](https://neuralforms.ai "View Demo")

= Why Neural Forms? =

Most form plugins stop at collecting submissions. Neural Forms starts there. Every submission is automatically analyzed by AI, categorized by intent, scored by priority, and paired with a draft reply you can send with one click. It is the difference between a form plugin and a lead management system.

= What You Get =

✅ **Visual Form Builder** — Drag-and-drop fields, live preview, and instant publishing via shortcode or Gutenberg block.
✅ **16 Field Types** — Text, email, textarea, phone, URL, number, date, select, radio, checkbox, checkboxes, file upload, hidden, range, acceptance, and more.
✅ **Lead Inbox** — View, search, filter, star, and triage submissions with status management. Keyboard shortcuts for power users.
✅ **AI Auto-Analysis** — Every submission gets an AI-generated summary, category, priority score, and draft reply.
✅ **Reply From Your Inbox** — Send replies directly from the entry detail view. Every reply is logged in a message thread.
✅ **CSV Export** — Export entries per form with date-range filtering.
✅ **Spam Protection** — Cloudflare Turnstile and Google reCAPTCHA v2 built in. Choose your provider.
✅ **Email Notifications** — Configurable admin alerts and customer auto-responder emails.
✅ **Privacy Mode** — Toggle IP and user-agent storage off with one click. GDPR-friendly.
✅ **Contact Form 7 Import** — Detect and migrate your existing CF7 forms in one click.
✅ **Full Block Editor Support** — Native Gutenberg block with 150+ styling attributes including per-corner radii, gradients, hover states, font controls, button transitions, and success/error message styling.
✅ **Developer Friendly** — Action hooks, filter hooks, capability gates, and a React SlotFill registry for extensions.

= AI That Actually Helps =

Neural Forms uses the Neural Forms Cloud service to analyze submissions. When a visitor fills out your form:

1. The submission text is securely sent to the Neural Forms Cloud API.
2. AI reads the message and generates a summary, category (inquiry, support, sales, etc.), priority (low, medium, high, urgent), and a relevance score (0-100).
3. A draft reply is written based on the context of your form and the visitor's message.
4. Everything appears on the entry detail screen — ready for you to review, edit, and send.

The AI features require a Neural Forms Cloud API key, which you can get for free at [neuralforms.ai](https://neuralforms.ai).

= Perfect For =

Freelancers and agencies managing client inquiries
Small businesses handling contact and support forms
SaaS companies triaging inbound leads
Bloggers and creators collecting feedback
WooCommerce stores managing pre-sale questions
Anyone tired of reading raw form submissions

= How It Works =

1. **Install and Activate** — Install Neural Forms Lite from your WordPress dashboard.
2. **Create a Form** — Use the visual builder to add fields and configure settings.
3. **Embed Anywhere** — Use the `[neural-forms id="42"]` shortcode or the Gutenberg block.
4. **Connect AI (Optional)** — Enter your free Neural Forms Cloud API key in Settings.
5. **Manage Leads** — View submissions in the inbox, review AI analysis, send replies.

No configuration headaches. No third-party accounts required for basic form functionality.

= Neural Forms Pro =

Looking for more? [Neural Forms Pro](https://neuralforms.ai/pricing) adds Divi 5 Visual Builder integration, AI spam detection, advanced AI models, and more. Visit [neuralforms.ai](https://neuralforms.ai) for details.

= Documentation =

Full documentation is available at [wpmozo.com/documentation/neural-forms](https://wpmozo.com/documentation/neural-forms/).

= External Services =

This plugin optionally connects to external third-party services. No connections are made unless you explicitly enable and configure them.

**Neural Forms Cloud**

When you enable AI features and enter a Neural Forms Cloud API key in Settings, the plugin sends form submission content (the text your visitors enter) and form field metadata to the Neural Forms Cloud API for AI analysis. This includes generating submission summaries, categories, priority scores, and draft replies. No data is sent unless you have enabled Cloud AI and provided an API key. The plugin never sends IP addresses, WordPress admin credentials, or WordPress core data to this service.

* Service URL: [https://api.neuralforms.ai](https://api.neuralforms.ai)
* Service website: [https://neuralforms.ai](https://neuralforms.ai)
* Terms of Service: [https://neuralforms.ai/term-and-conditions](https://neuralforms.ai/term-and-conditions)
* Privacy Policy: [https://neuralforms.ai/privacy-policy](https://neuralforms.ai/privacy-policy)

**Cloudflare Turnstile**

When you select Cloudflare Turnstile as your CAPTCHA provider and configure your site and secret keys, the plugin loads the Turnstile challenge script on pages with forms and verifies visitor responses server-side via the Cloudflare API. This service is only used when Turnstile is selected as the CAPTCHA provider in Settings.

* Service website: [https://www.cloudflare.com/products/turnstile/](https://www.cloudflare.com/products/turnstile/)
* Terms of Service: [https://www.cloudflare.com/website-terms/](https://www.cloudflare.com/website-terms/)
* Privacy Policy: [https://www.cloudflare.com/privacypolicy/](https://www.cloudflare.com/privacypolicy/)

**Google reCAPTCHA v2**

When you select Google reCAPTCHA v2 as your CAPTCHA provider and configure your site and secret keys, the plugin loads the reCAPTCHA challenge script on pages with forms and verifies visitor responses server-side via the Google API. This service is only used when reCAPTCHA v2 is selected as the CAPTCHA provider in Settings.

* Service website: [https://www.google.com/recaptcha/](https://www.google.com/recaptcha/)
* Terms of Service: [https://policies.google.com/terms](https://policies.google.com/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

== Source Code and Build Instructions ==

Neural Forms ships pre-built JavaScript and CSS files in `modules/admin/build/` so the plugin works on a clean WordPress install without a Node toolchain. The compiled/generated files are:

* `modules/admin/build/index.js` — admin React SPA (Forms, Entries, Stats, Settings)
* `modules/admin/build/index.css` and `modules/admin/build/index-rtl.css` — admin styles
* `modules/admin/build/block.js` — Gutenberg block (`neural-forms/form`) editor script
* `modules/admin/build/index.asset.php` and `modules/admin/build/block.asset.php` — auto-generated WordPress dependency manifests

The complete, human-readable source for every generated/compressed file above is included inside this plugin package under:

* `modules/admin/src/` — React + CSS source (admin SPA entry, block entry, screens, components, styles)
* `modules/admin/package.json` — npm dependency list and build scripts
* `modules/admin/package-lock.json` — exact dependency versions (lockfile)
* `modules/admin/webpack.config.js` — webpack configuration (extends `@wordpress/scripts`)

`modules/admin/build/index.js` is built from `modules/admin/src/index.js` and the modules it imports. `modules/admin/build/block.js` is built from `modules/admin/src/block-index.js`. Both are bundled by `@wordpress/scripts` (webpack + Babel) using the entry points declared in `modules/admin/webpack.config.js`. No obfuscation, packing, or encoding step is applied.

To rebuild the generated assets from source:

1. Install Node.js 18 or newer and npm.
2. Open a terminal in the plugin directory.
3. Run:

`cd modules/admin`
`npm install`
`npm run build`

This regenerates the files in `modules/admin/build/` from `modules/admin/src/`.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress dashboard.
2. Search for "Neural Forms Lite".
3. Click **Install Now**, then **Activate**.

= Manual Installation =

1. Download the plugin ZIP file.
2. Go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. Activate the plugin.

= After Activation =

1. Navigate to **Neural Forms > Forms** in your admin menu.
2. Click **Add New** to create your first form.
3. Add fields, configure settings, and publish.
4. Embed using the Gutenberg block or the `[neural-forms id="YOUR_FORM_ID"]` shortcode.
5. (Optional) Go to **Neural Forms > Settings** to connect Neural Forms Cloud for AI features.

== Frequently Asked Questions ==

= Do I need an API key to use Neural Forms? =

No. The form builder, lead inbox, email notifications, CAPTCHA, and CSV export all work without any API key. The AI features (auto-summary, draft replies) require a free Neural Forms Cloud API key, which you can get at [neuralforms.ai](https://neuralforms.ai).

= What data does the AI feature send to your servers? =

When AI is enabled, the plugin sends the form submission text (the visitor's answers) and the form schema (field types and labels) to the Neural Forms Cloud API at api.neuralforms.ai. No IP addresses, no WordPress admin emails, and no WordPress core data are sent. You can review the full [Privacy Policy](https://neuralforms.ai/privacy-policy).

= Is Neural Forms GDPR compliant? =

Neural Forms includes a Privacy Mode toggle that disables IP address and user-agent storage on submissions. When enabled, no personally identifiable visitor metadata is stored. For AI features, form submission content is processed by Neural Forms Cloud — see the [Privacy Policy](https://neuralforms.ai/privacy-policy) for data handling details.

= Will this slow down my site? =

No. Neural Forms loads assets only on pages where a form is rendered. The admin bundle loads only on Neural Forms admin screens. AI processing happens asynchronously via WP-Cron — it never blocks the visitor's form submission.

= Can I use this with the WordPress block editor (Gutenberg)? =

Yes. Neural Forms ships a native Gutenberg block (neural-forms/form) with 150+ styling attributes. Search for "Neural Forms" in the block inserter.

= What field types are supported? =

Text, email, textarea, phone, URL, number, date, select/dropdown, radio buttons, checkbox, checkboxes (multi), file upload, hidden, range/slider, and acceptance (terms). More field types are planned.

= How does spam protection work? =

Neural Forms supports Cloudflare Turnstile and Google reCAPTCHA v2 as CAPTCHA providers. Configure your preferred provider in Settings with your site and secret keys.

= Can I import my Contact Form 7 forms? =

Yes. Go to **Neural Forms > Migration**, click **Scan for CF7 Forms**, and import any detected form with one click. Fields, labels, and validation rules are mapped automatically.

= Can I export my form submissions? =

Yes. Go to **Neural Forms > Entries**, select a form, and click **Export CSV**. You can filter by date range before exporting.

= Does Neural Forms create custom database tables? =

Yes. Neural Forms creates 6 custom tables for forms, entries, AI responses, message threads, queue jobs, and logs. All tables are completely removed when you delete (not just deactivate) the plugin via the WordPress Plugins screen.

= Where can I find documentation? =

Full documentation is available at [wpmozo.com/documentation/neural-forms](https://wpmozo.com/documentation/neural-forms/).

== Screenshots ==

1. **Lead Inbox** — Submission inbox with status management, starring, search, and date filters.

== Changelog ==

= 1.1.0 - 2026-06-06 =

*Admin UI*

* Split the admin bundle into per-screen bundles (Forms, Entries, Stats, Settings, Templates, Integrations, Import, Builder, Analytics) for faster loads and isolated updates.
* Restored a refreshed Entries list and detail experience with per-form sidebar folders, URL routing, and richer field labels.
* Added a template modal that reads local plugin templates and supports preview, import, and an "Imported" badge for templates already brought in.
* Added an Integrations page with Pro-ready provider configuration surfaces.
* Refreshed Stats, Settings, and Templates screens with consistent layouts and design.
* Improved Builder UX: 3-panel layout, schema canonicalization, design panels shared across blocks.
* Added Page Performance analytics block and an Analytics screen redesign.

*Builder + Blocks*

* Improved Gutenberg block formatting and shared design panels across the Form, Multi-step, Poll, and Quiz blocks.
* Added Pro-ready Matrix and Repeater field gates in the builder (the fields render an upsell when Pro is not active).
* Refreshed Welcome screen handling, conditional logic, and field-label resolution for Entries display.

*AI*

* Improved Neural AI prompt UX and the AI engine routing surface.
* Added a proactive credits-exhausted gate on AI action buttons so failed runs don't surprise the user.
* Improved Settings gating with Pro-aware copy.

*Entries + Submission*

* Improved entry display, matrix rendering, repeater rendering, and CSV export formatting.
* Improved Reply-To handling on admin notifications.
* Lowered poll/quiz submission cap from 1,000 → 200 per form to reduce abuse surface.
* Improved entry validation, sanitization, and Pro-feature compatibility.

*Compatibility + Security*

* WPCS compliance pass; Plugin Check Tool report findings addressed.
* Added defence-in-depth ABSPATH guards across all module classes.
* Improved Lite/Pro gating: stable capability filter contract for `field_matrix`, `field_repeater`, `integrations`, AI features.

= 1.0.0 - 2026-04-29 =

Initial public release.

*Form Builder*

* React-based admin form builder with drag-and-drop fields, validation, and live preview.
* Frontend rendering via `[neural-forms]` shortcode and the native Gutenberg block.
* 16 field types: text, email, textarea, select, checkbox, checkboxes, number, phone, tel, URL, date, acceptance, radio, hidden, range, file upload.
* Submission pipeline with field-level validation and CAPTCHA verification.

*Lead Inbox*

* Inbox UI with status management (new, read, spam, trash), starring, and needs-reply flags.
* Per-form views, full-text search, date-range filters, and CSV export.
* Bulk status changes with keyboard shortcuts.
* Per-entry message thread with reply logging.

*AI (via Neural Forms Cloud)*

* Connection-first settings UX: enter Cloud API key, test connection, unlock AI controls.
* Auto-analysis on every submission: summary, category, priority, relevance score, and draft reply.
* Multi-tier AI model ladder (fast, balanced, smart, premium) selected by cloud based on plan.
* Draft history carousel: every analysis preserved (10 per entry, 30-day TTL on non-finalized drafts).
* Click-budget protection: 5 AI actions per 2-hour window per entry.

*Notifications*

* Configurable admin email notifications and customer auto-responder.
* Skip-spam toggle to suppress emails for AI-flagged spam entries.

*Security and Privacy*

* Cloudflare Turnstile and Google reCAPTCHA v2 support.
* Privacy mode toggle: disable IP and user-agent storage.
* API keys are write-only over REST — never exposed in responses.

*Developer*

* neural_forms_can_* capability filters for feature gating.
* React SlotFill registry for UI extensions.
* Action hooks: neural_forms_booted, neural_forms_entry_created, neural_forms_send_notifications, neural_forms_queue_ai_processing.
* Filter hooks: neural_forms_validate_submission_field, neural_forms_render_form, neural_forms_spam_check, and more.

*Maintenance*

* Daily WP-Cron purge of stale AI draft rows.
* 5-minute queue runner with 10-second post-submission nudge.
* Schema versioning via NEURAL_FORMS_DB_VERSION with automatic dbDelta() on upgrade.
* Full uninstall.php cleanup: drops all tables, deletes options, clears cron events, removes log files.

== Upgrade Notice ==

= 1.1.0 =
Per-screen admin bundles, refreshed Entries inbox and Builder, local template modal, Integrations surface, Page Performance analytics, and Pro-ready Matrix/Repeater field gates. Recommended.

= 1.0.0 =
Initial release.
