{{--
    Shared layout for HTTP error pages (403, 404, 419, 429, 500, 503).
    Standalone: no session or auth data is required to render it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('layouts.partials.head', ['pageTitle' => trim($__env->yieldContent('title'))])
    </head>
    <body class="flex h-full flex-col bg-ink-50 font-sans">
        <header class="px-6 py-5">
            <a href="{{ url('/') }}"><x-brand /></a>
        </header>

        <main class="flex flex-1 items-center justify-center px-6 pb-24">
            <div class="w-full max-w-md text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-brand-600 shadow-card ring-1 ring-ink-200">
                    <x-icon :name="trim($__env->yieldContent('icon', 'alert'))" class="h-7 w-7" />
                </span>
                <p class="mt-6 font-mono text-sm font-medium text-brand-600">@yield('code')</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-900">@yield('title')</h1>
                <p class="mt-3 text-base text-ink-500">@yield('message')</p>

                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn btn-secondary">
                        <x-icon name="arrow-left" class="h-4 w-4" /> {{ __('Go back') }}
                    </a>
                    <a href="{{ url('/dashboard') }}" class="btn btn-primary">{{ __('Go to dashboard') }}</a>
                </div>
            </div>
        </main>
    </body>
</html>
