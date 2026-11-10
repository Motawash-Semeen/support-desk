<?php

namespace App\Modules\Sla\Requests;

use App\Modules\Tickets\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSlaPoliciesRequest extends FormRequest
{
    /** At most 14 days in business minutes, well inside BusinessCalendar's limit. */
    public const MAX_MINUTES = 20_160;

    public function rules(): array
    {
        $priorities = implode(',', array_column(TicketPriority::cases(), 'value'));

        return [
            'policies' => ['required', 'array', "array:{$priorities}"],
            'policies.*' => ['required', 'array'],
            'policies.*.resolution_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MINUTES],
            'policies.*.warning_threshold' => ['required', 'numeric', 'min:0.05', 'max:0.99', 'decimal:0,2'],
            'policies.*.is_active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'policies.*.resolution_minutes' => 'resolution minutes',
            'policies.*.warning_threshold' => 'warning threshold',
        ];
    }
}
