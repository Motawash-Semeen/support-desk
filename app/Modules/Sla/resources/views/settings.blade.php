@php
    // 8 Nov 2026 is a Sunday: weekday 0 + n gives the day name for n.
    $dayName = fn (int $weekday) => \Carbon\CarbonImmutable::create(2026, 11, 8)->addDays($weekday)->dayName;
@endphp

<x-app-layout :title="__('SLA settings')" :subtitle="__('Resolution targets, business hours and holidays. Every deadline is calculated from these.')" width="medium">
    <div class="mb-6 flex items-start gap-3 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-inset ring-sky-200">
        <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0" />
        <p>
            {{ __('Changes apply to new tickets. Existing tickets keep the target they were created with; run') }}
            <code class="kbd">php artisan sla:recalculate --open</code>
            {{ __('to re-apply the current rules to active tickets.') }}
        </p>
    </div>

    <div class="space-y-6">
        {{-- Resolution targets --}}
        <form method="POST" action="{{ route('admin.sla-settings.policies.update') }}">
            @csrf
            @method('PUT')
            <x-card :title="__('Resolution targets')" :description="__('Business minutes per priority. The warning fires at the given fraction of the target (0.80 = 80%).')" :padded="false">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Priority') }}</th>
                                <th>{{ __('Target (business minutes)') }}</th>
                                <th>{{ __('Warning threshold') }}</th>
                                <th class="text-center">{{ __('Active') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($policies as $policy)
                                @php($key = $policy['priority']->value)
                                <tr>
                                    <td class="align-middle"><x-badge :color="$policy['priority']->color()">{{ $policy['priority']->label() }}</x-badge></td>
                                    <td>
                                        <label for="minutes-{{ $key }}" class="sr-only">{{ __('Target for :priority', ['priority' => $policy['priority']->label()]) }}</label>
                                        <div class="flex items-center gap-2">
                                            <input id="minutes-{{ $key }}" type="number" min="1" max="{{ \App\Modules\Sla\Http\Requests\UpdateSlaPoliciesRequest::MAX_MINUTES }}" required
                                                   name="policies[{{ $key }}][resolution_minutes]" value="{{ old("policies.$key.resolution_minutes", $policy['resolution_minutes']) }}"
                                                   class="field-input w-28 tabular-nums">
                                            <span class="text-xs text-ink-400">≈ {{ round($policy['resolution_minutes'] / 60, 1) }} h</span>
                                        </div>
                                        <x-input-error class="mt-1" :messages="$errors->get('policies.'.$key.'.resolution_minutes')" />
                                    </td>
                                    <td>
                                        <label for="threshold-{{ $key }}" class="sr-only">{{ __('Warning threshold for :priority', ['priority' => $policy['priority']->label()]) }}</label>
                                        <input id="threshold-{{ $key }}" type="number" min="0.05" max="0.99" step="0.01" required
                                               name="policies[{{ $key }}][warning_threshold]" value="{{ old("policies.$key.warning_threshold", number_format($policy['warning_threshold'], 2)) }}"
                                               class="field-input w-24 tabular-nums">
                                        <x-input-error class="mt-1" :messages="$errors->get('policies.'.$key.'.warning_threshold')" />
                                    </td>
                                    <td class="text-center align-middle">
                                        <input type="hidden" name="policies[{{ $key }}][is_active]" value="0">
                                        <input type="checkbox" name="policies[{{ $key }}][is_active]" value="1" aria-label="{{ __('Active') }}"
                                               @checked(old("policies.$key.is_active", $policy['is_active'])) class="field-check">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-input-error class="px-5 pb-3" :messages="$errors->get('policies')" />

                <x-slot name="footer">
                    <x-primary-button>{{ __('Save targets') }}</x-primary-button>
                </x-slot>
            </x-card>
        </form>

        {{-- Business hours --}}
        <form method="POST" action="{{ route('admin.sla-settings.business-hours.update') }}">
            @csrf
            @method('PUT')
            <x-card :title="__('Business hours')" :description="__('Wall-clock times in :tz. The closing minute is not working time.', ['tz' => config('support.timezone')])" :padded="false">
                <ul class="divide-y divide-ink-100">
                    @foreach ($days as $day)
                        @php($w = $day['weekday'])
                        <li class="flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-3 text-sm" x-data="{ working: @js((bool) old("days.$w.is_working_day", $day['is_working_day'])) }">
                            <span class="w-28 font-medium text-ink-800">{{ $dayName($w) }}</span>
                            <label class="inline-flex items-center gap-2 text-ink-600">
                                <input type="hidden" name="days[{{ $w }}][is_working_day]" value="0">
                                <input type="checkbox" name="days[{{ $w }}][is_working_day]" value="1" x-model="working" @checked(old("days.$w.is_working_day", $day['is_working_day'])) class="field-check">
                                {{ __('Working day') }}
                            </label>
                            <div class="flex items-center gap-2" :class="working ? '' : 'opacity-40'">
                                <label class="sr-only" for="opens-{{ $w }}">{{ __('Opens') }}</label>
                                <input id="opens-{{ $w }}" type="time" name="days[{{ $w }}][opens_at]" value="{{ old("days.$w.opens_at", $day['opens_at']) }}" class="field-input w-32" aria-label="{{ __('Opens') }}">
                                <span class="text-ink-400">–</span>
                                <label class="sr-only" for="closes-{{ $w }}">{{ __('Closes') }}</label>
                                <input id="closes-{{ $w }}" type="time" name="days[{{ $w }}][closes_at]" value="{{ old("days.$w.closes_at", $day['closes_at']) }}" class="field-input w-32" aria-label="{{ __('Closes') }}">
                            </div>
                            <x-input-error class="w-full" :messages="array_merge($errors->get('days.'.$w.'.opens_at'), $errors->get('days.'.$w.'.closes_at'))" />
                        </li>
                    @endforeach
                </ul>
                <x-input-error class="px-5 pb-3" :messages="$errors->get('days')" />

                <x-slot name="footer">
                    <x-primary-button>{{ __('Save business hours') }}</x-primary-button>
                </x-slot>
            </x-card>
        </form>

        {{-- Holidays --}}
        <x-card :title="__('Holidays')" :description="__('Whole days with no business hours.')" :padded="false">
            <ul class="divide-y divide-ink-100">
                @forelse ($holidays as $holiday)
                    <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                        <span class="flex items-center gap-3">
                            <span class="flex h-9 w-9 flex-col items-center justify-center rounded-lg bg-ink-100 leading-none">
                                <span class="text-[10px] font-semibold uppercase text-ink-500">{{ $holiday->date->format('M') }}</span>
                                <span class="text-sm font-semibold text-ink-900">{{ $holiday->date->format('d') }}</span>
                            </span>
                            <span>
                                <span class="block font-medium text-ink-900">{{ $holiday->name }}</span>
                                <span class="text-xs text-ink-500">{{ $holiday->date->format('l, Y') }}</span>
                            </span>
                        </span>
                        <form method="POST" action="{{ route('admin.sla-settings.holidays.destroy', $holiday) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm text-rose-600 hover:bg-rose-50 hover:text-rose-700">
                                <x-icon name="trash" class="h-4 w-4" /> {{ __('Remove') }}
                            </button>
                        </form>
                    </li>
                @empty
                    <li><x-empty-state icon="calendar" :title="__('No holidays configured.')" /></li>
                @endforelse
            </ul>

            <form method="POST" action="{{ route('admin.sla-settings.holidays.store') }}" class="flex flex-wrap items-end gap-3 border-t border-ink-100 bg-ink-50/60 px-5 py-4">
                @csrf
                <div>
                    <x-input-label for="holiday-date" :value="__('Date')" />
                    <input id="holiday-date" type="date" name="date" value="{{ old('date') }}" required class="field-input mt-1.5">
                    <x-input-error class="mt-1" :messages="$errors->get('date')" />
                </div>
                <div class="min-w-48 flex-1">
                    <x-input-label for="holiday-name" :value="__('Name')" />
                    <x-text-input id="holiday-name" name="name" maxlength="100" required class="mt-1.5" :value="old('name')" placeholder="{{ __('e.g. Victory Day') }}" />
                    <x-input-error class="mt-1" :messages="$errors->get('name')" />
                </div>
                <x-primary-button><x-icon name="plus" class="h-4 w-4" /> {{ __('Add holiday') }}</x-primary-button>
            </form>
        </x-card>
    </div>
</x-app-layout>
