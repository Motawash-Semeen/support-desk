<x-guest-layout :title="__('Confirm your password')" :subtitle="__('This is a secure area of the application. Please confirm your password before continuing.')">
    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1.5" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <x-primary-button class="w-full py-2.5">{{ __('Confirm') }}</x-primary-button>
    </form>
</x-guest-layout>
