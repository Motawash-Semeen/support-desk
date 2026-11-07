@php
    // 8 Nov 2026 is a Sunday: weekday 0 + n gives the day name for n.
    $dayName = fn (int $weekday) => \Carbon\CarbonImmutable::create(2026, 11, 8)->addDays($weekday)->dayName;
    $input = 'border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('SLA settings') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <p class="text-sm text-gray-600">
                {{ __('Changes apply to new tickets. Existing tickets keep the target they were created with; run') }}
                <code class="rounded bg-gray-100 px-1">php artisan sla:recalculate --open</code>
                {{ __('to re-apply the current rules to active tickets.') }}
            </p>

            {{-- Resolution targets --}}
            <form method="POST" action="{{ route('admin.sla-settings.policies.update') }}" class="bg-white shadow-sm sm:rounded-lg p-6">
                @csrf
                @method('PUT')
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('Resolution targets') }}</h3>
                <p class="text-sm text-gray-500 mb-4">{{ __('Business minutes per priority. The warning fires at the given fraction of the target (0.80 = 80%).') }}</p>

                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-2">{{ __('Priority') }}</th>
                            <th>{{ __('Target (business minutes)') }}</th>
                            <th>{{ __('Warning threshold') }}</th>
                            <th>{{ __('Active') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($policies as $policy)
                            @php($key = $policy['priority']->value)
                            <tr>
                                <td class="py-3"><x-badge :color="$policy['priority']->color()">{{ $policy['priority']->label() }}</x-badge></td>
                                <td class="py-3">
                                    <label for="minutes-{{ $key }}" class="sr-only">{{ __('Target for :priority', ['priority' => $policy['priority']->label()]) }}</label>
                                    <input id="minutes-{{ $key }}" type="number" min="1" max="{{ \App\Http\Requests\UpdateSlaPoliciesRequest::MAX_MINUTES }}" required
                                           name="policies[{{ $key }}][resolution_minutes]" value="{{ old("policies.$key.resolution_minutes", $policy['resolution_minutes']) }}"
                                           class="{{ $input }} w-32">
                                    <x-input-error class="mt-1" :messages="$errors->get('policies.'.$key.'.resolution_minutes')" />
                                </td>
                                <td class="py-3">
                                    <label for="threshold-{{ $key }}" class="sr-only">{{ __('Warning threshold for :priority', ['priority' => $policy['priority']->label()]) }}</label>
                                    <input id="threshold-{{ $key }}" type="number" min="0.05" max="0.99" step="0.01" required
                                           name="policies[{{ $key }}][warning_threshold]" value="{{ old("policies.$key.warning_threshold", number_format($policy['warning_threshold'], 2)) }}"
                                           class="{{ $input }} w-24">
                                    <x-input-error class="mt-1" :messages="$errors->get('policies.'.$key.'.warning_threshold')" />
                                </td>
                                <td class="py-3">
                                    <input type="hidden" name="policies[{{ $key }}][is_active]" value="0">
                                    <input type="checkbox" name="policies[{{ $key }}][is_active]" value="1" aria-label="{{ __('Active') }}"
                                           @checked(old("policies.$key.is_active", $policy['is_active'])) class="rounded border-gray-300 text-indigo-600">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-input-error class="mt-2" :messages="$errors->get('policies')" />
                <div class="mt-4 text-right"><x-primary-button>{{ __('Save targets') }}</x-primary-button></div>
            </form>

            {{-- Business hours --}}
            <form method="POST" action="{{ route('admin.sla-settings.business-hours.update') }}" class="bg-white shadow-sm sm:rounded-lg p-6">
                @csrf
                @method('PUT')
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('Business hours') }}</h3>
                <p class="text-sm text-gray-500 mb-4">{{ __('Wall-clock times in :tz. The closing minute is not working time.', ['tz' => config('support.timezone')]) }}</p>

                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($days as $day)
                        @php($w = $day['weekday'])
                        <li class="py-3 flex flex-wrap items-center gap-4">
                            <span class="w-28 font-medium text-gray-700">{{ $dayName($w) }}</span>
                            <label class="inline-flex items-center gap-2">
                                <input type="hidden" name="days[{{ $w }}][is_working_day]" value="0">
                                <input type="checkbox" name="days[{{ $w }}][is_working_day]" value="1" @checked(old("days.$w.is_working_day", $day['is_working_day']))
                                       class="rounded border-gray-300 text-indigo-600">
                                {{ __('Working day') }}
                            </label>
                            <label class="inline-flex items-center gap-2">
                                {{ __('Opens') }}
                                <input type="time" name="days[{{ $w }}][opens_at]" value="{{ old("days.$w.opens_at", $day['opens_at']) }}" class="{{ $input }}">
                            </label>
                            <label class="inline-flex items-center gap-2">
                                {{ __('Closes') }}
                                <input type="time" name="days[{{ $w }}][closes_at]" value="{{ old("days.$w.closes_at", $day['closes_at']) }}" class="{{ $input }}">
                            </label>
                            <x-input-error :messages="array_merge($errors->get('days.'.$w.'.opens_at'), $errors->get('days.'.$w.'.closes_at'))" />
                        </li>
                    @endforeach
                </ul>
                <x-input-error class="mt-2" :messages="$errors->get('days')" />
                <div class="mt-4 text-right"><x-primary-button>{{ __('Save business hours') }}</x-primary-button></div>
            </form>

            {{-- Holidays --}}
            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('Holidays') }}</h3>
                <p class="text-sm text-gray-500 mb-4">{{ __('Whole days with no business hours.') }}</p>

                <ul class="divide-y divide-gray-100 text-sm mb-6">
                    @forelse ($holidays as $holiday)
                        <li class="py-2 flex items-center justify-between gap-4">
                            <span><span class="font-medium">{{ $holiday->date->format('D, d M Y') }}</span> · {{ $holiday->name }}</span>
                            <form method="POST" action="{{ route('admin.sla-settings.holidays.destroy', $holiday) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">{{ __('Remove') }}</button>
                            </form>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('No holidays configured.') }}</li>
                    @endforelse
                </ul>

                <form method="POST" action="{{ route('admin.sla-settings.holidays.store') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <x-input-label for="holiday-date" :value="__('Date')" />
                        <input id="holiday-date" type="date" name="date" value="{{ old('date') }}" required class="{{ $input }} mt-1">
                        <x-input-error class="mt-1" :messages="$errors->get('date')" />
                    </div>
                    <div class="flex-1 min-w-48">
                        <x-input-label for="holiday-name" :value="__('Name')" />
                        <x-text-input id="holiday-name" name="name" maxlength="100" required class="mt-1 block w-full" :value="old('name')" />
                        <x-input-error class="mt-1" :messages="$errors->get('name')" />
                    </div>
                    <x-primary-button>{{ __('Add holiday') }}</x-primary-button>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
