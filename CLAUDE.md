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
  - `NEURAL_FORMS_LITE_VERSION` — canonical Lite version (Pro dep check reads this). Currently `1.2.0` on dev. The 2026-10-29 cut of `release/1.2.0` (Release A only) was NOT published: the owner clarified that the whole draft (Release A + B) is version 1.2.0, so Release B lands on dev first and `release/1.2.0` is re-cut from dev when it is done (see `docs/RELEASE-1.2-PLAN.md` and `docs/RELEASE-1.2-PLAN-B.md`).
  - `NEURAL_FORMS_VERSION` — kept as an alias (= `NEURAL_FORMS_LITE_VERSION`) for back-compat
  - `NEURAL_FORMS_DB_VERSION` — schema version consumed by `Database::maybe_upgrade()`. Currently `1.2.1` (1.2.0 added the lead-management columns, `meta` on `neural_forms_entry_ai_responses` and the three `neural_forms_workflow*` tables; 1.2.1 adds the pricing / payment columns on entries). The schema version intentionally runs ahead of the plugin version inside the 1.2.0 cycle so dev sites that already migrated pick up the part-B columns. Bump this whenever you change a table column or add a migration so existing installs re-run `dbDelta()` on admin_init.

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
| `frontend/block/` | Gutenberg form block (`neural-forms/form`) | `block.json` -- single source of truth for the 600-line attribute schema |
| `frontend/blocks/` | Metadata-only `block.json` for the 3 extra blocks (added 2026-07-01) | `quiz/block.json`, `multi-step/block.json`, `poll/block.json` |
| `notifications/` | Email system | `NotificationService.php` |
| `privacy/` | WordPress privacy tools (added 1.1.3) | `PrivacyTools.php` — registers the personal-data exporter + eraser (entries matched by submitted email via `EntryRepository::find_by_email()`) and suggested privacy-policy text |
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

| Tier | Model (as of 2026-10-28) | Used for |
|---|---|---|
| `fast` | Google `gemini-3.5-flash-lite` | Lite / free; universal fallback and final retry of every task chain |
| `balanced` | OpenAI `gpt-6-luna` | Second link in every chain (no plan maps to it directly) |
| `smart` | Anthropic `claude-haiku-4-5` | Pro Starter / Pro Agency / Pro Enterprise |

The previous ladder (`gpt-4.1-nano` fast, `gemini-2.5-flash-lite`
balanced/fallback) died on 2026-10-23 when OpenAI shut `gpt-4.1-nano`
down and Google closed `gemini-2.5-flash-lite` to new projects: every
chain link 503'd and the inbox showed "Neural Forms Cloud API error:
HTTP 503". There is no `premium` tier (Opus was retired cloud-side).
Keep this table in sync with `neural-forms-api/src/providers/ai/
model-registry.ts`.

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
| POST | `/entries/{id}/reanalyze` | Re-queue AI analysis |
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
| `neural_forms_process_ai_queue` | 5 min + 10s single-event after submission | `AIQueueProcessor::run()` | Drain `neural_forms_queue` (AI analysis jobs) |
| `neural_forms_ai_draft_purge` | daily | `AIResponseRepository::purge_older_than( DRAFT_TTL_DAYS )` | Garbage-collect stale (non-finalized) carousel drafts older than 30 days |
| `neural_forms_follow_up_sweep` | hourly | `WorkflowEngine::sweep_overdue_follow_ups()` | Fire the `follow_up_overdue` workflow trigger once per overdue, open follow-up (1.2.0) |
| `neural_forms_quote_expire` | daily | `QuoteService::expire_due()` | Move issued quotes past `valid_until` to `expired` (1.2.0 part B) |
| `neural_forms_payment_reconcile` | daily | `PaymentService::reconcile_due()` | Ask the gateway about attempts still open after an hour (PAY12) (1.2.0 part B) |

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
Capabilities::AI_PROCESS_ENTRIES              // 'ai_process_entries' (added 0.2.0)
Capabilities::PAGE_PERFORMANCE_ANALYTICS      // 'page_performance_analytics' (added 1.1.0)
Capabilities::POLL_QUIZ_UNLIMITED_SUBMISSIONS // 'poll_quiz_unlimited_submissions' (added 1.1.0)
Capabilities::FIELD_MATRIX                    // 'field_matrix' (added 1.1.0)
Capabilities::FIELD_REPEATER                  // 'field_repeater' (added 1.1.0)
Capabilities::INTEGRATIONS                    // 'integrations' (added 1.1.0)
Capabilities::CONDITIONAL_LOGIC_ADVANCED      // 'conditional_logic_advanced' (added 1.2.0)
Capabilities::WORKFLOWS                       // 'workflows' (added 1.2.0)
Capabilities::AI_BUSINESS_CONTEXT             // 'ai_business_context' (added 1.2.0)
Capabilities::CALCULATIONS                    // 'calculations' (added 1.2.0 part B)
Capabilities::QUOTES                          // 'quotes' (added 1.2.0 part B)
Capabilities::PAYMENTS                        // 'payments' (added 1.2.0 part B)

Capabilities::can( $cap )      // apply_filters("neural_forms_can_{$cap}", false)
Capabilities::all()            // snapshot of every cap (localized to React)
Capabilities::locked_error()   // WP_Error with pro_feature_locked, status 403
```

### Capability filters (default `false`)

| Capability | Filter | Unlocks |
|---|---|---|
| `ai_spam_detect` | `neural_forms_can_ai_spam_detect` | Spam settings UI, `ai_spam_enabled` save, queue spam classification |
| `ai_reanalyze` | `neural_forms_can_ai_reanalyze` | `POST /entries/{id}/reanalyze`, regenerate reply |
| `ai_refine_reply` | `neural_forms_can_ai_refine_reply` | Polish/refine reply endpoint |
| `ai_process_entries` | `neural_forms_can_ai_process_entries` | Per-form "Process with AI" mode (auto/manual) actually enqueueing/running entry analysis — Lite stores the setting but never calls the cloud without this cap |
| `page_performance_analytics` | `neural_forms_can_page_performance_analytics` | Page Performance section of the Analytics screen (entries grouped by source page); source-page capture itself is unconditional in Lite, only the aggregation endpoint + UI are gated |
| `poll_quiz_unlimited_submissions` | `neural_forms_can_poll_quiz_unlimited_submissions` | Lifts the 200 non-trash submission cap per poll/quiz form (`neural_forms_poll_quiz_submission_limit` filter sets the limit; `<= 0` also means unlimited). Local product limit, not a security boundary — see `SubmitEndpoint::check_poll_quiz_submission_limit()` |
| `field_matrix` | `neural_forms_can_field_matrix` | Matrix field type (survey rows/columns, radio or checkbox selection) — Lite ships the full engine; locked installs show it as a Pro-upsell in the builder palette and the frontend renderer skips it |
| `field_repeater` | `neural_forms_can_field_repeater` | Repeater field type (repeatable child-field groups) — Lite ships the full engine; locked installs show it as a Pro-upsell in the builder palette and the frontend renderer skips it |
| `integrations` | `neural_forms_can_integrations` | Integrations admin page (HubSpot, WhatsApp, Mailchimp, Google Forms, Zapier, Stripe, PayPal tabs). Lite ships the page shell/menu/React screen as a locked upsell; Pro wires every provider's config, test and delivery |
| `conditional_logic_advanced` | `neural_forms_can_conditional_logic_advanced` | Nested condition groups ("A and (B or C)"), conditions on sections and steps, ordered conditional confirmations. Gates the editor and SAVING advanced structures only — `SubmitEndpoint` always evaluates stored rules, so a lapsed Pro install never leaks a hidden field (decision D1 in `docs/RELEASE-1.2-PLAN.md`) |
| `workflows` | `neural_forms_can_workflows` | Workflow editor, runs/history, lead stage / assignee / follow-up controls in the inbox (engine lands in milestone A3/A4) |
| `ai_business_context` | `neural_forms_can_ai_business_context` | Per-form AI behaviour (tone, language, knowledge picker) and sending `ai_behaviour` to the cloud (milestone A5) |
| `calculations` | `neural_forms_can_calculations` | Priced Item + Calculated fields, Form settings › Pricing, server-side pricing snapshot (part B, B1) |
| `quotes` | `neural_forms_can_quotes` | Quote writes (create / edit / issue / revise / send / settings) and the inbox Quote card actions; stored quotes stay readable when locked (part B, B2) |
| `payments` | `neural_forms_can_payments` | Hosted checkout after submit (Stripe / PayPal providers live in Pro), Form settings › Payments, `POST /payments/{id}/check`; stored attempts stay readable when locked (part B, B3) |

### Where the checks are applied

| File | Method | Capability |
|---|---|---|
| `modules/rest/endpoints/EntriesEndpoint.php` | `reanalyze_entry()` | `AI_REANALYZE` |
| `modules/rest/endpoints/EntriesEndpoint.php` | `regenerate_reply()` | `AI_REANALYZE` |
| `modules/rest/endpoints/EntriesEndpoint.php` | `polish_reply()` | `AI_REFINE_REPLY` |
| `modules/ai/AIQueueProcessor.php` | `maybe_apply_spam()` | `AI_SPAM_DETECT` |
| `modules/rest/endpoints/SettingsEndpoint.php` | Save validation + defensive coercion | `AI_SPAM_DETECT` |
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
| `neural_forms_can_ai_process_entries` filter | `Capabilities::can()` | `__return_true` to let the AI queue actually process entries |
| `neural_forms_can_page_performance_analytics` filter | `Capabilities::can()` | `__return_true` to unlock Page Performance analytics |
| `neural_forms_can_poll_quiz_unlimited_submissions` filter | `Capabilities::can()` | `__return_true` to lift the 200/form poll & quiz submission cap |
| `neural_forms_can_field_matrix` filter | `Capabilities::can()` | `__return_true` to unlock the Matrix field type |
| `neural_forms_can_field_repeater` filter | `Capabilities::can()` | `__return_true` to unlock the Repeater field type |
| `neural_forms_can_integrations` filter | `Capabilities::can()` | `__return_true` to unlock the Integrations admin page |
| `neural_forms_can_conditional_logic_advanced` filter | `Capabilities::can()` | `__return_true` to unlock condition groups / section + step conditions / conditional confirmations (1.2.0) |
| `neural_forms_can_workflows` filter | `Capabilities::can()` | `__return_true` to unlock workflows + lead management (1.2.0) |
| `neural_forms_can_ai_business_context` filter | `Capabilities::can()` | `__return_true` to unlock business knowledge / voice / language for AI drafts (1.2.0) |
| `window.neuralForms.slots.registerFill` JS registry | Admin bundle at mount | Pro bundle registers SlotFills for premium UI regions |

Slot names exposed by Lite through its own registry `window.neuralForms.slots` (`modules/admin/src/slot-registry.js`, bare names, `registerFill( name, component )`; the same file installs the live plan store `window.neuralForms.plan` with `get / set / subscribe`, fed by Settings after Test connection so Pro fills re-render without a reload):

- `EntryReplyActions` — slot above reply box in entry detail (Pro `RefineWithAiFill.js`)
- `EntryAiActions` — slot for AI actions (Pro `ReanalyzeRegenerateFill.js`)
- `SettingsSpamPanel` — spam settings panel (Lite renders upsell fallback)
- `SettingsSiteProfile` — business-context card (Pro-owned feature; Lite only renders the upsell fallback)
- `SettingsLicense` — registered but no `<ProSlot>` renders it and Pro has no fill: Pro's `/license*` REST routes have no UI. Decide whether the cloud key IS the license (then delete Pro's licensing module) or build the panel.

------------------------------------------------------------------------

## Branch flow (keep main, release/* and dev from diverging)

`dev` is where work lands. A release is cut as `release/<version>` from
`dev`, then `dev` is bumped to the next version. After the release ships:

1. merge `release/<version>` into `main` (`--no-ff`), and
2. merge `main` back into `dev`,

so every branch contains the others' history and the next merge is
clean. **Never commit directly on `main`** — a one-line readme edit made
there on 2026-07-01 (a date fix the release branch also had) was the
only reason the 1.1.3 → main merge conflicted on 2026-10-29. Release
fixes (changelog wording, typos) go on the `release/*` branch and are
merged forward to `main` and `dev` the same way.

**Release branches ship only plugin code.** Right after cutting
`release/<version>`, run `bash <(git show dev:bin/strip-dev-files.sh)` (from a
copy, so nothing in the tree is staged) and commit — it removes
`CLAUDE.md`, `.claude/`, `tests/`, `bin/`, `docs/`, phpcs / phpunit
configs and the root `package.json` from the branch (the
`modules/admin` build tooling stays). No Claude co-author trailer on
release-branch commits. When merging `main` back into `dev`, use
`git merge --no-commit main` and then `git checkout dev -- CLAUDE.md
tests bin docs phpcs.xml.dist package.json` before committing, so `dev`
keeps its tooling.

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
`build:integrations`, `build:quotes`, `build:payments`, `build:block`. Each emits
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

### Rating field — hover-color selector bugs (fixed 2026-07-03)

Two separate, easy-to-repeat mistakes surfaced while wiring the Rating
field's hover color to mirror `ratingFilledColor` in
`BlockHandler::build_scoped_css()`:

1. **Don't set color on the parent `.rating-star` label when a sibling
   rule already sets color directly on the child `.rating-glyph` span.**
   `ratingEmptyColor` sets `color` directly on
   `.neural-forms-form__rating-glyph` unconditionally. A hover/checked
   rule that instead sets `color` on the parent `.rating-star` only
   reaches the glyph via CSS inheritance — and a direct declaration on
   an element always wins over an inherited value from its ancestor,
   *regardless of the ancestor rule's selector specificity*. Any new
   color rule for the Rating field must target `.rating-glyph` directly
   (like the existing `ratingFilledColor` rule does), not rely on
   inheriting from `.rating-star`.
2. **Never repeat a compound selector variable (e.g. `$rating_star_sel`,
   which already includes the `$wrap_sel` block-scope prefix) after a
   `~` sibling combinator.** Writing
   `$rating_star_sel . ':hover ~ ' . $rating_star_sel . ' .rating-glyph'`
   duplicates `$wrap_sel` mid-selector, producing
   `.wrap-X .rating-star:hover ~ .wrap-X .rating-star .rating-glyph` —
   this asks for a `.wrap-X` element that is a *sibling* of the hovered
   star, which can never match since the wrapper is an ancestor, not a
   sibling. The rule then only matches the exact hovered star itself,
   not the flood-fill of preceding-in-DOM/following-visually siblings
   the `row-reverse` trick depends on (see `form.css:591-596` +
   `624-638`). Use `$wrap_sel` (or the compound selector) as a
   **single** prefix per alternative, then plain unscoped class names
   for everything after the first combinator — exactly the pattern the
   `ratingFilledColor` rule (`BlockHandler.php` ~line 2400-2403) already
   uses correctly.

**Why:** both bugs are silent — no PHP error, no console warning, the
CSS just doesn't match what you'd expect from reading the selector
string, and they only show up as a visual mismatch when manually
testing hover behavior with a non-default color set.
**How to apply:** when adding any new color/typography rule to the
Rating field (or any other sibling-flood selector using the
`row-reverse` + `~` pattern), (a) always target `.rating-glyph`
directly rather than the parent `.rating-star`, and (b) never reuse a
`$wrap_sel`-prefixed variable a second time within the same selector
string — expand it once, then drop back to bare class names.

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

### Security audit batch (2026-10-28, 1.1.3)

- **Uploads are `private` attachments** (`SubmitEndpoint`): an unattached
  `inherit` attachment is public via `/wp/v2/media`. Keep `private`.
- **CSS values from block attributes** go through
  `BlockHandler::css_text()` / `css_url()` inside `build_wrap_style()` and
  `build_scoped_css()` — never plain `sanitize_text_field()` / `esc_attr()`
  there (they leave `{ } ;`). Tests: `tests/BlockCssSanitizerTest.php`.
- **Submission shape / size**: `SubmissionValidator::check_shape()` runs
  before required/type checks (rejects arrays for scalar fields, caps
  lengths via `max_length_for()` + `neural_forms_max_field_length`, caps
  array fields at `MAX_ARRAY_ITEMS`). Tests: `tests/SubmissionShapeLimitsTest.php`.
- **Editor preview branch** in `BlockHandler::render()` requires
  `edit_posts` in addition to `REST_REQUEST`.
- **Logs**: `neural-forms-<token>-YYYY-MM-DD.log` (token in option
  `neural_forms_log_token`); `.htaccess` carries `Require all denied`.
- **Caps**: settings writes + `/ai/process-queue` → `manage_options`.
- **Do not use the `mixed` type hint** anywhere (Requires PHP 7.4). Run
  the test suite under both 7.4 and 8.2 (Local ships
  `lightning-services/php-8.2.*`).

### Create form with Neural AI — Lite-owned (moved from Pro 2026-10-30)

The owner asked for form generation in Lite too, without duplicated
logic, so the whole engine MOVED (not copied) from Pro:
`modules/ai/SchemaNormalizer.php` (AI shape → builder schema, aliases,
container cleanup, matrix / repeater gated by their field caps, final
`FormValidator` pass), `modules/ai/CloudFormGeneratorClient.php`
(`POST {cloud}/v1/ai/forms/generate`, same headers / base-URL resolver as
`NeuralFormsCloudProvider`, cloud error → plugin code map; cloud 5xx →
424), `modules/ai/FormGeneratorService.php` (prompt limits, `status()`
= cloud connected + Cloud AI on + credits, `generate()`), REST
`POST|GET /neural-forms/v1/ai/generate-form[/status]`
(`FormGeneratorEndpoint`, manage-forms cap only). Entry points: the
"+ New Form ▾ Create form with Neural AI" modal (inline JS in
`AdminPanel::new_form_dropdown_injector_js()`) and the builder's
`forms/AiPromptPanel.js` (boot flags from
`neuralFormsAdmin.plugin.aiFormGenerate`). The `ai_form_generate`
capability, Pro's Unlocker filter, `PlanNormalizer` and the Pro-license
gate were deleted (pre-launch, no shims); the cloud's Pro-only plan gate
on `/v1/ai/forms/generate` was removed the same day, so Lite plans
generate and pay with credits like any other AI call. Tests:
`tests/FormGeneratorTest.php` (167 checks, ported from Pro).

### Security + integrity audit (2026-10-30)

Six parallel reviews (PHP escaping, JS XSS, payments/REST, duplication,
dead code, wiring) over Lite + Pro. Rules that came out of it:

- **Every CSS value from block / module attributes goes through a guard.**
  Lite: `BlockHandler::css_text()` (border *width* was the one gap). Pro
  Divi: `RenderCallbackTrait::css_val()` wraps all 229 former
  `sanitize_text_field()` sites; `sanitize_css_color()` only accepts
  numeric `rgb()/hsl()`. `sanitize_text_field()` leaves `{ } ;`, which
  closes the scoped rule for any Author-level editor.
- **Checkout URLs**: `PaymentService::safe_checkout_url()` (http/https,
  plain host, no DNS lookup — `wp_http_validate_url()` resolves the host
  and broke offline/harness runs); the frontend only navigates to
  `https?://` or relative URLs. Return-page "Back" link goes through
  `wp_validate_redirect()`.
- **Payments**: buyer-cancel is a guarded `update_if_status()`; a crashed
  `apply()` deletes its dedupe event row so the gateway retry is processed;
  refunds hold a 60 s transient lock and reject gateway `failed/canceled`
  results; refund totals never move backwards (`stale_refund_total`);
  idempotency keys are site-scoped (`nf-{site8}-{entry}-{quote}-{attempt}`);
  `start_checkout` only accepts the form's own providers; superseding /
  expiring a quote calls `PaymentService::expire_open_for_quote()`;
  deleting an entry with a settled attempt is refused (409
  `entry_has_payments`), open attempts are closed first. PayPal events
  carry payee + environment from the event itself; refund events match the
  capture via the `up` link; Stripe ignores `charge.refund.updated`.
- **Buy endpoint** runs the `neural_forms_rate_limit` filter and the form's
  CAPTCHA (`FormRenderer::captcha_widget_html()` is shared with the card;
  buy-button.js also serialises textareas for the reCAPTCHA token).
- **424, never 5xx passthrough**: integration clients and Settings test
  connection map upstream 5xx to 424 (the guard test only sees literals).
- **Cron**: the activation hook attaches the `five_minutes` interval before
  scheduling (plugins_loaded has already fired there) and `boot()`
  self-heals `AIQueueProcessor::schedule()`.
- **Mail**: `NotificationService::reply_headers()` is the one source of
  From / Reply-To for inbox replies and retries.
- **Dead code removed**: templates bundle + legacy `webpack.config.js`,
  PRO-badge paths of the AI dropdown / AI panel, unused methods
  (`StatsEndpoint::ai_insights/system_status`, `EntriesEndpoint::
  update_entry_status`, PlanManager subscription helpers, SchemaHelper
  row/quiz defaults, `CloudFormGeneratorClient::origin_tag`), unused JS
  exports in `schemaHelper.js` / `FieldTypes.js` / `dndHelpers.js` /
  `hubspotAutoMap.js`, the buy block's colour attributes, the form block's
  `*MsgBorderColor`. CLI note: `php -d memory_limit=256M` for diviplus.
- **Known duplication left for a later pass** (see the audit): Pro's
  Divi VB `buildDesignCss` mirrors the PHP builder (~4.7k lines), the three
  Divi design-tab field kits, Stripe/PayPal settings+endpoints, the four
  integration dispatchers, Pro's `DeactivationPage`, Lite REST boilerplate,
  the captcha verifiers, quote/payment token helpers, JS money / API-error
  helpers. Each is a mechanical extraction with a named home in the report.

### Payment form ("Neural Payment Form"): block, shortcode, Divi module (1.2.0 part B, owner request 2026-10-30)

User-facing name **Neural Payment Form** (renamed from Buy Button the same day; internal names `neural-forms/buy-button`, `[neural-forms-buy]`, `BuyButton`, `neural-forms/divi-buy` stay).

- **Renderer** `modules/payments/BuyButton.php`: `resolve( $attrs )` picks the
  form (must be active + `PaymentService::enabled_for()`), the Priced Item
  (`fieldId` or first product), the option (`choice`) and the unit price
  (fixed / options; `input` mode refused); `render( $attrs, [ 'preview' ] )`
  emits `<form data-neural-forms-buy …>` with item, live price, quantity
  (when the field allows it), name + email, honeypot, hidden `field` /
  `choice`, and enqueues `neural-forms-buy` + `neural-forms-form` (self-
  registers the assets when rendered before `wp_enqueue_scripts`).
  Visitors get nothing when the card cannot sell; editors get a notice.
  `catalogue()` lists forms + products for the editors.
- **Endpoint** `POST /forms/{id}/buy` (`BuyEndpoint`): honeypot → fake
  201; `SubmitEndpoint::default_rate_limit()` + `resolve_ip()` (now
  public); server-side price + quantity clamp; maps email / name onto
  the form's own email / name fields; `EntryRepository::create()` with the
  pricing snapshot; fires `neural_forms_entry_created`,
  `neural_forms_send_notifications`, `neural_forms_queue_ai_processing`;
  activity `entry.buy_button`; then `PaymentService::start_checkout()` →
  `outcome.redirect`. Other required form fields are deliberately NOT
  collected (quick buy). `GET /buy-catalogue` and `GET /buy-preview`
  (edit_posts) serve the editors.
- **Gutenberg** `neural-forms/buy-button`: `frontend/blocks/buy-button/
  block.json` holds its own attributes, MERGED at registration (PHP + JS)
  with the form block's design schema. The card uses the form block's BEM
  classes (`neural-forms-form`, `__label`, `__input`, `__submit`,
  `__success` / `__global-error`) so the Style tab reuses `ContainerPanel`,
  `LabelsPanel`, `InputFieldsPanel`, `SubmitButtonPanel`, `MessagesPanel`,
  `AdvancedPanel` from `DesignPanels.js` and `BlockHandler::render_buy_button`
  wraps the card exactly like `render()` (uid, `build_scoped_css()`,
  `build_wrap_style()`, `extraClass`; form.css + pointer-events inlined in
  the editor preview). Editor: `src/blocks/buy/BuyButtonEdit.js` (blocks-extra bundle).
- **Shortcode** `[neural-forms-buy form="68" field="book" choice="" quantity="1"
  label="Buy now" title="" description="" name="1" price="1" width="auto|full"
  align="left|center|right"]`.
- **Frontend** `assets/js/buy-button.js` (no deps): live total, JSON POST,
  follows the redirect; styles `.neural-forms-buy*` in form.css (button
  colours come from the Style tab, the old colour attributes are gone).
- **Divi 5** (Pro): `modules/divi/Modules/NeuralBuy/NeuralBuy.php`
  (`neural-forms/divi-buy`, subclass of NeuralForm, `render_buy()` →
  `BuyButton::render()`), VB `src/modules/neural-buy/*` (content tab reads
  `/buy-catalogue`, renderer fetches `/buy-preview`). The trait helpers
  `adv_value()` / `module_render()` became `protected`.
- Tests: `tests/live/B3BuyButtonHarness.php` (26 checks: resolve, render,
  shortcode, do_blocks, catalogue, preview, buy endpoint incl. clamp,
  honeypot, invalid email, payments-off, posted prices ignored).

### Payments page, invoices, refunds (1.2.0 part B, owner request 2026-10-29)

- **Payments page** Neural Forms › Payments (`screens/Payments.js`, bundle
  `payments`): `GET /payments` (`PaymentRepository::search()` — status
  comma list, provider, form_id, kind, date_from/to, search over entry id /
  provider ids / meta incl. invoice number, paging + `X-Total-Count`) plus
  `PaymentRepository::totals()` per currency and mode (received, refunded,
  net, open, counts). Attempts store `customer_name`, `customer_email`,
  `form_name` in `meta` at creation for the list.
- **Invoices** (`modules/payments/InvoiceRenderer.php`): same escaped
  table-only document as quotes, "Invoice" with a PAID / PARTLY REFUNDED /
  REFUNDED stamp, lines from the quote snapshot (quote payments) or the
  entry pricing snapshot, amount paid, refunded, remaining balance, payment
  method + provider id; branding from `QuoteSettings`. Number
  `INV-YYYY-NNNN` assigned once (`PaymentService::ensure_invoice_number()`,
  stored in `meta.invoice_number`, same atomic counter as quotes). Routes:
  `GET /payments/{id}/invoice` (payment token OR logged-in admin; `?format=pdf`
  via `QuotePdf::from_html()`), `POST /payments/{id}/invoice/send` →
  `PaymentService::send_invoice()` (plain-text mail + PDF attachment +
  link, outgoing message row, `payment.invoice_sent` activity).
  **Automatic**: `maybe_auto_invoice()` after every newly paid attempt when
  `settings.payment.send_invoice` (default TRUE) and the entry has an email.
- **Designing the documents**: Quotes page fields (business block, logo,
  accent colour, footer, `invoice_title`, `invoice_note`, quote intro /
  terms) feed both renderers. Developer hooks: `neural_forms_invoice_css`
  / `neural_forms_quote_css` (inline stylesheet, dompdf-safe CSS only) and
  `neural_forms_invoice_html( $html, $payment, $entry, $form, $quote,
  $settings, $options )` / `neural_forms_quote_html( $html, $quote,
  $options )` to restyle or replace the whole document (escape everything).
- **Emails on a confirmed payment** (both per form, default on, in
  `settings.payment`): `send_invoice` → the customer's invoice with PDF
  (`maybe_auto_invoice()`); `notify_admin` + optional `notify_email` list →
  `maybe_notify_admin()` mails the site owner (amount, kind, customer,
  remaining balance, gateway reference, entry link; falls back to the
  plugin's admin notification address, then the WP admin email). Activity
  `payment.admin_notified`. Workflows (`payment_confirmed`) remain the
  way to do anything beyond that.
- **Refunds**: `PaymentProviderInterface::refund( $payment, $amount_minor,
  $reason )` (Stripe `POST /v1/refunds` by payment_intent, PayPal
  `POST /v2/payments/captures/{id}/refund`, both with idempotency keys);
  `PaymentService::refund()` checks cap, settled status and
  `paid − refunded`, calls the gateway, records a `refund.issued` event and
  applies a cumulative `refunded` event (so the gateway's own refund
  webhook is a harmless duplicate), activity `payment.refund_issued` /
  `payment.refund_failed`. `POST /payments/{id}/refund { amount_minor
  (0 = all), reason }`. `expose()` adds `refundable_minor` and `invoice
  { number, sent_at, sent_to }`. UI: Payments page dialogs + inbox
  `PaymentCard` buttons (Invoice, Send invoice, Refund…).
- Tests: `tests/PaymentsTest.php` (invoice document), live
  `B3PaymentsHarness.php` (auto invoice mail + PDF, public/admin invoice
  routes, manual send, partial → full refund, over-amount 422, gateway
  refusal 424, list + totals), Pro provider tests (refund normalisation).

### Payment workflows, quote pay links, inbox payment views (1.2.0 part B, B4)

- **Trigger `payment_confirmed`** (`WorkflowDefinition::TRIGGER_PAYMENT`):
  `WorkflowEngine::on_payment_confirmed()` listens to
  `neural_forms_payment_confirmed` (fired only by `PaymentService` after a
  verified webhook / server fetch) and dispatches with `trigger_context
  { payment_id, payment { id, provider, kind, currency, amount_paid_minor,
  remaining_minor, status, paid_at, livemode } }`. Event id
  `payment:{entry}:{payment_id}` → one run per attempt, redeliveries are
  no-ops. `submission_received` runs never carry paid context.
- **Conditions**: meta sources `payment_status` (entry aggregate) and
  `payment_kind` (deposit | full, set only on a payment run);
  `evaluation_context( $entry, $form, $trigger_context )` /
  `WorkflowRunner::evaluate( …, $trigger_context )`.
- **Tokens**: `AbstractAction::token_data( $ctx )` adds `{payment_status}`,
  `{payment_amount}`, `{payment_remaining}`, `{payment_kind}`,
  `{payment_currency}`, `{payment_provider}` to the entry data used by
  `notify_internal` / `send_acknowledgement` (from the trigger context on a
  payment run, otherwise from the stored aggregate). `$ctx['trigger_context']`
  is available to every action.
- **Action `create_quote_draft`** (`Actions/CreateQuoteDraftAction.php`,
  Lite): draft by default; settings `issue` (number assigned) and `send`
  (emails the issued quote; validation requires `issue`). Skips when the
  entry has no pricing or already has a draft / issued quote; cap `quotes`.
- **Quote pay links**: `QuoteService::is_payable()` (issued + valid +
  due > 0 + form takes payments + provider connected + nothing settled for
  that quote) adds `links.pay` → `GET /quotes/{id}/pay?token=` (QuotesEndpoint
  `public_pay`) which calls `PaymentService::start_checkout( …, [ 'quote' ] )`
  and 302s to the gateway; already paid → 409 page. The public quote page
  and the quote email show the "Pay … now" button / link.
- **Inbox**: views `awaiting_payment` / `paid` (`EntryRepository::VALID_VIEWS`,
  `AWAITING_PAYMENT_WHERE` / `PAID_WHERE`, also in `/entries/counts`), smart
  filters gated by the `payments` capability, `PaymentPill` on list rows.
- Tests: `tests/live/B4WorkflowPaymentsHarness.php` (34 checks: trigger
  once per attempt, condition on payment_kind, tokens in mail, draft vs
  issue+send, pay link 302 / reuse / bad token / already paid / expired,
  views + counts, dry run).

### Payments: Stripe + PayPal (1.2.0 part B, B3)

`modules/payments/` — Lite owns the engine; Pro ships the two gateways
(`neural-forms-pro/modules/payments/{Stripe,PayPal}/`) through the
`neural_forms_payment_providers` filter (id => `PaymentProviderInterface`).

- **Contract** (`PaymentProviderInterface`): `create_checkout( $payment,
  $entry, $form, $urls, $details )` → `{ session_id, url, account_id,
  livemode }`, `fetch_checkout()` / `capture()` → normalised state
  `{ state, paid, processing, failed, amount_minor, currency,
  provider_payment_id, account_id, livemode, amount_refunded_minor }`,
  `expire_checkout()`, `verify_webhook( WP_REST_Request )` → normalised
  event `{ event_id, kind paid|completed|processing|failed|expired|
  cancelled|refunded|ignored, session_id, provider_payment_id,
  amount_minor, currency, livemode, account_id }`. Hosted checkout only —
  no card data, no secrets in REST / logs / exports (PAY13).
- **Tables** (DB 1.2.3): `neural_forms_payments` (one row per attempt;
  `idempotency_key` UNIQUE = `nf-{entry}-{quote|0}-{attempt}`, PAY8) and
  `neural_forms_payment_events` (`(provider, event_id)` UNIQUE, written
  BEFORE processing, PAY5). The entry keeps only the aggregate
  `payment_status` (`PaymentStatus::ENTRY_STATUSES`: none | pending |
  processing | failed | cancelled | deposit_paid | paid |
  partially_refunded | refunded), recomputed by `PaymentService::sync_entry()`.
- **Status model** (`PaymentStatus`, pure, fixture-tested):
  `apply_event()` refuses mode / account / currency / amount mismatches
  (PAY4, PAY10), never regresses a settled attempt (PAY5), only `paid`
  sets `amount_paid_minor` + `paid_at`; `aggregate()` keeps a deposit as
  `deposit_paid` (never "paid in full", PAY2), a later failed attempt
  cannot hide a settled one, and flags `needs_reconciliation` when two
  settled attempts share one intent or exceed the total (PAY12).
- **Flow**: `SubmitEndpoint` stores the entry first, then (cap `payments`,
  `settings.payment.enabled`, `due_now > 0`) `PaymentService::start_checkout()`
  → the 201 `outcome` becomes `{ mode: redirect, url }` (D8); a gateway
  failure never fails the submission (`payment_error` on the body, attempt
  `failed`). Open attempts are reused inside 23 h for the same amount
  (PAY8) or marked `replaced` + expired at the gateway (PAY9); a form
  price change never touches an open attempt. Quote checkouts
  (`opts.quote`) take the quote's `due_now` and require
  `QuoteService::can_start_payment()` (CQ9 / CQ11).
- **The only paths to `paid`**: a signature-verified webhook
  (`POST /neural-forms/v1/payments/webhook/{provider}`, Lite-owned route,
  provider verifies) or a server-to-server `reconcile()` (daily cron, the
  inbox "Check status now", or the return page). The return page
  (`GET /payments/{id}/return?token&result`, HMAC token like quotes) only
  READS stored state after reconciling; a forged URL is a 404 (PAY3).
  PayPal captures an APPROVED order server-side in `capture()` and in the
  `CHECKOUT.ORDER.APPROVED` webhook (browser closed, PAY6).
- `do_action( 'neural_forms_payment_confirmed', $entry_id, $payment,
  $aggregate )` fires once per newly paid attempt (B4 maps it to the
  `payment_confirmed` workflow trigger). Refunds only change the financial
  status, never the lead stage (PAY11). Activity rows `payment.*`.
- **Form settings** `payment { enabled, provider auto|choice|<id>,
  submit_label, description, success_url, cancel_url }`
  (`FormSettingsSanitizer::sanitize_payment`, `FormValidator::
  validate_settings_payment`: cap → 403, no Priced Item → 422). Renderer:
  submit label becomes the payment label, `provider: choice` renders a
  `nf_payment_provider` radio group before the submit button.
- **Admin**: Integrations › Stripe / PayPal tabs (`PaymentProviderPanel`
  in `screens/Integrations.js`, Pro REST `/neural-forms-pro/v1/
  integrations/{stripe|paypal}/{status|settings|test|disconnect}`, secrets
  masked, per-mode test / live credentials in `TokenVault`), Form
  settings › Payments (`PaymentsSection`, reads `GET /payments/providers`),
  inbox `entries/PaymentCard.js` (aggregate, attempts, events, Check
  status now, copy checkout link). `GET /entries?payment_status=a,b`
  filters the list.
- Tests: `tests/PaymentsTest.php` (status model, tokens, sanitiser,
  validator, SQL); `tests/live/B3PaymentsHarness.php` registers a FAKE
  gateway through the filter and drives submit → redirect, PAY3/4/5/8/9/
  10/11/12 and quote checkouts without network access. Pro:
  `tests/StripeProviderTest.php` (signature verification on recorded
  payloads, encoding, normalisation), `tests/PayPalProviderTest.php`.
  Real gateway calls need the owner's test keys (not available locally).

### Quotes (1.2.0 part B, B2)

`modules/quotes/` — Lite owns the whole lifecycle; Pro flips `quotes`
and supplies the PDF renderer (dompdf vendored in Pro under
`vendor-elicus/dompdf`, `modules/quotes/QuotePdfProvider.php`, hooked
on `neural_forms_quote_pdf_available` / `neural_forms_quote_pdf`).

- **Table** `neural_forms_quotes` (DB 1.2.2): one row per revision —
  `number` (assigned at ISSUE from the atomic per-year counter option
  `neural_forms_quote_seq_<year>`, format `PREFIX-YYYY-0001`),
  `revision`, `supersedes_id`, `status draft|issued|expired|superseded`,
  `snapshot` (JSON: business block, customer, line_items in minor units,
  discount, deposit, totals, intro/terms/footer/notes, source,
  validity_days), `valid_until`, `token_seed`, `issued_*`, `sent_at`.
  Deleted with the entry (`EntriesEndpoint::delete_entry`).
- **Immutability (CQ8):** `QuoteService::update_draft()` refuses anything
  but drafts (409); `issue()` is one guarded `WHERE status = 'draft'`
  transition; a correction is `revise()` → new draft row (revision + 1,
  same number) and issuing it marks the previous row `superseded`.
- **Public links (CQ9/CQ10):** `QuoteTokens::derive( id, revision, seed )`
  = HMAC-SHA256 with `wp_salt('auth')`, base64url — nothing stored but
  the seed, so links can be rebuilt (copy / resend) and a NULL seed
  revokes them. `check()` also enforces status + `valid_until`. Routes
  `GET /quotes/{id}/view|pdf?token=…` answer raw HTML / PDF through
  `QuotesBoot::serve_raw()` (`X-Neural-Forms-Raw` header on the
  `WP_REST_Response`); expired / superseded → 410, anything else → 404.
  `QuoteService::can_start_payment()` is the gate B3 must use.
- **Rendering (CQ7):** `QuoteRenderer::html()` — one escaped, table-only,
  inline-CSS document for the admin preview (`/quotes/{id}/preview`,
  cookie auth + `_wpnonce`), the public view (print stylesheet) and the
  PDF (`mode => 'pdf'`, logo as a data URI via `QuotePdf::logo_data_uri()`).
  No PDF renderer → the public `/pdf` link falls back to the HTML view.
- **Send:** `QuoteService::send()` = plain-text email with the link (+ PDF
  attachment through `NotificationService::send_mail( …, $attachments )`),
  recorded as an outgoing `EntryMessageRepository` row (`meta.quote_id`)
  and `quote.sent` activity. Nothing is ever sent automatically.
  Activity types `quote.*` (and `payment.*`) are now listed/deleted by
  `EntryActivity` next to `entry.*` / `workflow.*`.
- **Settings:** option `neural_forms_quote_settings` (`QuoteSettings`),
  `GET|PUT /quotes/settings`; its OWN admin page **Neural Forms › Business Details** (menu slug still
  `neural-forms-quotes`, `screens/Quotes.js`, `build:quotes`; renamed from
  "Quotes" on 2026-10-30 because the page is branding, not a list) — the owner asked for it to be
  moved out of Settings on 2026-10-29 because the long Settings page was
  confusing. Inbox: `entries/QuoteCard.js` under the
  Pricing card (draft editor, issue, send, copy link, revise; upsell when
  locked).
- Tests: `tests/QuotesTest.php` (pure parts), `tests/live/B2QuotesHarness.php`
  (DB + REST + PDF, 70 checks, same bootstrap as the A6 harness). Pro:
  `tests/QuotePdfProviderTest.php`.
- **Harness note:** `ExportEndpoint` streams and `exit()`s, so the A6
  harness runs its CSV check + cleanup from a shutdown handler and
  captures the CSV through an `ob_start()` callback (WordPress flushes
  all buffers from its own shutdown hook first).

### Calculations: Product + Calculated fields, pricing (1.2.0 part B, B1)

- **Naming / no WooCommerce.** The schema type key is `product` (never
  rename it — stored forms depend on it) but every user-facing label says
  **"Priced Item"** (palette chip, inspector, hints, admin notices,
  validation messages, changelogs). Prices are defined inside the form
  schema; the feature has NO dependency on WooCommerce or any shop plugin
  and must never gain one. The owner raised this on 2026-10-29 after
  reading "Product" as a Woo product picker.

- **Engine** `modules/pricing/`: `Money` (integer minor units, currency
  exponent table, half-up `percent_of`), `Expression` (tokeniser +
  shunting-yard, `+ - * / ( )`, `round min max if`, no eval),
  `PriceEngine::evaluate( $form, $data, $hidden_ids )` → snapshot
  `{ currency, exponent, line_items[], subtotal, discount, total, deposit,
  due_now, remaining, calculated{}, errors[], formula_revision }`,
  `client_config()` for the frontend, `ProductFormatter::display()` for
  emails / CSV / AI / inbox. JS mirror: `computePricing()` +
  `nfEvalExpression()` in form-submit.js. Fixtures
  `tests/fixtures/pricing.json` (+ `pricing.client.json` emitted by the
  PHP test) run by `tests/PriceEngineTest.php` and `tests/PriceEngineTest.js`.
- **Field schemas.** `product`: `pricing { mode fixed|options|input,
  price, display select|radio|checkboxes, quantity {enabled,min,max,
  default}, min_amount, max_amount }` + `options[].price`; posted as
  `{id}[choice]` / `{id}[choice][]` / `{id}[qty]` / `{id}[amount]`,
  sanitised by `SubmitEndpoint::sanitize_product()` to `{ choice, qty,
  amount }`. `calculated`: `expression, format currency|number,
  decimals`; never posted, never in entry `data` (lives in the snapshot).
- **Form settings `pricing`** `{ currency, discounts[{ id, label,
  conditions (v2), type percent|fixed, value, max }], deposit { type
  none|percent|fixed, value } }` — `PriceEngine::normalize_settings()`,
  dropped when default-only. First matching discount wins; conditions see
  a product as its CHOICE, expressions see its TOTAL.
- **Validation.** `FormValidator::validate_product()` (prices parse,
  unique option values, bounds), calculated expressions parse,
  `validate_calculations()` (refs must be number/range/rating/product/
  calculated, no self-ref, `PriceEngine::find_cycle()`),
  `validate_settings_pricing()` (called from `validate_settings_logic`).
- **Gating** (`Capabilities::CALCULATIONS`): builder chips locked, renderer
  renders nothing for product/calculated (admin-only notice, like Matrix),
  submit strips product values and never evaluates pricing; stored
  snapshots stay readable. `calculated` values are stripped from every
  submission regardless.
- **Submit** (`SubmitEndpoint`): after sanitising, `PriceEngine::evaluate`
  with the hidden ids → `$context['pricing']` →
  `EntryRepository::create()` writes `pricing_snapshot`,
  `amount_total_minor`, `amount_due_minor`, `currency`, `payment_status`
  (`pending` when something is due, else `none`); 201 body gains
  `pricing { currency, total, due_now, remaining, formatted{} }`.
- **Frontend**: `data-neural-forms-pricing` (client config) on the form,
  `.neural-forms-form__totals` block, `data-neural-forms-line-total` per
  product, `<output data-neural-forms-calculated>`; `initPricing()` re-runs
  on input/change and after every conditional pass.
- **Builder**: `PricingFieldsPalette`, `ProductSettingsBlock`,
  `CalculatedSettingsBlock` (click-to-insert field chips), price column in
  `OptionsEditor`, previews; Form settings › Pricing (`PricingSection`).
  Inbox: `PricingCard` on the entry + `formatProductValue()`.

### Business-aware AI drafts (1.2.0, A5)

- **Cloud-side storage.** Knowledge items, brand voice and language
  policy live on Neural Forms Cloud per installation
  (`SiteInstallation.businessContext` + `businessContextRevision`, legacy
  `Site` fallback), edited through Pro (`GET|PUT
  /neural-forms-pro/v1/business-context` → cloud `GET|PUT
  /v1/sites/business-context`). The cloud injects them into analyze /
  reanalyze / regenerate / polish server-side, so Lite's payloads are
  unchanged for sites without a context.
- **Per-form `settings.ai_behaviour`** `{ tone, language_mode
  (fixed|fallback), language, knowledge_ids[] }` — sanitised by
  `FormSettingsSanitizer::sanitize_ai_behaviour()` (detect / empty
  collapses to nothing), sent by
  `NeuralFormsCloudProvider::attach_ai_behaviour()` ONLY when
  `Capabilities::AI_BUSINESS_CONTEXT` is unlocked. Builder: Form
  settings › AI behaviour (upsell when locked; knowledge picker reads
  Pro's endpoint).
- **Per-field `ai_exclude: true`** (free): `NeuralFormsCloudProvider::
  strip_ai_excluded()` removes the value before ANY AI request
  (`format_entry()`). Builder: "Exclude from AI" checkbox under CSS class.
- **Response extras** `knowledge_refs[]`, `missing_info[]`,
  `reply_language`, `knowledge_revision` → `normalize_business_extras()`
  → stored as JSON in `neural_forms_entry_ai_responses.meta`
  (`AIResponseRepository::build_meta()` / `decode_row()`; NULL when
  empty). `AiCarousel.js` shows "Sources used", a "Needs business
  information" notice for `missing_info`, the draft language and a
  "Translate to <site language>" button.
- **Translation = polish with `translate_to`** (BCP-47): `polish_reply(
  $draft, $entry, $form, $options )` (interface gained the optional 4th
  arg), `POST /entries/{id}/polish-reply` takes `translate_to`, result
  is a new polish draft with `meta.translated_to`. `neuralFormsAdmin.
  siteLanguage` = `get_locale()` with `-`.
- **Review trail**: `ReplyEndpoint` stores `meta.reviewed_by` and
  `meta.source_response_id` (the inbox sends the selected draft id) and
  writes an `entry.reply_sent` activity row. Nothing in 1.2.0 sends AI
  text automatically.
- Tests: `tests/BusinessContextPluginTest.php`; cloud:
  `test/business-context.test.ts`.
- **Deploy order:** cloud first (migration adds nullable columns; old
  plugins ignore the new response keys), then plugins.

### Workflows engine (1.2.0, A4)

`modules/workflows/` — Lite owns the engine, Pro flips `workflows` and
adds integration actions.

- **Definition** (`WorkflowDefinition`): `{ form_id, name, trigger
  (submission_received | analysis_completed | stage_changed |
  follow_up_overdue), conditions (ConditionEngine v2 | null), actions[]
  { key a1.., type, settings, continue_on_failure }, settings
  { on_reanalysis, stage_from, stage_to } }`. Conditions may reference
  form fields AND the meta pseudo-fields in `meta_sources()`
  (ai_category, ai_priority, ai_score, ai_is_spam, lead_stage,
  assigned_to, status); `evaluation_context()` always sets every meta
  key (empty string when unknown) so "equals" on a missing AI result
  fails instead of hitting the lenient absent-field fallback.
- **Storage**: `WorkflowRepository` (`neural_forms_workflows`,
  `revision` bumps on every update), `WorkflowRunRepository`
  (`_runs` unique (workflow_id, event_id); `_actions` unique (run_id,
  action_key)). A run stores a `definition` snapshot in `context`, so
  edits never change queued work. Claims are single guarded UPDATEs.
- **Dispatch** (`WorkflowEngine`): hooks `neural_forms_entry_created`
  (prio 50), `neural_forms_entry_analysis_completed` (first analysis
  only unless `on_reanalysis`), `neural_forms_lead_stage_changed`
  (from/to filters), hourly `neural_forms_follow_up_sweep`
  (sets `follow_up_overdue_sent` BEFORE dispatching). Event ids:
  `created:{e}`, `analysis:{e}:{n}`, `stage:{e}:{from}>{to}:{ts}`,
  `overdue:{e}:{due}`. Lineage `W{id}:R{run},…` blocks a workflow that
  is already in the chain and caps depth at 3. Only active when
  `Capabilities::WORKFLOWS` is unlocked.
- **Queue**: runs are `job_type = 'workflow'` rows in
  `neural_forms_queue`; `WorkflowQueueProcessor::run()` is a SECOND
  callback on `AIQueueProcessor::CRON_HOOK` (priority 20) so workflows
  run with AI off; it also writes `neural_forms_queue_last_run`.
  `WorkflowEngine::kick_queue()` schedules a single event in 5 s.
- **Runner** (`WorkflowRunner::process_run`): conditions evaluated
  once (activity `workflow.started` / `workflow.skipped` with per-rule
  results), then actions in order: `done` / `skipped` continue; `retry`
  → backoff 1, 5, 30 min, max 3 attempts, run `waiting` + re-enqueued;
  `failed` / `uncertain` stop dependents unless `continue_on_failure`
  (uncertain is never auto-retried; manual Retry reopens the action).
  Run ends `done | partial | failed | skipped`.
  `dry_run()` = conditions + `describe()` per action, zero side effects.
- **Actions** (`Actions/`, registry `ActionRegistry` + filter
  `neural_forms_workflow_actions`): assign_user, set_stage (passes
  `_context` lineage into `EntryRepository::update`), create_follow_up
  (elapsed hours), notify_internal (fixed recipients, tokens
  `{form_name} {site_name} {entry_url} {field_id}`),
  send_acknowledgement (fixed template only — never AI text; records an
  outgoing message), prepare_ai_draft (queues a `regenerate_reply` job;
  nothing is sent). Pro adds sync_hubspot, sync_mailchimp,
  send_whatsapp, send_zapier (`modules/workflows/ProWorkflowActions.php`),
  each handing off to the provider's own scheduled-delivery hook so
  payloads and telemetry match the automatic path.
  `NotificationService::render_tokens() / send_mail() /
  submitter_email()` are the public helpers actions use.
- **REST** (`WorkflowsEndpoint`, manage_options + `workflows` cap except
  `/workflows/health`): `/workflows?form_id`, `/workflows/meta`,
  `/workflows/{id}` GET/PUT/DELETE (PUT with only `enabled` toggles),
  `/workflows/{id}/test` (body may carry an unsaved `definition`),
  `/workflows/{id}/runs`, `/workflows/{id}/cancel-pending`,
  `/workflow-actions/{id}/retry`.
- **UI**: Form settings › Workflows (`forms/WorkflowsSection.js`, builder
  bundle): list with run counts, When/If/Then editor, Test run, History
  with Retry; upsell when locked; needs a saved form id (`FormSettings`
  now receives `formId`). Settings › Processing health
  (`ProcessingHealthSection` in `screens/Settings.js`, settings bundle)
  reads `/workflows/health` and shows the system-cron hint.
- Tests: `tests/WorkflowDefinitionTest.php` (registry, normalise,
  validate, evaluation context, event ids, lineage, backoff, describe).
  DB-bound code (repositories, runner, processor, REST) is covered by
  `tests/live/A6LiveHarness.php`, run against the LOCAL site with
  Local's PHP + MySQL socket (see its header); it is deliberately not
  in the `tests/*.php` sweep.
- Retry semantics: a `retry` outcome leaves dependents PENDING (`$halt
  = 'wait'`), a final failure / uncertain outcome SKIPS them (`'fail'`).
  `reopen_action()` also accepts a waiting action ("retry now").

### Lead management + activity timeline (1.2.0, A3)

- **Columns** on `neural_forms_entries`: `assigned_to` (WP user id,
  NULL = unassigned), `lead_stage` (default `new`), `follow_up_due`
  (UTC datetime), `follow_up_done`, `follow_up_overdue_sent` (set once
  the overdue alert fired; A4). `EntryRepository::decode()` always
  returns them plus a computed `follow_up_overdue` flag. Lead stage is
  independent of `status` (new/read/spam/trash).
- **Stages**: `Storage\LeadStages` — `new, contacted, quote_sent, won,
  lost`, filter `neural_forms_lead_stages` (id => label); `new` is
  always re-added. Localised as `neuralFormsAdmin.plugin.leadStages`.
- **Writes**: `PATCH /entries/{id}` accepts `assigned_to` (0 = unassign,
  else a user with `manage_options`), `lead_stage`, `follow_up_due`
  (null clears; a new date reopens the follow-up), `follow_up_done`.
  Requires `Capabilities::WORKFLOWS` → `pro_feature_locked` otherwise.
  Validation lives in the pure static
  `EntriesEndpoint::validate_lead_fields()` / `extract_lead_fields()`.
  `EntryRepository::update()` fires
  `neural_forms_lead_stage_changed( $id, $from, $to, $context )` only
  when the stage really changed (`$fields['_context']` lets workflows
  pass `source` / `lineage`).
- **Reads**: `GET /entries` takes `stage`, `assigned_to` (0 = unassigned),
  `overdue=1`; view `overdue` (= `EntryRepository::OVERDUE_WHERE`);
  `/entries/counts` adds `overdue` and `stages{}`;
  `GET /entries/{id}/activity`; `GET /entries/assignees` (users with
  `manage_options`).
- **Activity**: `Storage\EntryActivity` writes/reads
  `neural_forms_logs` rows with `event_type` `entry.*` / `workflow.*`
  (payload `by` = user id). Deleted with the entry
  (`EntriesEndpoint::delete_entry`). Keep the `entry.` / `workflow.`
  prefixes — `list()` filters on them.
- **UI** (`build/entries.js`): `entries/LeadPanel.js` (stage / owner /
  follow-up controls under the hero; locked → read-only values +
  upsell) and `ActivityTimeline` (collapsible, above the conversation);
  `constants.js` `visibleSmartFilters()` hides the Overdue smart filter
  unless `workflows` is unlocked.
- Tests: `tests/LeadFieldsTest.php` (schema fragments incl. the
  idempotency unique keys, filter clauses, datetime coercion, stages,
  endpoint validation).

### Conditional logic — one engine, v2 format (1.2.0)

`modules/forms/ConditionEngine.php` is the only evaluator. v2 shape:
`{ enabled, version: 2, action: show|hide, match: all|any, rules: [{field,
operator, value}], groups: [{match, rules}] }`. The 1.0.0 single-rule
object (`{enabled, field, operator, value}`) is still read everywhere via
`ConditionEngine::normalize()` and is never rewritten in stored schemas;
the builder writes v2 when the user next saves that field's logic.

- `SubmitEndpoint::evaluate_conditional()` is a one-line delegate kept
  for the old tests; `resolve_hidden_field_ids()` iterates to a fixed
  point and passes already-hidden ids as *unavailable* so a rule on a
  hidden controller fails (legacy "controller hidden → dependent
  hidden" behaviour, generalised to many rules).
- `FormRenderer` emits `ConditionEngine::for_attribute()` as
  `data-conditional` (`{v, action, match, rules[, groups]}`).
  form-submit.js `normalizeConditional()` + `evaluateConditionTree()`
  mirror the PHP; `evaluateCondition()` is the per-rule port.
  `contains` with an empty rule value never matches on either side.
- `FormValidator::validate_conditional_logic()` is a second pass over
  the flattened fields: unknown operator, unknown/self field reference,
  bad action/match, > 10 rules → 422 `invalid_form_data`; groups
  without the `conditional_logic_advanced` cap → 403 `pro_feature_locked`.
- Builder helpers `normalizeConditional()` / `toEditableConditional()`
  / `CONDITION_OPERATORS` live in `schemaHelper.js`. The builder copy of
  `normalizeConditional()` must stay equivalent to the frontend one.
- Fixtures: `tests/fixtures/conditions.json`, run by
  `tests/ConditionEngineTest.php` and (Node 18)
  `tests/ConditionEngineTest.js`. Add a case there, not ad-hoc asserts,
  when touching any evaluator.
- Sections are heading nodes (title + description, no children), so a
  "conditional section" hides the heading only (`data-conditional` on
  the section div; same evaluator as fields).
- **Steps (Pro, A2):** a Step node's `conditional_logic` decides
  eligibility. The renderer emits it as `data-conditional-step` (NOT
  `data-conditional`, because inactive steps are `display:none` too and
  the serializer treats a hidden `[data-conditional]` ancestor as
  "hidden by logic"). form-submit.js `computeStepEligibility()` (pure,
  fixture-tested) marks skipped steps with
  `.neural-forms-form__step--skipped`; the serializer skips their
  inputs; Next/Prev jump over them; progress counts eligible steps
  only. Server: `resolve_hidden_field_ids()` takes the top-level nodes
  as a 3rd arg and hides every field of an ineligible step
  (`SchemaHelper::step_logic_map()`). Validation: a step may only
  reference fields from EARLIER steps, and the first step can never
  carry logic.
- **Conditional confirmations (Pro, A2):** `settings.on_success.rules[]
  = { id, conditions (v2, enabled), mode, message, url }`, ordered,
  first match wins, default `on_success.mode/message/url` otherwise.
  Sanitised by `FormSettingsSanitizer::sanitize_on_success()`
  (kses + esc_url_raw, max 10), validated against the schema by
  `FormValidator::validate_settings_logic()` from `FormsEndpoint`
  (create + update; update without a schema loads the stored one).
  `SubmitEndpoint::resolve_success_outcome()` adds `outcome: { mode,
  message, url }` to the 201 body only when a rule matched; the JS
  applies it (`applySuccessMessage()` / redirect) and otherwise falls
  back to the `data-on-success-*` attributes as before.
- Builder: `ConditionEditor.js` is the single editor (rules, Pro
  groups, action/match head). `BuilderInspector.js` wraps it for
  fields (`ConditionalLogicPanel`) and steps/sections
  (`LayoutLogicPanel`, Pro upsell when locked); `FormSettings.js`
  `ConfirmationRules` uses it for the on_success rules (duplicate
  condition sets are flagged as unreachable).
- Tests: `tests/ConditionalStepsAndConfirmationsTest.php` + the
  `resolve_steps` fixtures (also run by the Node test).
- Standalone test files that load `FormValidator.php`, `FormRenderer.php`
  or `SubmitEndpoint.php` and exercise logic must also require
  `modules/forms/ConditionEngine.php` (no autoloader in tests).

### Never answer a REST request with 502 or 504 (fixed 2026-10-29, 1.2.0)

Cloudflare (and most CDNs) replace an origin's 502/504 body with their
own error page, so a `WP_Error` carrying `'status' => 502` reaches the
admin UI as Cloudflare's JSON blob and the real message is lost — that
is exactly what a customer saw on the Pro "Refresh now" button. Use
**424** for "an upstream call failed" (cloud API, template library,
integrations). `tests/NoProxySwallowedStatusTest.php` fails the sweep if
a 502/504 status creeps back into `modules/`. Same rule in Pro.

### Privacy mode (fixed 2026-10-27, 1.1.3)

`privacy_mode` was defined in `Config`, saved by `SettingsEndpoint` and
toggled in Settings, but NOTHING read it at submit time — entries were
stored with ip_address + user_agent regardless. `SubmitEndpoint::submit()`
now reads it once (right after `resolve_ip()`) and blanks `ip` /
`user_agent` in the Step 8 context and in the CAPTCHA-failure log. The IP
is still used in memory for the rate limit and CAPTCHA verification.
`Database::maybe_upgrade()` (DB version 1.1.3) runs
`scrub_visitor_metadata()` once when the toggle is on. Toggling it on
later does NOT scrub historic rows — call `Database::scrub_visitor_
metadata()` explicitly if that's wanted. Tests: `tests/PrivacyModeTest.php`.

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

### File upload size limit (fixed 2026-10-27, 1.1.3)

`SchemaHelper::file_size_limit( $field )` → `{ bytes, label }` is the
single source of truth: `min( max_file_size (default 5 MB),
wp_max_upload_size() )`. Used by `FormRenderer::render_file()` (hint
text, `data-max-size`, `data-max-size-label`), by both file paths in
`SubmitEndpoint` (`process_file_fields()` flat, `process_repeater_file_
fields()` nested) and by `upload_file_cell()`. `UPLOAD_ERR_INI_SIZE` /
`UPLOAD_ERR_FORM_SIZE` map to the field-level `file_too_large` 422 via
`file_too_large_error()`; a multipart body over `post_max_size` (PHP
empties `$_POST` + `$_FILES`) is caught by a CONTENT_LENGTH guard in
`submit()` and returns a 413. `form-submit.js` reads
`data-max-size-label` for its messages. Don't reintroduce a bare `5`
anywhere — tests: `tests/FileSizeLimitTest.php`.

### Entries (per-screen `build/entries.js` bundle)

- **Paging (1.1.3):** `EntriesScreen.js` fetches 50 rows per page via
  `fetchEntriesPage()` (`parse: false` so it can read the endpoint's
  `X-Total-Count` header — note the Lite endpoints emit `X-Total-Count`,
  not `X-WP-Total`) and renders a "Load more" row until the total is
  reached. Header checkbox still selects loaded rows only.
- **Search (1.1.3):** `EntryRepository::create()` stores data with
  `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`; pre-1.1.3 rows are
  still `\uXXXX` / `\/` escaped, so `build_filter_clauses()` LIKE-matches
  both the raw term and its JSON-escaped form. No migration.
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
| `build/payments.js` | `src/payments-entry.js` | `npm run build:payments` | Payments page (`?page=neural-forms-payments`) — `src/screens/Payments.js` + `src/payments.css` (imports `settings.css`); list, totals, invoice, refund (1.2.0 part B). |
| `build/quotes.js` | `src/quotes-entry.js` | `npm run build:quotes` | Business Details page (`?page=neural-forms-quotes`, label renamed 2026-10-30) — `src/screens/Quotes.js`; imports `settings.css` so it looks like Settings (1.2.0 part B). |
| `build/integrations.js` | `src/integrations-entry.js` | `npm run build:integrations` | Integrations screen — `src/screens/Integrations.js` + `src/integrations.css` (Pro-ready provider config surfaces; also `src/forms/IntegrationsSection.js` in the builder). |
| `build/block.js` | `src/block-index.js` | `npm run build:block` | Gutenberg form-rendering block (registered via `frontend/block/block.json`). |
| `build/blocks-extra.js` | `src/blocks-extra-entry.js` | `npm run build:blocks-extra` | After editing the Quiz / Multi-step / Poll Gutenberg blocks. Each of the 3 registers via its own `modules/frontend/blocks/{quiz,multi-step,poll}/block.json` (metadata only — name/title/description/keywords/category/textdomain/editorScript/editorStyle), added 2026-07-01 so WordPress.org's directory scanner detects all 4 blocks (it counts `block.json` files; the 3 extras were previously registered with inline PHP args and were invisible to the scanner). The attribute schema still comes from the form block's `frontend/block/block.json` at runtime and is passed as the `register_block_type()` args override in `BlockHandler::register_extra_blocks()` — one source of truth for the ~600-line attribute list, not duplicated across the 3 new files. |

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
- **Template library modal** — edit `src/templates/`, run `build:forms` (the modal ships inside the forms bundle; the separate templates bundle was dead and removed 2026-10-30).
- **Inline CSS/JS in PHP** — no build at all; ships the moment PHP
  is deployed.

### Smart build verification (run after every per-screen build)

1. `git status --short modules/admin/build/` should ONLY show the
   per-screen artefact you intended to rebuild plus its `.css` /
   `.asset.php` siblings.
2. PHP test sweep — run every file under `tests/`:
   `for f in tests/*.php; do php "$f" 2>&1 | tail -1; done`
