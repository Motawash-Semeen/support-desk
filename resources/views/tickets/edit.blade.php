<x-app-layout :title="__('Edit').' '.$ticket->ticket_number" width="narrow">
    <x-slot name="header">
        <a href="{{ route('tickets.show', $ticket) }}" class="inline-flex items-center gap-1 text-sm font-medium text-ink-500 hover:text-ink-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> {{ __('Back to ticket') }}
        </a>
        <h1 class="mt-3 text-2xl font-semibold tracking-tight text-ink-900">
            {{ __('Edit') }} <span class="ticket-no align-middle text-sm">{{ $ticket->ticket_number }}</span>
        </h1>
    </x-slot>

    <form method="POST" action="{{ route('tickets.update', $ticket) }}">
        @csrf
        @method('PATCH')

        <x-card>
            @include('tickets.partials.form', ['showPriority' => $canChangePriority, 'showCategory' => $canChangeCategory])

            <x-slot name="footer">
                <a href="{{ route('tickets.show', $ticket) }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Save changes') }}</x-primary-button>
            </x-slot>
        </x-card>
    </form>
</x-app-layout>
