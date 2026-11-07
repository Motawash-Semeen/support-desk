<x-settings-section :title="__('Update Password')" :description="__('Ensure your account is using a long, random password to stay secure.')">
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        @method('put')

        <div class="space-y-5 p-5">
            <div>
                <x-input-label for="update_password_current_password" :value="__('Current Password')" />
                <x-text-input id="update_password_current_password" name="current_password" type="password" class="mt-1.5" autocomplete="current-password" />
                <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="update_password_password" :value="__('New Password')" />
                    <x-text-input id="update_password_password" name="password" type="password" class="mt-1.5" autocomplete="new-password" />
                    <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="update_password_password_confirmation" :value="__('Confirm Password')" />
                    <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1.5" autocomplete="new-password" />
                    <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-b-xl border-t border-ink-100 bg-ink-50/60 px-5 py-3">
            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2500)" class="inline-flex items-center gap-1 text-sm text-emerald-700">
                    <x-icon name="check-circle" class="h-4 w-4" /> {{ __('Saved.') }}
                </p>
            @endif
            <x-primary-button>{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</x-settings-section>
