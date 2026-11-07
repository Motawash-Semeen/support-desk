# Security checklist

Each item was verified against the code. Where a test proves it, the test is named; those tests run in CI on
every push.

| # | Item | Status | Where / proof |
|---|---|---|---|
| 1 | Every controller action authorizes on the server | ✅ | Policies `app/Policies/TicketPolicy.php`, `AttachmentPolicy.php`; gates in `AppServiceProvider::configureAuthorization`. **Automated audit:** `tests/Feature/Security/RouteAuthorizationAuditTest.php` scans every route and fails when an authenticated route has neither `role:` middleware nor a detectable `authorize()` / `Gate::` / `->can()` / FormRequest authorization, unless it is on a reviewed allowlist (own-account Breeze routes, each with a reason). A second assertion requires a policy call on every `tickets.*`, `admin.*` and download action even behind role middleware. |
| 2 | Role checks are layered | ✅ | `role:` middleware (`app/Http/Middleware/EnsureRole.php`) for coarse areas + policy per action + `Ticket::visibleTo()` on lists and route binding. Matrix: `Authorization/TicketPolicyMatrixTest.php` (376 cases). |
| 3 | IDOR: customers cannot reach others' data | ✅ | `{ticket}` binding resolves through `visibleTo()` (`AppServiceProvider::configureRouteBindings`), so another customer's ticket is a 404 identical to a missing one. `Authorization/IdorTest.php`: every ticket route, JSON, attachment by id, notification by id, enumeration of neighbouring numbers, staff-only filters. |
| 4 | Mass assignment | ✅ | Every model uses `$fillable`; no `$guarded = []` and no `unguard()` (asserted by `SecurityHardeningTest`). `role`, `is_available`, `last_assigned_at` are not fillable on `User` and change only through Actions (`forceFill`). Tests: registration with `role=admin` creates a customer; profile update ignores `role` and `is_available`. Strict mode (`Model::preventSilentlyDiscardingAttributes`) throws on non-fillable writes outside production. |
| 5 | Validation on every input | ✅ | One FormRequest per write (`app/Http/Requests/*`), enums validated with `Rule::enum`, sort fields whitelisted (`TicketFilter::SORTS`), keyword sanitised for FULLTEXT (`FullTextQuery`), all SQL values bound. CSV rows validated per row (`ImportTicketRows`). |
| 6 | Output escaping | ✅ | Blade `{{ }}` everywhere; **no `{!! !!}` in application views** (asserted). Ticket description and replies are printed with `{{ }}` inside `whitespace-pre-line`, which has the same effect as `nl2br(e())`: line breaks kept, HTML shown as text. Test: `<script>` and `<img onerror>` in subject or description render escaped. Error-report CSV cells are guarded against formula injection (`TicketImportController::safeCell`). |
| 7 | CSRF | ✅ | Laravel's `web` group verifies the token on every POST/PUT/PATCH/DELETE; no exceptions are configured (asserted). Forms use `@csrf`; the only Alpine `fetch` calls are GETs (import progress, customer autocomplete). |
| 8 | Session security | ✅ | Session regenerated on login and invalidated with a new token on logout (`AuthenticatedSessionController`). Cookies are `http_only`, `same_site=lax`, and `secure` by default when `APP_ENV=production` (`config/session.php`). |
| 9 | Password rules | ✅ | `Password::defaults()`: production requires at least 10 characters, mixed case, numbers and **not breached** (HIBP k-anonymity); 8 characters elsewhere. Bcrypt. Login throttled (5 per email+IP, Breeze); email verification enforced (`User implements MustVerifyEmail`, D-120). |
| 10 | Rate limiting | ✅ | Named limiters per user (`AppServiceProvider::configureRateLimits`, `config/support.php` `rate_limits`): tickets 10/min, replies 30/min, downloads 60/min, imports 5/min; verification resend 6/min. Tests: `Security/RateLimitTest.php`. |
| 11 | File uploads | ✅ | Whitelist on the extension **and** on the content-sniffed type (`AttachmentRules`, `SafeUpload`); size and count limits; stored under random UUID names on the non-public `private` disk; original names cleaned (`SafeFilename`). |
| 12 | File downloads | ✅ | Only through `AttachmentController` → `AttachmentPolicy` (internal-note files hidden from customers; others 404). `X-Content-Type-Options: nosniff`, a sandboxing CSP on the file, and `Content-Disposition: attachment` except for PNG/JPEG. Tests: `Conversation/AttachmentDownloadTest.php`, `AttachmentSecurityTest.php`. |
| 13 | Internal-note secrecy | ✅ | Single switch `TicketPolicy::viewInternalNotes` used by `repliesVisibleTo` / `activitiesVisibleTo`, resources and notifications. `AgentRepliedNotification` refuses internal notes. Test: `Conversation/InternalNoteVisibilityTest.php` (page, JSON, email, in-app, timeline). |
| 14 | Security headers | ✅ | `app/Http/Middleware/SecurityHeaders.php` on the web group: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS over HTTPS, and a CSP with a per-request script nonce (no `unsafe-inline` scripts). Tests: `Security/SecurityHardeningTest.php`. |
| 15 | Secrets only in `.env` | ✅ | `env()` appears only in `config/*` (asserted), so `config:cache` is safe. `.env` is git-ignored; `.env.example` lists every key with production notes (`APP_DEBUG=false`, secure cookies). |
| 16 | Debug off in production | ✅ | `.env.example` documents `APP_ENV=production` / `APP_DEBUG=false`. Database errors render a friendly page with a reference id, while context is logged (`bootstrap/app.php`). Strict Eloquent is disabled in production so that a missed eager load degrades rather than fails. |
| 17 | Queued work cannot leak across tenants | ✅ | Notifications resolve recipients from the database at send time (`ResolvesRecipients`); jobs carry ids, not models, and re-check state under a guard (`EscalateTicket`, `AutoAssignTicket`). |
| 18 | Static analysis | ✅ | Larastan level 5, no baseline (`phpstan.neon`), in CI. |
| 19 | Every error logged and visible to admins | ✅ | The `tracker` log channel, which is part of the default stack, stores every log entry at `error` or above, including every reported exception. `TrackHttpErrors` stores 4xx responses grouped by status. Admins review them at `/admin/errors` (`view-error-log` gate, admin-only, covered by the route audit). Context keys that look secret (password, token, cookie, api_key and similar) and secret query parameters are redacted before storage. Visitors see only a custom error page with a reference number, never the internals. Tests: `ErrorTracking/*`. |

## Known gaps (accepted)

- **CSP allows `'unsafe-eval'`.** The standard Alpine.js build evaluates `x-*` expressions with `Function()`. The
  `@alpinejs/csp` build would remove this, but every component would have to be rewritten as `Alpine.data()`.
- **CSP allows inline styles** (`'unsafe-inline'` for `style-src`) for Alpine's `x-show` and Tailwind
  utilities generated at runtime. Style injection cannot execute script.
- **No virus scanning** of uploads. Recommended for production: ClamAV on the queue before an attachment is
  marked available.
- **No 2FA** for staff. Breeze does not include it; Laravel Fortify can add it.
