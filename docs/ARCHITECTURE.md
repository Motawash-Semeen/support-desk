# Architecture

## Request path (writes)

```
HTTP request
    │
    ▼
Route (auth / verified middleware)
    │
    ▼
Controller ──────────────── thin: no queries beyond what the view needs, no rules
    │
    ├─► FormRequest ─────── validates input, rejects early (422 / redirect back)
    │
    ├─► Policy ──────────── $this->authorize(...) — server-side, every action
    │
    ▼
Action (app/Actions/...) ── one use case, one DB::transaction boundary
    │
    ├─► Service (app/Services/...) ── reusable domain logic (SLA maths, agent selection)
    │
    ├─► Model (Eloquent) ─── persistence, relationships, casts (enums), scopes
    │
    └─► event(...) after commit
            │
            ▼
        Listeners ──────── ShouldQueue for any I/O
            │
            ├─► Notifications (mail / database, queued)
            └─► Jobs (queued, idempotent, retry-safe)

Scheduler (routes/console.php) ──► Jobs ──► Actions / Services
```

## Request path (reads)

```
Controller ─► Policy (viewAny / view) ─► Model query
                                          · eager load relations (with)
                                          · aggregates via withCount
                                          · select only listed columns
                                          · paginate()
           ─► Blade view (dates converted to config('support.timezone'))
```

## Layer rules

| Layer         | May call                              | Must not                                   |
|---------------|---------------------------------------|--------------------------------------------|
| Controller    | FormRequest, Policy, Action, Model reads | contain business rules or multi-step writes |
| Action        | Services, Models, events, DB::transaction | read the HTTP request or session          |
| Service       | Models, config                        | dispatch HTTP responses, depend on Actions |
| Listener/Job  | Actions, Services, Notifications      | assume it runs in the request lifecycle    |
| Model         | relationships, casts, scopes          | call Actions or send notifications         |

## Cross-cutting

- **Configuration:** every business tunable is read from `config/support.php`.
- **Time:** stored in UTC (`APP_TIMEZONE=UTC`); converted to the business timezone only in
  `App\Services\Sla` and in views.
- **Storage:** attachments go to the non-public `private` disk and are streamed through an
  authorized controller action.
- **Strict Eloquent:** outside production, lazy loading, silently discarded attributes and
  missing attributes throw, so N+1 queries fail tests instead of slipping through.
- **Queues/cache:** Redis in production; database queue as the local fallback.
