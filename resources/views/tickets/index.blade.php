@use('App\Support\BusinessTime')

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                @staff {{ __('All tickets') }} @else {{ __('My tickets') }} @endstaff
            </h2>
            @can('create', App\Models\Ticket::class)
                <a href="{{ route('tickets.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 rounded-md text-xs font-semibold text-white uppercase tracking-widest hover:bg-gray-700">
                    {{ __('New ticket') }}
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <nav class="mb-4 flex gap-2 text-sm" aria-label="{{ __('Ticket scope') }}">
                @foreach (['active' => __('Active'), 'closed' => __('Resolved / Closed')] as $key => $label)
                    <a href="{{ route('tickets.index', ['scope' => $key]) }}"
                       @class([
                           'rounded-md px-3 py-1.5 font-medium',
                           'bg-gray-800 text-white' => $scope === $key,
                           'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200' => $scope !== $key,
                       ])
                       @if ($scope === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            @include('tickets.partials.filter-bar')

            <p class="mb-2 text-sm text-gray-600" role="status">
                {{ trans_choice('{0} No tickets match.|{1} 1 ticket|[2,*] :count tickets', $tickets->total(), ['count' => number_format($tickets->total())]) }}
            </p>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('Ticket') }}</th>
                            <th class="px-4 py-3">{{ __('Subject') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3">{{ __('Priority') }}</th>
                            <th class="px-4 py-3">{{ __('Category') }}</th>
                            @staff
                                <th class="px-4 py-3">{{ __('Customer') }}</th>
                            @endstaff
                            <th class="px-4 py-3">{{ __('Agent') }}</th>
                            <th class="px-4 py-3">{{ __('Due') }} <span class="normal-case font-normal">({{ BusinessTime::zone() }})</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tickets as $ticket)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap font-mono">
                                    <a href="{{ route('tickets.show', $ticket) }}" class="text-indigo-600 hover:underline">{{ $ticket->ticket_number }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $ticket->subject }}</td>
                                <td class="px-4 py-3 whitespace-nowrap"><x-badge :color="$ticket->status->color()">{{ $ticket->status->label() }}</x-badge></td>
                                <td class="px-4 py-3 whitespace-nowrap"><x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge></td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $ticket->category->name }}</td>
                                @staff
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $ticket->creator->name }}</td>
                                @endstaff
                                <td class="px-4 py-3 whitespace-nowrap">{{ $ticket->assignee?->name ?? __('Unassigned') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div>{{ BusinessTime::format($ticket->due_at) }}</div>
                                    <x-sla-badge :ticket="$ticket" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-gray-500">
                                    @if (collect($filters)->except(['sort', 'direction'])->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->isNotEmpty())
                                        {{ __('No tickets match these filters.') }}
                                        <a href="{{ route('tickets.index') }}" class="text-indigo-600 hover:underline">{{ __('Clear filters') }}</a>
                                    @else
                                        {{ __('No tickets yet.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $tickets->links() }}</div>
        </div>
    </div>
</x-app-layout>
