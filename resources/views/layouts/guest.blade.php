<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-white">
    <head>
        @include('layouts.partials.head', ['pageTitle' => $title])
    </head>
    <body class="h-full font-sans">
        <div class="flex min-h-full">
            {{-- Brand panel --}}
            <aside class="relative hidden w-[44%] max-w-xl overflow-hidden bg-ink-950 lg:flex lg:flex-col lg:justify-between lg:p-12">
                <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                    <div class="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-brand-500/25 blur-3xl"></div>
                    <div class="absolute -bottom-32 right-0 h-[28rem] w-[28rem] rounded-full bg-sky-500/10 blur-3xl"></div>
                    <svg class="absolute inset-0 h-full w-full opacity-[0.07]" xmlns="http://www.w3.org/2000/svg">
                        <defs><pattern id="grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0v32" fill="none" stroke="white" stroke-width="1"/></pattern></defs>
                        <rect width="100%" height="100%" fill="url(#grid)" />
                    </svg>
                </div>

                <a href="{{ url('/') }}" class="relative"><x-brand :dark="true" /></a>

                <div class="relative">
                    <p class="text-3xl font-semibold leading-tight tracking-tight text-white">
                        {{ __('Every ticket answered,') }}<br>
                        <span class="text-brand-300">{{ __('every deadline kept.') }}</span>
                    </p>
                    <ul class="mt-8 space-y-4 text-sm text-ink-300">
                        @foreach ([
                            ['clock', __('SLA deadlines counted in business hours, with automatic escalation')],
                            ['users', __('Fair auto-assignment that never gives a ticket to two agents')],
                            ['lock', __('Private internal notes and secure attachments')],
                        ] as [$icon, $text])
                            <li class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/5 text-brand-300 ring-1 ring-white/10">
                                    <x-icon :name="$icon" class="h-4 w-4" />
                                </span>
                                <span class="pt-1.5">{{ $text }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative text-xs text-ink-500">&copy; {{ date('Y') }} {{ config('app.name', 'Support Desk') }}</p>
            </aside>

            {{-- Form --}}
            <main class="flex flex-1 flex-col justify-center px-6 py-12 sm:px-12">
                <div class="mx-auto w-full max-w-sm">
                    <a href="{{ url('/') }}" class="mb-10 inline-block lg:hidden"><x-brand /></a>

                    @if ($title)
                        <h1 class="text-2xl font-semibold tracking-tight text-ink-900">{{ $title }}</h1>
                    @endif
                    @if ($subtitle)
                        <p class="mt-2 text-sm text-ink-500">{{ $subtitle }}</p>
                    @endif

                    <div class="mt-8">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
