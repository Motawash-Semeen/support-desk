<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Support\AttachmentRules;
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
