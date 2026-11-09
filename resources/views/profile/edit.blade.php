<x-app-layout :title="__('Profile')" :subtitle="__('Your account details, password and availability.')" width="medium">
    <div class="space-y-6">
        @if (auth()->user()->isAgent())
            @include('Assignment::partials.availability-form')
        @endif

        @include('profile.partials.update-profile-information-form')
        @include('profile.partials.update-password-form')
        @include('profile.partials.delete-user-form')
    </div>
</x-app-layout>
