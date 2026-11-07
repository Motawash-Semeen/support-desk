<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">{{ __('Availability') }}</h2>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('While unavailable you receive no auto-assigned tickets. Tickets you already hold stay with you, and you can still accept tickets from the queue.') }}
        </p>
    </header>

    <form method="POST" action="{{ route('profile.availability.update') }}" class="mt-6 flex items-center gap-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="is_available" value="{{ $user->is_available ? '0' : '1' }}">

        <x-badge :color="$user->is_available ? 'green' : 'gray'">
            {{ $user->is_available ? __('Available') : __('Unavailable') }}
        </x-badge>

        <x-primary-button>
            {{ $user->is_available ? __('Go unavailable') : __('Become available') }}
        </x-primary-button>
    </form>
</section>
