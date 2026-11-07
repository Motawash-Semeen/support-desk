<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A guarded (compare-and-set) assignment UPDATE matched zero rows: somebody
 * else changed the assignment first. Rendered as 409 Conflict with a clear
 * message, never a 500, and nothing is overwritten.
 */
class AssignmentConflict extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $ticketNumber = null)
    {
        parent::__construct($message);
    }

    public static function ticketTaken(?string $ticketNumber = null): self
    {
        return new self(__('This ticket was just taken by another agent.'), $ticketNumber);
    }

    public static function changedSinceViewed(?string $ticketNumber = null): self
    {
        return new self(__('The assignment of this ticket changed while you were looking at it. Reload and try again.'), $ticketNumber);
    }

    public static function noLongerAssigned(?string $ticketNumber = null): self
    {
        return new self(__('This ticket is no longer assigned to that agent.'), $ticketNumber);
    }

    /** Expected under contention: log for the audit trail, not as an error. */
    public function report(): void
    {
        Log::info('Assignment conflict.', [
            'ticket_number' => $this->ticketNumber,
            'message' => $this->getMessage(),
            'user_id' => auth()->id(),
        ]);
    }

    public function render(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return response()->view('errors.assignment-conflict', [
            'message' => $this->getMessage(),
            'ticketNumber' => $this->ticketNumber,
        ], 409);
    }
}
