<?php

namespace App\Actions\Tickets;

use App\Actions\Replies\StoreAttachments;
use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Jobs\AutoAssignTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use App\Services\TicketActivityRecorder;
use App\Services\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Opens a ticket: number, SLA snapshot, audit entry and event in one
 * transaction. If anything fails, the number is rolled back with the ticket.
 */
final class CreateTicket
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
        private readonly TicketActivityRecorder $activity,
        private readonly StoreAttachments $storeAttachments,
    ) {}

    /**
     * @param  array{subject: string, description: string, priority: string|TicketPriority, category_id: int|string, source?: TicketSource, external_reference?: string|null}  $data
     * @param  list<UploadedFile>  $files  ticket-level attachments (already validated)
     */
    public function __invoke(User $actor, array $data, array $files = []): Ticket
    {
        $stored = collect();

        try {
            return $this->create($actor, $data, $files, $stored);
        } catch (Throwable $e) {
            StoreAttachments::discard($stored); // the rows rolled back; remove the files too

            throw $e;
        }
    }

    private function create(User $actor, array $data, array $files, Collection &$stored): Ticket
    {
        return DB::transaction(function () use ($actor, $data, $files, &$stored): Ticket {
            $now = CarbonImmutable::now();
            $priority = $data['priority'] instanceof TicketPriority
                ? $data['priority']
                : TicketPriority::from($data['priority']);

            $ticket = Ticket::query()->create([
                'ticket_number' => $this->numbers->generate($now),
                'subject' => $data['subject'],
                'description' => $data['description'],
                'priority' => $priority,
                'status' => TicketStatus::Open,
                'category_id' => (int) $data['category_id'],
                'created_by' => $actor->getKey(),
                'source' => $data['source'] ?? TicketSource::Web,
                'external_reference' => $data['external_reference'] ?? null,
            ] + $this->sla->calculate($priority, $now)->toTicketAttributes());

            $this->activity->record($ticket, ActivityType::TicketCreated, $actor, "Ticket {$ticket->ticket_number} created", [], [
                'status' => TicketStatus::Open->value,
                'priority' => $priority->value,
                'category_id' => $ticket->category_id,
            ]);

            $stored = ($this->storeAttachments)($ticket, null, $actor, $files);

            // Both wait for the outermost commit: nothing fires for a rollback.
            TicketCreated::dispatch($ticket, $actor);

            if (config('support.assignment.auto_on_create')) {
                AutoAssignTicket::dispatch($ticket->getKey())->afterCommit();
            }

            return $ticket;
        });
    }
}
