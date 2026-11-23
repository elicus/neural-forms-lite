# Neural Forms API Documentation

Base: `/wp-json/neural-forms/v1`

## Forms

| Method | Path | Description |
|--------|------|-------------|
| GET | `/forms` | List forms -> `{ items, total }` |
| POST | `/forms` | Create form |
| PUT | `/forms/{id}` | Update form |
| DELETE | `/forms/{id}` | Delete form |

## Entries

| Method | Path | Description |
|--------|------|-------------|
| GET | `/entries` | List entries (params: form_id, view, search, date_from, date_to) |
| GET | `/entries/{id}` | Single entry |
| PATCH | `/entries/{id}` | Update entry (status, flags) |
| POST | `/entries/bulk` | Bulk status change |
| GET | `/entries/counts` | Counts per view |
| GET | `/entries/export` | CSV export (params: form_id, view, search, date_from, date_to) |

## Submission

| Method | Path | Description |
|--------|------|-------------|
| POST | `/submit` | Public form submission (no auth required) |

## AI

| Method | Path | Description |
|--------|------|-------------|
| POST | `/ai/process-queue` | Trigger immediate queue run (admin only) |
| POST | `/entries/{id}/retry-ai` | Re-queue AI analysis for an entry |

## Replies & Messages

| Method | Path | Description |
|--------|------|-------------|
| POST | `/entries/{id}/reply` | Send reply to submitter |
| GET | `/entries/{id}/messages` | Entry message thread |

## Settings

| Method | Path | Description |
|--------|------|-------------|
| GET | `/settings` | Read settings (API keys returned as `*_set: bool` only) |
| PATCH | `/settings` | Partial update (write-only keys: only persist non-empty values) |
| POST | `/settings/test-email` | Send test email |

## Stats

| Method | Path | Description |
|--------|------|-------------|
| GET | `/stats` | Dashboard statistics |

## Rules

- Do not break response shape
- Use `X-WP-Total` / `X-WP-TotalPages` headers for pagination
- API keys are write-only: GET returns `*_set: bool`, never raw values
- All admin endpoints require `manage_options` capability
- `/submit` is public but validates CAPTCHA when configured
