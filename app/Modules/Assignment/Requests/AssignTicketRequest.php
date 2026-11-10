<?php

namespace App\Modules\Assignment\Requests;

use App\Enums\Role;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Admin (re)assignment. expected_agent_id is the assignee shown on the page (empty = unassigned). */
class AssignTicketRequest extends FormRequest
{
    /** Returns the policy's answer, so a ticket the user may not see stays a 404. */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('assign', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'agent_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Agent->value)->where('is_available', true),
            ],
            'expected_agent_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['agent_id.exists' => __('Choose an available agent.')];
    }

    public function expectedAgentId(): ?int
    {
        $value = $this->validated('expected_agent_id');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
