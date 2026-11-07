{{-- Sidebar navigation item. --}}
@props(['active' => false, 'icon' => 'document', 'count' => null])

<a {{ $attributes->merge(['class' => $active
        ? 'group flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white'
        : 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-ink-300 hover:bg-white/5 hover:text-white']) }}
   @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" @class(['h-5 w-5 shrink-0', 'text-brand-300' => $active, 'text-ink-400 group-hover:text-ink-200' => ! $active]) />
    <span class="flex-1 truncate">{{ $slot }}</span>
    @if ($count)
        <span class="rounded-full bg-brand-500/20 px-2 py-0.5 text-xs font-semibold text-brand-200">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
