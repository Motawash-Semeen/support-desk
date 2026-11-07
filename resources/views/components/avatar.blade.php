{{-- Initials avatar; the colour is derived from the name so it stays stable. --}}
@props(['name' => '?', 'size' => 'md'])

@php
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
    $palette = ['bg-brand-100 text-brand-800', 'bg-sky-100 text-sky-800', 'bg-violet-100 text-violet-800', 'bg-amber-100 text-amber-800', 'bg-rose-100 text-rose-800', 'bg-emerald-100 text-emerald-800'];
    $tone = $palette[abs(crc32($name)) % count($palette)];
    $sizeClass = match ($size) {
        'sm' => 'h-7 w-7 text-[11px]',
        'lg' => 'h-10 w-10 text-sm',
        default => 'h-8 w-8 text-xs',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-full font-semibold {$sizeClass} {$tone}"]) }} aria-hidden="true">{{ $initials }}</span>
