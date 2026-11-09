{{--
    Buttons appear only when the policy allows the ability AND the status map
    allows the move. Both are enforced again on the server.
--}}
@use('App\Modules\Tickets\Enums\TicketStatus')
@php
    $isAdmin = auth()->user()->isAdmin();
    $status = $ticket->status;
    $possible = fn (TicketStatus $to): bool => $status->canTransitionTo($to, $isAdmin);

    // Working-status moves (dedicated buttons cover resolve / close / reopen).
    $workingMoves = $status->isActive()
        ? array_values(array_filter($status->allowedTransitions(), fn (TicketStatus $to) => $to->isActive()))
        : [];

    // One button per finishing move; each posts its target status to tickets.status.
    $actions = array_filter([
        'resolve' => auth()->user()->can('work', $ticket) && $possible(TicketStatus::Resolved) ? [__('Resolve'), TicketStatus::Resolved] : null,
        'close' => auth()->user()->can('close', $ticket) && $possible(TicketStatus::Closed) ? [__('Close'), TicketStatus::Closed] : null,
        'reopen' => auth()->user()->can('reopen', $ticket) && $possible(TicketStatus::reopenTarget()) ? [__('Reopen'), TicketStatus::reopenTarget()] : null,
    ]);
    $canChangeStatus = auth()->user()->can('work', $ticket) && $workingMoves !== [];
    $icons = ['resolve' => 'check-circle', 'close' => 'lock', 'reopen' => 'refresh'];
@endphp

@if ($actions !== [] || $canChangeStatus)
    <x-card :title="__('Actions')">
        <div class="space-y-4">
            @if ($canChangeStatus)
                <form method="POST" action="{{ route('tickets.status', $ticket) }}" class="space-y-1.5">
                    @csrf
                    <label for="status" class="field-label">{{ __('Change status') }}</label>
                    <div class="flex gap-2">
                        <select id="status" name="status" class="field-input flex-1">
                            @foreach ($workingMoves as $to)
                                <option value="{{ $to->value }}">{{ $to->label() }}</option>
                            @endforeach
                        </select>
                        <x-secondary-button type="submit">{{ __('Set') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            @foreach ($actions as $action => [$label, $target])
                <form method="POST" action="{{ route('tickets.status', $ticket) }}" class="space-y-2" x-data="{ reason: false }">
                    @csrf
                    <input type="hidden" name="status" value="{{ $target->value }}">
                    <div x-show="reason" x-cloak>
                        <label for="reason-{{ $action }}" class="sr-only">{{ __('Reason (optional)') }}</label>
                        <input id="reason-{{ $action }}" name="reason" type="text" maxlength="1000" placeholder="{{ __('Reason (optional)') }}" class="field-input">
                    </div>
                    <div class="flex gap-2">
                        @if ($action === 'reopen')
                            <x-secondary-button type="submit" class="flex-1"><x-icon :name="$icons[$action]" class="h-4 w-4" /> {{ $label }}</x-secondary-button>
                        @else
                            <x-primary-button class="flex-1"><x-icon :name="$icons[$action]" class="h-4 w-4" /> {{ $label }}</x-primary-button>
                        @endif
                        <button type="button" class="btn btn-ghost px-2.5" @click="reason = ! reason" :aria-expanded="reason.toString()" title="{{ __('Add a reason') }}">
                            <x-icon name="pencil" class="h-4 w-4" />
                            <span class="sr-only">{{ __('Add a reason') }}</span>
                        </button>
                    </div>
                </form>
            @endforeach
        </div>
    </x-card>
@endif
