@props(['align' => 'right', 'width' => '56', 'direction' => 'down'])

@php
    $alignment = match ($align) {
        'left' => 'left-0 origin-top-left',
        'full' => 'inset-x-0',
        default => 'right-0 origin-top-right',
    };
    $position = $direction === 'up' ? 'bottom-full mb-2' : 'top-full mt-2';
    $widthClass = match ($width) {
        '48' => 'w-48',
        'full' => '',
        default => 'w-56',
    };
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 {{ $position }} {{ $widthClass }} {{ $alignment }} rounded-xl bg-white p-1.5 shadow-lift ring-1 ring-ink-200"
         @click="open = false">
        {{ $content }}
    </div>
</div>
