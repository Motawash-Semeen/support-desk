@use('App\Support\BusinessTime')

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Unassigned queue') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <p class="mb-4 text-sm text-gray-600">{{ __('Active tickets nobody owns yet, most urgent deadline first.') }}</p>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('Ticket') }}</th>
                            <th class="px-4 py-3">{{ __('Subject') }}</th>
                            <th class="px-4 py-3">{{ __('Priority') }}</th>
                            <th class="px-4 py-3">{{ __('Category') }}</th>
                            <th class="px-4 py-3">{{ __('Customer') }}</th>
                            <th class="px-4 py-3">{{ __('Due') }} <span class="normal-case font-normal">({{ BusinessTime::zone() }})</span></th>
                            <th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tickets as $ticket)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap font-mono">
                                    <a href="{{ route('tickets.show', $ticket) }}" class="text-indigo-600 hover:underline">{{ $ticket->ticket_number }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $ticket->subject }}</td>
                                <td class="px-4 py-3 whitespace-nowrap"><x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge></td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $ticket->category->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $ticket->creator->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div>{{ BusinessTime::format($ticket->due_at) }}</div>
                                    <x-sla-badge :ticket="$ticket" />
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right">
                                    @can('accept', $ticket)
                                        <form method="POST" action="{{ route('tickets.accept', $ticket) }}">
                                            @csrf
                                            <x-primary-button>{{ __('Accept') }}</x-primary-button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-gray-500">{{ __('The queue is empty.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $tickets->links() }}</div>
        </div>
    </div>
</x-app-layout>
