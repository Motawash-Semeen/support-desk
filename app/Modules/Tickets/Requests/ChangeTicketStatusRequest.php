<?php

namespace App\Modules\Tickets\Requests;

use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Every status change. The ability depends on the target: close, reopen, or work. */
class ChangeTicketStatusRequest extends FormRequest
{
    /** Returns the policy's answer, so a ticket the user may not see stays a 404. */
    public function authorize(): Response
    {
        $to = TicketStatus::tryFrom((string) $this->input('status'));
        $ticket = $this->route('ticket');

        // Unknown status: check visibility here; validation answers with a 422.
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
            $to === TicketStatus::Closed => 'close',
            ! $ticket->isActive() && $to->isActive() => 'reopen',
            default => 'work',
        };
    }
}
