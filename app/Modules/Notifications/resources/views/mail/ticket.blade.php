<x-mail::message>
# {{ $heading }}

Hello {{ $name }},

{{ $message }}

@foreach ($details as $line)
> {{ $line }}

@endforeach
<x-mail::button :url="$url">
View {{ $ticket->ticket_number }}
</x-mail::button>

**{{ $ticket->ticket_number }}** · {{ $ticket->subject }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
