<?php

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Generic status change. The ability depends on the target, so moving to
 * Resolved needs `resolve`, to Closed needs `close`, out of a finished state
 * needs `reopen`, anything else needs `changeStatus`.
 */
class ChangeTicketStatusRequest extends FormRequest
{
    /** The policy's full answer, so a ticket the user may not see stays a 404. */
    public function authorize(): Response
    {
        $to = TicketStatus::tryFrom((string) $this->input('status'));
        $ticket = $this->route('ticket');

        // Unknown value: still check that the ticket is visible, then let validation answer with a 422.
        return Gate::forUser($this->user())->inspect($to === null ? 'view' : self::abilityFor($ticket, $to), $ticket);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function target(): TicketStatus
    {
        return TicketStatus::from($this->validated('status'));
    }

    public static function abilityFor(Ticket $ticket, TicketStatus $to): string
    {
        return match (true) {
            $to === TicketStatus::Resolved => 'resolve',
            $to === TicketStatus::Closed => 'close',
            ! $ticket->isActive() && $to->isActive() => 'reopen',
            default => 'changeStatus',
        };
    }
}
