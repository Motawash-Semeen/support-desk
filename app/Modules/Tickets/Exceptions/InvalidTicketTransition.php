<?php

namespace App\Modules\Tickets\Exceptions;

use App\Modules\Tickets\Enums\TicketStatus;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Thrown when a status change is not allowed by TicketStatus. Rendered as a
 * validation error on the `status` field (422 / redirect back), never a 500.
 */
class InvalidTicketTransition extends DomainException
{
    public function __construct(
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
        public readonly ?int $ticketId = null,
    ) {
        parent::__construct("A ticket cannot move from {$from->label()} to {$to->label()}.");
    }

    public static function between(TicketStatus $from, TicketStatus $to, ?int $ticketId = null): self
    {
        return new self($from, $to, $ticketId);
    }

    /** A user error, not a fault: log for the audit trail at info level only. */
    public function report(): void
    {
        Log::info('Rejected ticket status transition.', [
            'ticket_id' => $this->ticketId,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'user_id' => auth()->id(),
        ]);
    }

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['status' => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['status' => $this->getMessage()]);
    }
}
