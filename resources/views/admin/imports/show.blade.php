@use('Illuminate\Support\Facades\Vite')
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Import') }}: {{ $import->original_name }}
        </h2>
    </x-slot>

    {{-- Polls the JSON status endpoint every 3 s until the import finishes. --}}
    <div class="py-8"
         x-data="importStatus(@js(route('admin.imports.status', $import)), @js([
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
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex items-center justify-between text-sm">
                    <span>{{ __('Status') }}: <strong x-text="state.status">{{ $import->status->value }}</strong></span>
                    <span class="tabular-nums" x-text="state.percent + '%'">{{ $import->total_rows > 0 ? (int) floor($import->processed_rows / $import->total_rows * 100) : 0 }}%</span>
                </div>

                <div class="h-3 rounded bg-gray-100" role="progressbar" aria-label="{{ __('Import progress') }}" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="state.percent">
                    <div class="h-3 rounded bg-indigo-500 transition-all" :style="'width: ' + state.percent + '%'"></div>
                </div>

                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-gray-500">{{ __('Rows') }}</dt><dd class="text-xl font-semibold tabular-nums" x-text="state.total">{{ $import->total_rows }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Processed') }}</dt><dd class="text-xl font-semibold tabular-nums" x-text="state.processed">{{ $import->processed_rows }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Imported') }}</dt><dd class="text-xl font-semibold tabular-nums text-green-700" x-text="state.success">{{ $import->success_rows }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Failed') }}</dt><dd class="text-xl font-semibold tabular-nums text-red-600" x-text="state.failed">{{ $import->failed_rows }}</dd></div>
                </dl>

                <p x-show="state.error_message" x-cloak class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800" x-text="state.error_message"></p>

                <div class="flex flex-wrap gap-3">
                    <a x-show="state.errors_url" x-cloak :href="state.errors_url" class="text-sm text-indigo-600 hover:underline">{{ __('Download error report (CSV)') }}</a>

                    @if ($import->status !== \App\Enums\ImportStatus::Completed)
                        <form method="POST" action="{{ route('admin.imports.resume', $import) }}" x-show="state.status === 'failed'" x-cloak>
                            @csrf
                            <x-secondary-button type="submit">{{ __('Resume import') }}</x-secondary-button>
                        </form>
                    @endif

                    <a href="{{ route('admin.imports.index') }}" class="text-sm text-gray-600 hover:underline">{{ __('All imports') }}</a>
                </div>
            </section>
        </div>
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
