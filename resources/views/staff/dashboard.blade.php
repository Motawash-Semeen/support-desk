@use('App\Support\BusinessTime')
@use('App\Enums\TicketPriority')

@php
    $cards = [
        ['label' => __('Active'), 'value' => $stats->totalActive, 'tone' => 'gray', 'query' => ['scope' => 'active']],
        ['label' => __('Unassigned'), 'value' => $stats->unassignedActive, 'tone' => 'gray', 'query' => ['scope' => 'active', 'assigned_agent_id' => 'unassigned']],
        ['label' => __('Critical'), 'value' => $stats->criticalActive, 'tone' => 'red', 'query' => ['scope' => 'active', 'priority' => ['critical']]],
        ['label' => __('SLA breached'), 'value' => $stats->slaBreachedActive, 'tone' => 'red', 'query' => ['scope' => 'active', 'sla_status' => 'breached']],
        ['label' => __('At risk'), 'value' => $stats->approachingActive, 'tone' => 'amber', 'query' => ['scope' => 'active', 'sla_status' => 'approaching']],
        ['label' => __('Escalated'), 'value' => $stats->escalatedActive, 'tone' => 'red', 'query' => ['scope' => 'active', 'escalated' => 1]],
        ['label' => __('Resolved / closed'), 'value' => $stats->resolvedTotal, 'tone' => 'green', 'query' => ['scope' => 'closed']],
        ['label' => __('Resolved today'), 'value' => $stats->resolvedToday, 'tone' => 'green', 'query' => ['scope' => 'closed', 'sort' => 'updated_at', 'direction' => 'desc']],
    ];
    $toneClass = fn (string $tone, int $value) => match (true) {
        $value === 0 => 'text-gray-900',
        $tone === 'red' => 'text-red-600',
        $tone === 'amber' => 'text-amber-600',
        $tone === 'green' => 'text-green-700',
        default => 'text-gray-900',
    };
    $maxPriority = max([1, ...array_values($stats->byPriority)]);
    $maxAgent = max([1, ...array_column($stats->byAgent, 'active')]);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Staff dashboard') }}</h2>
            <p class="text-xs text-gray-500">
                {{ $stats->scope === 'global' ? __('All tickets') : __('Your tickets and the unassigned queue') }}
                · {{ __('updated :time', ['time' => BusinessTime::format(\Carbon\CarbonImmutable::parse($stats->generatedAt), 'H:i:s')]) }}
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ($cards as $card)
                    <a href="{{ route('tickets.index', $card['query']) }}" class="block bg-white shadow-sm sm:rounded-lg p-5 hover:ring-2 hover:ring-indigo-200 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                        <div class="mt-1 text-3xl font-semibold {{ $toneClass($card['tone'], $card['value']) }}">{{ number_format($card['value']) }}</div>
                    </a>
                @endforeach
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-800 mb-3">{{ __('Active tickets by priority') }}</h3>
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($stats->byPriority as $priority => $count)
                                @php($enum = TicketPriority::from($priority))
                                <tr>
                                    <td class="py-1.5 pr-3 w-24"><x-badge :color="$enum->color()">{{ $enum->label() }}</x-badge></td>
                                    <td class="py-1.5">
                                        <div class="h-2.5 rounded bg-gray-100" aria-hidden="true">
                                            <div class="h-2.5 rounded bg-indigo-500" style="width: {{ round($count / $maxPriority * 100) }}%"></div>
                                        </div>
                                    </td>
                                    <td class="py-1.5 pl-3 w-12 text-right tabular-nums">
                                        <a href="{{ route('tickets.index', ['scope' => 'active', 'priority' => [$priority]]) }}" class="hover:underline">{{ $count }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>

                <section class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-800 mb-3">{{ __('Active tickets by agent') }}</h3>
                    @if ($stats->byAgent === [])
                        <p class="text-sm text-gray-500">{{ __('No assigned active tickets.') }}</p>
                    @else
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs text-gray-500">
                                <tr><th class="pb-2">{{ __('Agent') }}</th><th class="pb-2"><span class="sr-only">{{ __('Load') }}</span></th><th class="pb-2 text-right">{{ __('Active') }}</th><th class="pb-2 text-right">{{ __('Breached') }}</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($stats->byAgent as $row)
                                    <tr>
                                        <td class="py-1.5 pr-3 whitespace-nowrap">
                                            <a href="{{ route('tickets.index', ['scope' => 'active', 'assigned_agent_id' => $row['id']]) }}" class="hover:underline">{{ $row['name'] }}</a>
                                        </td>
                                        <td class="py-1.5 w-1/2">
                                            <div class="h-2.5 rounded bg-gray-100" aria-hidden="true">
                                                <div class="h-2.5 rounded bg-indigo-500" style="width: {{ round($row['active'] / $maxAgent * 100) }}%"></div>
                                            </div>
                                        </td>
                                        <td class="py-1.5 pl-3 text-right tabular-nums">{{ $row['active'] }}</td>
                                        <td @class(['py-1.5 pl-3 text-right tabular-nums', 'text-red-600 font-semibold' => $row['breached'] > 0])>{{ $row['breached'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>

            <section class="bg-white shadow-sm sm:rounded-lg p-5">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('Recent escalations') }}</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($stats->recentEscalations as $escalation)
                        <li class="py-2 flex flex-wrap items-baseline justify-between gap-2">
                            <span>
                                <a href="{{ route('tickets.show', $escalation['ticket_number']) }}" class="font-mono text-indigo-600 hover:underline">{{ $escalation['ticket_number'] }}</a>
                                {{ $escalation['subject'] }}
                                <span class="text-gray-500">· {{ $escalation['agent'] ?? __('Unassigned') }}</span>
                            </span>
                            <span class="text-gray-500">{{ $escalation['description'] }} · {{ BusinessTime::format(\Carbon\CarbonImmutable::parse($escalation['at'])) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('No escalations.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
