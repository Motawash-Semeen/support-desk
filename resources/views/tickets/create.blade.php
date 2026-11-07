<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('New ticket') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                @csrf

                @include('tickets.partials.form', ['showPriority' => true, 'showCategory' => true])
                @include('tickets.partials.attachment-input')

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('tickets.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Cancel') }}</a>
                    <x-primary-button>{{ __('Submit ticket') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
