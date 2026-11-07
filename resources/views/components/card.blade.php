{{--
    Surface with an optional header. <x-card title="..." description="..."> ... </x-card>
    Slots: actions (header right side), footer. padded=false for tables/lists.
--}}
@props(['title' => null, 'description' => null, 'padded' => true, 'as' => 'section'])

<{{ $as }} {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <header class="card-header">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="card-title">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-ink-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['card-body' => $padded])>
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="flex items-center justify-end gap-3 rounded-b-xl border-t border-ink-100 bg-ink-50/60 px-5 py-3">
            {{ $footer }}
        </footer>
    @endisset
</{{ $as }}>
