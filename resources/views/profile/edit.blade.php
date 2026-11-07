<x-app-layout :title="__('Profile')" :subtitle="__('Your account details, password and availability.')" width="medium">
    <div class="space-y-6">
        @can('update-availability')
            @include('profile.partials.update-availability-form')
        @endcan

        @include('profile.partials.update-profile-information-form')
        @include('profile.partials.update-password-form')
        @include('profile.partials.delete-user-form')
    </div>
</x-app-layout>
