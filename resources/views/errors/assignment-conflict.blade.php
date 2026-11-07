<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Assignment conflict') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div role="alert" class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
                {{ $message }}
            </div>

            <div class="flex gap-4 text-sm">
                @can('view-unassigned-queue')
                    <a href="{{ route('staff.unassigned') }}" class="text-indigo-600 hover:underline">{{ __('Back to the unassigned queue') }}</a>
                @endcan
                @if ($ticketNumber)
                    <a href="{{ route('tickets.show', $ticketNumber) }}" class="text-indigo-600 hover:underline">{{ __('View :number', ['number' => $ticketNumber]) }}</a>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
