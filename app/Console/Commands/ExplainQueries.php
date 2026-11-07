<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Measures the real queries behind each page and job on the current database
 * (meant for the bulk PerformanceSeeder data, see docs/PERFORMANCE.md).
 *
 * Each scenario is executed through the real HTTP kernel or Artisan command,
 * every SELECT it issues is captured, and then analysed: ANALYZE on MariaDB
 * (estimated AND actual rows), EXPLAIN on MySQL (estimated rows). Output is a
 * Markdown table per scenario.
 */
class ExplainQueries extends Command
{
    protected $signature = 'perf:explain {--only= : run only scenarios whose name contains this text}';

    protected $description = 'EXPLAIN every query behind the main pages and jobs (development only)';

    /** @var Collection<int, QueryExecuted>|null queries of the running scenario; null = not recording */
    private ?Collection $recorded = null;

    /** Framework bookkeeping, not application queries. */
    private const IGNORED_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'notifications'];

    public function handle(HttpKernel $kernel): int
    {
        if (app()->isProduction()) {
            $this->error('perf:explain runs analysed queries; refusing in production.');

            return self::FAILURE;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('perf:explain needs MySQL/MariaDB.');

            return self::FAILURE;
        }

        // Compute, never serve from cache; discard queued jobs.
        config(['cache.default' => 'array', 'queue.default' => 'null', 'session.driver' => 'array']);

        $admin = User::query()->where('role', Role::Admin->value)->firstOrFail();
        $agent = User::query()->where('role', Role::Agent->value)->orderBy('id')->firstOrFail();
        $customer = User::query()->where('role', Role::Customer->value)
            ->withCount('createdTickets')->orderByDesc('created_tickets_count')->firstOrFail();
        $busyTicket = Ticket::query()->withCount('activities')->orderByDesc('activities_count')->firstOrFail();
        $ownTicket = Ticket::query()->where('created_by', $customer->id)->latest('id')->firstOrFail();
        $category = Category::query()->firstOrFail();
        DB::listen(function (QueryExecuted $query): void {
            $this->recorded?->push($query);
        });

        $mariadb = str_contains(strtolower((string) DB::selectOne('select version() as v')->v), 'mariadb');

        $scenarios = [
            'Ticket list, admin default (active, due soonest)' => [$admin, '/tickets'],
            'Ticket list, agent default' => [$agent, '/tickets'],
            "Customer 'my tickets'" => [$customer, '/tickets'],
            'Filter: status = open' => [$admin, '/tickets?status[]=open'],
            'Filter: priority = critical' => [$admin, '/tickets?priority[]=critical'],
            'Filter: category' => [$admin, "/tickets?category_id={$category->id}"],
            'Filter: tickets by agent' => [$admin, "/tickets?assigned_agent_id={$agent->id}"],
            'Filter: unassigned' => [$admin, '/tickets?assigned_agent_id=unassigned'],
            'Filter: customer' => [$admin, "/tickets?customer={$customer->id}"],
            'Filter: created date range' => [$admin, '/tickets?created_from='.now()->subDays(30)->toDateString().'&created_to='.now()->toDateString().'&status[]=open&status[]=in_progress&status[]=resolved&status[]=closed'],
            'Filter: SLA breached' => [$admin, '/tickets?sla_status=breached'],
            'Filter: SLA approaching' => [$admin, '/tickets?sla_status=approaching'],
            'Filter: escalated' => [$admin, '/tickets?escalated=1'],
            'Filter: closed scope, newest first' => [$admin, '/tickets?scope=closed'],
            'Sort: priority' => [$admin, '/tickets?sort=priority&direction=desc'],
            'Sort: last updated' => [$admin, '/tickets?sort=updated_at&direction=desc'],
            'Keyword search (FULLTEXT)' => [$admin, '/tickets?q=printer+refund'],
            'Keyword = ticket number' => [$admin, '/tickets?q='.$busyTicket->ticket_number],
            'Unassigned queue (staff)' => [$agent, '/staff/unassigned'],
            'Dashboard, admin' => [$admin, '/staff/dashboard'],
            'Dashboard, agent' => [$agent, '/staff/dashboard'],
            'Ticket page + activity timeline, staff' => [$admin, '/tickets/'.$busyTicket->ticket_number],
            'Ticket page + activity timeline, customer' => [$customer, '/tickets/'.$ownTicket->ticket_number],
            'Customer autocomplete' => [$admin, '/staff/customers/search?q=Customer+12'],
            'sla:scan (warning, breach, level-2 passes)' => [null, 'sla:scan'],
        ];

        foreach ($scenarios as $name => [$user, $target]) {
            if ($this->option('only') && ! str_contains(strtolower($name), strtolower((string) $this->option('only')))) {
                continue;
            }

            $queries = $this->capture(function () use ($kernel, $user, $target): string {
                if ($user === null) {
                    Artisan::call($target);

                    return 'ok';
                }

                Auth::guard('web')->setUser($user);
                $response = $kernel->handle(Request::create($target, 'GET'));

                return (string) $response->getStatusCode();
            }, $status);

            $this->line('');
            $this->line("### {$name}");
            $this->line('');
            $this->line('`'.($user ? "GET {$target} as {$user->role->value}" : "php artisan {$target}")."` → {$status}, ".count($queries).' application queries');
            $this->line('');
            $this->line($mariadb
                ? '| # | Query | ms | Table | Access | Index used | Rows (est.) | Rows (actual) | Extra |'
                : '| # | Query | ms | Table | Access | Index used | Rows (est.) | Extra |');
            $this->line($mariadb ? '|---|---|---:|---|---|---|---:|---:|---|' : '|---|---|---:|---|---|---|---:|---|');

            foreach ($queries as $i => $query) {
                foreach ($this->analyse($query, $mariadb) as $step => $row) {
                    // ms: time of the query during the real page request (first plan row only).
                    $cells = [$i + 1, '`'.$this->shorten($query->sql).'`', $step === 0 ? round($query->time, 1) : '', $row->table ?? '', $row->type ?? '', $row->key ?? '—', $row->rows ?? ''];
                    if ($mariadb) {
                        $cells[] = isset($row->r_rows) ? (int) round((float) $row->r_rows) : '';
                    }
                    $cells[] = str_replace('|', '/', (string) ($row->Extra ?? ''));
                    $this->line('| '.implode(' | ', $cells).' |');
                }
            }
        }

        Auth::guard('web')->forgetUser();

        return self::SUCCESS;
    }

    /** @return list<QueryExecuted> */
    private function capture(callable $run, ?string &$status): array
    {
        $this->recorded = collect();
        $status = $run();
        $captured = $this->recorded;
        $this->recorded = null;

        return $captured->filter(function (QueryExecuted $q): bool {
            $sql = strtolower(ltrim($q->sql));
            if (! str_starts_with($sql, 'select')) {
                return false;
            }

            foreach (self::IGNORED_TABLES as $table) {
                if (preg_match('/from [`"]?'.$table.'[`"]?(\s|$)/', $sql)) {
                    return false;
                }
            }

            return true;
        })->values()->all();
    }

    /** @return list<object> */
    private function analyse(QueryExecuted $query, bool $mariadb): array
    {
        return DB::select(($mariadb ? 'ANALYZE ' : 'EXPLAIN ').$query->sql, $query->bindings);
    }

    private function shorten(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', str_replace('`', '', $sql));

        return mb_strlen($sql) > 110 ? mb_substr($sql, 0, 107).'...' : $sql;
    }
}
