# Performance

Every query behind the main pages and jobs was measured on a realistic volume, so these numbers are evidence rather
than assumptions about indexes.

## Method

| | |
|---|---|
| Data | A bulk seeder on a throwaway database: **100,000 tickets** over two years (70% resolved or closed; of the active ones about 8% breached and 10% approaching), **300,000 replies** (15% internal notes), **50,000 attachment rows**, **~400,000 activities**, 5,000 customers and 40 agents. Chunked multi-row INSERTs, deterministic random seed. |
| Engine | MariaDB 10.4 (XAMPP, 16 MB buffer pool), the reference development server. Production and CI run MySQL 8, whose optimizer makes the same choices for these query shapes. |
| Tool | A `perf:explain` command. It ran each page through the **real HTTP kernel** (or the real Artisan command), captured every SELECT, and ran `ANALYZE` on it (MariaDB: estimated **and actual** rows, plus filtered %). Framework bookkeeping (sessions, cache) is excluded. |
| Reproduce | Both tools were removed to keep the app small (D-137). They are in git history up to commit `9edc811` (`database/seeders/PerformanceSeeder.php`, `app/Console/Commands/ExplainQueries.php`). |

"Rows (actual)" is what the engine read for that step (`r_rows` of `ANALYZE`). A good plan reads close to what it
returns: about 15–16 rows for a list page.

## Results: before and after, 100k tickets

Total time of each scenario's application queries during the real request, on a cold cache. **Full scan** means the plan read the whole `tickets` table (`ALL`) or walked its entire primary key.

| Scenario | Before: time (cold) | Before: full scan? | After: time (cold) | After: full scan? |
|---|---:|:---:|---:|:---:|
| Ticket list, admin default (active, due soonest) | 453 ms | no | 401 ms | no |
| Ticket list, agent default | 41 ms | no | 36 ms | no |
| Customer 'my tickets' | 360 ms | no | 287 ms | no |
| Filter: status = open | 335 ms | no | 260 ms | no |
| Filter: priority = critical | 2.1 s | no | 160 ms | no |
| Filter: category | 2.6 s | no | 342 ms | no |
| Filter: tickets by agent | 1.5 s | no | 128 ms | no |
| Filter: unassigned | 1.1 s | no | 84 ms | no |
| Filter: customer | 157 ms | no | 4 ms | no |
| Filter: created date range | 1.3 s | **yes** | 502 ms | no |
| Filter: SLA breached | 47 ms | no | 226 ms | no |
| Filter: SLA approaching | 75.5 s | no | 245 ms | no |
| Filter: escalated | 401 ms | no | 74 ms | no |
| Filter: closed scope, newest first | 58.8 s | no | 1.0 s | no |
| Sort: priority | 112.3 s | no | 107 ms | no |
| Sort: last updated | 487 ms | no | 250 ms | no |
| Keyword search (FULLTEXT) | 39.2 s | no | 38.7 s | no |
| Keyword = ticket number | 37 ms | no | 34 ms | no |
| Unassigned queue (staff) | 1.2 s | no | 103 ms | no |
| Dashboard, admin | 59.7 s | **yes** | 589 ms | no |
| Dashboard, agent | 8.1 s | no | 33 ms | no |
| Ticket page + activity timeline, staff | 422 ms | no | 253 ms | no |
| Ticket page + activity timeline, customer | 140 ms | no | 279 ms | no |
| Customer autocomplete | 73 ms | no | 46 ms | no |
| sla:scan (warning, breach, level-2 passes) | 3.8 s | **yes** | 192 ms | no |

## What the measurement found, and what changed

Every row below was a real finding on the 100k data set. The fixes are the indexes in the
tickets and notifications migrations, `App\Support\DeferredPagination`,
`DashboardStatsService::counters()` and `ScanSla::dispatchFor()` (DECISIONS D-121, D-128).

| Finding (before) | Cause | Fix |
|---|---|---|
| Created-date filter: COUNT did a **full table scan** (`ALL`, 100k rows) | `(created_at)` did not cover `status IN (…)` | `(created_at)` → `(created_at, status)` |
| SLA approaching: count 39 s, list 36 s (cold) | `(status, due_at)` range, then one row read per candidate to check `sla_warning_at` | `(status, sla_warning_at)` → `(status, sla_warning_at, due_at, warning_notified_at)`, plus deferred-join paging |
| Priority sort: 112 s | `CASE priority` sort needed `priority` from every active row | new `tickets_dashboard_cover_index (status, priority, …)` + deferred join (the id query sorts index entries only) |
| Closed list, newest first: 59 s | walked `created_at` backwards reading full rows to test `status`; with status in the index MariaDB 10.4 then chose `ALL` | `(created_at, status)` + deferred join (the id query walks the index only) |
| Category / agent / priority / unassigned lists: 1–2.6 s | `(x, status)` had no `due_at` for the ORDER BY | `(x, status)` → `(x, status, due_at)` |
| Admin dashboard counters: **full table scan** | one conditional-aggregation pass over every ticket, history included | two queries, active and finished, each index-only (`tickets_dashboard_cover_index`, `(status, resolved_at)`) |
| Dashboard tickets-by-agent: 59 s | `(assigned_agent_id, status)` had no `due_at` | `tickets_agent_cover_index (assigned_agent_id, status, due_at, priority, is_escalated, sla_warning_at, resolved_at)` |
| Agent dashboard: 31 s + 7 s + 26 s | "assignee = me OR nobody" reached rows through the agent index for every counter column | same `tickets_agent_cover_index` |
| `sla:scan` (every minute): **primary-key walk over all rows** in each pass; level 2 then a full scan | `chunkById` adds `ORDER BY id LIMIT`, so the optimizer walked `id` order; `escalation_level` was not indexed | ids read through the SLA indexes (no ORDER BY id); `(status, due_at, breached_notified_at)`, `(status, is_escalated, escalation_level, due_at)` |
| Customer "my tickets": row read per own ticket to test `status` | `(created_by, created_at)` lacked `status` | `(created_by, created_at, status)` |
| Notifications: unread count, list and prune not indexed | Laravel's default table indexes only the morph pair | `(notifiable…, read_at)`, `(notifiable…, created_at)`, `(read_at)` (migration `…100004`) |

**Deferred join (`App\Support\DeferredPagination`).** A list query selects display columns that no index can hold,
so the engine used to read a full row for every candidate before sorting and keeping 15. The ticket list and the
unassigned queue now run:

1. `COUNT(*)`, index-only;
2. `SELECT id … ORDER BY … LIMIT 15`, index-only, typically 1–25 ms in the tables below;
3. the 15 rows by primary key.

The cost per page is bounded by the index range, not by row width or table history.

## CSV import at 100,000 rows

A 100,000-row CSV, imported through the real `RunTicketImport` on the same 100k-ticket database, so 200k tickets
by the end:

| Measure | Result |
|---|---|
| Outcome | `completed`: **99,900 imported, 100 rejected**. The 100 rejected rows are exactly the invalid emails planted in the file, each reported with its spreadsheet row number. |
| Memory | baseline 26 MB, peak 50 MB: **+24 MB** for the whole run. The file is streamed and processed in 500-row chunks, so memory does not grow with the file size. The 10k-row test asserts the same bound. |
| Time | 1,405 s, about **71 rows/s**, on the reference MariaDB (16 MB buffer pool) maintaining ~15 secondary indexes plus FULLTEXT on every chunk. |
| Resumability | each chunk commits with its checkpoint (`last_processed_row`); `TicketImportProcessingTest` crashes an import mid-way and resumes it without duplicates or number gaps. |

Throughput is bound by index maintenance on this small buffer pool, not by PHP: the same 10k-row test runs in about
9 s on SQLite. A production MySQL with a properly sized buffer pool is much faster. The scale-up path is parallel chunk
jobs that reserve ticket-number blocks up front.

## Beyond indexes

| Concern | How it is handled | Proof |
|---|---|---|
| N+1 queries | `Model::preventLazyLoading()` outside production, so any lazy load throws. Lists eager-load `creator:id,name`, `assignee:id,name`, `category:id,name` (`withListRelations`); the ticket page eager-loads replies with authors and attachments, and paginates the timeline. | `Database/ListQueryTest` (same query count for 3 or 30 rows), `Search/TicketFilterTest` (5 vs 50 rows), `Bootstrap/PageSmokeTest` (every page × every role with strict mode on) |
| Columns | Lists select `Ticket::LIST_COLUMNS` (no `description` TEXT) and relations `id,name` only; the dashboard returns aggregates, never rows. | `Database/ListQueryTest` "selects only list columns" |
| COUNT queries | Pagination counts run on the same WHERE and are answered from covering indexes (`Using index` in every list above). | tables above |
| Caching | SLA rules (`SlaPolicyRepository`, flushed by `SlaRulesObserver` when an admin edits them) and dashboard numbers (30 s per scope). | `Sla/SlaPolicyRepositoryTest`, `Dashboard/DashboardStatsTest` |
| Background work | `sla:scan` reads candidate ids through indexes and queues one small unique job per ticket; imports stream the CSV in 500-row chunks with bulk inserts. | `Sla/SlaScanTest`, `Import/TicketImportProcessingTest` |

## Known, accepted

- **Keyword search on this data set** reads about 58k FULLTEXT matches. The generator writes descriptions from a
  30-word vocabulary, so "printer refund" matches over half of all tickets. Real ticket text is far more selective,
  and InnoDB returns matches in relevance order. If keyword search ever dominates, it moves to Scout plus a search
  engine.
- **The closed list (newest first)** walks `(created_at, status)` backwards, index-only, skipping the newest tickets
  that are still active. In this data set every active ticket is among the newest 30%, the worst case for that walk.
- **Timings are cold-cache numbers** on a 16 MB buffer pool, so absolute milliseconds are pessimistic. The access type,
  index used and rows read are what carry over to a production MySQL 8 server.
- **More indexes cost writes.** Tickets now have about 15 secondary indexes. Tickets are written rarely (create, a few
  status changes) compared to how often lists, filters and dashboards read them.

