# Release plan: Neural Forms Lite 1.2.0 / Pro 1.1.0

Status: approved for implementation planning (2026-10-29)
Source: owner's draft "Neural Forms release implementation plan" (29 Oct 2026),
scoped by the owner to the Release A features only, integrated with the
existing engine (no parallel systems).

## 1. Scope

This release ships three features. Everything else in the draft
(calculations, branded quotes, Stripe payments, their workflow events) is
**not** in this release; only the contracts below are shaped so they can
carry those later without a rewrite.

| Feature | Lite 1.2.0 | Pro 1.1.0 |
|---|---|---|
| Field conditions: several rules, All / Any, show or hide | yes | yes |
| Nested condition groups, conditional sections, step skipping, conditional confirmations and redirects | editor locked (upsell) | unlocked |
| Workflows (When → If → Then) with triggers, actions, test run and history | engine present, editor locked | unlocked |
| Lead stage, assigned user, follow-up reminder, activity timeline | columns present, controls locked | unlocked |
| Per-field "exclude from AI" switch | yes (privacy, free) | yes |
| Business knowledge, brand voice, language policy for AI drafts | locked (upsell card) | unlocked |

Versions: Lite `1.2.0` (DB version `1.2.0`), Pro `1.1.0` with
`NEURAL_FORMS_PRO_MIN_CORE = 1.2.0`. Pro 1.0.x keeps working against Lite
1.2.0 because no existing filter, hook, route or response shape changes.

## 2. Code map: what exists, what is reused

| Need | Already in the code | Reuse decision |
|---|---|---|
| Single-rule conditions | `field.conditional_logic = {enabled, field, operator, value}`, 7 operators (`equals not_equals contains empty not_empty gte lte`); JS `evaluateCondition()` + `updateConditionalFields()` in `modules/frontend/assets/js/form-submit.js`; PHP `SubmitEndpoint::evaluate_conditional()` + `resolve_hidden_field_ids()`; renderer emits `data-conditional`; editor `ConditionalLogicPanel` in `modules/admin/src/forms/BuilderInspector.js`; test `tests/ConditionalLogicOperatorsTest.php` | Keep the v1 shape readable forever. Add a v2 shape on top (see §3). Same operators, same per-type coercions. |
| Confirmation / redirect | `settings.on_success = {mode, message, url}` and `on_error`; sanitised in `FormSettingsSanitizer`; edited in `FormSettings.js` | Add `on_success.rules[]` (ordered) with the existing object as the mandatory default. |
| Multi-step navigation | `initMultiStep()` in form-submit.js, `.neural-forms-form__step`, `data-step-action` | Add an eligibility check per step; progress bar counts eligible steps only. |
| Durable queue | table `neural_forms_queue` (`job_type, payload, status, attempts, scheduled_at`), `AIQueueProcessor::run()` on cron `neural_forms_process_ai_queue` (5 min + 10 s single event), `MAX_ATTEMPTS 3`, `BATCH_SIZE 5` | Workflow runs are `job_type = 'workflow'` jobs in the same table, drained on the same cron by a sibling `WorkflowQueueProcessor`. No second queue. |
| Submission events | `neural_forms_entry_created ($entry_id, $form_id, $data)` in `SubmitEndpoint`; `neural_forms_entry_analysis_completed ($entry_id, $form_id, $result)` in `AIRepository`; `neural_forms_send_notifications` | Workflow triggers subscribe to these two actions. New action `neural_forms_lead_stage_changed ($entry_id, $from, $to, $context)` fired from `EntryRepository::update()` when the column changes. |
| Email sending | `NotificationService::send_with_retry()`, `apply_tokens()` ({field:id}, form name), `resolve_sender()`, `parse_recipient_list()` | Workflow email actions call these; no new mailer. |
| Activity / logs | table `neural_forms_logs (entry_id, event_type, payload, status, message)` | Entry activity timeline = rows in this table with `event_type` prefixes `entry.*` and `workflow.*`. New read route only. |
| AI drafts | `neural_forms_entry_ai_responses` + `AIResponseRepository`, carousel UI `AiCarousel.js`; regenerate job in `AIQueueProcessor::process_regenerate_reply()` | "Generate a draft for review" workflow action enqueues the existing regenerate job. Drafts gain a `meta` column for knowledge references, language and warnings. |
| Sent replies | `ReplyEndpoint` writes `neural_forms_entry_messages` with `sender` | Add `reviewed_by` (user id) and `source_response_id` into the message `meta`. Nothing sends automatically. |
| Integrations (Pro) | `IntegrationManager::dispatch($event, $payload)`, `IntegrationEvents`, HubSpot / Mailchimp / Webhook / WhatsApp dispatchers on `neural_forms_entry_created` | Pro registers `sync_integration` and `webhook` workflow actions through the new `neural_forms_workflow_actions` filter; they call the existing delivery services. |
| Business context | Pro-owned site profile: `SiteProfileService`, `/neural-forms-pro/v1/site-profile`, cloud `Site.profile` / `SiteInstallation.profile`, injected into the analyze prompt by `prompt-builder.ts` | Knowledge, voice and language live next to the profile on the cloud and are injected server-side for analyze, reanalyze, regenerate and polish. Plugin payloads stay the same; new fields are optional. |
| Capability gating | `Capabilities::` constants + `neural_forms_can_*` filters, Pro `Unlocker` with `__return_true`, `PlanManager::can()` for cloud plan | Three new capabilities (§6). Pro adds three lines. |
| Permissions | every inbox and settings route requires `manage_options` | No new role system in this release. Assignment targets are users with `manage_options`; see decision D4. |

## 3. Conditional logic

### 3.1 One condition format (v2, additive)

```json
{
  "enabled": true,
  "version": 2,
  "action": "show",
  "match": "all",
  "rules": [
    { "field": "service", "operator": "equals", "value": "website_development" },
    { "field": "budget",  "operator": "gte",    "value": "5000" }
  ],
  "groups": [
    { "match": "any", "rules": [ { "field": "a", "operator": "equals", "value": "x" }, { "field": "b", "operator": "not_empty", "value": "" } ] }
  ]
}
```

- `action`: `show` (default, equals today's behaviour) or `hide`.
- `match`: `all` or `any`, applied across `rules` and the results of `groups`.
- `groups`: Pro only, one level deep (depth cap 2 overall), each with its own `match` and `rules`. This expresses "A AND (B OR C)".
- A v1 object (`field, operator, value` at the top level, no `version`) is read as `{version:2, action:'show', match:'all', rules:[that rule]}`. **Stored schemas are never rewritten.** The builder writes v2 only when the user saves that field's logic again.
- Operators are unchanged. The membership check for checkbox groups keeps today's comma-joined comparison, so `contains` on a checkboxes field means "one of the selected options is X". Empty string, missing key and an unticked box all evaluate as empty exactly as `evaluate_conditional()` does now.

### 3.2 Where the engine lives

- PHP: new `modules/forms/ConditionEngine.php` with `normalize(array $cl): array`, `evaluate(array $cl, array $data, array $types): bool`, `validate(array $cl, array $known_ids, bool $advanced): WP_Error|true`, `references(array $cl): string[]`. `SubmitEndpoint::evaluate_conditional()` becomes a one-line delegate so existing tests keep passing; `resolve_hidden_field_ids()` keeps its controller-hidden propagation and 50-iteration guard, and learns about sections and steps.
- JS (frontend): `normalizeConditional()`, `evaluateConditionTree()` added to form-submit.js beside `evaluateCondition()`. `data-conditional` carries the normalised v2 JSON.
- JS (builder): `normalizeConditional()` duplicated in `schemaHelper.js` (the builder bundle cannot import frontend code today; keep the two in sync via the shared fixtures).
- Fixtures: `tests/fixtures/conditions.json` (cases × inputs × expected) consumed by `tests/ConditionEngineTest.php` and `tests/ConditionEngineTest.js` (Node, same style as `BuilderSavePayloadTest.js`). CL4 is this file.

### 3.3 Sections, steps, confirmations (Pro)

- Section and Step nodes accept the same `conditional_logic` object. (Discovery, A1: a Section is a heading node with title and description only, no child fields, so a section condition hides the heading block; the "applies to descendants" rule is for Steps.) A field is applicable only if its own tree and every ancestor's tree pass. `FormValidator` rejects a rule that references a field inside its own subtree or a later step, and rejects cycles (CL8).
- Frontend: `updateConditionalFields()` evaluates sections and steps too. `initMultiStep()` navigates over eligible steps only, recomputes eligibility on every input and change event, keeps the progress bar honest, and clears values of steps that became ineligible on the way back (CL6). The server recomputes everything on submit (CL5).
- Confirmations: `on_success.rules[] = [{ id, conditions, mode, message, url }]`, evaluated after validation against the effective payload; first match wins, else the existing default. `FormSettingsSanitizer` validates URLs with `esc_url_raw` and rejects visitor-controlled tokens in URLs. The builder warns when two rules can both match.
- Lite rendering of a form that already contains Pro structures (Pro deactivated or lapsed): the submit path **still evaluates the stored rules** so hidden fields cannot leak, but `FormsEndpoint` refuses to save new or changed advanced structures with `pro_feature_locked`. Decision D1.

### 3.4 Acceptance mapping

CL1 fixtures include every v1 case from `ConditionalLogicOperatorsTest.php`. CL2 and CL3 are fixture cases. CL7 is a unit test over the rule list. CL9 is a manual pass with keyboard and VoiceOver on a branching multi-step form. CL10 holds because the only renderer change is the content of the existing `data-conditional` attribute, shared by shortcode, Gutenberg and the Divi module.

## 4. Workflows

### 4.1 Storage (new tables, created by `Database::maybe_upgrade()` at DB version 1.2.0)

| Table | Columns |
|---|---|
| `neural_forms_workflows` | id, form_id, name, description, trigger varchar(40), conditions longtext, actions longtext, enabled tinyint, revision int, created_by, created_at, updated_at. Key form_id, enabled. |
| `neural_forms_workflow_runs` | id, workflow_id, revision, entry_id, trigger, event_id varchar(80), status (pending, running, done, failed, cancelled), lineage varchar(255), started_at, finished_at. **Unique key (workflow_id, event_id)** gives WF4 for duplicate events. |
| `neural_forms_workflow_actions` | id, run_id, action_key varchar(80), type varchar(40), status (pending, running, done, failed, uncertain, skipped), attempts, next_attempt_at, external_ref varchar(191), result text. **Unique key (run_id, action_key)**. |

Entries table gains `assigned_to bigint unsigned null`, `lead_stage varchar(32) not null default 'new'`, `follow_up_due datetime null`, `follow_up_done tinyint default 0`, `follow_up_overdue_sent tinyint default 0`. Keys on lead_stage and follow_up_due. `neural_forms_entry_ai_responses` gains `meta longtext null`.

All additions are nullable or defaulted, so `dbDelta()` is safe to rerun and old rows need no backfill. `uninstall.php` drops the three new tables. Privacy exporter and eraser already walk entries by id; workflow rows reference entries by id only and are deleted with the entry.

### 4.2 Events and execution

1. `WorkflowEngine::boot()` hooks `neural_forms_entry_created` (priority 50), `neural_forms_entry_analysis_completed` (priority 50), `neural_forms_lead_stage_changed`, and the new hourly cron `neural_forms_follow_up_sweep`.
2. On an event the engine loads enabled workflows for the form with the matching trigger, builds `event_id` (`created:{entry_id}`, `analysis:{entry_id}:{response_id}`, `stage:{entry_id}:{from}>{to}:{timestamp}`, `overdue:{entry_id}:{due}`), and inserts one run per workflow (ignored on duplicate key). Each run gets one queue job `{job_type:'workflow', payload:{run_id}}`.
3. `WorkflowQueueProcessor::run()` is called from the same cron callback as `AIQueueProcessor::run()`. It claims a run with `UPDATE … SET status='running' WHERE id=%d AND status='pending'` (affected rows must be 1), evaluates conditions with `ConditionEngine` against the entry data plus entry metadata (`ai_category`, `ai_priority`, `lead_stage`, `assigned_to`), writes a `workflow.conditions` log row with every rule's outcome (WF5), then executes actions in order.
4. Each action is claimed the same way. Executors return `done`, `failed_retryable`, `failed_final` or `uncertain`. Retryable failures reschedule with backoff 1, 5, 30 minutes up to 3 attempts; `uncertain` (mail accepted but unconfirmed, HTTP timeout after send) is never retried automatically and shows a "Retry" button in history (WF12). A failed action stops later actions that depend on it (default) unless the action is marked `continue_on_failure`.
5. Loop control: a stage change caused by a workflow carries `lineage = run_id chain`. A workflow never starts from an event whose lineage already contains that workflow id, and lineage depth is capped at 3 (WF8).
6. Reanalysis: the analysis trigger fires on the first finalised analysis only unless the workflow sets `on_reanalysis: true`.
7. Disabling a workflow cancels its pending runs on request (`POST /workflows/{id}/cancel-pending`); a running job finishes under the revision it started with.

### 4.3 Actions in this release

| Key | Owner | What it does |
|---|---|---|
| `assign_user` | Lite | sets `assigned_to` to a user with `manage_options` (validated at save and at run time) |
| `set_stage` | Lite | sets `lead_stage`; fires the stage event with lineage |
| `create_follow_up` | Lite | sets `follow_up_due = now + N hours` (business-day scheduling is out of scope; documented) |
| `notify_internal` | Lite | email to configured recipients via `send_with_retry`, body with `apply_tokens()` |
| `send_acknowledgement` | Lite | fixed, admin-written email to the submitter, tokens only; recorded in `entry_messages` with `meta.source = workflow` |
| `prepare_ai_draft` | Lite | enqueues the existing regenerate job; result lands in the carousel for review (AI9, WF9) |
| `sync_integration` | Pro | `IntegrationManager::dispatch()` for the chosen provider using existing form settings |
| `webhook` | Pro | existing `WebhookDispatcher` delivery |

Executors register through `apply_filters('neural_forms_workflow_actions', array $registry)`; Pro adds its two. Visitor text can never pick a recipient or destination: recipients and URLs come from the saved workflow only.

Lead stages: `new, contacted, quote_sent, won, lost`, overridable with `neural_forms_lead_stages` filter. Nothing moves a lead to `won` except an explicit `set_stage` action or a human.

### 4.4 REST (namespace `/neural-forms/v1`, all `manage_options`)

- `GET|POST /workflows`, `GET|PUT|DELETE /workflows/{id}`, `POST /workflows/{id}/test`, `GET /workflows/{id}/runs`, `POST /workflows/{id}/cancel-pending`, `POST /workflow-actions/{id}/retry`.
- `GET /entries/{id}/activity` (logs for the entry, newest first).
- `PATCH /entries/{id}` accepts `assigned_to`, `lead_stage`, `follow_up_due`, `follow_up_done` (additive). Stage change through PATCH fires the stage event with empty lineage.
- `GET /entries` accepts `stage`, `assigned_to`, `overdue=1` filters (additive); `/entries/counts` adds `overdue` and per-stage counts.
- Test run returns `{ trigger_ok, values, conditions: [{rule, result, reason}], planned_actions }` and performs no side effects, uses stored AI results only, and consumes no credits (WF10).

### 4.5 UI

- Form settings gains a **Workflows** section in `FormSettings.js` (same section list as On success / Notifications). Locked: upsell card. Unlocked: list, editor (name, trigger, condition editor reusing the builder's condition component, ordered actions with per-type fields, enabled switch), Test run panel, and History with retry.
- Entry detail (`EntryDetail.js`): a business-state row in the hero card (stage select, assignee select, follow-up date with Done) and an **Activity** section under the conversation. Sidebar gains an **Overdue** smart view and per-stage counts. Locked installs see read-only values when present and an upsell on the controls.
- Settings → Processing health: pending jobs by type, last cron run, and a note on `DISABLE_WP_CRON` with a system cron line. Reuses the queue summary already exposed by `StatsEndpoint`.

## 5. Business-aware AI drafts

### 5.1 Storage and ownership

- Cloud: `SiteInstallation.businessContext Json?` (and the legacy `Site.businessContext` fallback, same pattern as `profile`) holding `{ knowledge: [{id, type, title, body, enabled, source_url, revision}], voice: {tone, terminology, signature, instructions}, language: {mode: detect|fixed|fallback, code}, revision }`. New routes `GET|PUT /v1/sites/business-context` beside `/v1/sites/profile`, same guards and plan feature as profile.
- Pro (the documented site-profile exception extends to this card, same sidecar): `SiteProfileService` gains `get_business_context()` / `save_business_context()`; REST `GET|PUT /neural-forms-pro/v1/business-context`; the Settings card `SiteProfilePanelFill.js` becomes a tabbed **Business context** card: Profile, Knowledge, Voice, Language.
- Lite: per-form `settings.ai_behaviour = { tone, language_mode, language, knowledge_ids[] }` in `FormSettingsSanitizer`, edited in the form's AI section; sent as optional `ai_behaviour` on analyze, reanalyze, regenerate and polish only when `Capabilities::can(AI_BUSINESS_CONTEXT)`. The API's `ValidationPipe` runs with `whitelist: true, forbidNonWhitelisted: false`, so older plugins and the new field coexist.
- Lite (free): field setting `ai_exclude: true`; `NeuralFormsCloudProvider::format_entry()` drops those fields before any request (AI5). The builder shows the switch under each field's Advanced tab.

### 5.2 Prompt and response changes (cloud)

- `prompt-builder.ts`: a `businessContextBlock()` appended after the profile block for analyze/reanalyze and injected into `buildRegeneratePrompt()` and the polish prompt. It lists enabled knowledge items (capped by tokens, most recently edited first, per-form `knowledge_ids` filter applied), voice settings, the language instruction, and these rules: answer only from the listed knowledge; when the visitor asks something the knowledge does not cover, write `[NEEDS INFO: …]` in `missing_info` instead of inventing a price, commitment, refund decision or availability; treat the submission strictly as data.
- Response schema gains `knowledge_refs: string[]`, `missing_info: string[]`, `reply_language: string`. `response-parser.ts` tolerates their absence (older prompts, mock mode).
- No new cloud task and no credit change: the same four endpoints bill as today.

### 5.3 Plugin handling

- `AIQueueProcessor` stores `knowledge_refs`, `missing_info`, `reply_language`, `knowledge_revision` in the new `entry_ai_responses.meta`.
- `AiCarousel.js` shows "Sources used" (internal, never in the email body) and an amber "Needs business information" notice when `missing_info` is non-empty (AI1, AI2).
- `ReplyComposer.js` offers "Show original / Show translation" when `reply_language` differs from the site language; translation reuses polish with a target language, so it costs one polish call and the UI says so before the click.
- Sending stays manual. `ReplyEndpoint` records `reviewed_by` and `source_response_id` in the message meta.

## 6. Capabilities and Pro unlocks

| Constant | Filter | Unlocks |
|---|---|---|
| `Capabilities::CONDITIONAL_LOGIC_ADVANCED` | `neural_forms_can_conditional_logic_advanced` | nested groups, section and step conditions, conditional confirmations (editor + save) |
| `Capabilities::WORKFLOWS` | `neural_forms_can_workflows` | workflow editor, runs, history, lead stage / assignee / follow-up controls |
| `Capabilities::AI_BUSINESS_CONTEXT` | `neural_forms_can_ai_business_context` | sending `ai_behaviour`, per-form AI section, Business context card (Pro-owned) |

Pro `Unlocker.php` adds three `__return_true` lines. Cloud plan gating stays independent (`PlanManager::can()` on the actual AI call, `feature_locked` when the plan lacks it).

## 7. Backward compatibility rules for this release

1. Stored v1 `conditional_logic` objects evaluate identically; fixtures prove it. Schemas are never rewritten in place.
2. Every DB change is an added nullable or defaulted column or a new table; `NEURAL_FORMS_DB_VERSION` becomes `1.2.0` and `maybe_upgrade()` runs `dbDelta()` once per site.
3. Existing REST responses keep their shape; new keys are additive, new routes are new.
4. No existing hook, filter, slot, option name or capability changes. New: `neural_forms_lead_stage_changed`, `neural_forms_workflow_actions`, `neural_forms_lead_stages`, `neural_forms_follow_up_sweep` (cron).
5. Pro 1.0.3 on Lite 1.2.0: unchanged behaviour. Pro 1.1.0 requires Lite ≥ 1.2.0 via `NEURAL_FORMS_PRO_MIN_CORE` and shows the existing dependency notice otherwise.
6. Cloud is deployed first (additive DTO fields, two new routes, one nullable column). Plugins 1.1.x keep working against it unchanged.
7. Lite with AI disabled, no cloud key or zero credits: conditions, workflows (non-AI actions) and inbox controls work in full (WF2); `prepare_ai_draft` fails final with a clear message and does not block other actions.
8. Shortcode, Gutenberg and Divi share `FormRenderer`, so the only frontend contract change is the JSON inside `data-conditional`.

## 8. Milestones

| Milestone | Deliverable | Exit gate |
|---|---|---|
| A0 (this document) | code map, contracts, decisions, version bump on dev | owner sign-off on §1 and §9 |
| A1 ✅ 2026-10-29 | `ConditionEngine` (PHP + JS), v2 normaliser, flat All/Any + show/hide editor, fixtures, Lite capability stubs, Pro unlock lines | CL1 to CL4 pass on PHP 7.4 and 8.2 and Node |
| A2 ✅ 2026-10-29 | sections, step eligibility, conditional confirmations, builder validation, Pro unlock | CL1–CL8 automated; CL9 (keyboard / screen reader on a branching multi-step form) and CL10 (shortcode / block / Divi smoke) are manual checks still to run in A6 |
| A3 ✅ 2026-10-29 | DB 1.2.0 migration, entry columns, activity log, entry PATCH/list additions, inbox controls, Overdue view | migration rerun-safe (dbDelta, added columns only); new columns hold staff data, not submitter data, so the privacy exporter is unchanged and the eraser still deletes the entry plus its timeline |
| A4 ✅ 2026-10-29 | WorkflowEngine, queue processor, six Lite actions, Pro actions filter, REST, editor, test run, history, processing health | WF1–WF12 designed in; WF4/WF6/WF8/WF10 covered by unit tests on the pure helpers, the rest need the manual end-to-end pass in A6 (DB-bound) |
| A5 ✅ 2026-10-29 (code) | cloud business-context storage + routes + prompt block + response fields; Pro card; Lite `ai_behaviour`, `ai_exclude`, carousel sources and warnings, translation toggle, reviewed_by | API suite 301 green, plugin tests green; **cloud deploy to s2 is the owner's step** (rsync + `prisma migrate deploy` for the new columns); AI1–AI3 need a manual draft check after deploy |
| A6 | upgrade test from 1.1.3 fixture DB, Pro 1.0.3 + Lite 1.2.0 combo test, accessibility pass, docs (CLAUDE.md sections, readme), changelogs in the standard short format, release cut per the release procedure | release gates in the draft §12 |

A5 can start as soon as A1's `ai_exclude` field setting exists; it does not wait for A4.

## 9. Decisions recorded

- **D1 Lapsed Pro and advanced logic.** Stored advanced rules keep being evaluated on submit (never leak a hidden field); only saving new advanced structures is blocked. Chosen over "ignore advanced rules", which would expose hidden fields on existing forms.
- **D2 One queue.** Workflows ride `neural_forms_queue` and the existing cron. A second scheduler would duplicate the health and retry story.
- **D3 Activity timeline reuses `neural_forms_logs`.** No new table; a new read route and `event_type` prefixes.
- **D4 No per-form user permissions in this release.** Every inbox route already requires `manage_options`, so there are no restricted users to protect (WF11 holds trivially). Assignment targets are limited to users with `manage_options`. A per-form access model is a separate feature.
- **D5 Knowledge lives on the cloud**, injected server-side like the site profile. This gives AI4 isolation for free (keyed by API key / installation), keeps analyze payloads unchanged, and keeps Pro thin. Manual entry only; no crawling, PDF ingestion or vector store.
- **D6 Three capabilities, not six.** Splitting later is additive and safe; merging later would break Pro.
- **D7 Follow-up timing is elapsed hours.** Business-day scheduling is documented as not included.
- **D8 Translation reuses polish.** No new cloud task or credit price.
- **D9 Release B contracts kept open, not built.** `ConditionEngine::evaluate()` takes a generic data array so price rules can use it; `workflow_runs.trigger` is a string so `payment_confirmed` can be added; `entry_ai_responses.meta` and `entry_messages.meta` are JSON for later fields.

## 10. Owner input still useful (non-blocking)

1. Default lead-stage labels and whether agencies need custom stage sets at launch (filter exists either way).
2. Whether the per-form AI section in Lite should show the knowledge picker when Pro is absent (planned: upsell only).
3. Cloud plan feature for business context: reuse the profile's feature (planned) or a new `ai_business_context` plan feature.
