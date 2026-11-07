<x-guest-layout :title="__('Check your inbox')" :subtitle="__('We sent a verification link to your email address. Click it to start using Support Desk.')">
    @if (session('status') == 'verification-link-sent')
        <x-auth-session-status class="mb-6" :status="__('A new verification link has been sent to the email address you provided during registration.')" />
    @endif

    <div class="rounded-xl bg-ink-50 p-4 text-sm text-ink-600 ring-1 ring-inset ring-ink-200">
        {{ __("Didn't get the email? Check your spam folder, or send it again.") }}
    </div>

    <div class="mt-6 flex items-center justify-between gap-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>{{ __('Resend Verification Email') }}</x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost">{{ __('Log Out') }}</button>
        </form>
    </div>
</x-guest-layout>
