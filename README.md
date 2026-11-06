# Support Desk — Support Ticket & SLA Management System

Laravel 12 · PHP 8.2+ · MySQL 8 · Redis (queue + cache) · Blade + Tailwind (Breeze) · Pest

- Architecture and layer rules: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Design decisions and trade-offs: [docs/DECISIONS.md](docs/DECISIONS.md)
- Business settings (SLA targets, business hours, attachments, assignment): [config/support.php](config/support.php)

## Requirements

- PHP 8.2+ with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`
- Composer 2
- MySQL 8 (MariaDB 10.3+ also works with the `mysql` driver)
- Redis (production queue/cache). Not required locally: use the database queue/cache.
- Node.js 20.19+ or 22.12+ (Vite 7) for building assets

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the databases (utf8mb4):

```sql
CREATE DATABASE support_desk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE support_desk_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Local machine without Redis: in `.env` set `QUEUE_CONNECTION=database` and `CACHE_STORE=database`.

```bash
php artisan migrate --seed
npm install && npm run build
composer run dev   # serve + queue:listen + pail + vite
```

## Environment

| Concern     | Local                          | Production                         |
|-------------|--------------------------------|------------------------------------|
| Database    | MySQL / MariaDB                | MySQL 8                            |
| Queue       | `database` (or `redis`)        | `redis` (`REDIS_CLIENT=predis` or `phpredis`) |
| Cache       | `database` (or `redis`)        | `redis`                            |
| Session     | `database`                     | `database`                         |
| Mail        | `log` (→ `storage/logs`)       | `smtp` (see commented block in `.env.example`) |
| Files       | `local`; attachments on `private` (`storage/app/private`, never served by URL) | same, or an S3 disk via `SUPPORT_ATTACHMENTS_DISK` |
| Timezone    | App and DB in **UTC**; business timezone `SUPPORT_TIMEZONE` (Asia/Dhaka) used only by the SLA calculator and views |

## Testing

```bash
php artisan test        # or vendor/bin/pest
vendor/bin/pint         # code style
```

The test database is chosen by [tests/bootstrap.php](tests/bootstrap.php) and printed at the start of every run
(`Test database: ...`):

- `DB_TEST_CONNECTION=auto` (default): use **MySQL** `support_desk_testing` if it is reachable with the
  `DB_TEST_*` credentials, otherwise fall back to **SQLite in-memory**.
- `DB_TEST_CONNECTION=mysql`: always MySQL (fails loudly if unavailable — use in CI).
- `DB_TEST_CONNECTION=sqlite`: always SQLite in-memory.

`DB_TEST_*` are read from the real environment first, then from `.env`. They never change the application's
own `DB_*` connection. The reference environment for this project runs the suite on MySQL (MariaDB 10.4 locally).

Outside production, Eloquent runs in strict mode: lazy loading (N+1), silently discarded attributes and missing
attributes throw, so tests fail on an N+1 query.
