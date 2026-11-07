{{--
    Ticket history. $activities is already filtered for the viewer
    (Ticket::activitiesVisibleTo) and simple-paginated by 20.
--}}
@props(['ticket', 'activities', 'direction' => 'desc'])
@use('App\Support\BusinessTime')

<x-card :title="__('Activity')" {{ $attributes }}>
    <x-slot name="actions">
        <a href="{{ request()->fullUrlWithQuery(['timeline' => $direction === 'desc' ? 'oldest' : 'newest', 'timeline_page' => null]) }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline">
            <x-icon name="chevron-up-down" class="h-3.5 w-3.5" />
            {{ $direction === 'desc' ? __('Oldest first') : __('Newest first') }}
        </a>
    </x-slot>

    @if ($activities->isEmpty())
        <p class="text-sm text-ink-500">{{ __('No activity recorded.') }}</p>
    @else
        <ol class="relative space-y-4 border-l border-ink-200 pl-5">
            @foreach ($activities as $activity)
                <li class="relative">
                    <span class="absolute -left-[26px] top-1 h-2.5 w-2.5 rounded-full bg-white ring-2 ring-brand-500" aria-hidden="true"></span>
                    <p class="text-sm text-ink-800">{{ $activity->description }}</p>
                    <p class="mt-0.5 text-xs text-ink-500">
                        <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ BusinessTime::format($activity->created_at) }}</time>
                        · {{ $activity->actor?->name ?? __('System') }}
                    </p>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($activities->hasPages())
        <div class="mt-4">{{ $activities->links() }}</div>
    @endif
</x-card>
