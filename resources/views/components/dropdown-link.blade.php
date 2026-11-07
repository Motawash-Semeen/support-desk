@props(['icon' => null])

<a {{ $attributes->merge(['class' => 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-ink-700 hover:bg-ink-50 hover:text-ink-900 focus:bg-ink-50 focus:outline-none']) }}>
    @if ($icon)
        <x-icon :name="$icon" class="h-4 w-4 text-ink-400" />
    @endif
    {{ $slot }}
</a>
