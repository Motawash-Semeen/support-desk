<?php

namespace App\Modules\Imports\Http\Requests;

use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import', Ticket::class);
    }

    public function rules(): array
    {
        return [
            // csv/txt by content and by extension; size from config.
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv,txt', 'max:'.(int) config('support.import.max_kb')],
        ];
    }
}
