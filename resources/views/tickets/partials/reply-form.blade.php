{{--
    Reply / internal-note composer. Customers never see the type choice; the
    server forces their replies to public anyway (StoreReplyRequest).
--}}
@php
    $canReply = auth()->user()->can('reply', $ticket);
    $canNote = auth()->user()->can('addInternalNote', $ticket);
    $initialType = old('type', $canReply ? 'public' : 'internal_note');
@endphp

@if ($canReply || $canNote)
    <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" enctype="multipart/form-data"
          class="card overflow-hidden" x-data="{ type: @js($initialType) }">
        @csrf

        @if ($canNote)
            <fieldset class="flex gap-1 border-b border-ink-100 bg-ink-50/60 px-3 pt-2">
                <legend class="sr-only">{{ __('Visibility') }}</legend>
                @if ($canReply)
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="public" x-model="type" @checked($initialType === 'public') class="peer sr-only">
                        <span class="-mb-px inline-flex items-center gap-1.5 rounded-t-lg border border-transparent px-3 py-2 text-sm font-medium text-ink-500 peer-checked:border-ink-200 peer-checked:border-b-white peer-checked:bg-white peer-checked:text-ink-900 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">
                            <x-icon name="chat" class="h-4 w-4" /> {{ __('Reply to customer') }}
                        </span>
                    </label>
                @endif
                <label class="cursor-pointer">
                    <input type="radio" name="type" value="internal_note" x-model="type" @checked($initialType === 'internal_note') class="peer sr-only">
                    <span class="-mb-px inline-flex items-center gap-1.5 rounded-t-lg border border-transparent px-3 py-2 text-sm font-medium text-ink-500 peer-checked:border-amber-200 peer-checked:border-b-amber-50 peer-checked:bg-amber-50 peer-checked:text-amber-900 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">
                        <x-icon name="lock" class="h-4 w-4" /> {{ __('Internal note (staff only)') }}
                    </span>
                </label>
            </fieldset>
        @endif

        <div class="space-y-4 p-4" :class="type === 'internal_note' ? 'bg-amber-50' : ''">
            <div>
                <x-input-label for="body" :value="__('Your message')" class="sr-only" />
                <textarea id="body" name="body" rows="5" maxlength="10000" required
                          placeholder="{{ __('Write your message…') }}"
                          class="field-input resize-y">{{ old('body') }}</textarea>
                <x-input-error class="mt-2" :messages="$errors->get('body')" />
                <x-input-error class="mt-2" :messages="$errors->get('type')" />
            </div>

            @include('tickets.partials.attachment-input')

            <div class="flex items-center justify-between gap-3">
                @if ($canNote)
                    <p class="text-xs text-ink-500" x-show="type === 'internal_note'" x-cloak>
                        <x-icon name="lock" class="inline h-3.5 w-3.5" /> {{ __('Only staff can see this.') }}
                    </p>
                @endif
                <x-primary-button class="ml-auto">{{ __('Send') }}</x-primary-button>
            </div>
        </div>
    </form>
@elseif ($ticket->status === \App\Enums\TicketStatus::Closed)
    <div class="flex items-center gap-3 rounded-xl bg-ink-100 px-4 py-3 text-sm text-ink-600">
        <x-icon name="lock" class="h-4 w-4 shrink-0" />
        {{ __('This ticket is closed. Reopen it to reply.') }}
    </div>
@endif
