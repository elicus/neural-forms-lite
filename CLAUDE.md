# Neural Forms Lite -- Claude Context File

## Project Overview

Neural Forms Lite is the **base engine** of a two-plugin product. It ships as
a standalone WordPress form builder and is extended by **Neural Forms Pro**
(separate plugin) which unlocks premium features via capability filters.

Lite combines:

- Form builder (React-based admin UI)
- REST API backend (WordPress)
- Frontend rendering + submission
- Gmail-style inbox for entries (lead management system)
- AI-powered entry analysis, suggested replies, and spam detection via Neural Forms Cloud

Premium add-ons live in Neural Forms Pro (folder: `neural-forms-pro`), which
ships separately and hooks into Lite's extension points:

- Divi 5 integration (VB module + frontend renderer)
- Spam detection UI + capability unlock (`ai_spam_detect`)
- Entry reanalyze + regenerate reply (`ai_reanalyze`)
- Polish/refine reply (`ai_refine_reply`)

See `../neural-forms-pro/CLAUDE.md` for the Pro-side contract.

------------------------------------------------------------------------

## Plugin Identity

- **Folder:** `neural-forms-lite/`
- **Plugin header name:** `Neural Forms Lite`
- **Main class:** `NeuralForms\NeuralForms` (namespace **not** renamed)
- **Version constants:**
  - `NEURAL_FORMS_LITE_VERSION` — canonical Lite version (Pro dep check reads this). Currently `1.1.3`.
  - `NEURAL_FORMS_VERSION` — kept as an alias (= `NEURAL_FORMS_LITE_VERSION`) for back-compat
  - `NEURAL_FORMS_DB_VERSION` — schema version consumed by `Database::maybe_upgrade()`. Currently `1.1.0`. Bump this whenever you change a table column or add a migration so existing installs re-run `dbDelta()` on admin_init.

**Identifiers deliberately NOT renamed** (changing these would break installs):

- REST namespace: `/neural-forms/v1`
- JS global: `window.neuralForms`, `window.neuralFormsAdmin`
- PHP namespaces: `NeuralForms\*`
- Database tables: `neural_forms_*`
- Option names: `neural_forms_settings`, `neural_forms_queue`, etc.
- Action/filter hook prefix: `neural_forms_*`

Re-activation is required once after the folder rename because WordPress
stores `active_plugins` by basename.

------------------------------------------------------------------------

## Architecture Overview

### Backend (PHP)

Located in: `modules/`

#### Module Map

| Module | Purpose | Key files |
|--------|---------|-----------|
| `core/` | Plugin-wide settings (`Config.php`), logging (`Logger.php`) | `Config.php` stores all settings in a single `neural_forms_settings` WP option |
| `ai/` | AI layer -- queue, repository, provider | `AiManager.php`, `AIQueueProcessor.php`, `QueueListener.php`, `AIRepository.php` |
| `ai/Contracts/` | Provider interface | `AIAnalysisProviderInterface.php` -- `analyze()`, `regenerate_reply()`, `polish_reply()`, `provider_id()` |
| `ai/Providers/` | Provider implementation | `NeuralFormsCloudProvider.php` -- sole provider, calls `api.neuralforms.ai` |
| `rest/` | REST API routes | `RestAPI.php` registers all endpoints; namespace: `/neural-forms/v1` |
| `rest/endpoints/` | Individual endpoints | `FormsEndpoint`, `EntriesEndpoint`, `SettingsEndpoint`, `SubmitEndpoint`, `ExportEndpoint`, `ProcessQueueEndpoint`, `ReplyEndpoint`, `RetryEndpoint`, `MessagesEndpoint`, `StatsEndpoint` |
| `storage/` | Data access | `EntryRepository.php`, `EntryMessageRepository.php` |
| `forms/` | Form logic | `FormRepository.php`, `FieldRegistry.php`, `FormValidator.php`, `SubmissionValidator.php` |
| `frontend/` | Public-facing rendering | `FormRenderer.php`, `PublicForms.php`, `BlockHandler.php`, `ShortcodeHandler.php` |
| `frontend/assets/` | Frontend CSS/JS | `form.css`, `form-submit.js` |
| `frontend/block/` | Gutenberg block | `block.json` |
| `notifications/` | Email system | `NotificationService.php` |
| `captcha/` | CAPTCHA verification | `CaptchaManager.php`, `TurnstileVerifier.php`, `RecaptchaV2Verifier.php` |
| `admin/` | Admin React app | `AdminPanel.php` enqueues; `src/` contains React source; `build/` contains compiled output |
| `core/Capabilities.php` | Premium-capability registry — filter-based unlock gates | see "Capability / Unlock Architecture" below |

#### Bootstrap / Includes

| File | Purpose |
|------|---------|
| `neural-forms-lite.php` | Plugin entry point, constants, hooks (header reads "Neural Forms Lite") |
| `includes/class-neuralforms.php` | Main plugin class; fires `neural_forms_booted` at end of `boot()` so Pro can attach |
| `includes/class-autoloader.php` | PSR-4 autoloader: `NeuralForms\X` -> `modules/x/X.php` |
| `includes/class-database.php` | Table creation via `dbDelta()` |
| `includes/class-activator.php` | Activation hook |
| `includes/class-deactivator.php` | Deactivation hook |
| `uninstall.php` | Cleanup on uninstall |

------------------------------------------------------------------------

### AI System

Neural Forms uses **Neural Forms Cloud** as its sole AI backend. There are no local/direct provider paths (no OpenAI, Anthropic, or Google direct calls).

#### Flow

1. `SubmitEndpoint` saves an entry and fires `neural_forms_queue_ai_processing`
2. `QueueListener` inserts a job into `neural_forms_queue` table (guards: `ai_enabled`, `cloud_ai_enabled`, `cloud_api_key`, `ai_auto_process`)
3. `AIQueueProcessor` picks up jobs via WP-Cron (5-min recurring + 10s single-event)
4. `AiManager::resolve()` returns a `NeuralFormsCloudProvider` instance
5. Provider calls `api.neuralforms.ai` endpoints: `/v1/ai/analyze`, `/v1/ai/reply/regenerate`, `/v1/ai/reply/polish`
6. Results stored via `AIRepository`; each finalized response row is also written to `AIResponseRepository` for the draft carousel (see below)

#### Key Constants / Config

- `cloud_ai_enabled` -- master toggle for Neural Forms Cloud (also synced to `ai_enabled` by the frontend save handler)
- `cloud_api_key` -- API key (write-only in UI; never returned by GET)
- `cloud_api_base_url` -- defaults to `https://api.neuralforms.ai`; override via `NEURAL_FORMS_CLOUD_API_URL` PHP constant or `neural_forms_cloud_api_url` filter
- `ai_auto_process` -- auto-queue analysis on submission
- `ai_spam_enabled` / `ai_spam_mode` / `ai_spam_threshold` -- spam detection config

#### Interface

`AIAnalysisProviderInterface` defines:
- `analyze(array $entry, array $form): array` -- returns summary, category, priority, reply_draft, score, is_spam, spam_confidence, spam_reason, ai_provider, ai_model, **ai_tier** (`fast|balanced|smart|premium`)
- `regenerate_reply(array $entry, array $form): array` -- returns reply_draft + ai_tier
- `polish_reply(string $draft, array $entry, array $form): array` -- returns polished_reply + ai_tier
- `provider_id(): string` -- e.g. `'neural-forms-cloud'`

#### Multi-tier model ladder (cloud-side contract)

The cloud API selects a model tier per request using the caller's plan cap.
The plugin does NOT pick the tier — it just forwards `entry_id` and the
cloud echoes back `ai_tier` on every response for display + diagnostics.

| Tier | Model (as of 1.1.0) | Used for |
|---|---|---|
| `fast` | OpenAI `gpt-4.1-nano` | Lite / free; universal speed floor |
| `balanced` | Google `gemini-2.5-flash-lite` | Pro (default); also the universal fallback if the smart/premium tier errors |
| `smart` | Anthropic `claude-haiku-4.5` | Pro Starter / Pro Agency |
| `premium` | Anthropic `claude-opus-4-1` | Pro Enterprise — **unverified**: cloud commit `elicus/neural-forms-api@d249933` lists `ALLOWED_MODELS` as only gpt-4.1-nano / gemini-2.5-flash-lite / claude-haiku-4.5, which doesn't include an Opus entry. Confirm with cloud team whether `premium` still routes to Opus or has been collapsed/retired. |

Tier values are persisted on `neural_forms_entries.ai_tier` (string, nullable)
and surfaced in the entry detail UI so users can see which tier wrote a
given analysis/reply.

#### Response carousel (AIResponseRepository)

Every finalized AI response (analysis reply, regenerate, polish) writes
a row to `neural_forms_entry_ai_responses`. The detail UI reads this as
a left/right carousel so users can page through previous drafts.

- **Table:** `neural_forms_entry_ai_responses` (columns: id, entry_id, kind, tier, body, model, created_at, finalized_at)
- **MAX_DRAFTS_PER_ENTRY:** 10 — oldest rows pruned on insert
- **DRAFT_TTL_DAYS:** 30 — non-finalized drafts are purged after this window by the daily cron
- **Auto-finalize:** the first `analyze` result for an entry is auto-finalized (so it appears as the "current" response without needing an explicit save)
- **Purge job:** `neural_forms_ai_draft_purge` — WP-Cron daily hook that calls `AIResponseRepository::purge_older_than( DRAFT_TTL_DAYS )`. Scheduled in `Activator::activate()` and self-scheduled in `NeuralForms::boot()` so existing installs pick it up on upgrade. Cleared in `Deactivator::deactivate()`.

------------------------------------------------------------------------

### Frontend (JS)

Located in: `modules/admin/src/`

#### Key Components

- `entries/Entries.js` -- main state controller (inbox logic)
- `entries/EntriesSidebar.js` -- sidebar navigation (forms + views)
- `entries/EntriesList.js` -- list view (rows + bulk actions)
- `entries/EntriesListRow.js` -- individual row
- `entries/EntryDetail.js` -- detail view (like email open)
- `entries/hooks/useEntriesList.js` -- API data fetching
- `entries/hooks/useInboxKeyboard.js` -- keyboard shortcuts
- `forms/` -- form builder components
- `screens/Settings.js` -- settings UI (Cloud AI, notifications, email, CAPTCHA)
- `screens/Entries.js`, `screens/Forms.js`, `screens/Stats.js` -- screen wrappers
- `Router.js` -- SPA routing
- `index.css` -- all admin styles (BEM under `#neural-forms-app`)

#### Build

Run `npm run build` inside `modules/admin/` after any JS/CSS change.
The build outputs to `modules/admin/build/`.
Root `package.json` provides workspace shortcuts: `npm run build` from plugin root.

#### Form builder layout blocks

Top-level, non-field nodes in a form schema: Row, Step, Section, and
Welcome Screen. Shared helpers live in `modules/forms/SchemaHelper.php`
(PHP) and `modules/admin/src/forms/schemaHelper.js` (JS).

**Welcome Screen** (added 2026-06-04) — a display-only intro screen
shown before the form fields. Max one per form (`LayoutPalette.js`
disables the chip once `hasWelcomeScreen()` is true).

- Fields: `title` (default "Let's get started"), `description` — the
  "welcome text" body copy, default empty — `buttonText`/`button_text`
  (default "Start"), `settings.alignment` (left/center/right).
- Never collects data — excluded from entries, exports, AI analysis, and
  notifications. Validated as top-level-only / exactly-one in
  `FormValidator.php`.
- Builder: `LayoutPalette.js` (palette chip), `FieldList.js`
  (`.nf-welcome-block` tile), `BuilderInspector.js`
  `WelcomeScreenInspector` (Title / Description / Button Text / Alignment).
- Preview: `PreviewModal.js` mirrors the frontend CSS so the welcome
  screen + form body stack together in the admin preview.
- Frontend render: `FormRenderer.php::render_welcome_screen()` —
  `.neural-forms-form__welcome*` classes, `data-neural-forms-has-welcome`
  on the form wrap. Reveal behavior (Start button hides welcome, shows
  form body; no-JS fallback shows both) lives in
  `modules/frontend/assets/js/form-submit.js` `initWelcomeScreen()`.
- A Full Height toggle and colour inspector controls (background/text/
  button colours) were originally removed from the builder's
  `WelcomeScreenInspector` (Content tab) to keep it minimal — don't
  re-add them there.
- **Gutenberg block design panel** (added 2026-06-12, Container parity
  added same day) — every block's Style tab gets a "Welcome Screen"
  panel (`WelcomeScreenPanel` in `modules/admin/src/blocks/shared/
  DesignPanels.js`), mounted from `FormDesignExtras.js`,
  `MultiStepDesignExtras.js`, `QuizDesignExtras.js`, and
  `PollDesignExtras.js`. Mirrors `ContainerPanel`'s control set, in
  this order: Background (color/gradient/image with size/position/
  repeat/blend), Full Height, Border (`BorderBoxControl`), Border
  Radius (4-corner), Dimensions (padding/margin), Content Alignment
  (left/center/right — mirrors the existing `.neural-forms-form__
  welcome--align-*` classes: `text-align` + inner margin reset, NOT
  flexbox, to avoid changing default layout), Box Shadow, then
  Title/Description/Button color+size+weight/hover controls. 19
  `welcome*` attributes registered in `modules/frontend/block/
  block.json`; CSS emitted by `BlockHandler::build_scoped_css()`
  against `.neural-forms-form__welcome*` selectors, reusing
  `border_css()` and the image>gradient>color priority pattern from
  `ContainerPanel`'s CSS. Width/Max-Width/Alignment (page-level form
  sizing) were deliberately NOT mirrored — the welcome screen is an
  internal full-bleed section, not an independently sized box.
- **Welcome Title — Form-Title parity** (added 2026-06-12) — the
  Title section of `WelcomeScreenPanel` mirrors `TitlePanel` (the
  form title's panel) minus Alignment and Dimensions (those stay
  welcome-screen-specific: Content Alignment + the shared Dimensions
  block above). Adds Heading Level (`welcomeTitleLevel`, h1-h6,
  default h2), Font Family (`welcomeTitleFontFamily` via
  `FontFamilySelect`), and the full `TypographyPanel` (pfx=
  `welcomeTitle`) covering `welcomeTitleFontSize`,
  `welcomeTitleFontStyle`, `welcomeTitleFontWeight`,
  `welcomeTitleLetterSpacing`, `welcomeTitleTextDecoration`,
  `welcomeTitleTextTransform`, `welcomeTitleLineHeight` — replacing
  the old plain Font Size/Font Weight controls. 7 new attributes in
  `block.json` + whitelist/CSS in `BlockHandler::build_scoped_css()`
  (mirrors the `.neural-forms-form__title` rule-building in the same
  file). Heading level is structural, not CSS: `BlockHandler::render()`
  passes `welcome_title_level` through `$render_options` (same channel
  as `title_level`), and `FormRenderer::render_welcome_screen()` now
  takes `$options` and emits the configurable `<h1>`-`<h6>` tag
  (default `h2`, same validation pattern as the form title).
- **Welcome Description — Title parity** (added 2026-06-12) — the
  Description section of `WelcomeScreenPanel` mirrors the Title section
  minus Heading Level: Color (`welcomeDescColor`), Font Family
  (`welcomeDescFontFamily` via `FontFamilySelect`), and the full
  `TypographyPanel` (pfx=`welcomeDesc`) covering `welcomeDescFontSize`,
  `welcomeDescFontStyle`, `welcomeDescFontWeight`,
  `welcomeDescLetterSpacing`, `welcomeDescTextDecoration`,
  `welcomeDescTextTransform`, `welcomeDescLineHeight` — replacing the
  old plain Color/Font Size/Font Weight controls. 6 new attributes in
  `block.json` + whitelist/CSS in `BlockHandler::build_scoped_css()`
  targeting `.neural-forms-form__welcome-desc`.
- **Welcome Button — Submit Button parity** (added 2026-06-12) — the
  Button section of `WelcomeScreenPanel` now renders the shared
  `BtnControls` component (same one used for the submit/nav buttons)
  with `showAlign={false}` and `showFullWidth={false}` — Alignment and
  Full Width are intentionally excluded (welcome screen already has its
  own Content Alignment, and the button sizes to its content). Covers
  Background (color/gradient), Text Color, Border (`BorderBoxControl`),
  Border Radius (4-corner), Font Family + full `TypographyPanel`
  (pfx=`welcomeBtn`), Padding (4-side), Transition Duration/Easing,
  Hover Background/Color/Border Color, and icon support
  (`welcomeBtnIconEnabled/Icon/IconPosition/IconShowOnHover`).
  `BtnControls` itself gained a `showFullWidth` param (default `true`)
  to support this. 28 new/changed attributes in `block.json`; CSS in
  `BlockHandler::build_scoped_css()` mirrors the Multi-step `navBtn*`
  duplicated-block pattern (border_css, per-corner radius, font-family/
  size, typography, padding) plus the `fileBtn*` gradient-priority
  pattern (`if (gradient) background-image elseif (color)
  background-color`) for both normal and hover states, targeting
  `.neural-forms-form__welcome-btn` /
  `.neural-forms-form__welcome-btn:hover:not(:disabled),
  ...:focus:not(:disabled)`. A `transition` rule is **always** emitted
  (default `0.15s ease` covering background-color/color/border-color/
  filter) — the welcome-btn's `form.css` default only transitions
  opacity/transform, so without this the hover color/background changes
  apply instantly. Icon rendering mirrors the submit button: `BlockHandler::
  render()` populates `welcome_btn_icon*` render options,
  `FormRenderer::render_welcome_screen()` builds the `nf-btn-icon` span
  via `ButtonIcons::get()` and assembles icon-before/after label content.
- **"Show Welcome Screen" toggle** — Display panel (Settings tab) in
  `FormSelector.js`, attribute `showWelcomeScreen` (default `true`).
  Only shown when the selected form's schema has a Welcome Screen
  (`hasWelcomeScreen(selectedForm.form_schema.fields)`, computed in
  `FormSelector.js` and passed to `extras` via `cloneElement` —
  every *DesignExtras component now accepts `formHasWelcomeScreen`).
  Gates both the Style-tab panel above and frontend rendering —
  `FormRenderer.php::render_form()` skips `render_welcome_screen()`
  when `show_welcome_screen` is `false`, via
  `BlockHandler::render()`'s `$render_options`.
- All of the above ship in the `blocks-extra` bundle —
  `npm run build:blocks-extra`.
- **Welcome Description/Button — Margin Top** (added 2026-06-15) — a
  single "Margin Top" `UnitControl` was added to the end of the
  Description section (`welcomeDescMarginTop` →
  `.neural-forms-form__welcome-desc{margin-top:...}`) and the Button
  section (`welcomeBtnMarginTop` →
  `.neural-forms-form__welcome-btn{margin-top:...}`) of
  `WelcomeScreenPanel`, to control vertical spacing between the
  title/description/button stack. Deliberately top-only (not a full
  4-side `PaddingMarginDimensions`/margin block) — Button already has
  its own Padding via `BtnControls`, and the only need is spacing
  between the stacked elements.

------------------------------------------------------------------------

## Entries (Inbox System)

Entries behave like a Gmail inbox.

### Status Values

- `new` (unread)
- `read`
- `spam`
- `trash`

### Flags

- `is_starred`
- `needs_reply`

------------------------------------------------------------------------

## REST API

Base: `/wp-json/neural-forms/v1`

### Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/forms` | List forms |
| POST | `/forms` | Create form |
| PUT | `/forms/{id}` | Update form |
| DELETE | `/forms/{id}` | Delete form |
| GET | `/entries` | List entries (form_id, view, search, date_from, date_to) |
| GET | `/entries/{id}` | Get single entry |
| PATCH | `/entries/{id}` | Update entry |
| POST | `/entries/bulk` | Bulk status change |
| GET | `/entries/counts` | View counts |
| GET | `/entries/export` | CSV export |
| POST | `/submit` | Public form submission |
| GET/PATCH | `/settings` | Plugin settings |
| POST | `/settings/test-email` | Send test email |
| POST | `/ai/process-queue` | Trigger queue run (admin) |
| POST | `/entries/{id}/reply` | Send reply |
| POST | `/entries/{id}/retry-ai` | Re-queue AI analysis |
| GET | `/entries/{id}/messages` | Entry message thread |
| GET | `/stats` | Dashboard stats |

### Rules

- Do not break response shape
- Use headers for totals (`X-WP-Total`, `X-WP-TotalPages`)
- API keys are write-only: GET returns `*_set: bool`, never raw values

------------------------------------------------------------------------

## Settings Keys (Config.php defaults)

| Key | Default | Notes |
|-----|---------|-------|
| `ai_enabled` | `false` | Master AI switch (mirrored from `cloud_ai_enabled` server-side in `SettingsEndpoint::patch_settings()` on save) |
| `ai_auto_process` | `true` | Auto-queue on submission |
| `privacy_mode` | `false` | Skip IP + user-agent storage |
| `sender_name` | `''` | Email From name |
| `sender_email` | `''` | Email From address |
| `reply_subject_pattern` | `'Re: Your submission'` | |
| `enable_admin_notification` | `true` | |
| `admin_notification_email` | `''` | Falls back to WP admin email |
| `enable_autoresponder` | `false` | |
| `autoresponder_subject` | `'Thank you for your submission'` | |
| `autoresponder_body` | `(template)` | |
| `ai_spam_enabled` | `false` | |
| `ai_spam_mode` | `'suggest'` | `'suggest'` or `'auto'` |
| `ai_spam_threshold` | `85` | 60-100 |
| `email_skip_spam_notifications` | `false` | |
| `captcha_provider` | `'none'` | `'none'`, `'turnstile'`, `'recaptcha_v2'` |
| `captcha_site_key` | `''` | |
| `captcha_secret_key` | `''` | Write-only |
| `cloud_ai_enabled` | `false` | Enable Neural Forms Cloud |
| `cloud_api_key` | `''` | Write-only |
| `cloud_api_base_url` | `'https://api.neuralforms.ai'` | Override via constant/filter |

------------------------------------------------------------------------

## Credits Widget (Settings screen)

Lives in `modules/admin/src/screens/Settings.js`. Shows the user how many
AI credits their current Cloud plan has left.

- Always shows one line: **`Available credits: {remaining}`**
- No progress bar, no `/ total` denominator (deliberately removed in 0.2.0 — users found it confusing because the denominator resets on renewal while top-ups persist)
- Low-balance alert panel appears only when `remaining < threshold`:
  - **Pro plans** (`plan.id` starts with `pro`): threshold = **500**
  - **Lite / free**: threshold = **30**
- Alert panel renders a "Buy tokens" button linked to the Elicus store

Relevant source:

```js
const planId    = String( cloudPlan?.id ?? '' ).toLowerCase();
const isPro     = planId.startsWith( 'pro' );
const threshold = isPro ? 500 : 30;
const remaining = Math.max( 0, cloudBalance?.credits ?? 0 );
const isLow     = remaining < threshold;
```

CSS lives under `.neural-forms-cloud-plan__meter-*` in `modules/admin/src/index.css`.
Bar/fill classes (`__meter`, `__bar`, `__bar-fill`, `__bar--ok/mid/low`) were
removed as dead code. Keep `__meter-cta`, `__meter-msg`, `__meter-btn`.

------------------------------------------------------------------------

## WP-Cron Hooks

| Hook | Schedule | Handler | Purpose |
|---|---|---|---|
| `neural_forms_process_queue` | 5 min + 10s single-event after submission | `AIQueueProcessor::process_queue()` | Drain `neural_forms_queue` (AI analysis jobs) |
| `neural_forms_ai_draft_purge` | daily | `AIResponseRepository::purge_older_than( DRAFT_TTL_DAYS )` | Garbage-collect stale (non-finalized) carousel drafts older than 30 days |

Both hooks are scheduled in `Activator::activate()`, cleared in
`Deactivator::deactivate()`, and self-scheduled in `NeuralForms::boot()`
guarded by `wp_next_scheduled()` so upgrades from older versions don't
require a re-activation to pick the new job up.

------------------------------------------------------------------------

## Capability / Unlock Architecture

Lite owns every premium feature's implementation. Pro does not re-implement
anything — it only flips capability filters. This keeps one code path and
avoids drift between Lite and Pro versions of the same feature.

**Documented exception — site profile.** The monthly business-context
classifier (homepage scrape + cloud `/v1/sites/profile` call + "Refresh now"
button) lives wholly in Pro: PHP service, REST endpoint under
`/neural-forms-pro/v1/`, cron interval, React fill, CSS. Lite only mounts
an empty `<SettingsSiteProfile>` SlotFill + upsell fallback. This is
allowed because site profile is a standalone monthly sidecar with zero
coupling to Lite's AI queue, submission pipeline, or entry endpoints —
unlike spam / reanalyze / polish, which thread through shared Lite code
and therefore stay Lite-resident behind capability filters. Do not use
this exception as a template; the default is still "Lite owns engine".

### Two-layer gating

Every premium entry point layers two checks:

1. **`Capabilities::can( $cap )`** — plugin-level UX gate: "is Pro installed?"
   Pure filter. Fail = user sees a Lite upsell.
2. **`PlanManager::can( $feature )`** — Cloud plan gate: "does plan permit it?"
   Fail = user sees an "upgrade your Cloud plan" prompt.
3. Then execute the feature / call the provider.

Distinct error codes so the frontend can route to the right upgrade flow:

- `pro_feature_locked` — plugin cap locked (install Pro)
- `feature_locked` — Cloud plan cap locked (upgrade plan)

### Registry: `modules/core/Capabilities.php`

```php
Capabilities::AI_SPAM_DETECT                  // 'ai_spam_detect'
Capabilities::AI_REANALYZE                    // 'ai_reanalyze'
Capabilities::AI_REFINE_REPLY                 // 'ai_refine_reply'
Capabilities::AI_FORM_GENERATE                // 'ai_form_generate'
Capabilities::AI_PROCESS_ENTRIES              // 'ai_process_entries' (added 0.2.0)
Capabilities::PAGE_PERFORMANCE_ANALYTICS      // 'page_performance_analytics' (added 1.1.0)
Capabilities::POLL_QUIZ_UNLIMITED_SUBMISSIONS // 'poll_quiz_unlimited_submissions' (added 1.1.0)
Capabilities::FIELD_MATRIX                    // 'field_matrix' (added 1.1.0)
Capabilities::FIELD_REPEATER                  // 'field_repeater' (added 1.1.0)
Capabilities::INTEGRATIONS                    // 'integrations' (added 1.1.0)

Capabilities::can( $cap )      // apply_filters("neural_forms_can_{$cap}", false)
Capabilities::all()            // snapshot of every cap (localized to React)
Capabilities::locked_error()   // WP_Error with pro_feature_locked, status 403
```

### Capability filters (default `false`)

| Capability | Filter | Unlocks |
|---|---|---|
| `ai_spam_detect` | `neural_forms_can_ai_spam_detect` | Spam settings UI, `ai_spam_enabled` save, queue spam classification |
| `ai_reanalyze` | `neural_forms_can_ai_reanalyze` | `POST /entries/{id}/retry-ai`, regenerate reply |
| `ai_refine_reply` | `neural_forms_can_ai_refine_reply` | Polish/refine reply endpoint |
| `ai_form_generate` | `neural_forms_can_ai_form_generate` | "Create with AI" option in the +New Form dropdown, which opens a prompt modal that POSTs to Pro's `/neural-forms-pro/v1/ai/generate-form` endpoint |
| `ai_process_entries` | `neural_forms_can_ai_process_entries` | Per-form "Process with AI" mode (auto/manual) actually enqueueing/running entry analysis — Lite stores the setting but never calls the cloud without this cap |
| `page_performance_analytics` | `neural_forms_can_page_performance_analytics` | Page Performance section of the Analytics screen (entries grouped by source page); source-page capture itself is unconditional in Lite, only the aggregation endpoint + UI are gated |
| `poll_quiz_unlimited_submissions` | `neural_forms_can_poll_quiz_unlimited_submissions` | Lifts the 200 non-trash submission cap per poll/quiz form (`neural_forms_poll_quiz_submission_limit` filter sets the limit; `<= 0` also means unlimited). Local product limit, not a security boundary — see `SubmitEndpoint::check_poll_quiz_submission_limit()` |
| `field_matrix` | `neural_forms_can_field_matrix` | Matrix field type (survey rows/columns, radio or checkbox selection) — Lite ships the full engine; locked installs show it as a Pro-upsell in the builder palette and the frontend renderer skips it |
| `field_repeater` | `neural_forms_can_field_repeater` | Repeater field type (repeatable child-field groups) — Lite ships the full engine; locked installs show it as a Pro-upsell in the builder palette and the frontend renderer skips it |
| `integrations` | `neural_forms_can_integrations` | Integrations admin page (HubSpot, WhatsApp, Mailchimp, Google Forms, Zapier tabs). Lite ships the page shell/menu/React screen as a locked upsell; no provider config is wired up on either side yet — each tab is a "Coming soon" card |

### Where the checks are applied

| File | Method | Capability |
|---|---|---|
| `modules/rest/endpoints/EntriesEndpoint.php` | `reanalyze_entry()` | `AI_REANALYZE` |
| `modules/rest/endpoints/EntriesEndpoint.php` | `regenerate_reply()` | `AI_REANALYZE` |
| `modules/rest/endpoints/EntriesEndpoint.php` | `polish_reply()` | `AI_REFINE_REPLY` |
| `modules/ai/AIQueueProcessor.php` | `maybe_apply_spam()` | `AI_SPAM_DETECT` |
| `modules/rest/endpoints/SettingsEndpoint.php` | Save validation + defensive coercion | `AI_SPAM_DETECT` |
| `modules/admin/AdminPanel.php` | `enqueue_new_form_dropdown_injector()` | `AI_FORM_GENERATE` |
| `modules/ai/AiProcessingResolver.php` | resolver entry point | `AI_PROCESS_ENTRIES` |
| `modules/rest/Endpoints/StatsEndpoint.php` | Page Performance aggregation | `PAGE_PERFORMANCE_ANALYTICS` |
| `modules/rest/Endpoints/SubmitEndpoint.php` | `check_poll_quiz_submission_limit()` | `POLL_QUIZ_UNLIMITED_SUBMISSIONS` |
| `modules/rest/Endpoints/SubmitEndpoint.php` | matrix field submission validation | `FIELD_MATRIX` |
| `modules/frontend/FormRenderer.php` | Matrix field render | `FIELD_MATRIX` |
| `modules/frontend/FormRenderer.php` | Repeater field render | `FIELD_REPEATER` |
| `modules/frontend/BlockHandler.php` | block editor localization (`fieldMatrix`, `fieldRepeater`) | `FIELD_MATRIX`, `FIELD_REPEATER` |
| `modules/admin/AdminPanel.php` | `script_data()` — localizes snapshot | all (via `Capabilities::all()`) |

Capability check always runs **before** the Cloud plan check, so a Lite
install gets `pro_feature_locked` (correct prompt) and a Pro install on a
free plan gets `feature_locked` (correct prompt).

### Frontend consumption

`window.neuralFormsAdmin.plugin.capabilities` is a `{cap: bool}` snapshot.
The React bundle reads it to choose between upsell UI (Lite) and unlocked
UI (Pro, rendered via SlotFill from the Pro bundle).

------------------------------------------------------------------------

## Pro Extension Points (do not remove)

These are the contract between Lite and Pro. Removing/renaming any of them
is a breaking change for Pro.

| Hook | Fired from | Used by Pro for |
|---|---|---|
| `neural_forms_booted` action | `NeuralForms::boot()` end | Register Pro modules only after Lite is fully up |
| `neural_forms_admin_script_data` filter | `AdminPanel::script_data()` | Add `pro` namespace (e.g. `diviActive`) to `window.neuralFormsAdmin` |
| `neural_forms_can_ai_spam_detect` filter | `Capabilities::can()` | `__return_true` to unlock spam detection |
| `neural_forms_can_ai_reanalyze` filter | `Capabilities::can()` | `__return_true` to unlock reanalyze / regenerate reply |
| `neural_forms_can_ai_refine_reply` filter | `Capabilities::can()` | `__return_true` to unlock polish reply |
| `neural_forms_can_ai_form_generate` filter | `Capabilities::can()` | `__return_true` to unlock "Create with AI" form generation |
| `neural_forms_can_ai_process_entries` filter | `Capabilities::can()` | `__return_true` to let the AI queue actually process entries |
| `neural_forms_can_page_performance_analytics` filter | `Capabilities::can()` | `__return_true` to unlock Page Performance analytics |
| `neural_forms_can_poll_quiz_unlimited_submissions` filter | `Capabilities::can()` | `__return_true` to lift the 200/form poll & quiz submission cap |
| `neural_forms_can_field_matrix` filter | `Capabilities::can()` | `__return_true` to unlock the Matrix field type |
| `neural_forms_can_field_repeater` filter | `Capabilities::can()` | `__return_true` to unlock the Repeater field type |
| `neural_forms_can_integrations` filter | `Capabilities::can()` | `__return_true` to unlock the Integrations admin page |
| `window.neuralForms.slots.registerFill` JS registry | Admin bundle at mount | Pro bundle registers SlotFills for premium UI regions |

React SlotFill names exposed by Lite (via `@wordpress/components createSlotFill`):

- `NeuralForms.EntryReplyActions` — slot above reply box in entry detail
- `NeuralForms.EntryAiActions` — slot for AI actions (reanalyze, regenerate)
- `NeuralForms.SettingsSpamPanel` — spam settings panel (Lite renders upsell fallback)
- `NeuralForms.SettingsSiteProfile` — business-context card (Pro-owned feature; Lite only renders the upsell fallback)
- `NeuralForms.SettingsIntegrations` — integrations tab (Divi status, etc.) —
  **not yet registered** in `modules/admin/src/slot-registry.js` as of
  1.1.0 (only `EntryAiActions`, `EntryReplyActions`, `SettingsSpamPanel`,
  `SettingsSiteProfile` are). Register it there before Pro can fill it.

------------------------------------------------------------------------

## Important Rules

DO NOT:
- Break API response contracts
- Change DB schema without version bump
- Cast file metadata arrays to string in PHP -- always handle the
  `file` field type explicitly (casting emits E_WARNING in PHP 8,
  which can corrupt HTTP status codes when display_errors is On)
- Reference direct AI providers (OpenAI, Anthropic, Google) -- Neural Forms
  uses Neural Forms Cloud exclusively
- Move AI logic (queue, providers, analyze, regenerate, polish, spam) into
  Pro. Lite owns the engine; Pro only unlocks it via filters. The single
  documented exception is the site-profile feature (see "Capability /
  Unlock Architecture" above) — do not use it as a template for new work.
- Rename the REST namespace, JS globals, DB tables, PHP namespaces, or
  `neural_forms_*` hook prefix. Installs depend on these.
- Rename, remove, or reshape any filter/action listed under "Pro Extension
  Points" without a coordinated Pro release.

ALWAYS:
- Keep changes minimal
- Follow existing patterns
- After admin JS/CSS changes, run the matching per-screen build
  (`npm run build:<screen>`) or `npm run build` to rebuild every
  bundle. Every screen ships from its own per-screen bundle under
  `modules/admin/build/`.
- When adding a new premium feature: implement in Lite behind a new
  `Capabilities::` constant + `neural_forms_can_*` filter, default false.
  Have Pro flip the filter in its `Unlocker`.

------------------------------------------------------------------------

## Admin build (1.1.0+)

Every admin screen ships from its own per-screen bundle under
`modules/admin/build/`. `npm run build` chains every `build:<screen>`
script via `webpack.split.config.js`.

```
cd modules/admin
npm install
npm run build
```

Per-screen scripts (use one when you only changed a single screen):
`build:builder`, `build:results`, `build:blocks-extra`, `build:forms`,
`build:entries`, `build:settings`, `build:stats`, `build:import`,
`build:integrations`, `build:templates`, `build:block`. Each emits
exactly one JS + one CSS + one asset.php without touching sibling
bundles (CleanWebpackPlugin is dropped from the split config).

------------------------------------------------------------------------

## Known Gotchas

### PHP E_WARNING corrupts HTTP status (LocalWP)

In LocalWP `display_errors = On`. Any PHP warning output before
`status_header()` is called causes the browser to receive HTTP 200
instead of the intended status. Always handle `file` type fields
explicitly in any code that iterates form fields and reads their values.

### Bash tool path issue after rename

The Bash tool's working directory is pinned to the path at session start.
The plugin folder has been renamed twice in its history (`formora` →
`neural-forms` → `neural-forms-lite`); any Claude session started before a
rename will see Bash commands fail with "path does not exist" once the
rename happens mid-session. Use Read/Edit/Glob tools directly with absolute
paths, or spawn Agent subagents for shell work.

### Phone field — Input Fields panel styling (fixed 2026-06-15)

The Phone field (`render_phone()` in `FormRenderer.php`) wraps the
`<input type="tel">` in `.neural-forms-form__phone` (the visible box,
with the country-flag `.neural-forms-form__phone-flag` button on the
left and a `.neural-forms-form__phone-dropdown` country picker as an
absolutely-positioned child). `form.css` strips the inner `<input>` of
its own border/radius/background so only the wrapper's box is visible.

Because of this, the Input Fields design panel's border / radius /
background / padding / focus / hover rules — which target
`.neural-forms-form__input` / `.neural-forms-form__select` — didn't
reach the Phone field's visible box. `BlockHandler::build_scoped_css()`
now also emits each of those rule sets against
`.neural-forms-form__phone` (and `:focus-within` / `:hover:not(:focus-
within)` for the focus/hover variants), plus a higher-specificity reset
(`0,3,1` vs form.css's `0,1,1`) that re-zeroes the inner
`.neural-forms-form__phone > input[type="tel"]`'s border/radius/
background so it doesn't double up with the wrapper.

The flag button (`.neural-forms-form__phone-flag`) lost its own
hardcoded background/border-right/colors (`form.css`) — it's now
transparent with `color: inherit` so it blends into the wrapper's
styled box.

**Separator + pill-radius overflow (fixed 2026-06-15, second pass)** —
a full-height `border-right` on the flag (even with matching
`border-radius`/`overflow: hidden` on the flag) still pokes past the
wrapper's curve once `fieldBorderRadius` is large enough to make the
field pill-shaped — the straight border line is taller than the
oval's curved edge at that x-position. Fixed by replacing the
`border-right` separator with a `.neural-forms-form__phone-flag::after`
pseudo-element: a short vertical line (`top: 8px; bottom: 8px`) that
never reaches the wrapper's rounded corners regardless of radius.
`BlockHandler::build_scoped_css()` sets its `background-color` from
the field border color (`.neural-forms-form__phone-flag::after{
background-color:... }`, computed the same way as before — handles
both linked and split/`right`-side border shapes). The flag itself no
longer needs `overflow: hidden` or left corner-radius mirroring (those
PHP/CSS rules were removed) since the separator can't overflow on its
own.

**Do not** set `.neural-forms-form__phone { overflow: hidden }` to fix
corner-overflow — the country-picker dropdown is an absolutely-
positioned child of `.neural-forms-form__phone` (`top: calc(100% + 4px)`)
and would get clipped. The pseudo-element separator above already
prevents overflow without needing `overflow: hidden` anywhere.

### Submission rate limit + client IP (fixed 2026-10-26, 1.1.3)

`SubmitEndpoint::resolve_ip()` returns `REMOTE_ADDR` by default and only
reads `CF-Connecting-IP` / `X-Forwarded-For` when `REMOTE_ADDR` matches
the `neural_forms_trusted_proxies` filter (IPs or CIDR ranges; empty by
default). Before 1.1.3 it trusted `X-Forwarded-For` unconditionally and
a junk value blanked the IP, which skipped the limiter entirely. The
limiter (`default_rate_limit()`) is keyed `md5( form_id . '|' . ip )`,
runs AFTER the honeypot check, and never exempts an empty IP. Tests:
`tests/SubmitRateLimitTest.php`. Keep the helper methods extractable by
regex (`private [static ]function name(...) ... \n\t}`) — the test
harness lifts them out of the file rather than booting WordPress.

### Entries (per-screen `build/entries.js` bundle)

- BEM root: `.neural-forms-entries-*` / `.neural-forms-entry-detail__*`;
  self-contained `src/entries.css`.
- Default All-Forms selection uses bare `?view=inbox`.
- Per-form sidebar folders nest six system views (Inbox / Unread /
  Needs Reply / Starred / Spam / Trash) under each form, scoped via
  `form_id=N&view=<id>`; selection is URL-synced (replaceState).
- Entry detail is URL-routed: `entry_id=N` swaps the right pane to
  a toolbar (Back / Mark unread/read / Star / Reply / Spam / Trash) +
  hero card (icon · form name · `Entry #N · date · IP` · source URL ·
  read pill) + `NeedsReplyBanner` + Submitted fields + AI Analysis +
  AI Drafts carousel + Conversation thread + Reply composer.
  `buildFieldLabels(form)` resolves field ids to human labels via the
  form's `form_schema`.
- Reanalyze / Generate reply / Refine with AI: when capability is
  unlocked, the buttons render via Pro's `EntryAiActions` /
  `EntryReplyActions` SlotFills (Pro fills the slot with real action
  buttons). When capability is locked (Pro absent), Lite renders the
  same-DOM lock-icon buttons that pop a brand-aligned cream/gold
  "Upgrade to Pro" notice instead of redirecting. Same CSS classes
  in both cases — Lite owns the styling.
- Per-action AI feedback: navy pulse on the AI Analysis card after
  reanalyze, mint pulse on the AI Drafts card + reply textarea after
  regenerate / refine, inline credit-used strip routed under the
  action that paid for it (analyze → between AI Analysis and AI
  Drafts; refine → under reply composer footer).

The shared admin page-header unifier
(`AdminPanel::page_hero_unifier_css()`) intentionally excludes
Entries — the entries bundle ships its own hero card defined in
`src/entries.css` sized to its 2-pane Gmail-style layout. Stats /
Forms list / Settings / Import are unified via the CSS injector;
Analytics ships its own (`nf-analytics-hero` in `src/results.css`);
Entries owns its layout.

------------------------------------------------------------------------

## Current Direction

- Lite/Pro split: Lite is the free engine, Pro is a paid add-on that
  unlocks premium features via capability filters — no duplicated logic
- Neural Forms Cloud as sole AI backend
- Per-form inbox system (Gmail-style UX)
- AI-powered entry analysis, replies, spam detection
- CSV export with date-range filtering
- CAPTCHA integration (Turnstile, reCAPTCHA v2)
- Connection-first settings UX: API key -> test -> unlock AI controls

------------------------------------------------------------------------

## Goal

Neural Forms should feel like a modern lead inbox system with intelligent
AI-powered analysis, not just a form plugin.

------------------------------------------------------------------------

## Build commands cheat sheet (1.1.0+)

Every Lite admin asset is a per-screen split bundle. `npm run build`
chains them all via `webpack.split.config.js`; per-screen scripts let
you rebuild a single bundle in isolation without touching siblings
(CleanWebpackPlugin is dropped from the split config).

| Bundle | Source entry | Per-screen command | When to rebuild |
|---|---|---|---|
| `build/builder.js` | `src/builder-entry.js` | `npm run build:builder` | After editing anything under `src/forms/` (Builder, FormSettings, formTemplates) |
| `build/results.js` | `src/results-entry.js` | `npm run build:results` | After editing `src/results/` (analytics + page-performance) or `src/results.css` |
| `build/forms.js` | `src/forms-entry.js` | `npm run build:forms` | Forms list screen — `src/screens/Forms.js` + `src/forms.css` |
| `build/stats.js` | `src/stats-entry.js` | `npm run build:stats` | Stats screen — `src/screens/Stats.js` + `src/stats.css` |
| `build/settings.js` | `src/settings-entry.js` | `npm run build:settings` | Settings screen — `src/screens/Settings.js` + `src/settings.css`. Pro fills `SettingsSpamPanel` and `SettingsSiteProfile` slots. |
| `build/entries.js` | `src/entries-entry.js` | `npm run build:entries` | Entries inbox — `src/entries/*` + `src/entries.css`. Pro fills `EntryAiActions` and `EntryReplyActions` slots. |
| `build/import.js` | `src/import-entry.js` | `npm run build:import` | Import (CF7 migration) — 3-state list / preview / result flow over `/migration/cf7/*`. |
| `build/integrations.js` | `src/integrations-entry.js` | `npm run build:integrations` | Integrations screen — `src/screens/Integrations.js` + `src/integrations.css` (Pro-ready provider config surfaces; also `src/forms/IntegrationsSection.js` in the builder). |
| `build/templates.js` | `src/templates-entry.js` | `npm run build:templates` | Template library modal — `src/templates/TemplateLibrary.js` + `TemplateLibraryModal.js`. |
| `build/block.js` | `src/block-index.js` | `npm run build:block` | Gutenberg form-rendering block (registered via `frontend/block/block.json`). |
| `build/blocks-extra.js` | `src/blocks-extra-entry.js` | `npm run build:blocks-extra` | After editing the Quiz / Multi-step / Poll Gutenberg blocks. |

### Node version

Use Node 18 (or newer) for any `npm run build*` invocation:

```
. ~/.nvm/nvm.sh && nvm exec 18 npm run build
```

### Picking the right approach for a given UI change

- **Builder** — edit `src/forms/`, run `build:builder`.
- **Analytics** — edit `src/results/`, run `build:results`.
- **Forms list** — edit `src/screens/Forms.js` + `src/forms.css`,
  run `build:forms`. `AdminPanel::enqueue_*_injector()` still ships
  inline JS for the shortcode column, "+ New Form" split button, etc.
- **Stats / Settings / Entries / Import / Integrations** — edit the
  matching `src/screens/` or `src/entries/` source + `src/<screen>.css`,
  run the corresponding `build:<screen>`.
- **Template library modal** — edit `src/templates/`, run `build:templates`.
- **Inline CSS/JS in PHP** — no build at all; ships the moment PHP
  is deployed.

### Smart build verification (run after every per-screen build)

1. `git status --short modules/admin/build/` should ONLY show the
   per-screen artefact you intended to rebuild plus its `.css` /
   `.asset.php` siblings.
2. PHP test sweep — run every file under `tests/`:
   `for f in tests/*.php; do php "$f" 2>&1 | tail -1; done`
