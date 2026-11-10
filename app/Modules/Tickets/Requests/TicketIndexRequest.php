<?php

namespace App\Modules\Tickets\Requests;

use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\TicketFilter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates every list parameter. TicketFilter ignores the staff-only ones for customers. */
class TicketIndexRequest extends FormRequest
{
    /** Anyone signed in may list; TicketFilter limits what they see. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'array', 'max:5'],
            'status.*' => [Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', 'array', 'max:4'],
            'priority.*' => [Rule::enum(TicketPriority::class)],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'assigned_agent_id' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== 'unassigned' && ! ctype_digit((string) $value)) {
                    $fail(__('Choose an agent or "unassigned".'));
                }
            }],
            'customer' => ['nullable', 'integer', 'min:1'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sla_status' => ['nullable', Rule::in(TicketFilter::SLA_STATUSES)],
            'escalated' => ['nullable', 'boolean'],
            'scope' => ['nullable', Rule::in(['active', 'closed', 'all'])],
            'sort' => ['nullable', Rule::in(TicketFilter::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
