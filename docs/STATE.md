# Neural Forms State Model

## Inbox State

```
scope: { type: 'all' | 'form', formId }
view: inbox | unread | starred | needs_reply | spam | trash
```

## Entry Status Values

- `new` -- unread
- `read`
- `spam`
- `trash`

## Entry Flags

- `is_starred`
- `needs_reply`

## AI Status (`ai_status` column)

- `null` -- no AI analysis requested
- `queued` -- job inserted, awaiting processing
- `processing` -- currently being analysed
- `complete` -- analysis finished, results stored
- `failed` -- all retry attempts exhausted

## Optimistic Updates

- Apply state change instantly in the UI
- Fire API call
- Refresh list on success
- Revert on failure
