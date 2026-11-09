<?php

namespace App\Modules\Conversation\Http\Requests;

use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Support\AttachmentRules;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reply or internal note. The reply type is NEVER taken from a customer:
 * replyType() forces Public for customers, and validation rejects (422) any
 * other `type` a customer sends, so a tampered form cannot create a note.
 */
class StoreReplyRequest extends FormRequest
{
    /** The policy's full answer, so a ticket the user may not see stays a 404. */
    public function authorize(): Response
    {
        $ability = $this->replyType() === ReplyType::InternalNote ? 'addInternalNote' : 'reply';

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

    /** Server-side decision: staff choose, customers always reply publicly. */
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
