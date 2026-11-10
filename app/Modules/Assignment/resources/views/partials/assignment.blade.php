{{-- Assignment controls; the server checks every action again. --}}
@php
    $user = auth()->user();
    $canAccept = $ticket->assigned_agent_id === null && $user->can('accept', $ticket);
    $canAssign = $user->can('assign', $ticket) && $agents->isNotEmpty();
    $canRelease = $user->can('release', $ticket);
@endphp

@if ($canAccept || $canAssign || $canRelease)
    <x-card :title="__('Assignment')">
        <div class="space-y-3">
            @if ($canAccept)
                <form method="POST" action="{{ route('tickets.accept', $ticket) }}">
                    @csrf
                    <x-primary-button class="w-full"><x-icon name="check-circle" class="h-4 w-4" /> {{ __('Accept ticket') }}</x-primary-button>
                </form>
            @endif

            @if ($canAssign)
                <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="space-y-1.5">
                    @csrf
                    {{-- The assignee shown on this page, to detect concurrent changes. --}}
                    <input type="hidden" name="expected_agent_id" value="{{ $ticket->assigned_agent_id }}">
                    <label for="agent_id" class="field-label">
                        {{ $ticket->assigned_agent_id ? __('Reassign to') : __('Assign to') }}
                    </label>
                    <div class="flex gap-2">
                        <select id="agent_id" name="agent_id" class="field-input flex-1">
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}" @selected($agent->id === $ticket->assigned_agent_id)>{{ $agent->name }}</option>
                            @endforeach
                        </select>
                        <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
                    </div>
                    <x-input-error :messages="$errors->get('agent_id')" />
                </form>
            @endif

            @if ($canRelease)
                <form method="POST" action="{{ route('tickets.release', $ticket) }}">
                    @csrf
                    <x-secondary-button type="submit" class="w-full"><x-icon name="inbox" class="h-4 w-4" /> {{ __('Release to queue') }}</x-secondary-button>
                </form>
            @endif
        </div>
    </x-card>
@endif
