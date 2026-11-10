<?php

namespace App\Modules\Conversation\Requests;

use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Reply or internal note. Customers always reply publicly; any other type they send is a 422. */
class StoreReplyRequest extends FormRequest
{
    /** Returns the policy's answer, so a ticket the user may not see stays a 404. */
    public function authorize(): Response
    {
        $ability = $this->replyType() === ReplyType::InternalNote ? 'work' : 'reply';

        return Gate::forUser($this->user())->inspect($ability, $this->ticket());
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'type' => $this->user()->isStaff()
                ? ['nullable', Rule::enum(ReplyType::class)]
                : ['nullable', Rule::in([ReplyType::Public->value])],
        ] + AttachmentRules::rules();
    }

    public function attributes(): array
    {
        return AttachmentRules::attributes();
    }

    public function messages(): array
    {
        return ['type.in' => __('Only staff can add internal notes.')];
    }

    /** Staff choose the type; customers always reply publicly. */
    public function replyType(): ReplyType
    {
        if (! $this->user()->isStaff()) {
            return ReplyType::Public;
        }

        return ReplyType::tryFrom((string) $this->input('type')) ?? ReplyType::Public;
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
