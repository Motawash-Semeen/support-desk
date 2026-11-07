@use('Illuminate\Support\Facades\Vite')
{{--
    GET form, so every filtered view is a shareable URL. Staff-only fields are
    not rendered for customers, and are ignored server-side if sent anyway.
--}}
@php
    $input = 'block w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500';
    $selected = fn (string $key) => (array) ($filters[$key] ?? []);
@endphp

<form method="GET" action="{{ route('tickets.index') }}" class="bg-white shadow-sm sm:rounded-lg p-4 mb-4 space-y-4" role="search" aria-label="{{ __('Filter tickets') }}">
    <div class="grid gap-3 md:grid-cols-4">
        <div class="md:col-span-2">
            <label for="q" class="block text-xs font-medium text-gray-600">{{ __('Search') }}</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="200"
                   placeholder="{{ __('Keywords or ticket number, e.g. TKT-2026-000042') }}" class="{{ $input }} mt-1">
        </div>

        <div>
            <label for="scope" class="block text-xs font-medium text-gray-600">{{ __('Show') }}</label>
            <select id="scope" name="scope" class="{{ $input }} mt-1">
                <option value="active" @selected($scope === 'active')>{{ __('Active') }}</option>
                <option value="closed" @selected($scope === 'closed')>{{ __('Resolved / closed') }}</option>
                <option value="" @selected($scope === null)>{{ __('All') }}</option>
            </select>
        </div>

        <div>
            <label for="category_id" class="block text-xs font-medium text-gray-600">{{ __('Category') }}</label>
            <select id="category_id" name="category_id" class="{{ $input }} mt-1">
                <option value="">{{ __('Any') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-4">
        <fieldset>
            <legend class="block text-xs font-medium text-gray-600">{{ __('Status') }}</legend>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                @foreach (App\Enums\TicketStatus::cases() as $status)
                    <label class="inline-flex items-center gap-1">
                        <input type="checkbox" name="status[]" value="{{ $status->value }}" @checked(in_array($status->value, $selected('status'), true)) class="rounded border-gray-300 text-indigo-600">
                        {{ $status->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="block text-xs font-medium text-gray-600">{{ __('Priority') }}</legend>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                @foreach (App\Enums\TicketPriority::cases() as $priority)
                    <label class="inline-flex items-center gap-1">
                        <input type="checkbox" name="priority[]" value="{{ $priority->value }}" @checked(in_array($priority->value, $selected('priority'), true)) class="rounded border-gray-300 text-indigo-600">
                        {{ $priority->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <span class="block text-xs font-medium text-gray-600">{{ __('Created (:tz)', ['tz' => App\Support\BusinessTime::zone()]) }}</span>
            <div class="mt-1 flex gap-2">
                <label class="sr-only" for="created_from">{{ __('From') }}</label>
                <input id="created_from" name="created_from" type="date" value="{{ $filters['created_from'] ?? '' }}" class="{{ $input }}">
                <label class="sr-only" for="created_to">{{ __('To') }}</label>
                <input id="created_to" name="created_to" type="date" value="{{ $filters['created_to'] ?? '' }}" class="{{ $input }}">
            </div>
        </div>

        <div>
            <span class="block text-xs font-medium text-gray-600">{{ __('Sort') }}</span>
            <div class="mt-1 flex gap-2">
                <label class="sr-only" for="sort">{{ __('Sort by') }}</label>
                <select id="sort" name="sort" class="{{ $input }}">
                    @foreach (['due_at' => __('Due date'), 'created_at' => __('Created'), 'updated_at' => __('Last update'), 'priority' => __('Priority')] as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="direction">{{ __('Direction') }}</label>
                <select id="direction" name="direction" class="{{ $input }} w-28">
                    <option value="asc" @selected($direction === 'asc')>{{ __('Asc') }}</option>
                    <option value="desc" @selected($direction === 'desc')>{{ __('Desc') }}</option>
                </select>
            </div>
        </div>
    </div>

    @staff
        <div class="grid gap-3 md:grid-cols-4">
            <div>
                <label for="assigned_agent_id" class="block text-xs font-medium text-gray-600">{{ __('Agent') }}</label>
                <select id="assigned_agent_id" name="assigned_agent_id" class="{{ $input }} mt-1">
                    <option value="">{{ __('Anyone') }}</option>
                    <option value="unassigned" @selected(($filters['assigned_agent_id'] ?? '') === 'unassigned')>{{ __('Unassigned') }}</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}" @selected((string) ($filters['assigned_agent_id'] ?? '') === (string) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Customer autocomplete: searches by name/email, submits the customer id. --}}
            <div x-data="customerPicker(@js($selectedCustomer?->only(['id', 'name', 'email'])))" class="relative">
                <label for="customer-search" class="block text-xs font-medium text-gray-600">{{ __('Customer') }}</label>
                <input type="hidden" name="customer" :value="selected ? selected.id : ''">
                <input id="customer-search" type="text" autocomplete="off" x-model="term" @input.debounce.300ms="search()"
                       placeholder="{{ __('Name or email') }}" class="{{ $input }} mt-1" role="combobox" :aria-expanded="results.length > 0">
                <ul x-show="results.length" x-cloak class="absolute z-10 mt-1 w-full rounded-md border border-gray-200 bg-white shadow text-sm" role="listbox">
                    <template x-for="customer in results" :key="customer.id">
                        <li role="option">
                            <button type="button" class="block w-full px-3 py-2 text-left hover:bg-gray-50" @click="choose(customer)">
                                <span x-text="customer.name"></span> <span class="text-gray-500" x-text="customer.email"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            <div>
                <label for="sla_status" class="block text-xs font-medium text-gray-600">{{ __('SLA') }}</label>
                <select id="sla_status" name="sla_status" class="{{ $input }} mt-1">
                    <option value="">{{ __('Any') }}</option>
                    @foreach (['within_sla' => __('Within SLA'), 'approaching' => __('At risk'), 'breached' => __('Breached')] as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['sla_status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <label class="inline-flex items-center gap-2 text-sm self-end pb-2">
                <input type="checkbox" name="escalated" value="1" @checked(! empty($filters['escalated'])) class="rounded border-gray-300 text-indigo-600">
                {{ __('Escalated only') }}
            </label>
        </div>
    @endstaff

    <div class="flex items-center justify-end gap-3">
        <a href="{{ route('tickets.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('Reset') }}</a>
        <x-primary-button>{{ __('Apply filters') }}</x-primary-button>
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
