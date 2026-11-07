{{--
    Buttons appear only when the policy allows the ability AND the status map
    allows the move. Both are enforced again on the server.
--}}
@use('App\Enums\TicketStatus')
@php
    $isAdmin = auth()->user()->isAdmin();
    $status = $ticket->status;
    $possible = fn (TicketStatus $to): bool => $status->canTransitionTo($to, $isAdmin);

    // Working-status moves (dedicated buttons cover resolve / close / reopen).
    $workingMoves = $status->isActive()
        ? array_values(array_filter($status->allowedTransitions(), fn (TicketStatus $to) => $to->isActive()))
        : [];

    $actions = array_filter([
        'resolve' => auth()->user()->can('resolve', $ticket) && $possible(TicketStatus::Resolved) ? __('Resolve') : null,
        'close' => auth()->user()->can('close', $ticket) && $possible(TicketStatus::Closed) ? __('Close') : null,
        'reopen' => auth()->user()->can('reopen', $ticket) && $possible(TicketStatus::reopenTarget()) ? __('Reopen') : null,
    ]);
    $canChangeStatus = auth()->user()->can('changeStatus', $ticket) && $workingMoves !== [];
@endphp

@if ($actions !== [] || $canChangeStatus)
    <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <h3 class="font-semibold text-gray-800">{{ __('Actions') }}</h3>

        @if ($canChangeStatus)
            <form method="POST" action="{{ route('tickets.status', $ticket) }}" class="flex gap-2">
                @csrf
                <label for="status" class="sr-only">{{ __('Change status') }}</label>
                <select id="status" name="status" class="flex-1 border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach ($workingMoves as $to)
                        <option value="{{ $to->value }}">{{ $to->label() }}</option>
                    @endforeach
                </select>
                <x-secondary-button type="submit">{{ __('Set') }}</x-secondary-button>
            </form>
        @endif

        @foreach ($actions as $action => $label)
            <form method="POST" action="{{ route("tickets.{$action}", $ticket) }}" class="space-y-2">
                @csrf
                <label for="reason-{{ $action }}" class="sr-only">{{ __('Reason (optional)') }}</label>
                <input id="reason-{{ $action }}" name="reason" type="text" maxlength="1000" placeholder="{{ __('Reason (optional)') }}"
                       class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                @if ($action === 'reopen')
                    <x-secondary-button type="submit" class="w-full justify-center">{{ $label }}</x-secondary-button>
                @else
                    <x-primary-button class="w-full justify-center">{{ $label }}</x-primary-button>
                @endif
            </form>
        @endforeach
    </section>
@endif
