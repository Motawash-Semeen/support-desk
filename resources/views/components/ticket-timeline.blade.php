{{--
    Ticket history. $activities is already filtered for the viewer
    (Ticket::activitiesVisibleTo) and simple-paginated by 20.
--}}
@props(['ticket', 'activities', 'direction' => 'desc'])
@use('App\Support\BusinessTime')

<section {{ $attributes->merge(['class' => 'bg-white shadow-sm sm:rounded-lg p-6']) }}>
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-gray-800">{{ __('Activity') }}</h3>
        <a href="{{ request()->fullUrlWithQuery(['timeline' => $direction === 'desc' ? 'oldest' : 'newest', 'timeline_page' => null]) }}"
           class="text-xs text-indigo-600 hover:underline">
            {{ $direction === 'desc' ? __('Oldest first') : __('Newest first') }}
        </a>
    </div>

    <ol class="space-y-3 text-sm">
        @forelse ($activities as $activity)
            <li>
                <div class="text-gray-800">{{ $activity->description }}</div>
                <div class="text-gray-500">
                    <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ BusinessTime::format($activity->created_at) }}</time>
                    · {{ $activity->actor?->name ?? __('System') }}
                </div>
            </li>
        @empty
            <li class="text-gray-500">{{ __('No activity recorded.') }}</li>
        @endforelse
    </ol>

    @if ($activities->hasPages())
        <div class="mt-4">{{ $activities->links() }}</div>
    @endif
</section>
