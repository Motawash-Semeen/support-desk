<?php

namespace App\Modules\Imports\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // csv/txt by content and by extension; size from config.
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv,txt', 'max:'.(int) config('support.import.max_kb')],
        ];
    }
}
