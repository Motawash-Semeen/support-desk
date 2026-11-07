# Known limitations and the scale-up path

The spec says not every enterprise scenario is needed. These are the boundaries chosen on purpose, each with what
it would take to remove it.

## Product behaviour

| Limitation | Impact | How to lift it |
|---|---|---|
| **SLA clock is not paused in `waiting_for_customer`** | A ticket waiting days for a customer reply can breach. Agents see the status, but the SLA does not exclude that time. | Add a `ticket_sla_pauses` ledger (paused_at, resumed_at), extend `due_at` by the business minutes paused on every resume, and have `SlaStatusResolver` ignore paused tickets. The calculator already exposes `addBusinessMinutes`. |
| **One business timezone and one calendar** | Every ticket uses Asia/Dhaka (`SUPPORT_TIMEZONE`) Sunday–Thursday hours; no regional teams. | Add `calendar_id` on categories or teams; `SlaPolicyRepository` builds one `BusinessCalendar` per calendar (the class is already timezone-parameterised). |
| **Resolution SLA only** | `first_response_at` is recorded, not enforced. | A second policy column and a second deadline pair; the scan gets one more pass. |
| **No partial-day hours or holidays** | One window per weekday; no lunch breaks or half-day holidays. | Allow several windows per weekday; the calendar loop already walks windows. |
| **Keyword search covers subject and description only** | Text that appears only in replies is not found. | Add a FULLTEXT index on `ticket_replies.body` (public replies for customers) or move search to Scout + Meilisearch/OpenSearch. `TicketFilter::keyword()` is the single seam. |
| **FULLTEXT token rules** | InnoDB ignores words shorter than `innodb_ft_min_token_size` (3) and stopwords such as "the"; "VPN" works, "PC" does not. Prefix search only (`print*` finds "printer"), no fuzzy matching. Exact ticket numbers bypass FULLTEXT and use the unique index. | Lower the token size (server setting, then rebuild the index) or use a search engine. |
| **No live updates** | Pages refresh on navigation; only the import progress page polls. | Laravel Reverb broadcasting `TicketUpdated` to a private channel per ticket. |
| **Dashboard numbers can be 30 s old** | Cached per role or agent for 30 seconds. | Lower the TTL or invalidate on write events; the queries themselves are fast (see PERFORMANCE.md). |
| **No customer-facing API** | JSON exists for the ticket page (content negotiation) but there are no API tokens. | Sanctum tokens plus the existing API Resources and policies. |
| **Single organisation** | No multi-tenancy. | `organization_id` on users and tickets, with a global scope in `visibleTo()`. |
| **Email in only through the web form** | Customers cannot reply by email. | An inbound mail webhook (Mailgun or Postmark) mapped onto `AddReply` using a reply-to token. |
| **No user-management screen** | Staff accounts (agents, admins) and agent categories come from the seeder or tinker; roles are deliberately not fillable from any form. | An admin-only `UserController` with a FormRequest and an Action that sets `role` / categories through `forceFill`, plus policy tests. |
| **No 2FA, no SSO** | Password plus email verification only. | Fortify 2FA for staff, Socialite or SAML for SSO. |

## Technical boundaries

| Limitation | Impact | How to lift it |
|---|---|---|
| **Attachments are streamed by PHP** | Each download holds a PHP worker for the transfer; fine for 5 MB files, wasteful at high volume. | Keep the policy check in PHP, then hand off with `X-Accel-Redirect` (nginx) or a short-lived S3 signed URL. The controller is the only place that changes. |
| **No antivirus scan of uploads** | Type and content sniffing reject disguised files, but not malware inside a real PDF. | ClamAV scan in a queued job before the attachment becomes downloadable. |
| **CSV import is one sequential job per file** | 100,000 rows take a few minutes on one worker; two imports at once contend for the ticket-number counter. | `Bus::batch()` of chunk jobs that reserve number blocks up front (`TicketNumberGenerator::reserve()` already allocates contiguous blocks). |
| **The ticket-number counter is one row per year** | Every ticket insert briefly locks that row; this serialises creation at roughly thousands per second. | Reserve blocks per worker, or accept gaps and use the auto-increment id in the number. |
| **Activity log grows forever** | About 4 rows per ticket; indexed by `(ticket_id, created_at)`, so the timeline stays fast. | Partition by month, or archive closed tickets older than N years to cold storage. |
| **Read notifications are pruned after 90 days** | Old bell entries disappear by design. | `SUPPORT_PRUNE_READ_NOTIFICATIONS_DAYS`. |
| **Alpine.js needs `'unsafe-eval'` in the CSP** | Slightly weaker CSP. | Switch to the `@alpinejs/csp` build and register components with `Alpine.data()`. |
| **Local MariaDB in the reference environment** | Development used MariaDB 10.4 (XAMPP), production targets MySQL 8, and CI runs MySQL 8. Behaviour is the same for everything used here (FULLTEXT boolean mode, `FOR UPDATE`, JSON). | — |

## Scale-up path (in order)

1. **Workers:** separate Horizon supervisors for `default`, `notifications` and `imports`; queue-depth autoscaling.
2. **Read replica:** route list, dashboard and search reads to a replica (`read`/`write` connections). Writes and the
   compare-and-set stay on the primary.
3. **Cache:** Redis cluster for cache, locks and sessions; dashboard invalidation through events instead of a TTL.
4. **Search:** Scout + Meilisearch/OpenSearch when replies must be searchable or the FULLTEXT index becomes a hot spot.
5. **Files:** S3 with signed URLs, so PHP never streams bytes.
6. **Data growth:** partition `ticket_activities` and `ticket_replies` by month; archive closed tickets.
7. **Organisations and regions:** tenant id plus per-team calendars (see above).
