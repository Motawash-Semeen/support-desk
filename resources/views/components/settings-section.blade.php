{{-- Settings row: description on the left, form card on the right. --}}
@props(['title', 'description' => null, 'danger' => false])

<section {{ $attributes->merge(['class' => 'grid gap-4 md:grid-cols-3 md:gap-8']) }}>
    <header class="md:pt-1">
        <h2 @class(['text-base font-semibold', 'text-rose-700' => $danger, 'text-ink-900' => ! $danger])>{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-ink-500">{{ $description }}</p>
        @endif
    </header>
    <div @class(['card md:col-span-2', 'ring-rose-200' => $danger])>
        {{ $slot }}
    </div>
</section>
