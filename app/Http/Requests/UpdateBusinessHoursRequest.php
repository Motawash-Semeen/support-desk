<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-sla-settings');
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'array:0,1,2,3,4,5,6', 'size:7'],
            'days.*' => ['required', 'array'],
            'days.*.is_working_day' => ['required', 'boolean'],
            'days.*.opens_at' => ['nullable', 'required_if_accepted:days.*.is_working_day', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'required_if_accepted:days.*.is_working_day', 'date_format:H:i'],
        ];
    }

    /**
     * Closing must follow opening on working days, and at least one day must
     * be worked, otherwise no SLA deadline could ever be computed.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $days = $this->input('days', []);

                if (! is_array($days) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $working = array_filter($days, fn ($day) => filter_var($day['is_working_day'] ?? false, FILTER_VALIDATE_BOOL));

                if ($working === []) {
                    $validator->errors()->add('days', __('At least one working day is required.'));
                }

                foreach ($working as $weekday => $day) {
                    if (($day['closes_at'] ?? '') <= ($day['opens_at'] ?? '')) {
                        $validator->errors()->add("days.{$weekday}.closes_at", __('Closing time must be after opening time.'));
                    }
                }
            },
        ];
    }
}
