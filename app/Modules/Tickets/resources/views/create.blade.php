<x-app-layout :title="__('New ticket')" :subtitle="__('Tell us what is going on. You will get a ticket number and email updates on every reply.')" width="narrow">
    <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data">
        @csrf

        <x-card>
            <div class="space-y-5">
                @include('Tickets::partials.form', ['showPriority' => true, 'showCategory' => true])
                @include('Conversation::partials.attachment-input')
            </div>

            <x-slot name="footer">
                <a href="{{ route('tickets.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Submit ticket') }}</x-primary-button>
            </x-slot>
        </x-card>
    </form>
</x-app-layout>
