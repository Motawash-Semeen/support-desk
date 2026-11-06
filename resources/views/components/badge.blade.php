@props(['color' => 'gray'])

@php
    // Full class names listed literally so Tailwind's scanner compiles them.
    $classes = match ($color) {
        'blue' => 'bg-blue-100 text-blue-800',
        'indigo' => 'bg-indigo-100 text-indigo-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'green' => 'bg-green-100 text-green-800',
        'orange' => 'bg-orange-100 text-orange-800',
        'red' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $slot }}
</span>
