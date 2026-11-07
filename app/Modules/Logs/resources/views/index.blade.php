@use('App\Support\BusinessTime')
@use('App\Modules\Logs\Models\ErrorLog')

<x-app-layout :title="__('Error log')" :subtitle="__('Every error the application logged, grouped by cause. Resolved errors reopen automatically if they happen again.')">
    <x-slot name="actions">
        @can('viewLogViewer')
            <a href="{{ route('log-viewer.index') }}" class="btn btn-secondary"><x-icon name="document" class="h-4 w-4" /> {{ __('Raw log files') }}</a>
        @endcan
        @if ((int) $summary->resolved_count > 0)
            <form method="POST" action="{{ route('admin.errors.clear-resolved') }}">
                @csrf
                <button type="submit" class="btn btn-secondary"><x-icon name="trash" class="h-4 w-4" /> {{ __('Clear resolved') }}</button>
            </form>
        @endif
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card :label="__('Open')" :value="(int) $summary->open_count" tone="red" icon="alert" :href="route('admin.errors.index', ['status' => 'open'])" />
        <x-stat-card :label="__('Seen in the last 24 hours')" :value="(int) $summary->last_day" tone="amber" icon="clock" :href="route('admin.errors.index', ['status' => 'all'])" />
        <x-stat-card :label="__('Resolved')" :value="(int) $summary->resolved_count" tone="green" icon="check-circle" :href="route('admin.errors.index', ['status' => 'resolved'])" />
    </div>

    <form method="GET" action="{{ route('admin.errors.index') }}" class="card mb-4 flex flex-wrap items-center gap-2 p-3" role="search" aria-label="{{ __('Filter errors') }}">
        <nav class="flex gap-1 rounded-lg bg-ink-100 p-1 text-sm" aria-label="{{ __('Error status') }}">
            @foreach (['open' => __('Open'), 'resolved' => __('Resolved'), 'all' => __('All')] as $key => $label)
                <a href="{{ route('admin.errors.index', array_merge($filters, ['status' => $key])) }}"
                   @class(['rounded-md px-3 py-1 font-medium', 'bg-white text-ink-900 shadow-sm' => $status === $key, 'text-ink-500 hover:text-ink-800' => $status !== $key])
                   @if ($status === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <input type="hidden" name="status" value="{{ $status }}">

        <div class="relative min-w-[14rem] flex-1">
            <label for="q" class="sr-only">{{ __('Search') }}</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="200" placeholder="{{ __('Search message or exception class') }}" class="field-input pl-9">
        </div>

        <label for="level" class="sr-only">{{ __('Level') }}</label>
        <select id="level" name="level" class="field-input w-auto">
            <option value="">{{ __('Any level') }}</option>
            @foreach (ErrorLog::LEVELS as $level)
                <option value="{{ $level }}" @selected(($filters['level'] ?? '') === $level)>{{ ucfirst($level) }}</option>
            @endforeach
        </select>

        <label for="source" class="sr-only">{{ __('Source') }}</label>
        <select id="source" name="source" class="field-input w-auto">
            <option value="">{{ __('Any source') }}</option>
            @foreach (['exception' => __('Exceptions'), 'log' => __('Logged errors'), 'http' => __('HTTP 4xx')] as $key => $label)
                <option value="{{ $key }}" @selected(($filters['source'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <x-primary-button>{{ __('Filter') }}</x-primary-button>
    </form>

    <div class="card overflow-hidden">
        @if ($entries->isEmpty())
            <x-empty-state icon="check-circle"
                           :title="$status === 'open' ? __('No open errors.') : __('Nothing here.')"
                           :description="$status === 'open' ? __('Everything that was logged has been resolved. New errors appear here automatically.') : __('No errors match these filters.')" />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Error') }}</th>
                            <th>{{ __('Level') }}</th>
                            <th class="text-right">{{ __('Count') }}</th>
                            <th>{{ __('Last seen') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($entries as $error)
                            <tr class="group">
                                <td class="min-w-[22rem] max-w-xl">
                                    <a href="{{ route('admin.errors.show', $error) }}" class="block">
                                        <span class="line-clamp-2 font-medium text-ink-900 group-hover:text-brand-700">{{ $error->message }}</span>
                                        <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-500">
                                            <span class="ticket-no">#{{ $error->id }}</span>
                                            @if ($error->exception_class)
                                                <span class="font-mono">{{ class_basename($error->exception_class) }}</span>
                                            @endif
                                            @if ($error->url)
                                                <span class="truncate">{{ \Illuminate\Support\Str::limit($error->url, 60) }}</span>
                                            @endif
                                        </span>
                                    </a>
                                </td>
                                <td class="whitespace-nowrap"><x-badge :color="$error->levelColor()">{{ ucfirst($error->level) }}</x-badge></td>
                                <td class="whitespace-nowrap text-right font-semibold tabular-nums text-ink-800">{{ number_format($error->occurrences) }}</td>
                                <td class="whitespace-nowrap text-ink-600">
                                    <time datetime="{{ $error->last_seen_at->toIso8601String() }}" title="{{ BusinessTime::format($error->last_seen_at) }}">{{ $error->last_seen_at->diffForHumans() }}</time>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($error->isResolved())
                                        <x-badge color="green">{{ __('Resolved') }}</x-badge>
                                    @else
                                        <x-badge color="red">{{ __('Open') }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>
</x-app-layout>
