# Neural Forms UI Guidelines

## Design Principles

- Gmail-inspired UX
- Clean, minimal rows
- Interaction-driven controls (hover/click dropdowns over inline selects)
- BEM CSS naming under `#neural-forms-app` scope
- CSS variables prefixed `--f-` (e.g. `--f-gray-400`, `--f-accent`)

## Sidebar Layout

- Forms list first
- Views second (inbox, unread, starred, needs_reply, spam, trash)

## Entry Row

```
[checkbox] [star] [title] [snippet] [time]
```

Unread: bold text + indicator dot

## Settings Screen

Sections in order:
1. Neural Forms Cloud AI (API key -> test connection -> enable AI -> auto-process -> spam detection)
2. Notifications (admin + autoresponder)
3. Email Settings (sender identity, test email)
4. General & Privacy
5. CAPTCHA

## Hover Dropdowns

- Bridge trigger and menu with `padding-top` on menu wrapper, not margin or gap
- Use a separate inner div for the visual box
- Never use `top: calc(100% + Npx)` -- breaks CSS `:hover` chaining

## Toggles

Custom `<button role="switch">` component, not native checkbox.
Supports `disabled` prop for locked states.
