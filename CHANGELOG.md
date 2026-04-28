# Changelog

All notable changes to **Neural Forms Lite** are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2026-04-29

Initial public release.

### Form builder
- React-based admin form builder with field registry, validation, and live preview.
- Frontend rendering via `[neural_form]` shortcode and the Gutenberg block.
- Submission pipeline with field-level validation and CAPTCHA verification (Cloudflare Turnstile and reCAPTCHA v2).

### Lead inbox
- Gmail-style inbox UI with status (new / read / spam / trash), starring, and "needs reply" flags.
- Per-form views, search, date-range filters, and CSV export.
- Bulk status changes and keyboard shortcuts.
- Stored entry-message thread for every lead.

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

### Capability extension points
- `neural_forms_can_*` filters for spam detection, reanalyze, regenerate, and polish — all default to `false` and are flipped on by Neural Forms Pro.
- React SlotFill registry (`window.neuralForms.slots`) so Pro can fill premium UI regions without forking the bundle.

### Maintenance
- Daily WP-Cron purge of stale AI draft rows.
- 5-minute queue runner plus a 10-second single-event nudge on each new submission.
- Schema versioning via `NEURAL_FORMS_DB_VERSION`; existing installs re-run `dbDelta()` on admin_init when the constant bumps.
