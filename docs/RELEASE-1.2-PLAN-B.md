# Release plan, part B: calculations, quotes, payments (still Lite 1.2.0 / Pro 1.1.0)

Status: in progress from 2026-10-29. The owner clarified that the whole
draft (Release A and Release B) is ONE version: Lite 1.2.0 / Pro 1.1.0.
The Release-A-only cut of `release/1.2.0` / `release/1.1.0` made earlier
that day was never published; dev was set back to 1.2.0 / 1.1.0, part B
is built on dev, and the release branches are re-cut from dev (merge dev
into the branch, re-run the strip script, refresh the zips) when B5 is
done. Part A is in `docs/RELEASE-1.2-PLAN.md`.

## 1. Scope

| Feature | Lite 1.2.0 | Pro 1.1.0 |
|---|---|---|
| Priced Item field (schema type `product`; priced choices, quantity; prices live in the form, no WooCommerce) and Calculated field (expression) | engine shipped, locked in the builder (upsell), skipped by the renderer when locked — same pattern as Matrix / Repeater | unlocked (`calculations`) |
| Form pricing: currency, discount rules, deposit | settings stored, enforced only with the capability | unlocked |
| Authoritative server totals + pricing snapshot on the entry | yes (engine) | yes |
| Quotes: numbered, versioned, branded HTML + PDF, token pay links | no | `quotes` |
| Stripe Checkout: full payment or deposit, webhook-verified, reconciliation | tables + status columns present | `payments` |
| Workflow trigger `payment_confirmed`, action `create_quote_draft` | engine | unlocked with `workflows` |

Out of scope (unchanged from the draft): PayPal, subscriptions, instalments, balance collection, tax engine, multi-currency conversion, coupon campaigns, inventory, legally binding e-signature, "tax invoice" wording.

## 2. Code map: what exists, what is reused

| Need | Already in the code | Reuse decision |
|---|---|---|
| Field types | `FieldRegistry::$types` with `supports[]`; renderer `render_<type>()`; `FieldInspector` per type; `Capabilities::FIELD_MATRIX` pattern (locked type → palette upsell, renderer skip, submit strip) | Add `product` and `calculated` exactly that way behind one capability `calculations` |
| Conditions | `ConditionEngine::evaluate(array $cl, array $data, array $types)` is data-agnostic | Discount rules and price-rule visibility use it unchanged; `calculated` expressions can reference condition results via `if()` |
| Submit flow | `SubmitEndpoint`: hidden-field strip → validate → `EntryRepository::create` → `neural_forms_entry_created` → `outcome` on the 201 body (`mode: redirect` already handled by form-submit.js) | Pricing computed after the strip, stored on the entry; a Stripe Checkout URL is returned as `outcome.redirect` — no new frontend mechanism |
| Frontend | `form-submit.js` fixed-point `updateConditionalFields()`, `getFieldValue()` | Add `updateCalculations()` on the same input/change events; same fixture-parity approach as conditions |
| Secrets | Pro `TokenVault::encrypt/decrypt` (HubSpot token) + `HubSpotSettingsRepository` (public status / internal / mask) | `StripeSettingsRepository` with the same shape, two key sets (test / live) |
| Public REST in Pro | `/neural-forms-pro/v1/*` router | Webhook route with `permission_callback: __return_true` and signature verification; quote view / pay routes with token checks |
| Inbox | `LeadPanel.js` under the hero, `EntryActivity` timeline, `EntriesEndpoint` additive PATCH/list | `PaymentCard.js` + `QuoteCard.js` in the same place; activity rows `payment.*`, `quote.*` |
| Workflows | `ActionRegistry` + `neural_forms_workflow_actions`; triggers are strings on runs | New trigger `payment_confirmed` dispatched by the webhook handler; Pro action `create_quote_draft` |
| Email | `NotificationService::send_mail()` (wp_mail wrapper), `ReplyEndpoint` message records | Quote emails go out through `send_mail` with the PDF as attachment and are recorded as outgoing messages |
| Cron | `AIQueueProcessor::CRON_HOOK` + hourly `neural_forms_follow_up_sweep` | Daily `neural_forms_payment_reconcile`, daily `neural_forms_quote_expire` |

Nothing payment-related exists today (no currency, price or Stripe code in either plugin).

## 3. Calculations (B1)

### 3.1 Schema

- **`product` field**: `pricing: { mode: fixed | options | input, price: "1000.00", quantity: { enabled, min, max, default }, options: [{ value, label, price }] }`. `options` mode renders as select/radio/checkboxes (checkboxes = add-ons, each with a price). `input` mode lets the visitor type an amount within `min`/`max` (donations); never trusted beyond those bounds.
- **`calculated` field**: `expression: "{service} * {qty} - {discount}"`, `format: number | currency`, `decimals`. Display-only; never submitted as a value, always recomputed.
- **Form settings `pricing`**: `{ currency: "USD", discounts: [{ id, label, conditions (v2), type: percent|fixed, value, max }], deposit: { type: none|percent|fixed, value } }`. Only `fixed` and `percent` discounts; bounded so the payable amount never goes negative.
- Money is stored and computed in **integer minor units** with the currency's exponent (a small built-in table covers the common 0-, 2- and 3-decimal currencies; others default to 2). Rounding: half-up at the end of each line, never on intermediate sums.

### 3.2 Engine

- `modules/pricing/PriceEngine.php` (pure): `line_items(form, data)` → `[{ field, label, unit_minor, qty, total_minor }]`, `evaluate(form, data)` → `{ currency, exponent, line_items, subtotal, discount: { id, amount }, total, deposit, due_now, remaining, formula_revision, snapshot_hash }`.
- `modules/pricing/Expression.php`: tokenizer + shunting-yard evaluator over `{field_id}`, numbers, `+ - * / ( )`, functions `round(x, d)`, `min`, `max`, `if(cond, a, b)` where `cond` is a comparison. No PHP eval, no string ops. Dependency graph with cycle detection in `FormValidator` (CQ5), division by zero → validation error at save, evaluation error → 0 with a flagged `errors[]`.
- JS mirror in form-submit.js (`updateCalculations()`), fixtures `tests/fixtures/pricing.json` run by `tests/PriceEngineTest.php` and `tests/PriceEngineTest.js`. The example fixture (1,000 + 2 × 150 = 1,300; −10 % = 1,170; 30 % deposit = 351 due / 819 remaining) is case one (CQ6).
- Submit: `SubmitEndpoint` ignores any posted value for `calculated` fields and any posted price, recomputes from the saved form (CQ2), stores `entries.pricing_snapshot` (JSON) plus `amount_total_minor`, `amount_due_minor`, `currency` columns for sorting and filtering. Applicability from the condition engine excludes hidden-branch line items (CQ3).

## 4. Quotes (B2, Pro)

- Table `neural_forms_quotes`: id, entry_id, number (`{prefix}-{year}-{seq}`), revision, status `draft | issued | expired | superseded`, snapshot (JSON: line items, amounts, currency, formula_revision, business block, customer block, terms), notes, valid_until, access_token_hash, issued_by, issued_at, created_at. Corrections = a new row with `supersedes_id`; issued snapshots are never edited (CQ8).
- Branding (global Pro settings): logo attachment id, business name, address, tax id, terms, footer, quote prefix, validity days.
- Rendering: one HTML template with a print stylesheet; PDF through **dompdf** vendored in Pro (`vendor-elicus/dompdf`, LGPL). Files are generated on demand and streamed, never written to a public upload path (CQ10).
- Public access: `GET /neural-forms-pro/v1/quotes/{id}/view|pdf?token=…` — opaque 32-byte token, stored hashed, bound to the quote revision, expires with `valid_until`.
- Inbox: Quote card on the entry (create draft from the pricing snapshot, edit lines, issue, send by email with PDF, supersede), `quote.*` activity rows. A quote never changes the lead stage by itself.
- Daily cron `neural_forms_quote_expire` moves issued quotes past `valid_until` to `expired`.

## 5. Stripe payments (B3–B4, Pro)

### 5.1 Connection

API keys (publishable + secret) for **test** and **live**, plus the webhook signing secret, encrypted with `TokenVault`. Settings page: connect, test ("retrieve balance"), mode switch, webhook URL to paste into Stripe. Stripe Connect / OAuth is deferred (needs a platform account; the key approach ships first).

### 5.2 Flow

1. Submission stored (entry + pricing snapshot) **before** any Stripe call.
2. If the form has `payment.enabled` and `due_now > 0`: insert a `neural_forms_payments` row (`pending`, `kind: full|deposit`, idempotency key `nf-{entry}-{attempt}`), create a Checkout Session server-side from the snapshot (line items + currency + metadata `entry_id`, `payment_id`), store `session_id`, return `outcome: { mode: 'redirect', url }`.
3. Visitor pays on Stripe. Return URL = a site page with `?nf_payment=…&token=…` that shows "received / pending" from the stored state only (PAY3). Cancel URL re-shows the form message with a "Pay later" link if a quote exists.
4. Webhook `POST /neural-forms-pro/v1/stripe/webhook`: raw body + `Stripe-Signature` verified with the stored secret; account id and livemode must match the connection; `checkout.session.completed`, `checkout.session.async_payment_succeeded/failed`, `checkout.session.expired`, `payment_intent.succeeded/payment_failed`, `charge.refunded`. Every event id is stored in `neural_forms_payment_events` (unique) before processing; amount and currency are compared with the row (PAY4, PAY5). Paid only when `payment_status = paid` (delayed methods stay `processing`) (PAY6, PAY7).
5. Entry aggregate `payment_status`: `none | pending | processing | failed | cancelled | deposit_paid | paid | partially_refunded | refunded`; a later failed attempt never regresses a settled payment (PAY5). Deposit success shows amount paid and remaining balance (PAY2).
6. `neural_forms_payment_confirmed($entry_id, $payment)` → workflow trigger `payment_confirmed` with `{ amount_paid, remaining, kind, currency }`; `Submission received` never carries paid context (WF rule).
7. Reconciliation: daily cron retrieves sessions still `pending`/`processing` after one hour; entry card has "Check status now"; if two sessions for one entry both succeed, the entry gets `needs_reconciliation` and an activity warning, nothing is auto-refunded (PAY12).
8. Price or quote changes never mutate an open session; the old session is expired on the next attempt and a replacement created (PAY9).

### 5.3 Tables

- `neural_forms_payments`: id, entry_id, quote_id, provider, livemode, account_id, session_id, payment_intent_id, kind, amount_due_minor, amount_paid_minor, amount_refunded_minor, currency, status, idempotency_key (unique), attempt, created_at, updated_at, meta.
- `neural_forms_payment_events`: id, provider, event_id (unique), type, payment_id, received_at, processed_at, result.
- Entries: `payment_status`, `amount_total_minor`, `amount_due_minor`, `currency`, `pricing_snapshot`.
- Retention: payment rows are excluded from the generic log cleanup; the privacy eraser anonymises customer fields on quotes and payments but keeps amounts and provider ids (financial record).

### 5.4 What never happens

No card data touches WordPress (hosted Checkout only). Secrets never appear in REST responses (masked like the HubSpot token), exports, AI payloads (`strip_ai_excluded` + a hard exclusion of payment meta) or logs (PAY13). Test-mode events never touch live rows and vice versa (PAY10).

## 6. UI

- Builder: Product and Calculated chips in the palette (locked upsell in Lite); Form settings › **Pricing** (currency, discount rules with the shared condition editor, deposit) and › **Payments** (enable, full or deposit, return pages).
- Frontend: live totals block under the form (subtotal, discount, total, due now, remaining) updated with the form; "Pay securely with Stripe" as the submit label when payment is on.
- Inbox: payment pill in the list, Payment card and Quote card in the detail, filters `payment_status`.
- Settings (Pro): Stripe connection + Quote branding sections.

## 7. Milestones

| Milestone | Deliverable | Exit gate |
|---|---|---|
| B0 ✅ | this document; versions stay 1.2.0 / 1.1.0; dev reverted to those | done 2026-10-29 |
| B1 ✅ 2026-10-29 | product + calculated fields, pricing settings, PriceEngine + Expression (PHP + JS), fixtures, entry columns, submit recompute, live totals, builder locked/unlocked states | CQ1–CQ6 pass: 9 shared fixtures on PHP 7.4 / 8.2 / Node 18; live harness: tampered price / calculated / unknown option ignored, hidden product excluded, snapshot + columns stored, CSV shows choices |
| B2 | quotes table, numbering, branding, HTML + PDF, token routes, inbox Quote card, email send, expiry cron | CQ7–CQ11 |
| B3 | Stripe settings + vault, Checkout creation, return pages, webhook + event store, status model, reconciliation cron + button | PAY1–PAY13 (webhook replay / out-of-order / mismatch covered by unit tests on the handler with recorded events) |
| B4 | `payment_confirmed` trigger, `create_quote_draft` action, quote pay links, inbox filters | end-to-end deposit, full payment, failed, refund scenarios on the live harness |
| B5 | upgrade test, docs, changelogs, re-cut of release/1.2.0 and release/1.1.0 from dev | release gates |

B2 can run in parallel with B3 once B1's snapshot shape is fixed.

## 8. Decisions recorded

- **D1 Money as integer minor units** with a built-in currency exponent table; no floats in totals. Half-up rounding per line.
- **D2 Expression language is tiny and non-Turing**: arithmetic, three functions, `if()`. No eval.
- **D3 Stripe via API keys first**, Connect later. Hosted Checkout only; no Elements, no raw card fields.
- **D4 Webhook is the only path to "paid".** Return URLs and the admin "Check status" button only *read* Stripe and still go through the same verification as a webhook event.
- **D5 dompdf vendored in Pro** for PDFs (LGPL, fonts bundled); HTML print view is the fallback when the library cannot run (memory limits).
- **D6 Quotes are immutable once issued**; corrections create revisions. A quote is never called an invoice.
- **D7 One capability each**: `calculations`, `quotes`, `payments`. Pro flips all three; Lite ships every engine and locks the UI.
- **D8 Reuse the 201 `outcome.redirect`** for the Checkout hand-off rather than a new response shape.

## 9. Owner input (defaults applied, as the draft instructs; overrule any time)

1. Versions: Lite 1.2.0 / Pro 1.1.0 (confirmed by the owner's scope note).
2. Default currency and the initial list to expose in the Pricing settings (proposal: USD, EUR, GBP, INR, AUD, CAD plus "other ISO code").
3. Stripe account mode for development: test keys for the local site (needed from B3), and whether Stripe Connect is required for the 1.x line at all.
4. Quote branding defaults and numbering format (proposal: `Q-2026-0001`, 30-day validity).
5. Whether B2 (quotes) ships in the same release as B3 (payments) or payments go first; the plan assumes both.
