<?php

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generic status change. The ability depends on the target, so moving to
 * Resolved needs `resolve`, to Closed needs `close`, out of a finished state
 * needs `reopen`, anything else needs `changeStatus`.
 */
class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $to = TicketStatus::tryFrom((string) $this->input('status'));

        // Unknown value: let validation answer with a 422.
        return $to === null || $this->user()->can(self::abilityFor($this->route('ticket'), $to), $this->route('ticket'));
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
