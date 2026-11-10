<?php

namespace App\Modules\Assignment;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Notifications\TicketNotifier;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Who works on a ticket: accept, assign, release and automatic assignment.
 *
 * Each write is one guarded UPDATE whose WHERE repeats what the caller expects
 * ("still unassigned", "still the agent I saw"). If another request got there
 * first, 0 rows change, nothing is overwritten, and the loser gets a 409.
 */
class AssignmentService
{
    public function __construct(private readonly TicketNotifier $notifier) {}

    /**
     * Takes an unassigned ticket for $agent; false when someone else got it first.
     * $actor is null for auto-assignment.
     *
     *   UPDATE tickets SET assigned_agent_id = ? ... WHERE id = ? AND assigned_agent_id IS NULL AND status IN (active)
     */
    public function claim(Ticket $ticket, User $agent, ?User $actor = null): bool
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Only agents can be assigned tickets.');
        }

        return DB::transaction(function () use ($ticket, $agent, $actor): bool {
            $now = CarbonImmutable::now();
            $statusBefore = $this->currentStatus($ticket);

            $affected = Ticket::query()
                ->whereKey($ticket->getKey())
                ->whereNull('assigned_agent_id')
                ->whereIn('status', TicketStatus::activeValues())
                ->update([
                    'assigned_agent_id' => $agent->getKey(),
                    'assigned_at' => $now,
                    'status' => $this->openBecomesInProgress(),
                ]);

            if ($affected === 0) {
                return false;
            }

            $ticket->refresh();
            $this->touchAgent($agent, $now);

            TicketActivity::record(
                $ticket,
                ActivityType::Assigned,
                $actor,
                match (true) {
                    $actor === null => "Auto-assigned to {$agent->name}",
                    $actor->is($agent) => "Accepted by {$agent->name}",
                    default => "Assigned to {$agent->name}",
                },
                ['assigned_agent_id' => null, 'status' => $statusBefore],
                ['assigned_agent_id' => $agent->getKey(), 'status' => $ticket->status->value],
            );

            $this->notifier->assigned($ticket, $agent, $actor);

            return true;
        });
    }

    /** An agent takes a queue ticket. A repeat click by the winner is fine; losing to another agent is a 409. */
    public function accept(User $agent, Ticket $ticket): void
    {
        if ($this->claim($ticket, $agent, $agent)) {
            return;
        }

        if (Ticket::query()->whereKey($ticket->getKey())->value('assigned_agent_id') !== $agent->getKey()) {
            $this->conflict(__('This ticket was just taken by another agent.'), $ticket);
        }
    }

    /**
     * Admin (re)assignment, guarded by the assignee the admin saw ($expectedAgentId,
     * null = unassigned). A change in the meantime is a 409, not an overwrite.
     */
    public function assign(User $actor, Ticket $ticket, User $agent, ?int $expectedAgentId): Ticket
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Tickets can only be assigned to agents.');
        }

        if ($expectedAgentId === $agent->getKey()) {
            return $ticket; // the admin already sees this agent: nothing to do
        }

        return DB::transaction(function () use ($actor, $ticket, $agent, $expectedAgentId): Ticket {
            $now = CarbonImmutable::now();
            $statusBefore = $this->currentStatus($ticket);

            $affected = Ticket::query()
                ->whereKey($ticket->getKey())
                ->when(
                    $expectedAgentId === null,
                    fn ($query) => $query->whereNull('assigned_agent_id'),
                    fn ($query) => $query->where('assigned_agent_id', $expectedAgentId),
                )
                ->whereIn('status', TicketStatus::activeValues())
                ->update([
                    'assigned_agent_id' => $agent->getKey(),
                    'assigned_at' => $now,
                    'status' => $this->openBecomesInProgress(),
                ]);

            if ($affected === 0) {
                $this->conflict(__('The assignment of this ticket changed while you were looking at it. Reload and try again.'), $ticket);
            }

            $ticket->refresh();
            $this->touchAgent($agent, $now);

            if ($expectedAgentId === null) {
                $type = ActivityType::Assigned;
                $description = "Assigned to {$agent->name} by {$actor->name}";
            } else {
                $type = ActivityType::Reassigned;
                $previousName = User::query()->whereKey($expectedAgentId)->value('name') ?? "#{$expectedAgentId}";
                $description = "Reassigned from {$previousName} to {$agent->name} by {$actor->name}";
            }

            TicketActivity::record(
                $ticket,
                $type,
                $actor,
                $description,
                ['assigned_agent_id' => $expectedAgentId, 'status' => $statusBefore],
                ['assigned_agent_id' => $agent->getKey(), 'status' => $ticket->status->value],
            );

            $this->notifier->assigned($ticket, $agent, $actor);

            return $ticket;
        });
    }

    /**
     * Back to the unassigned queue (status Open). Guarded by the expected assignee,
     * so it never undoes a newer assignment.
     */
    public function release(User $actor, Ticket $ticket): Ticket
    {
        $expected = $actor->isAdmin() ? $ticket->assigned_agent_id : $actor->getKey();

        if ($expected === null) {
            $this->conflict(__('This ticket is no longer assigned to that agent.'), $ticket);
        }

        return DB::transaction(function () use ($actor, $ticket, $expected): Ticket {
            $statusBefore = $this->currentStatus($ticket);

            $affected = Ticket::query()
                ->whereKey($ticket->getKey())
                ->where('assigned_agent_id', $expected)
                ->whereIn('status', TicketStatus::activeValues())
                ->update(['assigned_agent_id' => null, 'assigned_at' => null, 'status' => TicketStatus::Open->value]);

            if ($affected === 0) {
                $this->conflict(__('This ticket is no longer assigned to that agent.'), $ticket);
            }

            $ticket->refresh();

            TicketActivity::record(
                $ticket,
                ActivityType::Released,
                $actor,
                "Released to the unassigned queue by {$actor->name}",
                ['assigned_agent_id' => $expected, 'status' => $statusBefore],
                ['assigned_agent_id' => null, 'status' => TicketStatus::Open->value],
            );

            return $ticket;
        });
    }

    /**
     * Run by the AutoAssignTicket job: lock the candidates, pick one, claim().
     * If an agent accepted the ticket meanwhile, claim() fails, which is fine.
     */
    public function autoAssign(int $ticketId): void
    {
        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null || $ticket->assigned_agent_id !== null || ! $ticket->isActive()) {
            return;
        }

        DB::transaction(function () use ($ticket): void {
            $agent = $this->pickAgent($ticket);

            if ($agent !== null) {
                $this->claim($ticket, $agent, null);

                return;
            }

            // One "skipped" entry per unassigned stretch, not one every five minutes.
            if ($ticket->activities()->latest()->latest('id')->value('type') !== ActivityType::AutoAssignSkipped) {
                TicketActivity::record($ticket, ActivityType::AutoAssignSkipped, null, 'No available agent; the ticket stays in the unassigned queue and is retried every 5 minutes');
            }

            Log::info('Auto-assignment found no available agent.', ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number]);
        });
    }

    /**
     * The agent a ticket goes to, or null. Call it inside a transaction.
     *
     * Candidates: available agents of the ticket's category or with no categories,
     * else every available agent; their rows are locked in id order. The pick:
     * lowest weighted workload, then longest since last assignment, then lowest id.
     */
    public function pickAgent(Ticket $ticket): ?User
    {
        $candidates = User::query()
            ->availableAgents()
            ->where(fn (Builder $query) => $query
                ->whereHas('categories', fn (Builder $categories) => $categories->whereKey($ticket->category_id))
                ->orWhereDoesntHave('categories'))
            ->pluck('id')
            ->all();

        if ($candidates === []) {
            $candidates = User::query()->availableAgents()->pluck('id')->all();
        }

        if ($candidates === []) {
            return null;
        }

        // Lock and re-check availability under the lock.
        $locked = User::query()->whereKey($candidates)->availableAgents()->orderBy('id')->lockForUpdate()->pluck('id')->all();

        if ($locked === []) {
            return null;
        }

        $weights = config('support.assignment.workload_weights', []);
        $cases = str_repeat(' WHEN ? THEN ?', count($weights));
        $bindings = collect($weights)->flatMap(fn (int $weight, string $priority) => [$priority, $weight])->all();

        $workload = DB::table('tickets')
            ->selectRaw("COALESCE(SUM(CASE tickets.priority{$cases} ELSE 1 END), 0)", $bindings)
            ->whereColumn('tickets.assigned_agent_id', 'users.id')
            ->whereIn('tickets.status', TicketStatus::activeValues());

        return User::query()
            ->whereKey($locked)
            ->select('users.*')
            ->selectSub($workload, 'workload')
            ->orderBy('workload')
            ->orderByRaw('last_assigned_at IS NOT NULL') // never assigned first (portable NULLS FIRST)
            ->orderBy('last_assigned_at')
            ->orderBy('id')
            ->first();
    }

    /** tickets:assign-unassigned (every 5 minutes): queues auto-assignment, oldest due first. */
    public function queueUnassigned(int $limit): int
    {
        $ids = Ticket::query()->active()->unassigned()->orderBy('due_at')->orderBy('id')->limit(max(1, $limit))->pluck('id');

        $ids->each(fn (int $id) => AutoAssignTicket::dispatch($id));

        return $ids->count();
    }

    /** Turns auto-assignment on or off for an agent; their current tickets stay with them. */
    public function setAvailability(User $agent, bool $available): User
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Only agents have an availability setting.');
        }

        $agent->forceFill(['is_available' => $available])->save(); // not mass assignable by design

        Log::info('Agent availability changed.', ['agent_id' => $agent->id, 'is_available' => $available]);

        return $agent;
    }

    /** Open becomes in_progress inside the same UPDATE; other statuses stay. */
    private function openBecomesInProgress(): Expression
    {
        return DB::raw(sprintf("CASE WHEN status = '%s' THEN '%s' ELSE status END", TicketStatus::Open->value, TicketStatus::InProgress->value));
    }

    private function currentStatus(Ticket $ticket): ?string
    {
        $status = Ticket::query()->whereKey($ticket->getKey())->value('status');

        return $status instanceof TicketStatus ? $status->value : $status;
    }

    private function touchAgent(User $agent, CarbonImmutable $at): void
    {
        User::query()->whereKey($agent->getKey())->update(['last_assigned_at' => $at]);
    }

    /** A lost race: logged as info (it is expected) and answered with a 409 page. */
    private function conflict(string $message, Ticket $ticket): never
    {
        Log::info('Assignment conflict.', ['ticket_number' => $ticket->ticket_number, 'message' => $message, 'user_id' => auth()->id()]);

        abort(409, $message);
    }
}
