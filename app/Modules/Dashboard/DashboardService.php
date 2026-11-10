<?php

namespace App\Modules\Dashboard;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * Staff dashboard numbers in a few aggregate queries, cached for 30 seconds
 * (one entry for admins, one per agent). Agents see their own tickets plus
 * the unassigned queue. SLA conditions match SlaStatus::resolve().
 */
class DashboardService
{
    public const CACHE_SECONDS = 30;

    public function for(User $user): array
    {
        return Cache::remember(self::cacheKey($user), self::CACHE_SECONDS, fn (): array => $this->build($user));
    }

    public static function cacheKey(User $user): string
    {
        return $user->isAdmin() ? 'dashboard:admin' : "dashboard:agent:{$user->id}";
    }

    private function build(User $user): array
    {
        $now = CarbonImmutable::now();
        $counters = $this->counters($user, $now);

        return [
            'scope' => $user->isAdmin() ? 'global' : 'agent',
            'totalActive' => (int) $counters->total_active,
            'unassignedActive' => (int) $counters->unassigned_active,
            'criticalActive' => (int) $counters->critical_active,
            'slaBreachedActive' => (int) $counters->sla_breached_active,
            'approachingActive' => (int) $counters->approaching_active,
            'resolvedTotal' => (int) $counters->resolved_total,
            'resolvedToday' => (int) $counters->resolved_today,
            'escalatedActive' => (int) $counters->escalated_active,
            'byPriority' => $this->byPriority($user),
            'byAgent' => $this->byAgent($user, $now),
            'recentEscalations' => $this->recentEscalations($user),
            'generatedAt' => $now->toIso8601String(),
        ];
    }

    /** Active and finished counters, one query each, both answered from indexes. */
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

    /** One row of conditional counts: COALESCE(SUM(CASE WHEN cond THEN 1 ELSE 0 END), 0). */
    private function aggregate(Builder $query, array $columns): object
    {
        $select = collect($columns)
            ->map(fn (array $column, string $alias) => "COALESCE(SUM(CASE WHEN {$column[0]} THEN 1 ELSE 0 END), 0) AS {$alias}")
            ->implode(', ');

        return $query->toBase()->selectRaw($select, collect($columns)->flatMap(fn (array $c) => $c[1])->all())->first();
    }

    private function byPriority(User $user): array
    {
        $counts = $this->scoped($user)
            ->whereIn('tickets.status', TicketStatus::activeValues())
            ->toBase()
            ->selectRaw('tickets.priority, COUNT(*) AS aggregate')
            ->groupBy('tickets.priority')
            ->pluck('aggregate', 'priority');

        return collect(TicketPriority::cases())
            ->sortByDesc(fn (TicketPriority $p) => $p->sortWeight())
            ->mapWithKeys(fn (TicketPriority $p) => [$p->value => (int) ($counts[$p->value] ?? 0)])
            ->all();
    }

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

    private function recentEscalations(User $user): array
    {
        return TicketActivity::query()
            ->toBase()
            ->join('tickets', 'tickets.id', '=', 'ticket_activities.ticket_id')
            ->leftJoin('users', 'users.id', '=', 'tickets.assigned_agent_id')
            ->where('ticket_activities.type', ActivityType::Escalated->value)
            ->when(! $user->isAdmin(), fn ($query) => $this->restrictToAgent($query, $user))
            ->orderByDesc('ticket_activities.created_at')
            ->orderByDesc('ticket_activities.id')
            ->limit(10)
            ->get(['tickets.ticket_number', 'tickets.subject', 'users.name as agent', 'ticket_activities.description', 'ticket_activities.created_at'])
            ->map(fn (object $row) => [
                'ticket_number' => $row->ticket_number,
                'subject' => $row->subject,
                'agent' => $row->agent,
                'description' => $row->description,
                'at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
            ])
            ->all();
    }

    /** Admins: every ticket. Agents: their own plus the unassigned queue. */
    private function scoped(User $user): Builder
    {
        $query = Ticket::query();

        if (! $user->isAdmin()) {
            $this->restrictToAgent($query, $user);
        }

        return $query;
    }

    private function restrictToAgent(Builder|QueryBuilder $query, User $agent): void
    {
        $query->where(fn ($q) => $q
            ->whereNull('tickets.assigned_agent_id')
            ->orWhere('tickets.assigned_agent_id', $agent->getKey()));
    }
}
