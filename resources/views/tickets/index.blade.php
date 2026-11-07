@use('App\Support\BusinessTime')

@php
    $isFiltered = collect($filters)->except(['sort', 'direction'])->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->isNotEmpty();
@endphp

<x-app-layout :title="auth()->user()->isStaff() ? __('All tickets') : __('My tickets')"
              :subtitle="trans_choice('{0} No tickets match.|{1} 1 ticket|[2,*] :count tickets', $tickets->total(), ['count' => number_format($tickets->total())])">
    <x-slot name="actions">
        @can('create', App\Models\Ticket::class)
            <a href="{{ route('tickets.create') }}" class="btn btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> {{ __('New ticket') }}
            </a>
        @endcan
    </x-slot>

    {{-- Scope tabs --}}
    <nav class="mb-4 flex gap-1 rounded-xl bg-ink-100 p-1 text-sm sm:inline-flex" aria-label="{{ __('Ticket scope') }}">
        @foreach (['active' => __('Active'), 'closed' => __('Resolved / Closed')] as $key => $label)
            <a href="{{ route('tickets.index', ['scope' => $key]) }}"
               @class([
                   'flex-1 rounded-lg px-4 py-1.5 text-center font-medium transition sm:flex-none',
                   'bg-white text-ink-900 shadow-sm' => $scope === $key,
                   'text-ink-500 hover:text-ink-800' => $scope !== $key,
               ])
               @if ($scope === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @include('tickets.partials.filter-bar')

    <div class="card overflow-hidden">
        @if ($tickets->isEmpty())
            @if ($isFiltered)
                <x-empty-state icon="funnel" :title="__('No tickets match these filters.')" :description="__('Try a broader search, or clear the filters to see everything.')">
                    <a href="{{ route('tickets.index') }}" class="btn btn-secondary">{{ __('Clear filters') }}</a>
                </x-empty-state>
            @else
                <x-empty-state icon="ticket" :title="__('No tickets yet.')" :description="__('When a ticket is opened it shows up here, with its deadline and owner.')">
                    @can('create', App\Models\Ticket::class)
                        <a href="{{ route('tickets.create') }}" class="btn btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New ticket') }}</a>
                    @endcan
                </x-empty-state>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Ticket') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Priority') }}</th>
                            @staff
                                <th class="hidden xl:table-cell">{{ __('Customer') }}</th>
                            @endstaff
                            <th>{{ __('Agent') }}</th>
                            <th>{{ __('Due') }} <span class="font-normal normal-case text-ink-400">({{ BusinessTime::zone() }})</span></th>
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
                                <td class="whitespace-nowrap"><x-badge :color="$ticket->status->color()">{{ $ticket->status->label() }}</x-badge></td>
                                <td class="whitespace-nowrap"><x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge></td>
                                @staff
                                    <td class="hidden whitespace-nowrap text-ink-600 xl:table-cell">{{ $ticket->creator->name }}</td>
                                @endstaff
                                <td class="whitespace-nowrap">
                                    @if ($ticket->assignee)
                                        <span class="inline-flex items-center gap-2 text-ink-700">
                                            <x-avatar :name="$ticket->assignee->name" size="sm" /> {{ $ticket->assignee->name }}
                                        </span>
                                    @else
                                        <span class="text-ink-400">{{ __('Unassigned') }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="text-ink-700">{{ BusinessTime::format($ticket->due_at) }}</div>
                                    <x-sla-badge :ticket="$ticket" class="mt-1" />
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
