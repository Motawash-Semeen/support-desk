<x-settings-section :title="__('Delete Account')" :description="__('Permanently remove your account and its data.')" :danger="true">
    <div class="flex flex-wrap items-center justify-between gap-4 p-5">
        <p class="max-w-md text-sm text-ink-600">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
        <x-danger-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            <x-icon name="trash" class="h-4 w-4" /> {{ __('Delete Account') }}
        </x-danger-button>
    </div>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable maxWidth="lg">
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <div class="flex items-start gap-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-600">
                    <x-icon name="alert" class="h-5 w-5" />
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-ink-900">{{ __('Are you sure you want to delete your account?') }}</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                    </p>
                </div>
            </div>

            <div class="mt-5">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />
                <x-text-input id="password" name="password" type="password" placeholder="{{ __('Password') }}" />
                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button>{{ __('Delete Account') }}</x-danger-button>
            </div>
        </form>
    </x-modal>
</x-settings-section>
