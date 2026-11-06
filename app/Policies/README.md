# Policies

Authorization rules per model (e.g. `TicketPolicy`). Every controller action calls
`authorize()` / `can` middleware; the UI only hides what the policy already forbids.
