{{-- Soft pill with a status dot. Colours come from the enums (TicketStatus::color() etc.). --}}
@props(['color' => 'gray', 'dot' => true])

@php
    // Full class names listed literally so Tailwind's scanner compiles them.
    [$pill, $dotClass] = match ($color) {
        'blue' => ['bg-sky-50 text-sky-700 ring-sky-600/20', 'bg-sky-500'],
        'indigo' => ['bg-indigo-50 text-indigo-700 ring-indigo-600/20', 'bg-indigo-500'],
        'amber' => ['bg-amber-50 text-amber-800 ring-amber-600/25', 'bg-amber-500'],
        'green' => ['bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'bg-emerald-500'],
        'orange' => ['bg-orange-50 text-orange-700 ring-orange-600/20', 'bg-orange-500'],
        'red' => ['bg-rose-50 text-rose-700 ring-rose-600/20', 'bg-rose-500'],
        'brand' => ['bg-brand-50 text-brand-700 ring-brand-600/20', 'bg-brand-500'],
        default => ['bg-ink-100 text-ink-600 ring-ink-500/15', 'bg-ink-400'],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$pill}"]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
