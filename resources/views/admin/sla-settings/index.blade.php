<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('SLA settings') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('Resolution targets (business minutes)') }}</h3>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr><th class="py-2">{{ __('Priority') }}</th><th>{{ __('Target') }}</th><th>{{ __('Warning at') }}</th><th>{{ __('Active') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($policies as $policy)
                            <tr>
                                <td class="py-2"><x-badge :color="$policy->priority->color()">{{ $policy->priority->label() }}</x-badge></td>
                                <td>{{ $policy->resolution_minutes }} min</td>
                                <td>{{ (int) round($policy->warning_threshold * 100) }}%</td>
                                <td>{{ $policy->is_active ? __('Yes') : __('No') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('Business hours') }} ({{ config('support.timezone') }})</h3>
                <ul class="text-sm divide-y divide-gray-100">
                    @foreach ($hours as $day)
                        <li class="py-2 flex justify-between">
                            <span>{{ \Carbon\Carbon::create()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($day->weekday)->dayName }}</span>
                            <span>{{ $day->is_working_day ? substr($day->opens_at, 0, 5).' – '.substr($day->closes_at, 0, 5) : __('Closed') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('Upcoming holidays') }}</h3>
                <ul class="text-sm divide-y divide-gray-100">
                    @forelse ($holidays as $holiday)
                        <li class="py-2 flex justify-between"><span>{{ $holiday->name }}</span><span>{{ $holiday->date->format('D, d M Y') }}</span></li>
                    @empty
                        <li class="py-2 text-gray-500">{{ __('No holidays configured.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
