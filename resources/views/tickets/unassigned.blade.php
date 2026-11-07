@use('App\Support\BusinessTime')

<x-app-layout :title="__('Unassigned queue')" :subtitle="__('Active tickets nobody owns yet, most urgent deadline first.')">
    <div class="card overflow-hidden">
        @if ($tickets->isEmpty())
            <x-empty-state icon="check-circle" :title="__('The queue is empty.')" :description="__('Every active ticket has an owner. New ones appear here until someone accepts them.')" />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Ticket') }}</th>
                            <th>{{ __('Priority') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Due') }} <span class="font-normal normal-case text-ink-400">({{ BusinessTime::zone() }})</span></th>
                            <th><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($tickets as $ticket)
                            <tr class="group">
                                <td class="min-w-[18rem]">
                                    <a href="{{ route('tickets.show', $ticket) }}" class="block">
                                        <span class="font-medium text-ink-900 group-hover:text-brand-700">{{ $ticket->subject }}</span>
                                        <span class="mt-1 flex items-center gap-2 text-xs text-ink-500">
                                            <span class="ticket-no">{{ $ticket->ticket_number }}</span>
                                            <span>{{ $ticket->category->name }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td class="whitespace-nowrap"><x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge></td>
                                <td class="whitespace-nowrap text-ink-600">{{ $ticket->creator->name }}</td>
                                <td class="whitespace-nowrap">
                                    <div class="text-ink-700">{{ BusinessTime::format($ticket->due_at) }}</div>
                                    <x-sla-badge :ticket="$ticket" class="mt-1" />
                                </td>
                                <td class="whitespace-nowrap text-right align-middle">
                                    @can('accept', $ticket)
                                        <form method="POST" action="{{ route('tickets.accept', $ticket) }}">
                                            @csrf
                                            <x-primary-button class="btn-sm">{{ __('Accept') }}</x-primary-button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
</x-app-layout>
