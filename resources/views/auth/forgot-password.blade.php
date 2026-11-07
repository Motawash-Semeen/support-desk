<x-guest-layout :title="__('Reset your password')" :subtitle="__('Enter your email address and we will send you a link to choose a new password.')">
    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1.5" type="email" name="email" :value="old('email')" required autofocus placeholder="you@company.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <x-primary-button class="w-full py-2.5">{{ __('Email Password Reset Link') }}</x-primary-button>
    </form>

    <p class="mt-8 text-center text-sm text-ink-500">
        <a href="{{ route('login') }}" class="link inline-flex items-center gap-1">
            <x-icon name="arrow-left" class="h-4 w-4" /> {{ __('Back to sign in') }}
        </a>
    </p>
</x-guest-layout>
