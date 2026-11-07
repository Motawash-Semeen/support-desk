@use('App\Support\BusinessTime')
@use('App\Enums\TicketPriority')

@php
    $cards = [
        ['label' => __('Active'), 'value' => $stats->totalActive, 'tone' => 'ink', 'icon' => 'ticket', 'query' => ['scope' => 'active']],
        ['label' => __('Unassigned'), 'value' => $stats->unassignedActive, 'tone' => 'amber', 'icon' => 'inbox', 'query' => ['scope' => 'active', 'assigned_agent_id' => 'unassigned']],
        ['label' => __('Critical'), 'value' => $stats->criticalActive, 'tone' => 'red', 'icon' => 'bolt', 'query' => ['scope' => 'active', 'priority' => ['critical']]],
        ['label' => __('SLA breached'), 'value' => $stats->slaBreachedActive, 'tone' => 'red', 'icon' => 'alert', 'query' => ['scope' => 'active', 'sla_status' => 'breached']],
        ['label' => __('At risk'), 'value' => $stats->approachingActive, 'tone' => 'amber', 'icon' => 'clock', 'query' => ['scope' => 'active', 'sla_status' => 'approaching']],
        ['label' => __('Escalated'), 'value' => $stats->escalatedActive, 'tone' => 'red', 'icon' => 'shield', 'query' => ['scope' => 'active', 'escalated' => 1]],
        ['label' => __('Resolved / closed'), 'value' => $stats->resolvedTotal, 'tone' => 'green', 'icon' => 'check-circle', 'query' => ['scope' => 'closed']],
        ['label' => __('Resolved today'), 'value' => $stats->resolvedToday, 'tone' => 'green', 'icon' => 'sparkles', 'query' => ['scope' => 'closed', 'sort' => 'updated_at', 'direction' => 'desc']],
    ];
    $maxPriority = max([1, ...array_values($stats->byPriority)]);
    $maxAgent = max([1, ...array_column($stats->byAgent, 'active')]);
    $barColor = fn (TicketPriority $p) => match ($p) {
        TicketPriority::Critical => 'bg-rose-500',
        TicketPriority::High => 'bg-orange-400',
        TicketPriority::Medium => 'bg-sky-400',
        default => 'bg-ink-300',
    };
@endphp

<x-app-layout :title="__('Dashboard')"
              :subtitle="($stats->scope === 'global' ? __('All tickets') : __('Your tickets and the unassigned queue')).' · '.__('updated :time', ['time' => BusinessTime::format(\Carbon\CarbonImmutable::parse($stats->generatedAt), 'H:i:s')])">
    <x-slot name="actions">
        <a href="{{ route('staff.unassigned') }}" class="btn btn-secondary"><x-icon name="inbox" class="h-4 w-4" /> {{ __('Unassigned queue') }}</a>
        <a href="{{ route('tickets.index') }}" class="btn btn-primary"><x-icon name="ticket" class="h-4 w-4" /> {{ __('All tickets') }}</a>
    </x-slot>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($cards as $card)
            <x-stat-card :label="$card['label']" :value="$card['value']" :tone="$card['tone']" :icon="$card['icon']" :href="route('tickets.index', $card['query'])" />
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        <x-card :title="__('Active tickets by priority')" class="lg:col-span-2">
            <ul class="space-y-3">
                @foreach ($stats->byPriority as $priority => $count)
                    @php($enum = TicketPriority::from($priority))
                    <li>
                        <a href="{{ route('tickets.index', ['scope' => 'active', 'priority' => [$priority]]) }}" class="group block">
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="font-medium text-ink-700 group-hover:text-brand-700">{{ $enum->label() }}</span>
                                <span class="tabular-nums text-ink-500">{{ $count }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-ink-100" aria-hidden="true">
                                <div class="h-2 rounded-full {{ $barColor($enum) }}" style="width: {{ round($count / $maxPriority * 100) }}%"></div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card :title="__('Active tickets by agent')" :padded="false" class="lg:col-span-3">
            @if ($stats->byAgent === [])
                <x-empty-state icon="users" :title="__('No assigned active tickets.')" />
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Agent') }}</th>
                            <th class="w-2/5"><span class="sr-only">{{ __('Load') }}</span></th>
                            <th class="text-right">{{ __('Active') }}</th>
                            <th class="text-right">{{ __('Breached') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($stats->byAgent as $row)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('tickets.index', ['scope' => 'active', 'assigned_agent_id' => $row['id']]) }}" class="inline-flex items-center gap-2 font-medium text-ink-800 hover:text-brand-700">
                                        <x-avatar :name="$row['name']" size="sm" /> {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="align-middle">
                                    <div class="h-2 overflow-hidden rounded-full bg-ink-100" aria-hidden="true">
                                        <div class="h-2 rounded-full bg-brand-500" style="width: {{ round($row['active'] / $maxAgent * 100) }}%"></div>
                                    </div>
                                </td>
                                <td class="text-right tabular-nums text-ink-700">{{ $row['active'] }}</td>
                                <td class="text-right tabular-nums">
                                    @if ($row['breached'] > 0)
                                        <span class="font-semibold text-rose-600">{{ $row['breached'] }}</span>
                                    @else
                                        <span class="text-ink-400">0</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>
    </div>

    <x-card :title="__('Recent escalations')" :padded="false" class="mt-6">
        @if ($stats->recentEscalations === [])
            <x-empty-state icon="shield" :title="__('No escalations.')" :description="__('Tickets that breach their SLA show up here.')" />
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($stats->recentEscalations as $escalation)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><x-icon name="alert" class="h-4 w-4" /></span>
                            <div class="min-w-0">
                                <a href="{{ route('tickets.show', $escalation['ticket_number']) }}" class="font-medium text-ink-900 hover:text-brand-700">{{ $escalation['subject'] }}</a>
                                <p class="text-xs text-ink-500"><span class="ticket-no">{{ $escalation['ticket_number'] }}</span> · {{ $escalation['agent'] ?? __('Unassigned') }}</p>
                            </div>
                        </div>
                        <span class="text-xs text-ink-500">{{ $escalation['description'] }} · {{ BusinessTime::format(\Carbon\CarbonImmutable::parse($escalation['at'])) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-app-layout>
