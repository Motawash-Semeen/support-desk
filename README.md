# Support Desk

A support ticket and SLA management system: customers open tickets, agents work them, and every deadline is
counted in business hours.

Laravel 12 · PHP 8.2+ · MySQL 8 · Blade + Tailwind + Alpine · Pest

- **How to use every screen (with screenshots):** [docs/UI_GUIDE.md](docs/UI_GUIDE.md)
- **How the code works, in plain language:** [docs/CODE_GUIDE.md](docs/CODE_GUIDE.md)
- **Why it is built this way:** [docs/DECISIONS.md](docs/DECISIONS.md)
- **Query measurements on 100k tickets:** [docs/PERFORMANCE.md](docs/PERFORMANCE.md)

## Requirements

- PHP 8.2+ (`pdo_mysql`, `mbstring`, `fileinfo`), Composer 2, Node.js 20.19+
- MySQL 8 or MariaDB 10.4+
- Redis is optional (production queue and cache). Locally everything runs on the database.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Open <http://localhost:8000>. `.env.example` expects a MySQL database `support_desk` on `127.0.0.1`, user `root`,
no password; change `DB_*` if yours differs. Tests use a separate database, `support_desk_testing`, or SQLite if
MySQL is not reachable.

## Sample accounts

`php artisan migrate --seed` creates a small support team, 14 customer businesses and 42 realistic tickets from the
last few weeks, in every state: new, in progress, waiting for the customer, at risk, breached and escalated,
resolved and closed. Each ticket has a real conversation and history. Every account's password is **`password`**.

| Role | Name | Email |
|---|---|---|
| Admin | Farhana Haque | `farhana.haque@supportdesk.test` |
| Agent | Tanvir Ahmed (billing, account) | `tanvir.ahmed@supportdesk.test` |
| Agent | Nusrat Jahan (technical, bugs) | `nusrat.jahan@supportdesk.test` |
| Agent | Arif Hossain (technical, features, general) | `arif.hossain@supportdesk.test` |
| Agent | Sadia Islam (billing, general) | `sadia.islam@supportdesk.test` |
| Agent | Rakib Hasan (on leave) | `rakib.hasan@supportdesk.test` |
| Customer | Mahmudul Karim, Greenleaf Traders | `mahmudul.karim@greenleaf-traders.test` |
| Customer | Emily Carter, Northwind Studio | `emily.carter@northwind-studio.test` |

The other customers are listed in `database/seeders/UserSeeder.php`. The seeder also creates categories, SLA
targets and business hours (Sunday–Thursday 09:00–18:00, Asia/Dhaka). Emails are written to
`storage/logs/laravel.log`, and every address uses the reserved `.test` domain, so nothing reaches a real inbox.
To start over: `php artisan migrate:fresh --seed`.

## Background work

Assignment, emails and the SLA scan run in the background. Locally:

```bash
php artisan queue:work --queue=notifications,default
php artisan queue:work imports-database --queue=imports --timeout=3600
php artisan schedule:work
```

or `composer run dev` to start the server, a queue worker, logs and Vite together. In production, add one cron
entry: `* * * * * cd /path/to/app && php artisan schedule:run`. The schedule is defined in `routes/console.php`.

## Tests

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## Admin pages

| Page | URL |
|---|---|
| SLA targets, business hours, holidays | `/admin/sla-settings` |
| CSV ticket import (sample file at `/admin/imports/sample`) | `/admin/imports` |
| Logs (application log files, opcodesio/log-viewer) | `/admin/log-viewer` |

Business settings (timezone, SLA defaults, attachment limits, assignment weights, import size) are in
`config/support.php`, and each can be overridden with a `SUPPORT_*` value in `.env`.
