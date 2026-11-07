@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'space-y-1 text-sm text-rose-600']) }}>
        @foreach ((array) $messages as $message)
            <li class="flex items-start gap-1.5">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ $message }}</span>
            </li>
        @endforeach
    </ul>
@endif
