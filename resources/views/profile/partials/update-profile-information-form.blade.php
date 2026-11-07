<x-settings-section :title="__('Profile Information')" :description="__('Update your account\'s profile information and email address.')">
    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}">
        @csrf
        @method('patch')

        <div class="space-y-5 p-5">
            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input id="name" name="name" type="text" class="mt-1.5" :value="old('name', $user->name)" required autofocus autocomplete="name" />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" name="email" type="email" class="mt-1.5" :value="old('email', $user->email)" required autocomplete="username" />
                <x-input-error class="mt-2" :messages="$errors->get('email')" />

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
                        {{ __('Your email address is unverified.') }}
                        <button form="send-verification" class="font-semibold underline hover:no-underline">{{ __('Click here to re-send the verification email.') }}</button>
                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 font-medium text-emerald-700">{{ __('A new verification link has been sent to your email address.') }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-b-xl border-t border-ink-100 bg-ink-50/60 px-5 py-3">
            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2500)" class="inline-flex items-center gap-1 text-sm text-emerald-700">
                    <x-icon name="check-circle" class="h-4 w-4" /> {{ __('Saved.') }}
                </p>
            @endif
            <x-primary-button>{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</x-settings-section>
