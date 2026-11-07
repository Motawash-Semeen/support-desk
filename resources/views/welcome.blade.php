<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('layouts.partials.head', ['pageTitle' => null])
    </head>
    <body class="bg-white font-sans text-ink-700">
        {{-- Header --}}
        <header class="absolute inset-x-0 top-0 z-20">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
                <x-brand :dark="true" />
                <nav class="flex items-center gap-2 text-sm">
                    <a href="#features" class="hidden rounded-lg px-3 py-2 font-medium text-ink-300 hover:text-white sm:inline-block">{{ __('Features') }}</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn btn-primary">{{ __('Open dashboard') }} <x-icon name="arrow-right" class="h-4 w-4" /></a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 font-medium text-ink-200 hover:text-white">{{ __('Log in') }}</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-primary">{{ __('Get started') }}</a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        {{-- Hero --}}
        <section class="relative isolate overflow-hidden bg-ink-950 pb-24 pt-32 sm:pt-40">
            <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                <div class="absolute left-1/2 top-0 h-[36rem] w-[64rem] -translate-x-1/2 rounded-full bg-brand-500/20 blur-3xl"></div>
                <svg class="absolute inset-0 h-full w-full opacity-[0.06]" xmlns="http://www.w3.org/2000/svg">
                    <defs><pattern id="hero-grid" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M40 0H0v40" fill="none" stroke="white" stroke-width="1"/></pattern></defs>
                    <rect width="100%" height="100%" fill="url(#hero-grid)" />
                </svg>
            </div>

            <div class="mx-auto max-w-6xl px-6 text-center">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1 text-xs font-medium text-brand-200 ring-1 ring-inset ring-white/10">
                    <x-icon name="sparkles" class="h-3.5 w-3.5" /> {{ __('Ticketing with SLAs that respect business hours') }}
                </span>
                <h1 class="mx-auto mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-white sm:text-6xl">
                    {{ __('Support that keeps') }} <span class="bg-gradient-to-r from-brand-300 to-sky-300 bg-clip-text text-transparent">{{ __('its promises') }}</span>
                </h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-ink-300">
                    {{ __('Customers open tickets, agents pick them up, and every deadline is tracked in real business hours, with escalation before anything slips.') }}
                </p>
                <div class="mt-10 flex flex-wrap items-center justify-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn btn-primary px-5 py-3 text-base">{{ __('Open dashboard') }} <x-icon name="arrow-right" class="h-4 w-4" /></a>
                    @else
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-primary px-5 py-3 text-base">{{ __('Open your first ticket') }} <x-icon name="arrow-right" class="h-4 w-4" /></a>
                        @endif
                        <a href="{{ route('login') }}" class="btn px-5 py-3 text-base text-white ring-1 ring-inset ring-white/20 hover:bg-white/5">{{ __('Log in') }}</a>
                    @endauth
                </div>
            </div>

            {{-- Product preview (static illustration) --}}
            <div class="mx-auto mt-16 max-w-5xl px-6" aria-hidden="true">
                <div class="overflow-hidden rounded-2xl bg-white/5 p-2 ring-1 ring-white/10 shadow-2xl">
                    <div class="overflow-hidden rounded-xl bg-white">
                        <div class="flex items-center gap-2 border-b border-ink-100 px-4 py-3">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                            <span class="ml-3 text-xs font-medium text-ink-400">{{ __('Tickets · Active') }}</span>
                        </div>
                        <div class="divide-y divide-ink-100 text-left text-sm">
                            @foreach ([
                                ['TKT-2026-000142', __('Card charged twice for November'), __('Breached'), 'red', __('Critical'), 'red', 'Aisha R.'],
                                ['TKT-2026-000139', __('VPN disconnects every hour'), __('At risk'), 'amber', __('High'), 'orange', 'Tom B.'],
                                ['TKT-2026-000137', __('Export to CSV times out'), __('Within SLA'), 'green', __('Medium'), 'blue', 'Lena K.'],
                                ['TKT-2026-000131', __('Add SSO for the finance team'), __('Within SLA'), 'green', __('Low'), 'gray', __('Unassigned')],
                            ] as [$no, $subject, $sla, $slaColor, $priority, $priorityColor, $agent])
                                <div class="grid grid-cols-12 items-center gap-3 px-4 py-3">
                                    <span class="col-span-3 sm:col-span-2"><span class="ticket-no">{{ $no }}</span></span>
                                    <span class="col-span-9 truncate font-medium text-ink-800 sm:col-span-5">{{ $subject }}</span>
                                    <span class="hidden sm:col-span-2 sm:block"><x-badge :color="$priorityColor">{{ $priority }}</x-badge></span>
                                    <span class="hidden sm:col-span-2 sm:block"><x-badge :color="$slaColor">{{ $sla }}</x-badge></span>
                                    <span class="hidden truncate text-ink-500 sm:col-span-1 sm:block">{{ $agent }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="mx-auto max-w-6xl px-6 py-24">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold text-brand-600">{{ __('Everything a support team needs') }}</p>
                <h2 class="mt-2 text-3xl font-semibold tracking-tight text-ink-900">{{ __('From first message to resolved, nothing gets lost') }}</h2>
            </div>
            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['clock', __('Business-hours SLAs'), __('Deadlines skip nights, weekends and holidays. A ticket opened at 5 PM on Thursday is due when your team is actually working.')],
                    ['bolt', __('Automatic escalation'), __('Warnings before a breach, escalation after it, and each one happens exactly once, never a flood of duplicate emails.')],
                    ['users', __('Fair assignment'), __('New tickets go to the least-loaded specialist. Two agents can never accept the same ticket.')],
                    ['eye-slash', __('Private internal notes'), __('Discuss a ticket with your team in the same thread. Customers never see a word of it.')],
                    ['chart', __('A dashboard that leads somewhere'), __('Open, unassigned, critical, at risk: every number links straight to the list behind it.')],
                    ['upload', __('Bring your history'), __('Import tens of thousands of tickets from CSV, with a row-by-row error report.')],
                ] as [$icon, $heading, $text])
                    <div class="card p-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100">
                            <x-icon :name="$icon" class="h-5 w-5" />
                        </span>
                        <h3 class="mt-4 text-base font-semibold text-ink-900">{{ $heading }}</h3>
                        <p class="mt-2 text-sm leading-6 text-ink-500">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- How it works --}}
        <section class="border-y border-ink-100 bg-ink-50">
            <div class="mx-auto grid max-w-6xl gap-10 px-6 py-20 md:grid-cols-3">
                @foreach ([
                    ['01', __('Customer opens a ticket'), __('With priority, category and attachments. A number like TKT-2026-000142 comes back instantly.')],
                    ['02', __('The right agent picks it up'), __('Assigned automatically by workload and speciality, or accepted from the queue.')],
                    ['03', __('Resolved within SLA'), __('Replies, notes and every status change are recorded in an audit trail no one can edit.')],
                ] as [$step, $heading, $text])
                    <div>
                        <span class="font-mono text-sm font-medium text-brand-600">{{ $step }}</span>
                        <h3 class="mt-2 text-lg font-semibold text-ink-900">{{ $heading }}</h3>
                        <p class="mt-2 text-sm leading-6 text-ink-500">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- CTA --}}
        <section class="mx-auto max-w-6xl px-6 py-24">
            <div class="relative overflow-hidden rounded-3xl bg-ink-950 px-8 py-14 text-center sm:px-16">
                <div class="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full bg-brand-500/30 blur-3xl" aria-hidden="true"></div>
                <h2 class="relative text-3xl font-semibold tracking-tight text-white">{{ __('Need help with something?') }}</h2>
                <p class="relative mx-auto mt-3 max-w-xl text-ink-300">{{ __('Sign in to follow your tickets, or create an account to open a new one.') }}</p>
                <div class="relative mt-8 flex flex-wrap justify-center gap-3">
                    @auth
                        <a href="{{ route('tickets.index') }}" class="btn btn-primary px-5 py-3">{{ __('Go to my tickets') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary px-5 py-3">{{ __('Log in') }}</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn px-5 py-3 text-white ring-1 ring-inset ring-white/20 hover:bg-white/5">{{ __('Create an account') }}</a>
                        @endif
                    @endauth
                </div>
            </div>
        </section>

        <footer class="border-t border-ink-100">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm text-ink-500">
                <x-brand />
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'Support Desk') }}</p>
            </div>
        </footer>
    </body>
</html>
