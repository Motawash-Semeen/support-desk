{{--
    Reply / internal-note form. Customers never see the type choice; the
    server forces their replies to public anyway (StoreReplyRequest).
--}}
@php
    $canReply = auth()->user()->can('reply', $ticket);
    $canNote = auth()->user()->can('addInternalNote', $ticket);
@endphp

@if ($canReply || $canNote)
    <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" enctype="multipart/form-data"
          class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
        @csrf

        <div>
            <x-input-label for="body" :value="__('Your message')" />
            <textarea id="body" name="body" rows="5" maxlength="10000" required
                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('body') }}</textarea>
            <x-input-error class="mt-2" :messages="$errors->get('body')" />
        </div>

        @include('tickets.partials.attachment-input')

        @if ($canNote)
            <fieldset class="flex flex-wrap gap-4 text-sm">
                <legend class="sr-only">{{ __('Visibility') }}</legend>
                @if ($canReply)
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="type" value="public" @checked(old('type', 'public') === 'public') class="border-gray-300 text-indigo-600">
                        {{ __('Reply to customer') }}
                    </label>
                @endif
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="type" value="internal_note" @checked(old('type') === 'internal_note' || ! $canReply) class="border-gray-300 text-amber-600">
                    {{ __('Internal note (staff only)') }}
                </label>
                <x-input-error :messages="$errors->get('type')" />
            </fieldset>
        @endif

        <div class="text-right"><x-primary-button>{{ __('Send') }}</x-primary-button></div>
    </form>
@elseif ($ticket->status === \App\Enums\TicketStatus::Closed)
    <p class="text-sm text-gray-500">{{ __('This ticket is closed. Reopen it to reply.') }}</p>
@endif
