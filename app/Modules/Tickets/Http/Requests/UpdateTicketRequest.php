<?php

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Field-level edit rights come from the policy: priority and category are
 * accepted only with changePriority / changeCategory, and are explicitly
 * prohibited otherwise (a 422, not a silent drop).
 */
class UpdateTicketRequest extends FormRequest
{
    /** The policy's full answer, so a ticket the user may not see stays a 404. */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->ticket());
    }

    public function rules(): array
    {
        $user = $this->user();
        $ticket = $this->ticket();

        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'priority' => $user->can('work', $ticket)
                ? ['sometimes', 'required', Rule::enum(TicketPriority::class)]
                : ['prohibited'],
            'category_id' => $user->can('work', $ticket)
                ? ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)]
                : ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return ['category_id' => 'category'];
    }

    public function messages(): array
    {
        return [
            'priority.prohibited' => __('You are not allowed to change the priority of this ticket.'),
            'category_id.prohibited' => __('You are not allowed to change the category of this ticket.'),
        ];
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
