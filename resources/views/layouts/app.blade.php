<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('layouts.partials.head', ['pageTitle' => $title])
    </head>
    <body class="h-full font-sans" x-data="{ sidebar: false }" @keydown.escape.window="sidebar = false">
        <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow-lift">
            {{ __('Skip to content') }}
        </a>

        {{-- Mobile drawer --}}
        <div x-show="sidebar" x-cloak class="relative z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="{{ __('Navigation') }}">
            <div x-show="sidebar" x-transition.opacity class="fixed inset-0 bg-ink-950/60 backdrop-blur-sm" @click="sidebar = false"></div>
            <div x-show="sidebar"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
                 class="fixed inset-y-0 left-0 flex w-72">
                @include('layouts.navigation')
                <button type="button" class="absolute -right-12 top-4 rounded-lg p-2 text-white hover:bg-white/10" @click="sidebar = false">
                    <span class="sr-only">{{ __('Close navigation') }}</span>
                    <x-icon name="x" class="h-6 w-6" />
                </button>
            </div>
        </div>

        {{-- Desktop sidebar --}}
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64">
            @include('layouts.navigation')
        </div>

        <div class="lg:pl-64">
            {{-- Mobile top bar --}}
            <div class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-ink-200 bg-white/90 px-4 backdrop-blur lg:hidden">
                <button type="button" class="-ml-1 rounded-lg p-2 text-ink-600 hover:bg-ink-100" @click="sidebar = true">
                    <span class="sr-only">{{ __('Open navigation') }}</span>
                    <x-icon name="menu" class="h-6 w-6" />
                </button>
                <a href="{{ route('dashboard') }}"><x-brand /></a>
            </div>

            <main id="content" class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div @class(['mx-auto', 'max-w-7xl' => $width === 'wide', 'max-w-3xl' => $width === 'narrow', 'max-w-5xl' => $width === 'medium'])>
                    {{-- Page header --}}
                    @isset($header)
                        <div class="mb-6">{{ $header }}</div>
                    @elseif ($title)
                        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                            <div class="min-w-0">
                                <h1 class="text-2xl font-semibold tracking-tight text-ink-900">{{ $title }}</h1>
                                @if ($subtitle)
                                    <p class="mt-1 text-sm text-ink-500">{{ $subtitle }}</p>
                                @endif
                            </div>
                            @isset($actions)
                                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                            @endisset
                        </div>
                    @endisset

                    {{-- Flash messages --}}
                    @if (session('status') || $errors->has('general') || $errors->has('status'))
                        <div class="mb-6 space-y-2">
                            @if (session('status'))
                                <div role="status" class="flex items-start gap-2.5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-200">
                                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{{ session('status') }}</span>
                                </div>
                            @endif
                            @foreach (['general', 'status'] as $key)
                                @error($key)
                                    <div role="alert" class="flex items-start gap-2.5 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-inset ring-rose-200">
                                        <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{{ $message }}</span>
                                    </div>
                                @enderror
                            @endforeach
                        </div>
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
