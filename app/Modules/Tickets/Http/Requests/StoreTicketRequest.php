<?php

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Conversation\Support\AttachmentRules;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
    }

    /**
     * Customers may pick any priority (D-047); staff can change it later and
     * every change is recorded in the activity log.
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
        ] + AttachmentRules::rules();
    }

    public function attributes(): array
    {
        return ['category_id' => 'category'] + AttachmentRules::attributes();
    }
}
