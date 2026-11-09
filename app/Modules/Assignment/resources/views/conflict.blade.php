<x-app-layout :title="__('Assignment conflict')" width="narrow">
    <div class="card p-8 text-center">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-200">
            <x-icon name="users" class="h-6 w-6" />
        </span>
        <p role="alert" class="mx-auto mt-4 max-w-md text-base font-medium text-ink-900">{{ $message }}</p>
        <p class="mt-2 text-sm text-ink-500">{{ __('Someone else changed this ticket at the same moment. Nothing was overwritten.') }}</p>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            @staff
                <a href="{{ route('staff.unassigned') }}" class="btn btn-secondary"><x-icon name="inbox" class="h-4 w-4" /> {{ __('Back to the unassigned queue') }}</a>
            @endcan
            @if ($ticketNumber)
                <a href="{{ route('tickets.show', $ticketNumber) }}" class="btn btn-primary">{{ __('View :number', ['number' => $ticketNumber]) }}</a>
            @endif
        </div>
    </div>
</x-app-layout>
