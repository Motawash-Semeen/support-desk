<?php

namespace App\Console\Commands;

use App\Actions\Tickets\CloseTicket;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidTicketTransition;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Daily: closes tickets that have stayed resolved for N days. Goes through
 * CloseTicket (status rules, row lock, activity with a system actor, events),
 * one ticket at a time; a ticket reopened in the meantime fails the
 * transition check and is skipped. Served by tickets(status, resolved_at).
 */
class AutoCloseResolvedTickets extends Command
{
    protected $signature = 'tickets:auto-close-resolved {--days= : Days a ticket must stay resolved (default: support.auto_close_resolved_after_days)}';

    protected $description = 'Close tickets that have been resolved for a number of days';

    public function handle(CloseTicket $closeTicket): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('support.auto_close_resolved_after_days')));
        $closed = 0;

        Ticket::query()
            ->where('status', TicketStatus::Resolved)
            ->where('resolved_at', '<=', now()->subDays($days))
            ->select(['id'])
            ->chunkById(200, function ($tickets) use ($closeTicket, $days, &$closed): void {
                foreach ($tickets as $ticket) {
                    try {
                        $closeTicket(null, $ticket, "Closed automatically after {$days} days resolved");
                        $closed++;
                    } catch (InvalidTicketTransition) {
                        // Reopened or closed meanwhile: nothing to do.
                    }
                }
            });

        $this->components->info("Closed {$closed} ticket(s) resolved for {$days}+ days.");

        return self::SUCCESS;
    }
}
