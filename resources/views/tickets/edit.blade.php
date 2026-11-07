<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit') }} <span class="font-mono text-gray-500">{{ $ticket->ticket_number }}</span>
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                @csrf
                @method('PATCH')

                @include('tickets.partials.form', ['showPriority' => $canChangePriority, 'showCategory' => $canChangeCategory])

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('tickets.show', $ticket) }}" class="text-sm text-gray-600 hover:underline">{{ __('Cancel') }}</a>
                    <x-primary-button>{{ __('Save changes') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
