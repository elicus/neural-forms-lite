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
  - `NEURAL_FORMS_LITE_VERSION` — canonical Lite version (Pro dep check reads this). Currently `0.2.0`.
  - `NEURAL_FORMS_VERSION` — kept as an alias (= `NEURAL_FORMS_LITE_VERSION`) for back-compat
  - `NEURAL_FORMS_DB_VERSION` — schema version consumed by `Database::maybe_upgrade()`. Currently `1.9.0`. Bump this whenever you change a table column or add a migration so existing installs re-run `dbDelta()` on admin_init.

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

| Tier | Model (as of 0.2.0) | Used for |
|---|---|---|
| `fast` | OpenAI `gpt-4.1-nano` | Lite / free; universal speed floor |
| `balanced` | Google `gemini-2.5-flash` | Pro (default); also the universal fallback if the smart/premium tier errors |
| `smart` | Anthropic `claude-sonnet-4-5` | Pro Monthly / Pro Yearly |
| `premium` | Anthropic `claude-opus-4-1` | Pro Enterprise |

Tier values are persisted on `neural_forms_entries.ai_tier` (string, nullable)
and surfaced in the entry detail UI so users can see which tier wrote a
given analysis/reply.

#### Click budget (plugin → cloud contract)

To keep abuse + runaway clicks bounded, reanalyze / regenerate-reply /
polish-reply endpoints all send `entry_id` in the POST body. The cloud
uses `(site_id, entry_id)` as the rate-limit scope:

- Window: 2 hours rolling
- Soft limit: 5 clicks per window → subsequent clicks get tier clamped down to `balanced`
- Cooldown: 10s between clicks (HTTP 429 + `retry_after` if violated)

Implementation lives in `modules/ai/Providers/NeuralFormsCloudProvider.php`
— both `regenerate_reply()` and `polish_reply()` now include `entry_id`
in the POST payload. If you add a new click endpoint that consumes credits,
you MUST send `entry_id` or it will fall back to a per-site bucket and
share budget across every entry.

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
| `ai_enabled` | `false` | Master AI switch (synced from `cloud_ai_enabled` by frontend) |
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
Capabilities::AI_SPAM_DETECT   // 'ai_spam_detect'
Capabilities::AI_REANALYZE     // 'ai_reanalyze'
Capabilities::AI_REFINE_REPLY  // 'ai_refine_reply'

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

### Where the checks are applied

| File | Method | Capability |
|---|---|---|
| `modules/rest/endpoints/EntriesEndpoint.php` | `reanalyze_entry()` | `AI_REANALYZE` |
| `modules/rest/endpoints/EntriesEndpoint.php` | `regenerate_reply()` | `AI_REANALYZE` |
| `modules/rest/endpoints/EntriesEndpoint.php` | `polish_reply()` | `AI_REFINE_REPLY` |
| `modules/ai/AIQueueProcessor.php` | `maybe_apply_spam()` | `AI_SPAM_DETECT` |
| `modules/rest/endpoints/SettingsEndpoint.php` | Save validation + defensive coercion | `AI_SPAM_DETECT` |
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
| `window.neuralForms.slots.registerFill` JS registry | Admin bundle at mount | Pro bundle registers SlotFills for premium UI regions |

React SlotFill names exposed by Lite (via `@wordpress/components createSlotFill`):

- `NeuralForms.EntryReplyActions` — slot above reply box in entry detail
- `NeuralForms.EntryAiActions` — slot for AI actions (reanalyze, regenerate)
- `NeuralForms.SettingsSpamPanel` — spam settings panel (Lite renders upsell fallback)
- `NeuralForms.SettingsSiteProfile` — business-context card (Pro-owned feature; Lite only renders the upsell fallback)
- `NeuralForms.SettingsIntegrations` — integrations tab (Divi status, etc.)

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
  bundle. The protected `build/index.js` is gone — every screen ships
  from its own per-screen bundle.
- When adding a new premium feature: implement in Lite behind a new
  `Capabilities::` constant + `neural_forms_can_*` filter, default false.
  Have Pro flip the filter in its `Unlocker`.

------------------------------------------------------------------------

## Admin build (1.1.0+)

The legacy `build/index.js` rich admin bundle has been retired; every
screen now ships from its own per-screen bundle under
`modules/admin/build/`. `npm run build` is safe — it chains every
`build:<screen>` script via `webpack.split.config.js` and never
regenerates `build/index.*`.

```
cd modules/admin
npm install
npm run build
```

Per-screen scripts (use one when you only changed a single screen):
`build:builder`, `build:results`, `build:blocks-extra`, `build:forms`,
`build:entries`, `build:settings`, `build:stats`, `build:import`,
`build:block`. Each emits exactly one JS + one CSS + one asset.php
without touching sibling bundles (CleanWebpackPlugin is dropped from
the split config).

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

### Entries source parity — shipped

Phase 5 of `refactor/remove-protected-admin-bundle` migrated the
Entries inbox to the per-screen `build/entries.js` bundle:

- `.nf-inbox__*` renamed to `.neural-forms-entries-*` /
  `.neural-forms-entry-detail__*`; self-contained `src/entries.css`.
- Polls / Quizzes / `normal_inbox` smart folders removed; default
  All-Forms selection uses bare `?view=inbox`.
- Per-form sidebar folders nest six system views (Inbox / Unread /
  Needs Reply / Starred / Spam / Trash) under each form, scoped via
  `form_id=N&view=<id>`; selection is URL-synced (replaceState).
- Entry detail is its own URL-routed view: `entry_id=N` swaps the
  right pane to a Back-button + EntryDetail layout. Submitted Fields
  card renders first; `buildFieldLabels(form)` resolves field ids to
  human labels via the form's `form_schema`.
- Reanalyze / Regenerate / Refine-with-AI buttons are Pro-rendered
  via `EntryAiActions` and `EntryReplyActions` SlotFills; Lite only
  passes click handlers through fillProps and shows an upsell link
  when caps are off.

The shared admin page-header unifier
(`AdminPanel::page_hero_unifier_css()`) **intentionally excludes
Entries** — the inbox's 2-pane Gmail-style layout has no top-level
`__header` element to retarget. Stats / Forms list / Settings /
Import are unified via that CSS injector; Analytics ships its own
(`nf-analytics-hero` in `src/results.css`); Entries owns its layout.

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
- **Stats / Settings / Entries / Import** — edit the matching
  `src/screens/` or `src/entries/` source + `src/<screen>.css`, run
  the corresponding `build:<screen>`.
- **Inline CSS/JS in PHP** — no build at all; ships the moment PHP
  is deployed.

### Smart build verification (run after every per-screen build)

1. `git status --short modules/admin/build/` should ONLY show the
   per-screen artefact you intended to rebuild plus its `.css` /
   `.asset.php` siblings.
2. PHP test sweep — run every file under `tests/`:
   `for f in tests/*.php; do php "$f" 2>&1 | tail -1; done`
