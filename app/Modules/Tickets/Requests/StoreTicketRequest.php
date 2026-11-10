<?php

namespace App\Modules\Tickets\Requests;

use App\Modules\Conversation\Requests\AttachmentRules;
use App\Modules\Tickets\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    /** Customers may pick any priority; staff can change it later. */
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
