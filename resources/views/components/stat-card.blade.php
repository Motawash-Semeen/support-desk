{{-- Dashboard number that links to the matching filtered list. --}}
@props(['label', 'value', 'href', 'icon' => 'ticket', 'tone' => 'ink'])

@php
    $active = $value > 0;
    [$iconWrap, $valueClass] = match (true) {
        ! $active => ['bg-ink-100 text-ink-500', 'text-ink-900'],
        $tone === 'red' => ['bg-rose-50 text-rose-600', 'text-rose-600'],
        $tone === 'amber' => ['bg-amber-50 text-amber-600', 'text-amber-600'],
        $tone === 'green' => ['bg-emerald-50 text-emerald-600', 'text-ink-900'],
        default => ['bg-brand-50 text-brand-600', 'text-ink-900'],
    };
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => 'card group flex items-start justify-between gap-3 p-4 transition hover:-translate-y-0.5 hover:shadow-lift focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500']) }}>
    <div>
        <p class="text-sm font-medium text-ink-500">{{ $label }}</p>
        <p class="mt-1.5 text-3xl font-semibold tracking-tight tabular-nums {{ $valueClass }}">{{ number_format($value) }}</p>
    </div>
    <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $iconWrap }}">
        <x-icon :name="$icon" class="h-5 w-5" />
    </span>
</a>
