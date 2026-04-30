# Changelog

All notable changes to **Neural Forms Lite** are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased] — 1.1.0

In development on the `1.1.0-dev` branch.

### Added

- **Row + column layout system.** The form schema now supports `type: "row"` containers that hold 1-4 columns of fields. Real fields live inside columns; rows and columns are layout-only — they never appear in submitted entry data, never get validated as fields, never carry a value. Old flat schemas continue to work unchanged.
- New PHP `NeuralForms\Forms\SchemaHelper` with `flatten_fields()`, `is_row()`, `walk()`, `normalize_row()`, `normalize_column_count()`, `default_row_settings()`, `generate_row_id()`, `generate_column_id()`. The flattener walks rows + columns in source order and yields only leaf fields, so submission, validation, sanitization, exports, notifications, and AI processing all see the same flat field stream they always did.
- Frontend rendering wraps each row in `.neural-forms-form__row.neural-forms-form__row--cols-N` with one `.neural-forms-form__row__column` per column. CSS uses `display: grid; grid-template-columns: repeat(N, minmax(0, 1fr))` for desktop; `@media (max-width: 768px)` collapses every multi-column row to a single column.
- `FormValidator` recursively validates row nodes (id pattern, 1-4 columns, no nested rows) and enforces that row, column, and field ids share one namespace so any id can be used to look up its node uniquely.
- React builder source extended with `RowBlock`, `Column`, and a `schemaHelper.js` mirror of the PHP helper. `Builder.js` exposes an "Add Layout" picker with 1/2/3/4 column options. `FieldList.js` now renders a tree of rows + flat fields, with dnd-kit nested droppables on every column. Compiled bundle in `modules/admin/build/` is unchanged at the 1.0.0 version — rebuild the bundle (Node 18+) to pick up the new builder UI.

### Column-count change preservation

Resizing a row never destroys fields. Shrinking 4 columns to 2 moves every field from the dropped columns into the last kept column, in order. The PHP `SchemaHelper::normalize_row()`, the JS `resizeRow()`, and the renderer all share this behaviour so a stale schema (saved with more columns than `settings.columns` declares) still renders cleanly.

### Backwards compatibility

- Existing flat schemas continue rendering identically; the legacy half-width pair-only path is preserved for fields with `width: "half"`.
- Existing entries are unaffected — the storage shape only ever held leaf field values.
- AI workflows, notifications, exports, and the REST contract are unchanged.

---

## [1.0.0] — 2026-04-29

Initial public release.

### Form builder
- React-based admin form builder with field registry, validation, and live preview.
- Frontend rendering via `[neural_form]` shortcode and the Gutenberg block.
- Submission pipeline with field-level validation and CAPTCHA verification (Cloudflare Turnstile and reCAPTCHA v2).
- Half-width / two-column field layout (renderer-only). Fields can declare `width: "half"`; two consecutive half-width fields render side-by-side on the frontend with pair-only semantics — an isolated half (no half neighbour) renders at full width. Mobile (≤600 px) collapses pairs back to one column.

### Lead inbox
- Gmail-style inbox UI with status (new / read / spam / trash), starring, and "needs reply" flags.
- Per-form views, search, date-range filters, and CSV export.
- Bulk status changes and keyboard shortcuts.
- Stored entry-message thread for every lead.
- Shortcode helper: each row in the Forms list shows its `[neural-forms slug="…"]` embed code with a Copy button so classic-editor / text-widget users can grab the shortcode without opening the form.

### AI (via Neural Forms Cloud)
- Connection-first settings UX: enter Cloud API key → test → unlock AI controls.
- Auto-analysis on submission with summary, category, priority, score, and a draft reply.
- Multi-tier model ladder (`fast` / `balanced` / `smart` / `premium`) selected by the cloud based on plan.
- Spam detection with suggest / auto modes and a 60–100 confidence threshold.
- Draft history carousel: every analyze / regenerate / polish call is preserved (10 per entry, 30-day TTL on non-finalized drafts).
- Click-budget protection on AI actions: 5 clicks per 2-hour window per entry; further clicks clamp to balanced.

### Notifications
- Configurable admin notifications and customer auto-responder emails.
- "Skip spam" toggle so suspicious leads don't trigger emails.

### Security
- Uploaded form files land on disk under a random 32-hex token (`nf-{token}.{ext}`) so attachment URLs in `/wp-content/uploads/` cannot be enumerated by guessing the original filename. The user-facing filename is preserved in entry data, exports, and notifications.
- `POST /forms` does not leak `$wpdb->last_error` into the API response on a failed insert. The raw error is logged via `Logger::file()` and a generic message is returned instead.

### Capability extension points
- `neural_forms_can_*` filters for spam detection, reanalyze, regenerate, and polish — all default to `false` and are flipped on by Neural Forms Pro.
- React SlotFill registry (`window.neuralForms.slots`) so Pro can fill premium UI regions without forking the bundle.

### Maintenance
- Daily WP-Cron purge of stale AI draft rows.
- 5-minute queue runner plus a 10-second single-event nudge on each new submission.
- Schema versioning via `NEURAL_FORMS_DB_VERSION`; existing installs re-run `dbDelta()` on admin_init when the constant bumps.
</content>
</invoke>