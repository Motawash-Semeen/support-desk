@props(['ticket'])

@if ($status = $ticket->slaStatus())
    <x-badge :color="$status->color()" {{ $attributes }}>{{ __($status->label()) }}</x-badge>
@endif
