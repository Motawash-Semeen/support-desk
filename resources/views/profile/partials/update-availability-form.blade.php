<x-settings-section :title="__('Availability')" :description="__('While unavailable you receive no auto-assigned tickets. Tickets you already hold stay with you, and you can still accept tickets from the queue.')">
    <form method="POST" action="{{ route('profile.availability.update') }}" class="flex flex-wrap items-center justify-between gap-4 p-5">
        @csrf
        @method('PATCH')
        <input type="hidden" name="is_available" value="{{ $user->is_available ? '0' : '1' }}">

        <div class="flex items-center gap-3">
            <span @class(['relative flex h-3 w-3'])>
                @if ($user->is_available)
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                @endif
                <span @class(['relative inline-flex h-3 w-3 rounded-full', 'bg-emerald-500' => $user->is_available, 'bg-ink-300' => ! $user->is_available])></span>
            </span>
            <div>
                <p class="text-sm font-semibold text-ink-900">{{ $user->is_available ? __('Available') : __('Unavailable') }}</p>
                <p class="text-xs text-ink-500">{{ $user->is_available ? __('New tickets can be assigned to you.') : __('Auto-assignment skips you.') }}</p>
            </div>
        </div>

        @if ($user->is_available)
            <x-secondary-button type="submit">{{ __('Go unavailable') }}</x-secondary-button>
        @else
            <x-primary-button>{{ __('Become available') }}</x-primary-button>
        @endif
    </form>
</x-settings-section>
