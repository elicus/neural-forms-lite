=== Neural Forms – AI Form Builder with Multi-Step Forms, Lead Inbox & AI Replies ===
Contributors: elicus
Tags: ai form builder, multi-step form, contact form, form builder, lead inbox
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI form builder with free multi-step forms. Describe a form, AI builds it. Entries land in a lead inbox where AI can summarize them and draft replies.

== Description ==

Neural Forms is a WordPress form plugin with a built-in lead inbox. Build forms with drag and drop, manage submissions in an email-style inbox, and let AI analyze every entry and draft your replies.

Most form plugins stop at collecting submissions. Neural Forms starts there. Every submission lands in your lead inbox with a status, a star, and (optionally) an AI-generated summary, category, priority score, and a draft reply ready to send.

[View Live Demo](https://neuralforms.ai)

= What's Included =

* Drag and drop form builder with live preview
* 18 field types: Text, Email, Textarea, Select, Checkbox, Number, Phone, URL, Date, Acceptance, Radio, Checkboxes, Range, Poll, Quiz, File Upload, Hidden, and Rating
* Row and column layouts (1 to 4 columns per row, mobile-responsive)
* Multi-step forms with steps, sections, and welcome screens
* Conditional logic: show or hide any field when all or any of several conditions match
* Exclude any field from AI so its answer never leaves your site
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

= More in Neural Forms Pro =

* Condition groups ("A and (B or C)"), conditional sections, step skipping, and a different confirmation or redirect per rule
* Lead stages, owners, follow-up reminders and an activity timeline on every entry
* Workflows: when a submission arrives, an AI analysis finishes, a stage changes or a follow-up is overdue, assign it, notify your team, send a fixed acknowledgement, set a follow-up, prepare an AI draft for review, or sync to HubSpot, Mailchimp, WhatsApp or Zapier
* Business knowledge, brand voice and reply-language policy so AI drafts answer from your approved facts and flag what they cannot answer

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

= 1.2.0 - 2026-10-29 =

* Added: Conditional logic can combine several conditions with All or Any and show or hide a field.
* Added: Pro users can group conditions, show or skip steps and sections based on answers, and pick a confirmation message or redirect per rule.
* Added: Pro users can track each lead through stages, assign an owner, set follow-up reminders and see an activity timeline on every entry.
* Added: Workflows for Pro users: when a submission arrives, an AI analysis finishes, a stage changes or a follow-up is overdue, assign, notify, acknowledge, set a follow-up or prepare an AI draft, with a test run and history.
* Added: A Processing health card in Settings shows waiting jobs and how to set up a system cron.
* Added: Pro users can give AI drafts approved business knowledge, a brand voice and a reply-language policy, site-wide and per form; drafts show which facts they used and flag what they could not answer.
* Added: Any field can be excluded from AI so its answer never leaves your site.
* Added: Translate an AI draft into your site language from the drafts carousel.
* Fixed: Cloud error messages show their real code instead of a bare HTTP status.
* Fixed: "Analyze with AI" runs immediately when a queued job was never picked up by cron.
* Fixed: Upstream failures no longer show a Cloudflare error page on proxied sites.

= 1.1.3 - 2026-10-28 =

* Security: Uploaded files are no longer visible through the public WordPress media API.
* Security: Block style settings can no longer inject CSS into the page.
* Security: Malformed or oversized submissions are rejected instead of causing errors.
* Security: Draft and archived forms no longer render for anonymous visitors.
* Security: Log files use random names and are blocked from direct access.
* Security: Changing settings and running the AI queue now require administrator permission.
* Security: The submission rate limit can no longer be bypassed with forged headers.
* Fixed: Privacy mode now stops the visitor's IP address and user agent from being stored.
* Added: Entries are included in WordPress's Export and Erase Personal Data tools.
* Added: File types and maximum size controls for the File Upload field.
* Added: A "Load more" button in the entries inbox for lists longer than 50 entries.
* Added: A Subject field in the inbox reply composer.
* Fixed: Field help text is now shown on the form.
* Fixed: Required fields hidden by conditional logic no longer block submission.
* Fixed: Previous and Submit buttons share one row on the last step of a multi-step form.
* Fixed: Validation messages use the field label when labels are hidden, and choice fields always show their question.
* Fixed: An optional Phone field left empty no longer blocks submission.
* Fixed: "Randomize options" now shuffles quiz and poll answers.
* Fixed: Emails and the inbox show option labels instead of internal values.
* Fixed: Inbox replies use the configured sender and the default reply subject.
* Fixed: Per-form notification email and admin notification settings are applied.
* Fixed: Blank Sender name and Sender email fall back to the site title and admin email.
* Fixed: "View entry" links in emails and analytics open the entry.
* Fixed: Entry search finds accented names and web addresses.
* Fixed: Keyboard shortcuts act on the entry that is open or highlighted.
* Fixed: File uploads respect the host's PHP upload limit with a clear size message.
* Fixed: Contact Form 7 import keeps placeholders, required acceptance, file limits and defaults.
* Fixed: Broken upgrade links in the poll and quiz limit message and in Analytics.
* Fixed: A fatal error on PHP 7.4 hosts when a styled block renders.
* Changed: Clearer wording about which AI features are free and which need Pro.

= 1.1.2 - 2026-07-01 =

* Fixed: Multi-step forms no longer auto-scroll on page load.
* Fixed: All four blocks are listed in the WordPress.org plugin directory.
* Fixed: Entry inbox labels no longer show raw slugs for forms built without labels.
* Fixed: "Analyze with Neural AI" works on Lite without Pro.
* Changed: Restyled the form builder's Active status pill.
* Changed: Clearer setup notices and upgrade links in the entry inbox.

= 1.1.1 - 2026-06-23 =

* Fixed: Front-end form assets were missing from the WordPress.org package.

= 1.1.0 - 2026-06-22 =

* Added: Multi-step forms.
* Added: Poll block and multi-step form block for the block editor.
* Added: Ready-to-use form templates.
* Changed: Improved form dashboard, builder drag and drop, and inbox layout.

= 1.0.0 - 2026-06-21 =

* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Conditional logic can now combine several conditions. Pro adds condition groups, step skipping, conditional confirmations, lead stages with follow-ups, workflows and business knowledge for AI drafts. The database upgrades automatically on first admin load; Pro 1.1.0 requires this version.

= 1.1.3 =
Privacy mode now actually stops IP and user-agent storage; sites with it on have existing entries cleaned on update. Security fix for the submission rate limit. Sites behind a reverse proxy or Cloudflare should add their proxy ranges via the `neural_forms_trusted_proxies` filter so the inbox shows real visitor IPs. Emails now go out from your site title and WordPress admin email when the Sender fields are blank; if your admin email is on another domain, set an on-domain Sender email in Settings > Email for best deliverability.

= 1.0.0 =
Initial release.
