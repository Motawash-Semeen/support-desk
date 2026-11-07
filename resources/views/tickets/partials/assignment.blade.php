{{--
    Assignment controls. Visibility mirrors the policy; the server decides
    again, and a lost race comes back as a 409 conflict page.
--}}
@php
    $user = auth()->user();
    $canAccept = $ticket->assigned_agent_id === null && $user->can('accept', $ticket);
    $canAssign = $user->can('assign', $ticket) && $agents->isNotEmpty();
    $canRelease = $user->can('release', $ticket);
@endphp

@if ($canAccept || $canAssign || $canRelease)
    <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <h3 class="font-semibold text-gray-800">{{ __('Assignment') }}</h3>

        @if ($canAccept)
            <form method="POST" action="{{ route('tickets.accept', $ticket) }}">
                @csrf
                <x-primary-button class="w-full justify-center">{{ __('Accept ticket') }}</x-primary-button>
            </form>
        @endif

        @if ($canAssign)
            <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="space-y-2">
                @csrf
                {{-- The assignee this page was rendered with; guards against concurrent changes. --}}
                <input type="hidden" name="expected_agent_id" value="{{ $ticket->assigned_agent_id }}">
                <label for="agent_id" class="block text-sm text-gray-600">
                    {{ $ticket->assigned_agent_id ? __('Reassign to') : __('Assign to') }}
                </label>
                <div class="flex gap-2">
                    <select id="agent_id" name="agent_id" class="flex-1 border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
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
                <x-secondary-button type="submit" class="w-full justify-center">{{ __('Release to queue') }}</x-secondary-button>
            </form>
        @endif
    </section>
@endif
