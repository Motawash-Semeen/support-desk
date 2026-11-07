@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex items-start gap-2 rounded-lg bg-brand-50 px-3 py-2.5 text-sm text-brand-800 ring-1 ring-inset ring-brand-200']) }} role="status">
        <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
        <span>{{ $status }}</span>
    </div>
@endif
