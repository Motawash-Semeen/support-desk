@props(['dark' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <x-application-logo class="h-8 w-8 shrink-0" />
    <span @class(['text-[15px] font-semibold tracking-tight', 'text-white' => $dark, 'text-ink-900' => ! $dark])>
        Support<span @class(['font-normal', 'text-brand-300' => $dark, 'text-brand-600' => ! $dark])>Desk</span>
    </span>
</span>
