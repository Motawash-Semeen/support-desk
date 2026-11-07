# Performance

Every query behind the main pages and jobs was measured on a realistic volume, so these numbers are evidence rather
than assumptions about indexes.

## Method

| | |
|---|---|
| Data | `database/seeders/PerformanceSeeder.php` on a throwaway database: **100,000 tickets** over two years (70% resolved or closed; of the active ones about 8% breached and 10% approaching), **300,000 replies** (15% internal notes), **50,000 attachment rows**, **~400,000 activities**, 5,000 customers and 40 agents. Chunked multi-row INSERTs, deterministic random seed. |
| Engine | MariaDB 10.4 (XAMPP, 16 MB buffer pool), the reference development server. Production and CI run MySQL 8, whose optimizer makes the same choices for these query shapes. |
| Tool | `php artisan perf:explain` (`app/Console/Commands/ExplainQueries.php`). It runs each page through the **real HTTP kernel** (or the real Artisan command), captures every SELECT, and runs `ANALYZE` on it (MariaDB: estimated **and actual** rows, plus filtered %). Framework bookkeeping (sessions, cache) is excluded. |
| Reproduce | `DB_DATABASE=support_desk_perf php artisan migrate:fresh --force && DB_DATABASE=support_desk_perf php artisan db:seed --class=PerformanceSeeder --force && DB_DATABASE=support_desk_perf php artisan perf:explain` |

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

Every row below was a real finding on the 100k data set. The fixes are in migration
`2026_11_07_100003_widen_tickets_list_indexes.php`, `App\Support\DeferredPagination`,
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
9 s on SQLite. A production MySQL with a properly sized buffer pool is much faster. The scale-up path (parallel chunk
jobs reserving ticket-number blocks) is in KNOWN_LIMITATIONS.md.

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
  engine (KNOWN_LIMITATIONS.md).
- **The closed list (newest first)** walks `(created_at, status)` backwards, index-only, skipping the newest tickets
  that are still active. In this data set every active ticket is among the newest 30%, the worst case for that walk.
- **Timings are cold-cache numbers** on a 16 MB buffer pool, so absolute milliseconds are pessimistic. The access type,
  index used and rows read are what carry over to a production MySQL 8 server.
- **More indexes cost writes.** Tickets now have about 15 secondary indexes. Tickets are written rarely (create, a few
  status changes) compared to how often lists, filters and dashboards read them.

## Appendix: every query, after the changes

Output of `php artisan perf:explain` on the final schema. Columns: **ms** is the time during the real request; **Rows (est.)** and **Rows (actual)** come from `ANALYZE`. `Using index` means the query was answered from the index alone.

### Ticket list, admin default (active, due soonest)

`GET /tickets as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?)` | 195.3 | tickets | range | tickets_status_due_at_breached_notified_at_index | 53934 | 30197 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) order by tickets.due_at asc, tickets.id asc limit ...` | 17.6 | tickets | range | tickets_status_due_at_breached_notified_at_index | 53934 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 155.3 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (170, 691, 1496, 1503, 1573, 1945, 2152, 3393, 3596, 3682, 383...` | 31.7 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (5, 8, 16, 18, 20, 21, 22, 24, 25, 39, 40)` | 0.4 | users | range | PRIMARY | 11 | 11 | Using where |
| 6 | `select id, name from categories where categories.id in (2, 3, 4, 5, 6)` | 0.4 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.4 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Ticket list, agent default

`GET /tickets as agent` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?)` | 15.9 | tickets | range | tickets_status_due_at_breached_notified_at_index | 53934 | 30197 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) order by tickets.due_at asc, tickets.id asc limit ...` | 17.8 | tickets | range | tickets_status_due_at_breached_notified_at_index | 53934 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 0.5 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (170, 691, 1496, 1503, 1573, 1945, 2152, 3393, 3596, 3682, 383...` | 0.3 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (5, 8, 16, 18, 20, 21, 22, 24, 25, 39, 40)` | 0.2 | users | range | PRIMARY | 11 | 11 | Using where |
| 6 | `select id, name from categories where categories.id in (2, 3, 4, 5, 6)` | 0.3 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.3 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Customer 'my tickets'

`GET /tickets as customer` → 200, 7 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where created_by = ? and status in (?, ?, ?)` | 193.7 | tickets | ref | tickets_created_by_created_at_status_index | 36 | 36 | Using where; Using index |
| 2 | `select tickets.id from tickets where created_by = ? and status in (?, ?, ?) order by tickets.created_at des...` | 0.7 | tickets | ref | tickets_created_by_created_at_status_index | 36 | 8 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 91.4 | tickets | range | PRIMARY | 8 | 8 | Using where |
| 4 | `select id, name from users where users.id in (3336)` | 0.4 | users | const | PRIMARY | 1 |  |  |
| 5 | `select id, name from users where users.id in (5, 9, 18, 21, 34, 37, 39)` | 0.3 | users | range | PRIMARY | 7 | 7 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 4, 5, 6)` | 0.4 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.4 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |

### Filter: status = open

`GET /tickets?status[]=open as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?)` | 4.6 | tickets | ref | tickets_status_due_at_breached_notified_at_index | 16444 | 9090 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?) order by tickets.created_at desc, tickets.id desc limit ...` | 41 | tickets | index | tickets_created_at_status_index | 96928 | 39 | Using where; Using index |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 161.7 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (208, 885, 1558, 1806, 2061, 2187, 2694, 2698, 2753, 3091, 341...` | 51.2 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (10, 18, 19, 35, 38, 41)` | 0.3 | users | range | PRIMARY | 6 | 6 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 4, 5, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: priority = critical

`GET /tickets?priority[]=critical as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and priority in (?)` | 16.1 | tickets | range | tickets_priority_status_due_at_index | 2130 | 2130 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and priority in (?) order by tickets.due_at asc, t...` | 2.3 | tickets | range | tickets_priority_status_due_at_index | 2130 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 138.2 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (170, 691, 1496, 1503, 1573, 1945, 2152, 3393, 3596, 3682, 383...` | 1.2 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (5, 8, 16, 18, 20, 21, 22, 24, 25, 39, 40)` | 0.6 | users | range | PRIMARY | 11 | 11 | Using where |
| 6 | `select id, name from categories where categories.id in (2, 3, 4, 5, 6)` | 0.5 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.4 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: category

`GET /tickets?category_id=1 as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and category_id = ?` | 29 | tickets | range | tickets_category_id_status_due_at_index | 5034 | 5034 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and category_id = ? order by tickets.due_at asc, t...` | 3.9 | tickets | range | tickets_category_id_status_due_at_index | 5034 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 307 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (154, 315, 967, 1614, 1663, 2652, 3171, 3308, 3525, 3539, 3555...` | 1.1 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (2, 3, 4, 6, 14, 24, 29, 40, 41)` | 0.2 | users | range | PRIMARY | 9 | 9 | Using where |
| 6 | `select id, name from categories where categories.id in (1)` | 0.3 | categories | const | PRIMARY | 1 |  |  |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: tickets by agent

`GET /tickets?assigned_agent_id=2 as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and assigned_agent_id = ?` | 17.9 | tickets | range | tickets_agent_cover_index | 621 | 621 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and assigned_agent_id = ? order by tickets.due_at ...` | 1.2 | tickets | range | tickets_agent_cover_index | 621 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 106.3 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (237, 1259, 1545, 1578, 2033, 2206, 2292, 2493, 2698, 2811, 29...` | 1.2 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (2)` | 0.4 | users | const | PRIMARY | 1 |  |  |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 5, 6)` | 0.3 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: unassigned

`GET /tickets?assigned_agent_id=unassigned as admin` → 200, 7 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and assigned_agent_id is null` | 5.2 | tickets | range | tickets_agent_cover_index | 11806 | 6376 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and assigned_agent_id is null order by tickets.due...` | 4.7 | tickets | range | tickets_agent_cover_index | 11806 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 58.9 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (126, 154, 217, 1169, 1663, 1945, 2212, 2324, 3449, 3526, 3604...` | 13.5 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from categories where categories.id in (1, 2, 3, 4, 5, 6)` | 0.3 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 6 | `select id, name from categories where is_active = ? order by name asc` | 0.3 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 7 | `select id, name from users where role = ? order by name asc` | 0.6 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: customer

`GET /tickets?customer=3336 as admin` → 200, 9 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and created_by = ?` | 0.5 | tickets | ref | tickets_created_by_created_at_status_index | 36 | 36 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and created_by = ? order by tickets.due_at asc, ti...` | 0.6 | tickets | ref | tickets_created_by_created_at_status_index | 36 | 8 | Using index condition; Using where; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 0.4 | tickets | range | PRIMARY | 8 | 8 | Using where |
| 4 | `select id, name from users where users.id in (3336)` | 0.3 | users | const | PRIMARY | 1 |  |  |
| 5 | `select id, name from users where users.id in (5, 9, 18, 21, 34, 37, 39)` | 0.3 | users | range | PRIMARY | 7 | 7 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 4, 5, 6)` | 0.3 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.3 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |
| 9 | `select id, name, email from users where role = ? and users.id = ? limit 1` | 0.5 | users | const | PRIMARY | 1 |  |  |

### Filter: created date range

`GET /tickets?created_from=2026-10-08&created_to=2026-11-07&status[]=open&status[]=in_progress&status[]=resolved&status[]=closed as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?, ?) and created_at >= ? and created_at < ?` | 272.3 | tickets | range | tickets_created_at_status_index | 48464 | 32921 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?, ?) and created_at >= ? and created_at < ? order by...` | 22.1 | tickets | range | tickets_created_at_status_index | 48464 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 168.3 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (1043, 1408, 1558, 1806, 1813, 2061, 2187, 2568, 2694, 2698, 3...` | 38 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (4, 11, 13, 18, 25, 26, 27, 37, 38)` | 0.3 | users | range | PRIMARY | 9 | 9 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 4, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: SLA breached

`GET /tickets?sla_status=breached as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and due_at is not null and status in (?...` | 46.4 | tickets | range | tickets_status_due_at_breached_notified_at_index | 36782 | 19980 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and due_at is not null and status in (?, ?, ?) and...` | 19 | tickets | range | tickets_status_due_at_breached_notified_at_index | 36782 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 141.9 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (170, 691, 1496, 1503, 1573, 1945, 2152, 3393, 3596, 3682, 383...` | 17.9 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (5, 8, 16, 18, 20, 21, 22, 24, 25, 39, 40)` | 0.3 | users | range | PRIMARY | 11 | 11 | Using where |
| 6 | `select id, name from categories where categories.id in (2, 3, 4, 5, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: SLA approaching

`GET /tickets?sla_status=approaching as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and due_at is not null and status in (?...` | 50.6 | tickets | range | tickets_status_sla_warning_at_due_at_warning_notified_at_index | 42704 | 22729 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and due_at is not null and status in (?, ?, ?) and...` | 19.5 | tickets | range | tickets_status_sla_warning_at_due_at_warning_notified_at_index | 42704 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 145.8 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (440, 1306, 1712, 1780, 2138, 2265, 2705, 2804, 3025, 3091, 31...` | 27.6 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (4, 5, 11, 20, 22, 24, 30, 36, 38)` | 0.3 | users | range | PRIMARY | 9 | 9 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 4, 5, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: escalated

`GET /tickets?escalated=1 as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and status in (?, ?, ?) and is_escalate...` | 58.6 | tickets | range | tickets_status_is_escalated_escalation_level_due_at_index | 25024 | 17552 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and status in (?, ?, ?) and is_escalated = ? order...` | 13.9 | tickets | range | tickets_status_is_escalated_escalation_level_due_at_index | 25024 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 0.5 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (170, 691, 1496, 1503, 1573, 1945, 2152, 3393, 3596, 3682, 383...` | 0.3 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (5, 8, 16, 18, 20, 21, 22, 24, 25, 39, 40)` | 0.2 | users | range | PRIMARY | 11 | 11 | Using where |
| 6 | `select id, name from categories where categories.id in (2, 3, 4, 5, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Filter: closed scope, newest first

`GET /tickets?scope=closed as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?)` | 57.1 | tickets | range | tickets_status_resolved_at_index | 96928 | 69803 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?) order by tickets.created_at desc, tickets.id desc lim...` | 876.4 | tickets | index | tickets_created_at_status_index | 96928 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 59.4 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (176, 858, 1534, 1803, 2051, 2245, 2652, 2888, 3513, 3798, 381...` | 47.8 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (2, 3, 5, 6, 7, 17, 25, 27, 28, 29, 32, 33, 40)` | 0.4 | users | range | PRIMARY | 13 | 13 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 3, 4, 5, 6)` | 0.4 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.4 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.4 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Sort: priority

`GET /tickets?sort=priority&direction=desc as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?)` | 36.9 | tickets | range | tickets_status_due_at_breached_notified_at_index | 53934 | 30197 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) order by CASE priority WHEN ? THEN ? WHEN ? THEN ?...` | 57.2 | tickets | range | tickets_dashboard_cover_index | 57362 | 15 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 0.5 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (1170, 1300, 1445, 1877, 2017, 2909, 2920, 3283, 3366, 3416, 3...` | 11.7 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (11, 19, 23, 35, 36, 37, 40)` | 0.3 | users | range | PRIMARY | 7 | 7 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 4, 5, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.4 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Sort: last updated

`GET /tickets?sort=updated_at&direction=desc as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?)` | 15.4 | tickets | range | tickets_status_due_at_breached_notified_at_index | 53934 | 30197 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) order by tickets.updated_at desc, tickets.id desc ...` | 231.2 | tickets | index | tickets_updated_at_index | 96928 | 30 | Using where |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 0.5 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (300, 1043, 1243, 1408, 1806, 1813, 2061, 2187, 2694, 2698, 31...` | 1.3 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (4, 11, 14, 18, 20, 25, 26, 27, 36, 37)` | 0.3 | users | range | PRIMARY | 10 | 10 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 4, 6)` | 0.3 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.3 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Keyword search (FULLTEXT)

`GET /tickets?q=printer+refund as admin` → 200, 8 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where match (subject, description) against (? in boolean mode) an...` | 19827.5 | tickets | fulltext | tickets_subject_description_fulltext | 1 | 58063 | Using where |
| 2 | `select tickets.id from tickets where match (subject, description) against (? in boolean mode) and status in...` | 18829.6 | tickets | fulltext | tickets_subject_description_fulltext | 1 | 15 | Using where; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 0.8 | tickets | range | PRIMARY | 15 | 15 | Using where |
| 4 | `select id, name from users where users.id in (170, 691, 1122, 1195, 1573, 1663, 2845, 3069, 3393, 3504, 352...` | 60.6 | users | range | PRIMARY | 15 | 15 | Using where |
| 5 | `select id, name from users where users.id in (5, 17, 20, 21, 22, 26, 29, 31, 40)` | 0.3 | users | range | PRIMARY | 9 | 9 | Using where |
| 6 | `select id, name from categories where categories.id in (1, 2, 3, 5, 6)` | 0.2 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |
| 7 | `select id, name from categories where is_active = ? order by name asc` | 0.2 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 8 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Keyword = ticket number

`GET /tickets?q=TKT-2026-000593 as admin` → 200, 3 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where ticket_number = ? and status in (?, ?, ?)` | 33.5 |  |  | — |  |  | Impossible WHERE noticed after reading const tables |
| 2 | `select id, name from categories where is_active = ? order by name asc` | 0.3 | categories | ref | categories_is_active_name_index | 6 | 6 | Using where; Using index |
| 3 | `select id, name from users where role = ? order by name asc` | 0.3 | users | ref | users_role_name_index | 40 | 40 | Using where; Using index |

### Unassigned queue (staff)

`GET /staff/unassigned as agent` → 200, 5 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select count(*) as aggregate from tickets where status in (?, ?, ?) and assigned_agent_id is null` | 17.8 | tickets | range | tickets_agent_cover_index | 11806 | 6376 | Using where; Using index |
| 2 | `select tickets.id from tickets where status in (?, ?, ?) and assigned_agent_id is null order by due_at asc,...` | 4.7 | tickets | range | tickets_agent_cover_index | 11806 | 20 | Using where; Using index; Using filesort |
| 3 | `select tickets.id, tickets.ticket_number, tickets.subject, tickets.priority, tickets.status, tickets.catego...` | 78.7 | tickets | range | PRIMARY | 20 | 20 | Using where |
| 4 | `select id, name from users where users.id in (53, 126, 154, 217, 1169, 1177, 1663, 1945, 2212, 2226, 2324, ...` | 1.9 | users | range | PRIMARY | 20 | 20 | Using where |
| 5 | `select id, name from categories where categories.id in (1, 2, 3, 4, 5, 6)` | 0.3 | categories | index | categories_name_unique | 6 | 6 | Using where; Using index |

### Dashboard, admin

`GET /staff/dashboard as admin` → 200, 5 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select COALESCE(SUM(CASE WHEN 1 = 1 THEN 1 ELSE 0 END), 0) AS total_active, COALESCE(SUM(CASE WHEN tickets....` | 64.8 | tickets | range | tickets_dashboard_cover_index | 57362 | 30197 | Using where; Using index |
| 2 | `select COALESCE(SUM(CASE WHEN 1 = 1 THEN 1 ELSE 0 END), 0) AS resolved_total, COALESCE(SUM(CASE WHEN ticket...` | 244.7 | tickets | range | tickets_status_resolved_at_index | 96928 | 69803 | Using where; Using index |
| 3 | `select tickets.priority, COUNT(*) AS aggregate from tickets where status in (?, ?, ?) group by tickets.prio...` | 29.7 | tickets | range | tickets_dashboard_cover_index | 57362 | 30197 | Using where; Using index; Using temporary; Using filesort |
| 4 | `select users.id, users.name, COUNT(*) AS active_count, COALESCE(SUM(CASE WHEN tickets.due_at < ? THEN 1 ELS...` | 186.6 | tickets | range | tickets_agent_cover_index | 48464 | 93624 | Using where; Using index; Using temporary; Using filesort |
| 4 | `select users.id, users.name, COUNT(*) AS active_count, COALESCE(SUM(CASE WHEN tickets.due_at < ? THEN 1 ELS...` |  | users | eq_ref | PRIMARY | 1 | 1 |  |
| 5 | `select * from ticket_activities where type = ? order by created_at desc, id desc limit 10` | 62.8 | ticket_activities | ref | ticket_activities_type_created_at_index | 1 | 0 | Using where |

### Dashboard, agent

`GET /staff/dashboard as agent` → 200, 5 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select COALESCE(SUM(CASE WHEN 1 = 1 THEN 1 ELSE 0 END), 0) AS total_active, COALESCE(SUM(CASE WHEN tickets....` | 11.6 | tickets | range | tickets_agent_cover_index | 12427 | 6997 | Using where; Using index |
| 2 | `select COALESCE(SUM(CASE WHEN 1 = 1 THEN 1 ELSE 0 END), 0) AS resolved_total, COALESCE(SUM(CASE WHEN ticket...` | 12.5 | tickets | range | tickets_agent_cover_index | 1743 | 1741 | Using where; Using index |
| 3 | `select tickets.priority, COUNT(*) AS aggregate from tickets where (tickets.assigned_agent_id is null or tic...` | 7.4 | tickets | range | tickets_agent_cover_index | 12427 | 6997 | Using where; Using index; Using temporary; Using filesort |
| 4 | `select users.id, users.name, COUNT(*) AS active_count, COALESCE(SUM(CASE WHEN tickets.due_at < ? THEN 1 ELS...` | 1.3 | users | const | PRIMARY | 1 |  |  |
| 4 | `select users.id, users.name, COUNT(*) AS active_count, COALESCE(SUM(CASE WHEN tickets.due_at < ? THEN 1 ELS...` |  | tickets | range | tickets_agent_cover_index | 621 | 621 | Using where; Using index |
| 5 | `select * from ticket_activities where type = ? and exists (select * from tickets where ticket_activities.ti...` | 0.3 | ticket_activities | ref | ticket_activities_type_created_at_index | 1 | 0 | Using where |
| 5 | `select * from ticket_activities where type = ? and exists (select * from tickets where ticket_activities.ti...` |  | tickets | eq_ref | PRIMARY | 1 |  | Using where |

### Ticket page + activity timeline, staff

`GET /tickets/TKT-2026-000593 as admin` → 200, 10 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select * from tickets where ticket_number = ? limit 1` | 11.1 | tickets | const | tickets_ticket_number_unique | 1 |  |  |
| 2 | `select id, name from users where users.id in (3716)` | 12.2 | users | const | PRIMARY | 1 |  |  |
| 3 | `select id, name from users where users.id in (41)` | 0.3 | users | const | PRIMARY | 1 |  |  |
| 4 | `select id, name from categories where categories.id in (1)` | 0.5 | categories | const | PRIMARY | 1 |  |  |
| 5 | `select * from ticket_replies where ticket_replies.ticket_id = ? and ticket_replies.ticket_id is not null or...` | 78.6 | ticket_replies | ref | ticket_replies_ticket_id_created_at_index | 7 | 7 | Using where |
| 6 | `select id, name, role from users where users.id in (2, 3, 5, 8, 14, 18, 38)` | 0.4 | users | range | PRIMARY | 7 | 7 | Using where |
| 7 | `select id, ticket_id, ticket_reply_id, original_name, mime_type, size_bytes from attachments where attachme...` | 92.1 | attachments | range | attachments_ticket_reply_id_index | 7 | 2 | Using index condition |
| 8 | `select id, ticket_id, ticket_reply_id, original_name, mime_type, size_bytes from attachments where attachme...` | 11.1 | attachments | ref | attachments_ticket_reply_id_index | 1 | 0 | Using index condition; Using where |
| 9 | `select * from ticket_activities where ticket_activities.ticket_id = ? and ticket_activities.ticket_id is no...` | 46.3 | ticket_activities | ref | ticket_activities_ticket_id_created_at_index | 8 | 8 | Using where |
| 10 | `select id, name from users where users.id in (2, 3, 5, 8, 14, 18, 38, 3716)` | 0.3 | users | range | PRIMARY | 8 | 8 | Using where |

### Ticket page + activity timeline, customer

`GET /tickets/TKT-2026-059817 as customer` → 200, 10 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select * from tickets where ticket_number = ? and created_by = ? limit 1` | 26.2 | tickets | const | tickets_ticket_number_unique | 1 |  |  |
| 2 | `select id, name from users where users.id in (3336)` | 0.3 | users | const | PRIMARY | 1 |  |  |
| 3 | `select id, name from users where users.id in (11)` | 0.2 | users | const | PRIMARY | 1 |  |  |
| 4 | `select id, name from categories where categories.id in (1)` | 0.2 | categories | const | PRIMARY | 1 |  |  |
| 5 | `select * from ticket_replies where ticket_replies.ticket_id = ? and ticket_replies.ticket_id is not null an...` | 56.1 | ticket_replies | ref | ticket_replies_ticket_id_type_index | 3 | 3 | Using index condition; Using where; Using filesort |
| 6 | `select id, name, role from users where users.id in (20, 29, 37)` | 0.3 | users | range | PRIMARY | 3 | 3 | Using where |
| 7 | `select id, ticket_id, ticket_reply_id, original_name, mime_type, size_bytes from attachments where attachme...` | 35.8 | attachments | range | attachments_ticket_reply_id_index | 3 | 0 | Using index condition |
| 8 | `select id, ticket_id, ticket_reply_id, original_name, mime_type, size_bytes from attachments where attachme...` | 46.2 | attachments | ref | attachments_ticket_id_index | 1 | 0 | Using where |
| 9 | `select * from ticket_activities where ticket_activities.ticket_id = ? and ticket_activities.ticket_id is no...` | 113.4 | ticket_activities | ref | ticket_activities_ticket_id_created_at_index | 6 | 6 | Using where |
| 10 | `select id, name from users where users.id in (20, 29, 37, 3336)` | 0.5 | users | range | PRIMARY | 4 | 4 | Using where |

### Customer autocomplete

`GET /staff/customers/search?q=Customer+12 as admin` → 200, 1 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select id, name, email from users where role = ? and (name like ? or email like ?) order by name asc limit 10` | 46.3 | users | range | users_role_name_index | 2466 | 233 | Using where |

### sla:scan (warning, breach, level-2 passes)

`php artisan sla:scan` → ok, 3 application queries

| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |
|---|---|---:|---|---|---|---:|---:|---|
| 1 | `select id from tickets where due_at is not null and status in (?, ?, ?) and due_at >= ? and sla_warning_at ...` | 89.8 | tickets | range | tickets_status_sla_warning_at_due_at_warning_notified_at_index | 42736 | 22737 | Using where; Using index |
| 2 | `select id from tickets where due_at is not null and status in (?, ?, ?) and due_at < ? and breached_notifie...` | 36.6 | tickets | range | tickets_status_due_at_breached_notified_at_index | 36830 | 19995 | Using where; Using index |
| 3 | `select id from tickets where status in (?, ?, ?) and is_escalated = ? and escalation_level = ? and due_at <= ?` | 65.9 | tickets | range | tickets_status_is_escalated_escalation_level_due_at_index | 25024 | 17552 | Using where; Using index |
