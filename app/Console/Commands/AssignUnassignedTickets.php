<?php

namespace App\Console\Commands;

use App\Jobs\AutoAssignTicket;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes: re-queues auto-assignment for unassigned
 * active tickets, oldest due date first (served by tickets_status_due_at_index).
 * AutoAssignTicket is unique per ticket, so overlapping runs cannot double up.
 */
class AssignUnassignedTickets extends Command
{
    protected $signature = 'tickets:assign-unassigned {--limit= : Maximum tickets to queue (default: support.assignment.retry_batch_size)}';

    protected $description = 'Queue auto-assignment for unassigned active tickets, oldest due first';

    public function handle(): int
    {
        $limit = max(1, (int) ($this->option('limit') ?? config('support.assignment.retry_batch_size')));

        $ids = Ticket::query()
            ->active()
            ->unassigned()
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $ids->each(fn (int $id) => AutoAssignTicket::dispatch($id));

        $this->components->info("Queued auto-assignment for {$ids->count()} unassigned ticket(s).");

        return self::SUCCESS;
    }
}
