<?php

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Default strategy. Among the candidate pool, pick the agent with the lowest
 * weighted open workload, then the one assigned least recently (never
 * assigned first), then the lowest id.
 *
 * Workload = SUM(weight by priority) of the agent's active tickets, with the
 * weights from config('support.assignment.workload_weights'). Computed in ONE
 * query as a correlated subquery (served by tickets_assigned_agent_id_status_index),
 * never a query per agent.
 */
final class LeastWorkloadStrategy implements AssignmentStrategy
{
    public function __construct(private readonly CandidateAgents $pool) {}

    public function pick(Ticket $ticket): ?User
    {
        $ids = $this->pool->lock($this->pool->idsFor($ticket));

        if ($ids === []) {
            return null;
        }

        return User::query()
            ->whereKey($ids)
            ->select('users.*')
            ->selectSub($this->workloadQuery(), 'workload')
            ->orderBy('workload')
            ->orderByRaw('last_assigned_at IS NOT NULL') // NULLS FIRST, portable
            ->orderBy('last_assigned_at')
            ->orderBy('id')
            ->first();
    }

    private function workloadQuery(): Builder
    {
        $weights = config('support.assignment.workload_weights', []);
        $cases = str_repeat(' WHEN ? THEN ?', count($weights));
        $bindings = collect($weights)->flatMap(fn (int $weight, string $priority) => [$priority, $weight])->all();

        return DB::table('tickets')
            ->selectRaw("COALESCE(SUM(CASE tickets.priority{$cases} ELSE 1 END), 0)", $bindings)
            ->whereColumn('tickets.assigned_agent_id', 'users.id')
            ->whereIn('tickets.status', TicketStatus::activeValues());
    }
}
