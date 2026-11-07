@use('App\Support\BusinessTime')

@php
    $statusColor = fn ($status) => match ($status->value) {
        'completed' => 'green',
        'failed' => 'red',
        'processing' => 'blue',
        default => 'gray',
    };
@endphp

<x-app-layout :title="__('Import tickets')" :subtitle="__('Bring tickets over from another system with a CSV file. Large files are processed in the background.')" width="medium">
    <x-slot name="actions">
        <a href="{{ route('admin.imports.sample') }}" class="btn btn-secondary"><x-icon name="download" class="h-4 w-4" /> {{ __('Download a sample file') }}</a>
    </x-slot>

    <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data">
        @csrf
        <x-card :title="__('Upload a CSV file')">
            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <x-input-label for="file" :value="__('CSV file (max :size)', ['size' => \App\Support\FileSize::human(config('support.import.max_kb') * 1024)])" />
                    <div class="mt-1.5 rounded-xl border-2 border-dashed border-ink-200 bg-ink-50/50 p-6 text-center">
                        <x-icon name="upload" class="mx-auto h-8 w-8 text-ink-400" />
                        <input id="file" name="file" type="file" accept=".csv,text/csv" required class="field-file mt-3">
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('file')" />
                </div>
                <div class="text-sm text-ink-600">
                    <p class="font-medium text-ink-800">{{ __('Header row required. Columns:') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($columns as $column)
                            <code class="kbd">{{ $column }}</code>
                        @endforeach
                    </div>
                    <p class="mt-3 text-ink-500">{{ __('Required: external_reference, customer_email, subject, description, priority, category.') }}</p>
                </div>
            </div>

            <x-slot name="footer">
                <x-primary-button><x-icon name="upload" class="h-4 w-4" /> {{ __('Start import') }}</x-primary-button>
            </x-slot>
        </x-card>
    </form>

    <x-card :title="__('Past imports')" :padded="false" class="mt-6">
        @if ($imports->isEmpty())
            <x-empty-state icon="document" :title="__('No imports yet.')" />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('File') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-right">{{ __('Imported') }}</th>
                            <th class="text-right">{{ __('Failed') }}</th>
                            <th>{{ __('By') }}</th>
                            <th>{{ __('Started') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($imports as $import)
                            <tr>
                                <td><a href="{{ route('admin.imports.show', $import) }}" class="inline-flex items-center gap-2 font-medium text-ink-900 hover:text-brand-700"><x-icon name="document" class="h-4 w-4 text-ink-400" /> {{ $import->original_name }}</a></td>
                                <td><x-badge :color="$statusColor($import->status)">{{ $import->status->label() }}</x-badge></td>
                                <td class="text-right tabular-nums text-ink-700">{{ number_format($import->success_rows) }}</td>
                                <td @class(['text-right tabular-nums', 'font-semibold text-rose-600' => $import->failed_rows > 0, 'text-ink-400' => $import->failed_rows === 0])>{{ number_format($import->failed_rows) }}</td>
                                <td class="text-ink-600">{{ $import->user?->name }}</td>
                                <td class="whitespace-nowrap text-ink-600">{{ BusinessTime::format($import->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <div class="mt-4">{{ $imports->links() }}</div>
</x-app-layout>
