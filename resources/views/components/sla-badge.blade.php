@props(['ticket'])

@switch($ticket->slaState())
    @case('breached')
        <x-badge color="red">{{ __('SLA breached') }}</x-badge>
        @break
    @case('at_risk')
        <x-badge color="amber">{{ __('At risk') }}</x-badge>
        @break
    @case('on_track')
        <x-badge color="green">{{ __('On track') }}</x-badge>
        @break
@endswitch
