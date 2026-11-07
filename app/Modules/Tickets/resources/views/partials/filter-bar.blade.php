@use('Illuminate\Support\Facades\Vite')
{{--
    GET form, so every filtered view is a shareable URL. Staff-only fields are
    not rendered for customers, and are ignored server-side if sent anyway.
--}}
@php
    $selected = fn (string $key) => (array) ($filters[$key] ?? []);
    $advancedKeys = ['status', 'priority', 'category_id', 'created_from', 'created_to', 'assigned_agent_id', 'customer', 'sla_status', 'escalated'];
    $activeFilters = collect($filters)->only($advancedKeys)->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->count();
@endphp

<form method="GET" action="{{ route('tickets.index') }}" class="card mb-4" role="search" aria-label="{{ __('Filter tickets') }}"
      x-data="{ more: {{ $activeFilters > 0 ? 'true' : 'false' }} }">
    <div class="flex flex-wrap items-center gap-2 p-3">
        <div class="relative min-w-[16rem] flex-1">
            <label for="q" class="sr-only">{{ __('Search') }}</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="200"
                   placeholder="{{ __('Search keywords or a ticket number, e.g. TKT-2026-000042') }}" class="field-input pl-9">
        </div>

        <div class="flex items-center gap-2">
            <label class="sr-only" for="scope">{{ __('Show') }}</label>
            <select id="scope" name="scope" class="field-input w-auto">
                <option value="active" @selected($scope === 'active')>{{ __('Active') }}</option>
                <option value="closed" @selected($scope === 'closed')>{{ __('Resolved / closed') }}</option>
                <option value="" @selected($scope === null)>{{ __('All') }}</option>
            </select>

            <label class="sr-only" for="sort">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" class="field-input w-auto">
                @foreach (['due_at' => __('Due date'), 'created_at' => __('Created'), 'updated_at' => __('Last update'), 'priority' => __('Priority')] as $key => $label)
                    <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="direction">{{ __('Direction') }}</label>
            <select id="direction" name="direction" class="field-input w-auto">
                <option value="asc" @selected($direction === 'asc')>{{ __('Asc') }}</option>
                <option value="desc" @selected($direction === 'desc')>{{ __('Desc') }}</option>
            </select>

            <button type="button" class="btn btn-secondary" @click="more = ! more" :aria-expanded="more.toString()" aria-controls="advanced-filters">
                <x-icon name="funnel" class="h-4 w-4" />
                {{ __('Filters') }}
                @if ($activeFilters > 0)
                    <span class="rounded-full bg-brand-600 px-1.5 text-xs font-semibold text-white">{{ $activeFilters }}</span>
                @endif
            </button>
            <x-primary-button>{{ __('Apply filters') }}</x-primary-button>
        </div>
    </div>

    <div id="advanced-filters" x-show="more" x-cloak class="border-t border-ink-100 bg-ink-50/50 p-4">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <fieldset>
                <legend class="field-label">{{ __('Status') }}</legend>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach (App\Modules\Tickets\Enums\TicketStatus::cases() as $status)
                        <label class="cursor-pointer">
                            <input type="checkbox" name="status[]" value="{{ $status->value }}" @checked(in_array($status->value, $selected('status'), true)) class="peer sr-only">
                            <span class="inline-flex rounded-full bg-white px-2.5 py-1 text-xs font-medium text-ink-600 ring-1 ring-inset ring-ink-200 transition peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $status->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="field-label">{{ __('Priority') }}</legend>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach (App\Modules\Tickets\Enums\TicketPriority::cases() as $priority)
                        <label class="cursor-pointer">
                            <input type="checkbox" name="priority[]" value="{{ $priority->value }}" @checked(in_array($priority->value, $selected('priority'), true)) class="peer sr-only">
                            <span class="inline-flex rounded-full bg-white px-2.5 py-1 text-xs font-medium text-ink-600 ring-1 ring-inset ring-ink-200 transition peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $priority->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label for="category_id" class="field-label">{{ __('Category') }}</label>
                <select id="category_id" name="category_id" class="field-input mt-1.5">
                    <option value="">{{ __('Any') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <span class="field-label">{{ __('Created (:tz)', ['tz' => App\Support\BusinessTime::zone()]) }}</span>
                <div class="mt-1.5 flex items-center gap-2">
                    <label class="sr-only" for="created_from">{{ __('From') }}</label>
                    <input id="created_from" name="created_from" type="date" value="{{ $filters['created_from'] ?? '' }}" class="field-input">
                    <span class="text-ink-400">–</span>
                    <label class="sr-only" for="created_to">{{ __('To') }}</label>
                    <input id="created_to" name="created_to" type="date" value="{{ $filters['created_to'] ?? '' }}" class="field-input">
                </div>
            </div>

            @staff
                <div>
                    <label for="assigned_agent_id" class="field-label">{{ __('Agent') }}</label>
                    <select id="assigned_agent_id" name="assigned_agent_id" class="field-input mt-1.5">
                        <option value="">{{ __('Anyone') }}</option>
                        <option value="unassigned" @selected(($filters['assigned_agent_id'] ?? '') === 'unassigned')>{{ __('Unassigned') }}</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((string) ($filters['assigned_agent_id'] ?? '') === (string) $agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Customer autocomplete: searches by name/email, submits the customer id. --}}
                <div x-data="customerPicker(@js($selectedCustomer?->only(['id', 'name', 'email'])))" class="relative">
                    <label for="customer-search" class="field-label">{{ __('Customer') }}</label>
                    <input type="hidden" name="customer" :value="selected ? selected.id : ''">
                    <input id="customer-search" type="text" autocomplete="off" x-model="term" @input.debounce.300ms="search()"
                           placeholder="{{ __('Name or email') }}" class="field-input mt-1.5" role="combobox" :aria-expanded="results.length > 0">
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

                <div>
                    <label for="sla_status" class="field-label">{{ __('SLA') }}</label>
                    <select id="sla_status" name="sla_status" class="field-input mt-1.5">
                        <option value="">{{ __('Any') }}</option>
                        @foreach (['within_sla' => __('Within SLA'), 'approaching' => __('At risk'), 'breached' => __('Breached')] as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['sla_status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink-700">
                    <input type="checkbox" name="escalated" value="1" @checked(! empty($filters['escalated'])) class="field-check">
                    {{ __('Escalated only') }}
                </label>
            @endstaff
        </div>

        <div class="mt-4 flex justify-end">
            <a href="{{ route('tickets.index') }}" class="btn btn-ghost">{{ __('Reset') }}</a>
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
