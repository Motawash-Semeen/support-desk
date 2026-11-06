@use('App\Support\BusinessTime')

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Staff dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                @foreach ([
                    'active' => __('Active'),
                    'unassigned' => __('Unassigned'),
                    'mine' => __('Assigned to me'),
                    'approaching' => __('At risk'),
                    'breached' => __('Breached'),
                ] as $key => $label)
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">{{ $label }}</div>
                        <div @class([
                            'mt-1 text-3xl font-semibold',
                            'text-red-600' => $key === 'breached' && $stats[$key] > 0,
                            'text-amber-600' => $key === 'approaching' && $stats[$key] > 0,
                            'text-gray-900' => ! in_array($key, ['breached', 'approaching'], true) || $stats[$key] === 0,
                        ])>{{ $stats[$key] }}</div>
                    </div>
                @endforeach
            </div>

            <section class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <h3 class="px-4 pt-4 font-semibold text-gray-800">{{ __('Most overdue') }}</h3>
                <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($breached as $ticket)
                            <tr>
                                <td class="px-4 py-3 font-mono whitespace-nowrap">
                                    <a href="{{ route('tickets.show', $ticket) }}" class="text-indigo-600 hover:underline">{{ $ticket->ticket_number }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $ticket->subject }}</td>
                                <td class="px-4 py-3 whitespace-nowrap"><x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge></td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $ticket->assignee?->name ?? __('Unassigned') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-red-600">{{ BusinessTime::format($ticket->due_at) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-gray-500">{{ __('Nothing overdue.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</x-app-layout>
