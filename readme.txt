=== Neural Forms ===
Contributors: elicus
Tags: contact form, forms, ai forms, lead inbox, form builder
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress form plugin with a built-in lead inbox. Build forms, manage submissions, and let AI analyze every entry and draft your replies.

== Description ==

Neural Forms is a WordPress form plugin with a built-in lead inbox. Build forms with drag and drop, manage submissions in an email-style inbox, and let AI analyze every entry and draft your replies.

Most form plugins stop at collecting submissions. Neural Forms starts there. Every submission lands in your lead inbox with a status, a star, and (optionally) an AI-generated summary, category, priority score, and a draft reply ready to send.

[View Live Demo](https://neuralforms.ai)

= What's Included =

* Drag and drop form builder with live preview
* 18 field types: Text, Email, Textarea, Select, Checkbox, Number, Phone, URL, Date, Acceptance, Radio, Checkboxes, Range, Poll, Quiz, File Upload, Hidden, and Rating
* Row and column layouts (1 to 4 columns per row, mobile-responsive)
* Multi-step forms with steps, sections, and welcome screens
* Native Gutenberg block with 150+ styling attributes
* Shortcode support: [neural-forms id="42"]
* Form template library: pick a template, click once, your form is live
* Email-style lead inbox with status, starring, search, and date filters
* Per-form views, bulk actions, and keyboard shortcuts
* Per-entry message thread for replies
* CSV export with date filtering
* Cloudflare Turnstile and Google reCAPTCHA v2 spam protection
* Admin email notifications and customer auto-responder
* Privacy mode: disable IP and user-agent storage (GDPR-friendly)
* One-click Contact Form 7 migration
* Developer hooks, capability filters, and React SlotFill registry

= AI Analysis for Your Submissions =

100 free AI credits included on signup. Connect your free Neural Forms API key, open any entry in the inbox and click "Analyze with Neural AI" to get:

* A summary of the submission
* An AI-assigned category (inquiry, support, sales, and more)
* A priority score (0 to 100)
* A drafted reply ready to review and send
* Fast-tier AI model (premium tiers in Pro)

In the free plugin each entry can be analyzed once, on demand. Automatic analysis of every new submission, re-analysis, and regenerated or polished drafts are part of Neural Forms Pro.

The form builder, lead inbox, email notifications, CAPTCHA, and CSV export all work without any AI key. AI features are optional and pause when free credits are consumed.

= How it works =

1. Install Neural Forms from your WordPress dashboard.
2. Use the visual builder to add fields and configure settings, or pick a template from the library.
3. Embed using the Gutenberg block or the [neural-forms id="42"] shortcode.
4. (Optional) Enter your free Neural Forms API key in Settings to enable AI features.
5. Manage leads in the inbox: review AI analysis, send replies, export entries.

No third-party accounts required for basic form functionality.

= Built for =

* Freelancers and agencies managing client inquiries
* Small businesses handling contact and support forms
* SaaS companies triaging inbound leads
* Bloggers and creators collecting feedback
* WooCommerce stores managing pre-sale questions

= Neural Forms Pro =

Need more? [Neural Forms Pro](https://neuralforms.ai/pricing/) adds:

* 7,500 to 25,000 AI credits per year (resets with renewal)
* Automatic AI analysis of every new submission, with per-form processing modes
* AI form generation: describe a form in plain English, AI builds it
* Smart spam detection with adjustable threshold
* Reanalyze, regenerate, and polish AI drafts
* Site profile scraper for context-aware AI
* Divi 5 Visual Builder module
* Native integrations with HubSpot, Mailchimp, Zapier, WhatsApp, and Google Forms
* Matrix and Repeater field types
* Premium AI model tiers
* Priority support
* Auto updates

Starting at $99 per year for unlimited sites. [See full pricing](https://neuralforms.ai/pricing/).

= Built by Elicus =

Neural Forms is built by Elicus, building plugins for WordPress since 2015. Our other products include Divi Extended, WPMozo, and Divi Plus, used on 300,000+ WordPress sites worldwide.

= Documentation =

Full documentation is available at [neuralforms.ai/documentation](https://neuralforms.ai/documentation/).

= External Services =

This plugin connects to external third-party services. Most connections require explicit opt-in (entering an API key or selecting a service). One service (the template library) is contacted when you open the Templates screen to fetch the list of available form templates. The disclosures below describe each service, what data is sent, when, and why.

**Neural Forms API**

When you enable AI features and enter a Neural Forms API key in Settings, the plugin sends form submission content (the text your visitors enter) and form field metadata to the Neural Forms API at api.neuralforms.ai for AI analysis. This includes generating submission summaries, categories, priority scores, and draft replies. No data is sent unless you have enabled AI and provided an API key. The plugin never sends IP addresses, WordPress admin credentials, or WordPress core data to this service.

* Service URL: [https://api.neuralforms.ai](https://api.neuralforms.ai)
* Service website: [https://neuralforms.ai](https://neuralforms.ai)
* Terms of Service: [https://neuralforms.ai/terms-and-conditions/](https://neuralforms.ai/terms-and-conditions/)
* Privacy Policy: [https://neuralforms.ai/privacy-policy/](https://neuralforms.ai/privacy-policy/)

**Neural Forms Template Library**

When you open the Templates screen in Neural Forms, the plugin connects to the template library at templates.neuralforms.ai to fetch the catalog of available form templates. When you click to import a specific template, the plugin fetches that template's content from the same host. The plugin sends the template ID and the builder type (block-editor) with each request. No form submission data, no visitor data, no WordPress admin information, and no API keys are sent. The catalog is cached locally for 6 hours to reduce remote requests. The host is fixed to templates.neuralforms.ai by default and validated against an allowlist before every request.

* Service URL: [https://templates.neuralforms.ai](https://templates.neuralforms.ai)
* Service website: [https://neuralforms.ai](https://neuralforms.ai)
* Terms of Service: [https://neuralforms.ai/terms-and-conditions/](https://neuralforms.ai/terms-and-conditions/)
* Privacy Policy: [https://neuralforms.ai/privacy-policy/](https://neuralforms.ai/privacy-policy/)

**Cloudflare Turnstile**

When you select Cloudflare Turnstile as your CAPTCHA provider and configure your site and secret keys, the plugin loads the Turnstile challenge script on pages with forms and verifies visitor responses server-side via the Cloudflare API. This service is used only when Turnstile is selected as the CAPTCHA provider in Settings.

* Service website: [https://www.cloudflare.com/products/turnstile/](https://www.cloudflare.com/products/turnstile/)
* Terms of Service: [https://www.cloudflare.com/website-terms/](https://www.cloudflare.com/website-terms/)
* Privacy Policy: [https://www.cloudflare.com/privacypolicy/](https://www.cloudflare.com/privacypolicy/)

**Google reCAPTCHA v2**

When you select Google reCAPTCHA v2 as your CAPTCHA provider and configure your site and secret keys, the plugin loads the reCAPTCHA challenge script on pages with forms and verifies visitor responses server-side via the Google API. This service is used only when reCAPTCHA v2 is selected as the CAPTCHA provider in Settings.

* Service website: [https://www.google.com/recaptcha/](https://www.google.com/recaptcha/)
* Terms of Service: [https://policies.google.com/terms](https://policies.google.com/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

== Source Code and Build Instructions ==

Neural Forms ships pre-built JavaScript and CSS files in `modules/admin/build/` so the plugin works on a clean WordPress install without a Node toolchain. The compiled/generated files are:

* `modules/admin/build/index.js`: admin React SPA (Forms, Entries, Stats, Settings)
* `modules/admin/build/index.css` and `modules/admin/build/index-rtl.css`: admin styles
* `modules/admin/build/block.js`: Gutenberg block (`neural-forms/form`) editor script
* `modules/admin/build/index.asset.php` and `modules/admin/build/block.asset.php`: auto-generated WordPress dependency manifests

The complete, human-readable source for every generated file above is included inside this plugin package under:

* `modules/admin/src/`: React + CSS source (admin SPA entry, block entry, screens, components, styles)
* `modules/admin/package.json`: npm dependency list and build scripts
* `modules/admin/package-lock.json`: exact dependency versions (lockfile)
* `modules/admin/webpack.config.js`: webpack configuration (extends `@wordpress/scripts`)

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
2. Search for "Neural Forms".
3. Click **Install Now**, then **Activate**.

= Manual Installation =

1. Download the plugin ZIP file.
2. Go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. Activate the plugin.

= After Activation =

1. Navigate to **Neural Forms > Forms** in your admin menu.
2. Click **Add New** to create your first form, or pick a template from the library.
3. Add fields, configure settings, and publish.
4. Embed using the Gutenberg block or the `[neural-forms id="YOUR_FORM_ID"]` shortcode.
5. (Optional) Go to **Neural Forms > Settings** to enter your free Neural Forms API key for AI features.

== Frequently Asked Questions ==

= Do I need an API key to use Neural Forms? =

No. The form builder, lead inbox, template library, email notifications, CAPTCHA, and CSV export all work without any API key. The AI features (on-demand summary, category, priority and a draft reply for any entry) require a free Neural Forms API key, which you can get at [neuralforms.ai](https://neuralforms.ai). 100 free AI credits are included on signup. Automatic analysis of every new submission is a Neural Forms Pro feature.

= What data does the AI feature send to your servers? =

When AI is enabled, the plugin sends the form submission text (the visitor's answers) and the form schema (field types and labels) to the Neural Forms API at api.neuralforms.ai. No IP addresses, no WordPress admin emails, and no WordPress core data are sent. Full [Privacy Policy](https://neuralforms.ai/privacy-policy/).

= What data does the template library send to your servers? =

When you open the Templates screen, the plugin connects to templates.neuralforms.ai to fetch the catalog of available form templates. When you click to import a template, the plugin fetches that template's content. The plugin sends only the template ID and builder type with each request. No form submission data, no visitor data, no WordPress admin information, and no API keys are sent. The catalog is cached locally for 6 hours to minimize remote requests.

= Is Neural Forms GDPR friendly? =

Yes. Neural Forms includes a Privacy Mode toggle that disables IP address and user-agent storage on submissions. When enabled, no personally identifiable visitor metadata is stored. For AI features, form submission content is processed by the Neural Forms API. See the [Privacy Policy](https://neuralforms.ai/privacy-policy/) for full data handling details.

= Will this slow down my site? =

No. Neural Forms loads assets only on pages where a form is rendered. The admin bundle loads only on Neural Forms admin screens. AI processing happens asynchronously via WP-Cron and never blocks the visitor's form submission. The template catalog is cached for 6 hours so the Templates screen loads from cache after the first fetch.

= Can I use this with the WordPress block editor (Gutenberg)? =

Yes. Neural Forms ships a native Gutenberg block (`neural-forms/form`) with 150+ styling attributes. Search for "Neural Forms" in the block inserter.

= What field types are supported? =

Text, Email, Textarea, Select, Checkbox, Checkboxes, Number, Phone, URL, Date, Acceptance, Radio, Hidden, Range, Poll, Quiz, File Upload, and Rating. That is 18 field types in the free version. Two additional Pro fields (Matrix and Repeater) are available in Neural Forms Pro.

= How does spam protection work? =

Neural Forms supports Cloudflare Turnstile and Google reCAPTCHA v2 as CAPTCHA providers. Configure your preferred provider in Settings with your site and secret keys. Pro users also get AI spam detection with an adjustable threshold.

= Can I import my Contact Form 7 forms? =

Yes. Go to **Neural Forms > Import**. Your Contact Form 7 forms are listed automatically; preview one and import it with one click. Field types, required flags, placeholders, defaults, options and file limits are mapped. Labels are generated from the CF7 field names (for example "your-name" becomes "Your Name"), so rename them in the builder if your CF7 form used different label text.

= Can I export my form submissions? =

Yes. Go to **Neural Forms > Entries**, select a form, and click **Export CSV**. Filter by date range before exporting.

= Does Neural Forms create custom database tables? =

Yes. Neural Forms creates 6 custom tables for forms, entries, AI responses, message threads, queue jobs, and logs. All tables are completely removed when you delete (not just deactivate) the plugin via the WordPress Plugins screen.

= Where can I find documentation? =

Full documentation is available at [neuralforms.ai/documentation](https://neuralforms.ai/documentation/).

== Screenshots ==

1. **Lead Inbox**: Email-style submission inbox with status management, starring, search, and date filters. Click any entry to see the full submission and AI analysis.
2. **AI-Analyzed Entry Detail**: Submission detail view showing the AI-generated summary, category badge, priority score, and the auto-drafted reply ready to send.
3. **Drag and Drop Form Builder**: The form builder with field library on the left, live form preview in the center, and field settings on the right.
4. **Template Library**: Pick a form template and click once to import. Templates include contact forms, surveys, quizzes, registrations, and more.
5. **Gutenberg Block in Action**: Native Neural Forms block in the WordPress block editor with full styling controls.

== Changelog ==

= 1.1.3 - 2026-10-27 =
* Privacy: Privacy mode now works. Previously entries stored the visitor's IP address and user agent even with the toggle on. Sites with Privacy mode on have that data removed from existing entries on update.
* New: Neural Forms entries are now included in WordPress's Export Personal Data and Erase Personal Data tools, matched by the submitted email address, and suggested privacy-policy text is provided under Settings > Privacy.
* Security: The submission rate limit could be bypassed by sending a forged X-Forwarded-For header. The client IP now comes from the server connection unless the request arrives via a proxy listed in the new `neural_forms_trusted_proxies` filter.
* Fix: The rate limit is now counted per form rather than shared across every form on the site, and bot submissions caught by the honeypot no longer use up attempts.
* Fix: The entries inbox now has a "Load more" button, so lists longer than 50 entries are fully reachable.
* Fix: Searching entries for accented names or web addresses now finds matching submissions, including in CSV exports.
* Fix: Contact Form 7 import now keeps placeholders as placeholders (they were imported as pre-filled values), imports acceptance checkboxes as required, converts file size limits and allowed file types correctly, and applies radio / select / checkbox defaults.
* Fix: The upgrade link shown when a poll or quiz reaches the free submission limit pointed to a missing page.
* Fix: Required fields hidden by conditional logic no longer block submission, and conditions based on an unticked checkbox now behave the same on the server as in the browser.
* Fix: On the last step of a multi-step form, the Previous and Submit buttons now sit on one row instead of stacking.
* Fix: With labels hidden, validation messages now use the field's label instead of its placeholder, and choice fields (radio, checkbox, rating, matrix) always show their question.
* Fix: An optional Phone field left empty no longer blocks the Next button or submission because of the pre-filled country code.
* Fix: A form's own Notification email and "Send admin notification" setting now take effect. Previously every form notified the global address regardless.
* Fix: Email Settings now behave as described — a blank Sender name uses your site title and a blank Sender email uses the WordPress admin email, for notifications, autoresponders, replies and the test email alike.
* Fix: The "View entry" link in admin notification emails and in quiz attempt analytics now opens the submitted entry instead of the entries list.
* Fix: The "Randomize options" setting on quiz questions and polls now actually shuffles the answer order for each visitor.
* Fix: Entries keyboard shortcuts now act on the entry you opened or the highlighted row, which is now visibly marked. Previously they could silently change a different entry.
* Fix: File upload fields now respect the host's PHP upload limit. The "Max" hint and the size check use the lower of the field setting and the server limit, and an oversized file shows a clear size message instead of a generic upload error.
* Improved: Clearer wording about AI in the free plugin — any entry can be analyzed once from the inbox with a free API key; automatic analysis of every new submission is part of Neural Forms Pro.

= 1.1.2 - 2026-07-01 =
* Fix: Multi-step forms no longer trigger an auto-scroll on page load.
* Improved: Refreshed the form builder's Active status pill to a blue background with mint text for better contrast.

= 1.1.1 - 2026-06-23 =
* Fix: Front-end form scripts and styles were missing from the WordPress.org download, which prevented forms from submitting. They are now bundled correctly.

= 1.1.0 - 2026-06-22 =
* New: Multi-step forms for breaking long forms into manageable steps.
* New: Poll block and multi-step form block for the block editor.
* New: Ready-to-use form templates so you can start with a working form in one click.
* Improved: Form dashboard layout for easier navigation.
* Improved: Form builder with smoother drag and drop.
* Improved: Inbox layout for faster scanning of submissions.

= 1.0.0 - 2026-06-21 =

Initial public release.

*Form Builder*

* React-based admin form builder with drag-and-drop fields, validation, and live preview.
* Frontend rendering via `[neural-forms]` shortcode and the native Gutenberg block.
* 18 field types: Text, Email, Textarea, Select, Checkbox, Checkboxes, Number, Phone, URL, Date, Acceptance, Radio, Hidden, Range, Poll, Quiz, File Upload, and Rating.
* Submission pipeline with field-level validation and CAPTCHA verification.

*Template Library*

* Remote template catalog hosted at templates.neuralforms.ai
* Fixed host allowlist enforced for every remote request
* 6-hour transient cache on catalog to minimize remote calls
* Per-template content fetched only on user-triggered import (not pre-fetched)
* Imported form data sanitized to strip integrations, webhooks, recipients, and tokens before persisting

*Lead Inbox*

* Inbox UI with status management (new, read, spam, trash), starring, and needs-reply flags.
* Per-form views, full-text search, date-range filters, and CSV export.
* Bulk status changes with keyboard shortcuts.
* Per-entry message thread with reply logging.

*AI (via Neural Forms API)*

* Connection-first settings UX: enter API key, test connection, unlock AI controls.
* Auto-analysis on every submission: summary, category, priority, relevance score, and draft reply.
* Multi-tier AI model ladder (fast, balanced, smart, premium) selected by the AI service based on plan.
* Draft history carousel: every analysis preserved (10 per entry, 30-day TTL on non-finalized drafts).
* Click-budget protection: 5 AI actions per 2-hour window per entry.
* 100 free AI credits included on signup.

*Notifications*

* Configurable admin email notifications and customer auto-responder.
* Skip-spam toggle to suppress emails for AI-flagged spam entries.

*Security and Privacy*

* Cloudflare Turnstile and Google reCAPTCHA v2 support.
* Privacy mode toggle: disable IP and user-agent storage.
* API keys are write-only over REST and never exposed in responses.

*Developer*

* `neural_forms_can_*` capability filters for feature gating.
* React SlotFill registry for UI extensions.
* Action hooks: `neural_forms_booted`, `neural_forms_entry_created`, `neural_forms_send_notifications`, `neural_forms_queue_ai_processing`.
* Filter hooks: `neural_forms_validate_submission_field`, `neural_forms_render_form`, `neural_forms_spam_check`, and more.

*Maintenance*

* Daily WP-Cron purge of stale AI draft rows.
* 5-minute queue runner with 10-second post-submission nudge.
* Schema versioning via `NEURAL_FORMS_DB_VERSION` with automatic dbDelta() on upgrade.
* Full uninstall.php cleanup: drops all tables, deletes options, clears cron events, removes log files.

== Upgrade Notice ==

= 1.1.3 =
Privacy mode now actually stops IP and user-agent storage; sites with it on have existing entries cleaned on update. Security fix for the submission rate limit. Sites behind a reverse proxy or Cloudflare should add their proxy ranges via the `neural_forms_trusted_proxies` filter so the inbox shows real visitor IPs. Emails now go out from your site title and WordPress admin email when the Sender fields are blank; if your admin email is on another domain, set an on-domain Sender email in Settings > Email for best deliverability.

= 1.0.0 =
Initial release.
