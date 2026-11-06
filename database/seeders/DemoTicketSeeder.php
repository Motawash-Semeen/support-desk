<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\ReplyType;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketReply;
use App\Models\TicketSequence;
use App\Models\User;
use Database\Factories\TicketFactory;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ~200 demo tickets spread across every status and SLA state, with a short
 * reply/activity history each, for the dashboard demo. Requires the
 * reference seeders and UserSeeder. Skips if tickets already exist.
 */
class DemoTicketSeeder extends Seeder
{
    private const INSERT_CHUNK = 500;

    public function run(): void
    {
        if (Ticket::query()->exists()) {
            $this->command?->warn('Tickets already exist; demo tickets skipped.');

            return;
        }

        $customers = User::query()->role(Role::Customer)->pluck('id');
        $agents = User::query()->agents()->pluck('id');
        $categories = Category::query()->pluck('id');
        $policies = SlaPolicy::query()->get()->keyBy(fn (SlaPolicy $policy) => $policy->priority->value);

        DB::transaction(function () use ($customers, $agents, $categories, $policies): void {
            $base = fn (): TicketFactory => Ticket::factory()->state(fn () => [
                'created_by' => $customers->random(),
                'category_id' => $categories->random(),
                'sla_policy_id' => fn (array $a) => $policies[$a['priority']->value]->id,
                'sla_minutes' => fn (array $a) => $policies[$a['priority']->value]->resolution_minutes,
            ]);
            $agent = fn () => ['assigned_agent_id' => $agents->random()];

            $base()->onTrack()->count(35)->create();                                   // open, unassigned
            $base()->breached()->count(5)->create();                                   // open, unassigned, overdue
            $base()->onTrack()->state($agent)->assigned()->count(20)->create();        // open, assigned
            $base()->onTrack()->state($agent)->inProgress()->count(21)->create();
            $base()->approaching()->state($agent)->inProgress()->count(10)->create();
            $base()->breached()->state($agent)->inProgress()->count(10)->create();
            $base()->breached()->escalated()->state($agent)->inProgress()->count(4)->create();
            $base()->onTrack()->state($agent)->waitingForCustomer()->count(20)->create();
            $base()->state($agent)->resolved()->count(45)->create();
            $base()->state($agent)->closed()->count(30)->create();

            $tickets = Ticket::query()
                ->select(['id', 'ticket_number', 'status', 'created_by', 'assigned_agent_id', 'created_at',
                    'assigned_at', 'first_response_at', 'resolved_at', 'closed_at'])
                ->orderBy('id')
                ->get();

            $this->insertHistory($tickets);
            $this->syncTicketSequences($tickets);
            $this->syncAgentLastAssigned($tickets);
        });
    }

    /** Bulk-insert replies and activities (one INSERT per chunk, not per row). */
    private function insertHistory(Collection $tickets): void
    {
        $replies = [];
        $activities = [];

        foreach ($tickets as $ticket) {
            $activities[] = $this->activity($ticket, ActivityType::Created, $ticket->created_by, 'Ticket created', $ticket->created_at);

            if ($ticket->assigned_agent_id !== null) {
                $activities[] = $this->activity($ticket, ActivityType::Assigned, null, 'Ticket auto-assigned', $ticket->assigned_at,
                    null, ['assigned_agent_id' => $ticket->assigned_agent_id]);
            }

            if ($ticket->first_response_at !== null) {
                $replies[] = $this->reply($ticket, $ticket->assigned_agent_id, ReplyType::Public, $ticket->first_response_at);

                if (fake()->boolean(60)) {
                    $replies[] = $this->reply($ticket, $ticket->created_by, ReplyType::Public, $ticket->first_response_at->addMinutes(fake()->numberBetween(10, 240)));
                }

                if (fake()->boolean(35)) {
                    $replies[] = $this->reply($ticket, $ticket->assigned_agent_id, ReplyType::InternalNote, $ticket->first_response_at->addMinutes(5));
                }
            }

            if ($ticket->status !== TicketStatus::Open) {
                $activities[] = $this->activity($ticket, ActivityType::StatusChanged, $ticket->assigned_agent_id,
                    "Status changed to {$ticket->status->label()}",
                    $ticket->closed_at ?? $ticket->resolved_at ?? $ticket->first_response_at ?? $ticket->created_at,
                    ['status' => TicketStatus::Open->value], ['status' => $ticket->status->value]);
            }
        }

        foreach (array_chunk($replies, self::INSERT_CHUNK) as $chunk) {
            TicketReply::query()->insert($chunk);
        }

        foreach (array_chunk($activities, self::INSERT_CHUNK) as $chunk) {
            TicketActivity::query()->insert($chunk);
        }
    }

    /** Keep the per-year counter ahead of the seeded numbers so new tickets never collide. */
    private function syncTicketSequences(Collection $tickets): void
    {
        $rows = $tickets
            ->map(fn (Ticket $ticket) => preg_match('/-(\d{4})-(\d+)$/', $ticket->ticket_number, $m) ? [(int) $m[1], (int) $m[2]] : null)
            ->filter()
            ->groupBy(0)
            ->map(fn (Collection $numbers, int $year) => ['year' => $year, 'last_number' => $numbers->max(1)])
            ->values()
            ->all();

        TicketSequence::query()->upsert($rows, ['year'], ['last_number']);
    }

    private function syncAgentLastAssigned(Collection $tickets): void
    {
        $tickets->whereNotNull('assigned_agent_id')
            ->groupBy('assigned_agent_id')
            ->each(fn (Collection $assigned, int $agentId) => User::query()
                ->whereKey($agentId)
                ->update(['last_assigned_at' => $assigned->max('assigned_at')]));
    }

    private function reply(Ticket $ticket, int $userId, ReplyType $type, DateTimeInterface $at): array
    {
        $at = min($at, now())->format('Y-m-d H:i:s');

        return [
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'type' => $type->value,
            'body' => fake()->paragraphs(fake()->numberBetween(1, 3), true),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    private function activity(
        Ticket $ticket,
        ActivityType $type,
        ?int $actorId,
        string $description,
        DateTimeInterface $at,
        ?array $old = null,
        ?array $new = null,
    ): array {
        return [
            'ticket_id' => $ticket->id,
            'actor_id' => $actorId,
            'type' => $type->value,
            'description' => $description,
            'old_values' => $old === null ? null : json_encode($old),
            'new_values' => $new === null ? null : json_encode($new),
            'created_at' => $at->format('Y-m-d H:i:s'),
        ];
    }
}
