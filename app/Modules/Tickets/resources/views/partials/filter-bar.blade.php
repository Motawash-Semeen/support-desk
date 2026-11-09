@use('Illuminate\Support\Facades\Vite')
@use('App\Modules\Tickets\Enums\TicketPriority')
@use('App\Modules\Tickets\Enums\TicketStatus')
@use('App\Support\BusinessTime')
{{--
    Ticket list filters. A GET form, so every filtered view is a shareable URL
    (empty fields are left out on submit to keep it short).
    Staff-only fields are not rendered for customers, and are ignored
    server-side if sent anyway.

    Layout: scope tabs, then one toolbar (search, Filters toggle, sort), the
    applied filters as removable chips, and the filter panel.
--}}
@php
    $query = request()->query();
    $selected = fn (string $key) => (array) ($filters[$key] ?? []);
    $scopeKey = $scope ?? 'all';

    // URL of the current list with one filter (or one value of a multi-value filter) removed.
    $without = function (string $key, ?string $value = null) use ($query): string {
        if ($value !== null && is_array($query[$key] ?? null)) {
            $query[$key] = array_values(array_diff($query[$key], [$value]));
        }
        if ($value === null || ($query[$key] ?? []) === []) {
            unset($query[$key]);
        }
        unset($query['page']);

        return route('tickets.index', $query);
    };

    // Status choices that make sense for the current tab.
    $statuses = match ($scope) {
        'active' => TicketStatus::activeCases(),
        'closed' => [TicketStatus::Resolved, TicketStatus::Closed],
        default => TicketStatus::cases(),
    };

    $slaLabels = ['within_sla' => __('Within SLA'), 'approaching' => __('At risk'), 'breached' => __('Breached')];

    // Applied filters, shown as chips: [label, value, url without it].
    $chips = [];
    if (filled($filters['q'] ?? null)) {
        $chips[] = [__('Search'), '“'.$filters['q'].'”', $without('q')];
    }
    foreach ($selected('status') as $value) {
        $chips[] = [__('Status'), TicketStatus::tryFrom($value)?->label() ?? $value, $without('status', $value)];
    }
    foreach ($selected('priority') as $value) {
        $chips[] = [__('Priority'), TicketPriority::tryFrom($value)?->label() ?? $value, $without('priority', $value)];
    }
    if (filled($filters['category_id'] ?? null)) {
        $chips[] = [__('Category'), $categories->firstWhere('id', (int) $filters['category_id'])?->name ?? '—', $without('category_id')];
    }
    if (filled($filters['created_from'] ?? null)) {
        $chips[] = [__('Created from'), $filters['created_from'], $without('created_from')];
    }
    if (filled($filters['created_to'] ?? null)) {
        $chips[] = [__('Created until'), $filters['created_to'], $without('created_to')];
    }
    if (filled($filters['assigned_agent_id'] ?? null)) {
        $agentLabel = $filters['assigned_agent_id'] === 'unassigned'
            ? __('Unassigned')
            : ($agents->firstWhere('id', (int) $filters['assigned_agent_id'])?->name ?? '—');
        $chips[] = [__('Agent'), $agentLabel, $without('assigned_agent_id')];
    }
    if ($selectedCustomer) {
        $chips[] = [__('Customer'), $selectedCustomer->name, $without('customer')];
    }
    if (filled($filters['sla_status'] ?? null)) {
        $chips[] = [__('SLA'), $slaLabels[$filters['sla_status']] ?? $filters['sla_status'], $without('sla_status')];
    }
    if (! empty($filters['escalated'])) {
        $chips[] = [__('Escalated'), __('Yes'), $without('escalated')];
    }

    // Panel filters only (search has its own box), for the badge on the Filters button.
    $panelCount = count($chips) - (filled($filters['q'] ?? null) ? 1 : 0);

    $sortLabels = [
        'due_at' => __('Due date'),
        'created_at' => __('Created'),
        'updated_at' => __('Last update'),
        'priority' => __('Priority'),
    ];
    $directionLabels = [
        'due_at' => ['asc' => __('Soonest first'), 'desc' => __('Latest first')],
        'created_at' => ['asc' => __('Oldest first'), 'desc' => __('Newest first')],
        'updated_at' => ['asc' => __('Least recent first'), 'desc' => __('Most recent first')],
        'priority' => ['asc' => __('Lowest first'), 'desc' => __('Highest first')],
    ];

    $chipClass = 'inline-flex cursor-pointer select-none items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-ink-600 ring-1 ring-inset ring-ink-200 transition hover:bg-ink-50 peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500';
@endphp

{{-- Scope tabs: switching keeps search and filters (except statuses, which belong to a tab). --}}
<nav class="mb-4 flex gap-1 overflow-x-auto rounded-xl bg-ink-100 p-1 text-sm sm:inline-flex" aria-label="{{ __('Ticket scope') }}">
    @foreach (['active' => __('Active'), 'closed' => __('Resolved & closed'), 'all' => __('All')] as $key => $label)
        <a href="{{ route('tickets.index', array_merge(\Illuminate\Support\Arr::except($query, ['scope', 'status', 'page']), ['scope' => $key])) }}"
           @class([
               'flex-1 whitespace-nowrap rounded-lg px-4 py-1.5 text-center font-medium transition sm:flex-none',
               'bg-white text-ink-900 shadow-sm' => $scopeKey === $key,
               'text-ink-500 hover:text-ink-800' => $scopeKey !== $key,
           ])
           @if ($scopeKey === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

<form method="GET" action="{{ route('tickets.index') }}" class="card mb-4" role="search" aria-label="{{ __('Filter tickets') }}"
      x-data="{ open: {{ $panelCount > 0 ? 'true' : 'false' }}, direction: @js($direction) }"
      @submit="[...$el.elements].forEach(field => { if (field.name && field.value === '') field.disabled = true })">
    <input type="hidden" name="scope" value="{{ $scopeKey }}">

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 p-3 lg:flex-row lg:items-center">
        <div class="relative min-w-0 flex-1">
            <label for="q" class="sr-only">{{ __('Search') }}</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="200"
                   placeholder="{{ __('Search subject, description or ticket number') }}" class="field-input pl-9">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="ticket-filters"
                    class="btn btn-secondary" :class="open && 'ring-brand-300 bg-brand-50 text-brand-700'">
                <x-icon name="funnel" class="h-4 w-4" />
                {{ __('Filters') }}
                @if ($panelCount > 0)
                    <span class="rounded-full bg-brand-600 px-1.5 text-xs font-semibold leading-5 text-white">{{ $panelCount }}</span>
                @endif
            </button>

            <div class="flex items-center gap-2">
                <label for="sort" class="text-sm text-ink-500">{{ __('Sort') }}</label>
                <select id="sort" name="sort" class="field-input w-auto py-2 pr-8" @change="$el.form.requestSubmit()">
                    @foreach ($sortLabels as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="direction" :value="direction" value="{{ $direction }}">
                <button type="button" class="btn btn-secondary whitespace-nowrap"
                        @click="direction = direction === 'asc' ? 'desc' : 'asc'; $nextTick(() => $el.form.requestSubmit())"
                        title="{{ __('Reverse the order') }}">
                    <x-icon name="chevron-up-down" class="h-4 w-4" />
                    {{ $directionLabels[$sort][$direction] ?? ucfirst($direction) }}
                </button>
            </div>

            <button type="submit" class="btn btn-primary">{{ __('Search') }}</button>
        </div>
    </div>

    {{-- Applied filters --}}
    @if ($chips !== [])
        <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 px-3 py-2.5">
            <span class="text-xs font-medium text-ink-500">{{ __('Applied:') }}</span>
            @foreach ($chips as [$label, $value, $url])
                <a href="{{ $url }}" class="group inline-flex items-center gap-1.5 rounded-full bg-brand-50 py-1 pl-3 pr-2 text-xs text-brand-800 ring-1 ring-inset ring-brand-200 hover:bg-brand-100"
                   title="{{ __('Remove this filter') }}">
                    <span class="text-brand-600">{{ $label }}:</span>
                    <span class="font-medium">{{ $value }}</span>
                    <x-icon name="x" class="h-3.5 w-3.5 text-brand-500 group-hover:text-brand-800" />
                    <span class="sr-only">{{ __('Remove') }}</span>
                </a>
            @endforeach
            <a href="{{ route('tickets.index', ['scope' => $scopeKey]) }}" class="ml-1 text-xs font-medium text-ink-500 underline-offset-2 hover:text-ink-800 hover:underline">{{ __('Clear all') }}</a>
        </div>
    @endif

    {{-- Filter panel --}}
    <div id="ticket-filters" x-show="open" x-cloak class="border-t border-ink-100 bg-ink-50/60">
        <div class="grid gap-x-6 gap-y-5 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <fieldset class="min-w-0">
                <legend class="field-label">{{ __('Status') }}</legend>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($statuses as $status)
                        <label>
                            <input type="checkbox" name="status[]" value="{{ $status->value }}" @checked(in_array($status->value, $selected('status'), true)) class="peer sr-only">
                            <span class="{{ $chipClass }}">{{ $status->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="min-w-0">
                <legend class="field-label">{{ __('Priority') }}</legend>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach (TicketPriority::cases() as $priority)
                        <label>
                            <input type="checkbox" name="priority[]" value="{{ $priority->value }}" @checked(in_array($priority->value, $selected('priority'), true)) class="peer sr-only">
                            <span class="{{ $chipClass }}">{{ $priority->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="min-w-0">
                <label for="category_id" class="field-label">{{ __('Category') }}</label>
                <select id="category_id" name="category_id" class="field-input mt-1.5">
                    <option value="">{{ __('Any category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            @staff
                {{-- Agents only ever list their own tickets, so the agent filter is for admins. --}}
                @if (auth()->user()->isAdmin())
                <div class="min-w-0">
                    <label for="assigned_agent_id" class="field-label">{{ __('Agent') }}</label>
                    <select id="assigned_agent_id" name="assigned_agent_id" class="field-input mt-1.5">
                        <option value="">{{ __('Anyone') }}</option>
                        <option value="unassigned" @selected(($filters['assigned_agent_id'] ?? '') === 'unassigned')>{{ __('Unassigned') }}</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((string) ($filters['assigned_agent_id'] ?? '') === (string) $agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                {{-- Customer autocomplete: searches by name/email, submits the customer id. --}}
                <div x-data="customerPicker(@js($selectedCustomer?->only(['id', 'name', 'email'])))" class="relative min-w-0" @click.outside="results = []">
                    <label for="customer-search" class="field-label">{{ __('Customer') }}</label>
                    <input type="hidden" name="customer" :value="selected ? selected.id : ''">
                    <input id="customer-search" type="text" autocomplete="off" x-model="term" @input.debounce.300ms="search()"
                           placeholder="{{ __('Type a name or email') }}" class="field-input mt-1.5" role="combobox" :aria-expanded="results.length > 0">
                    <ul x-show="results.length" x-cloak class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg bg-white text-sm shadow-lift ring-1 ring-ink-200" role="listbox">
                        <template x-for="customer in results" :key="customer.id">
                            <li role="option">
                                <button type="button" class="block w-full px-3 py-2 text-left hover:bg-ink-50" @click="choose(customer)">
                                    <span class="font-medium text-ink-800" x-text="customer.name"></span>
                                    <span class="block text-xs text-ink-500" x-text="customer.email"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>

                <fieldset class="min-w-0">
                    <legend class="field-label">{{ __('SLA') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <label>
                            <input type="radio" name="sla_status" value="" @checked(blank($filters['sla_status'] ?? null)) class="peer sr-only">
                            <span class="{{ $chipClass }}">{{ __('Any') }}</span>
                        </label>
                        @foreach ($slaLabels as $key => $label)
                            <label>
                                <input type="radio" name="sla_status" value="{{ $key }}" @checked(($filters['sla_status'] ?? '') === $key) class="peer sr-only">
                                <span class="{{ $chipClass }}">{{ $label }}</span>
                            </label>
                        @endforeach
                        <label>
                            <input type="checkbox" name="escalated" value="1" @checked(! empty($filters['escalated'])) class="peer sr-only">
                            <span class="{{ $chipClass }}">{{ __('Escalated only') }}</span>
                        </label>
                    </div>
                </fieldset>
            @endstaff

            <fieldset class="min-w-0 sm:col-span-2 lg:col-span-1">
                <legend class="field-label">{{ __('Created between') }} <span class="font-normal text-ink-400">({{ BusinessTime::zone() }})</span></legend>
                <div class="mt-1.5 grid grid-cols-2 gap-2">
                    <div class="min-w-0">
                        <label class="sr-only" for="created_from">{{ __('From') }}</label>
                        <input id="created_from" name="created_from" type="date" value="{{ $filters['created_from'] ?? '' }}" class="field-input w-full min-w-0">
                    </div>
                    <div class="min-w-0">
                        <label class="sr-only" for="created_to">{{ __('To') }}</label>
                        <input id="created_to" name="created_to" type="date" value="{{ $filters['created_to'] ?? '' }}" class="field-input w-full min-w-0">
                    </div>
                </div>
            </fieldset>
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-ink-100 px-4 py-3">
            <a href="{{ route('tickets.index', ['scope' => $scopeKey]) }}" class="btn btn-ghost">{{ __('Reset') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Apply filters') }}</button>
        </div>
    </div>
</form>

@staff
    <script nonce="{{ Vite::cspNonce() }}">
        function customerPicker(initial) {
            return {
                selected: initial,
                term: initial ? initial.name : '',
                results: [],
                async search() {
                    this.selected = null;
                    if (this.term.trim().length < 2) { this.results = []; return; }
                    const url = new URL(@js(route('staff.customers.search')), window.location.origin);
                    url.searchParams.set('q', this.term.trim());
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    this.results = response.ok ? await response.json() : [];
                },
                choose(customer) {
                    this.selected = customer;
                    this.term = customer.name;
                    this.results = [];
                },
            };
        }
    </script>
@endstaff
