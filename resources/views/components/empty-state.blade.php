@props(['icon' => 'inbox', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-ink-100 text-ink-500">
        <x-icon :name="$icon" class="h-5 w-5" />
    </span>
    <p class="mt-3 text-sm font-semibold text-ink-800">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-500">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
