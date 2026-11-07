<?php

namespace App\Http\Requests;

use App\Enums\ReplyType;
use App\Models\Ticket;
use App\Support\AttachmentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reply or internal note. The reply type is NEVER taken from a customer:
 * replyType() forces Public for customers, and validation rejects (422) any
 * other `type` a customer sends, so a tampered form cannot create a note.
 */
class StoreReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->replyType() === ReplyType::InternalNote ? 'addInternalNote' : 'reply';

        return $this->user()->can($ability, $this->ticket());
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
