<?php

namespace App\Modules\Dashboard\Services;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Staff dashboard numbers with as few queries as possible, cached for 30
 * seconds per scope (admins share one global entry; each agent has their own).
 *
 *  1. ONE conditional-aggregation query (SUM(CASE WHEN ... THEN 1 ELSE 0 END)) for
 *     all eight counters: one table pass, one consistent snapshot;
 *  2. one GROUP BY for active tickets per priority, covered by (priority, status);
 *  3. one JOIN users for active/breached per agent (top 10), on (assigned_agent_id, status);
 *  4. the last 10 escalations, (type, created_at) index, ticket and agent eager loaded.
 *
 * SLA conditions match SlaStatusResolver (breached: due_at < now; approaching:
 * sla_warning_at <= now <= due_at).
 */
final class DashboardStatsService
{
    public const CACHE_SECONDS = 30;

    public function for(User $user): DashboardStats
    {
        return Cache::remember(self::cacheKey($user), self::CACHE_SECONDS, fn (): DashboardStats => $this->build($user));
    }

    public static function cacheKey(User $user): string
    {
        return $user->isAdmin() ? 'dashboard:admin' : "dashboard:agent:{$user->id}";
    }

    private function build(User $user): DashboardStats
    {
        $now = CarbonImmutable::now();
        $counters = $this->counters($user, $now);

        return new DashboardStats(
            scope: $user->isAdmin() ? 'global' : 'agent',
            totalActive: (int) $counters->total_active,
            unassignedActive: (int) $counters->unassigned_active,
            criticalActive: (int) $counters->critical_active,
            slaBreachedActive: (int) $counters->sla_breached_active,
            approachingActive: (int) $counters->approaching_active,
            resolvedTotal: (int) $counters->resolved_total,
            resolvedToday: (int) $counters->resolved_today,
            escalatedActive: (int) $counters->escalated_active,
            byPriority: $this->byPriority($user),
            byAgent: $this->byAgent($user, $now),
            recentEscalations: $this->recentEscalations($user),
            generatedAt: $now->toIso8601String(),
        );
    }

    /**
     * Two aggregate queries instead of one pass over the whole table (measured
     * on 100k tickets, docs/PERFORMANCE.md): the cost follows the open backlog,
     * not the history.
     *  - active counters: WHERE status IN (active), answered from
     *    tickets_dashboard_cover_index alone (status, priority, assignee,
     *    escalated, due_at, sla_warning_at);
     *  - finished counters: WHERE status IN (resolved, closed), answered from
     *    (status, resolved_at).
     */
    private function counters(User $user, CarbonImmutable $now): object
    {
        $todayStart = $now->setTimezone(BusinessTime::zone())->startOfDay()->utc();

        $active = $this->aggregate($this->scoped($user)->whereIn('tickets.status', TicketStatus::activeValues()), [
            'total_active' => ['1 = 1', []],
            'unassigned_active' => ['tickets.assigned_agent_id IS NULL', []],
            'critical_active' => ['tickets.priority = ?', [TicketPriority::Critical->value]],
            'sla_breached_active' => ['tickets.due_at < ?', [$now]],
            'approaching_active' => ['tickets.sla_warning_at <= ? AND tickets.due_at >= ?', [$now, $now]],
            'escalated_active' => ['tickets.is_escalated = ?', [true]],
        ]);

        $finished = $this->aggregate($this->scoped($user)->whereIn('tickets.status', TicketStatus::finishedValues()), [
            'resolved_total' => ['1 = 1', []],
            'resolved_today' => ['tickets.resolved_at >= ?', [$todayStart]],
        ]);

        return (object) array_merge((array) $active, (array) $finished);
    }

    /**
     * One row of conditional counts: COALESCE(SUM(CASE WHEN cond THEN 1 ELSE 0 END), 0).
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, array{0: string, 1: list<mixed>}>  $columns  alias => [SQL condition, bindings]
     */
    private function aggregate(Builder $query, array $columns): object
    {
        $select = collect($columns)
            ->map(fn (array $column, string $alias) => "COALESCE(SUM(CASE WHEN {$column[0]} THEN 1 ELSE 0 END), 0) AS {$alias}")
            ->implode(', ');

        return $query->toBase()->selectRaw($select, collect($columns)->flatMap(fn (array $c) => $c[1])->all())->first();
    }

    /** @return array<string, int> */
    private function byPriority(User $user): array
    {
        $counts = $this->scoped($user)
            ->active()
            ->toBase()
            ->selectRaw('tickets.priority, COUNT(*) AS aggregate')
            ->groupBy('tickets.priority')
            ->pluck('aggregate', 'priority');

        return collect(TicketPriority::cases())
            ->sortByDesc(fn (TicketPriority $p) => $p->sortWeight())
            ->mapWithKeys(fn (TicketPriority $p) => [$p->value => (int) ($counts[$p->value] ?? 0)])
            ->all();
    }

    /** @return list<array{id: int, name: string, active: int, breached: int}> */
    private function byAgent(User $user, CarbonImmutable $now): array
    {
        return $this->scoped($user)
            ->toBase()
            ->join('users', 'users.id', '=', 'tickets.assigned_agent_id')
            ->whereIn('tickets.status', TicketStatus::activeValues())
            ->select(['users.id', 'users.name'])
            ->selectRaw('COUNT(*) AS active_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN tickets.due_at < ? THEN 1 ELSE 0 END), 0) AS breached_count', [$now])
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('active_count')
            ->orderBy('users.name')
            ->limit(10)
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'active' => (int) $row->active_count,
                'breached' => (int) $row->breached_count,
            ])
            ->all();
    }

    /** @return list<array{ticket_number: string, subject: string, agent: ?string, description: string, at: string}> */
    private function recentEscalations(User $user): array
    {
        return TicketActivity::query()
            ->where('type', ActivityType::Escalated)
            ->when(! $user->isAdmin(), fn (Builder $query) => $query->whereHas('ticket', fn (Builder $ticket) => $this->restrictToAgent($ticket, $user)))
            ->with(['ticket:id,ticket_number,subject,assigned_agent_id', 'ticket.assignee:id,name'])
            ->latest()
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (TicketActivity $activity) => [
                'ticket_number' => $activity->ticket->ticket_number,
                'subject' => $activity->ticket->subject,
                'agent' => $activity->ticket->assignee?->name,
                'description' => $activity->description,
                'at' => $activity->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Admins: every ticket. Agents: their own plus the unassigned queue.
     *
     * @return Builder<Ticket>
     */
    private function scoped(User $user): Builder
    {
        $query = Ticket::query();

        if (! $user->isAdmin()) {
            $this->restrictToAgent($query, $user);
        }

        return $query;
    }

    private function restrictToAgent(Builder $query, User $agent): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('tickets.assigned_agent_id')
            ->orWhere('tickets.assigned_agent_id', $agent->getKey()));
    }
}
