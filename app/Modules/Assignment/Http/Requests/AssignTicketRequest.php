<?php

namespace App\Modules\Assignment\Http\Requests;

use App\Enums\Role;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Admin assignment / reassignment. `expected_agent_id` is the assignee the
 * admin saw when the page rendered (empty = unassigned); it guards the
 * update so concurrent changes are reported as a conflict, not overwritten.
 */
class AssignTicketRequest extends FormRequest
{
    /** The policy's full answer, so a ticket the user may not see stays a 404. */
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
