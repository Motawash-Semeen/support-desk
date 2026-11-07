@props(['ticket'])

@if ($status = $ticket->slaStatus())
    <x-badge :color="$status->color()">{{ __($status->label()) }}</x-badge>
@endif
