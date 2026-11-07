<x-guest-layout :title="__('Sign in to Support Desk')" :subtitle="__('Welcome back. Use the email and password for your account.')">
    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1.5" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="you@company.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" :value="__('Password')" />
                @if (Route::has('password.request'))
                    <a class="text-sm link" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
                @endif
            </div>
            <x-text-input id="password" class="mt-1.5" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-ink-600">
            <input id="remember_me" type="checkbox" class="field-check" name="remember">
            {{ __('Remember me') }}
        </label>

        <x-primary-button class="w-full py-2.5">{{ __('Log in') }}</x-primary-button>
    </form>

    @if (Route::has('register'))
        <p class="mt-8 text-center text-sm text-ink-500">
            {{ __('New here?') }}
            <a href="{{ route('register') }}" class="link">{{ __('Create a customer account') }}</a>
        </p>
    @endif
</x-guest-layout>
