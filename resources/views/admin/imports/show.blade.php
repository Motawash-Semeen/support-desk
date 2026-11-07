@use('Illuminate\Support\Facades\Vite')
<x-app-layout :title="__('Import').': '.$import->original_name" width="narrow">
    <x-slot name="header">
        <a href="{{ route('admin.imports.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-ink-500 hover:text-ink-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> {{ __('All imports') }}
        </a>
        <h1 class="mt-3 flex items-center gap-3 text-2xl font-semibold tracking-tight text-ink-900">
            <x-icon name="document" class="h-6 w-6 text-ink-400" /> {{ $import->original_name }}
        </h1>
    </x-slot>

    {{-- Polls the JSON status endpoint every 3 s until the import finishes. --}}
    <div x-data="importStatus(@js(route('admin.imports.status', $import)), @js([
             'status' => $import->status->value,
             'finished' => $import->status->isFinished(),
             'total' => $import->total_rows,
             'processed' => $import->processed_rows,
             'success' => $import->success_rows,
             'failed' => $import->failed_rows,
             'percent' => $import->total_rows > 0 ? (int) floor($import->processed_rows / $import->total_rows * 100) : 0,
             'error_message' => $import->error_message,
             'errors_url' => $import->failed_rows > 0 ? route('admin.imports.errors', $import) : null,
         ]))"
         x-init="start()">
        <x-card>
            <div class="flex items-center justify-between">
                <span class="inline-flex items-center gap-2 text-sm text-ink-600">
                    {{ __('Status') }}
                    <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs font-semibold capitalize text-ink-800"
                          :class="{ 'bg-emerald-50 text-emerald-700': state.status === 'completed', 'bg-rose-50 text-rose-700': state.status === 'failed', 'bg-sky-50 text-sky-700': state.status === 'processing' }"
                          x-text="state.status">{{ $import->status->value }}</span>
                </span>
                <span class="text-2xl font-semibold tabular-nums text-ink-900" x-text="state.percent + '%'">{{ $import->total_rows > 0 ? (int) floor($import->processed_rows / $import->total_rows * 100) : 0 }}%</span>
            </div>

            <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-ink-100" role="progressbar" aria-label="{{ __('Import progress') }}" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="state.percent">
                <div class="h-2.5 rounded-full bg-brand-500 transition-all duration-500" :style="'width: ' + state.percent + '%'"></div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['total', __('Rows'), 'text-ink-900'],
                    ['processed', __('Processed'), 'text-ink-900'],
                    ['success', __('Imported'), 'text-emerald-600'],
                    ['failed', __('Failed'), 'text-rose-600'],
                ] as [$key, $label, $tone])
                    <div class="rounded-lg bg-ink-50 px-3 py-2.5">
                        <dt class="text-xs font-medium text-ink-500">{{ $label }}</dt>
                        <dd class="mt-0.5 text-xl font-semibold tabular-nums {{ $tone }}" x-text="Number(state.{{ $key }}).toLocaleString()">{{ number_format($import->{$key === 'total' ? 'total_rows' : $key.'_rows'}) }}</dd>
                    </div>
                @endforeach
            </dl>

            <p x-show="state.error_message" x-cloak class="mt-4 flex items-start gap-2 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-inset ring-rose-200">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /> <span x-text="state.error_message"></span>
            </p>

            <x-slot name="footer">
                <a x-show="state.errors_url" x-cloak :href="state.errors_url" class="btn btn-secondary mr-auto"><x-icon name="download" class="h-4 w-4" /> {{ __('Download error report (CSV)') }}</a>

                @if ($import->status !== \App\Enums\ImportStatus::Completed)
                    <form method="POST" action="{{ route('admin.imports.resume', $import) }}" x-show="state.status === 'failed'" x-cloak>
                        @csrf
                        <x-primary-button><x-icon name="refresh" class="h-4 w-4" /> {{ __('Resume import') }}</x-primary-button>
                    </form>
                @endif

                <a href="{{ route('admin.imports.index') }}" class="btn btn-ghost">{{ __('All imports') }}</a>
            </x-slot>
        </x-card>
    </div>

    <script nonce="{{ Vite::cspNonce() }}">
        function importStatus(url, initial) {
            return {
                state: initial,
                timer: null,
                start() {
                    if (this.state.finished) return;
                    this.timer = setInterval(() => this.poll(), 3000);
                },
                async poll() {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    this.state = await response.json();
                    if (this.state.finished) clearInterval(this.timer);
                },
            };
        }
    </script>
</x-app-layout>
