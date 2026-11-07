@use('App\Support\BusinessTime')

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Import tickets') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold text-gray-800">{{ __('Upload a CSV file') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('Header row required. Columns:') }}
                    <code class="text-xs">{{ implode(', ', $columns) }}</code>.
                    {{ __('Required: external_reference, customer_email, subject, description, priority, category.') }}
                    <a href="{{ route('admin.imports.sample') }}" class="text-indigo-600 hover:underline">{{ __('Download a sample file') }}</a>.
                </p>

                <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <x-input-label for="file" :value="__('CSV file (max :size)', ['size' => \App\Support\FileSize::human(config('support.import.max_kb') * 1024)])" />
                        <input id="file" name="file" type="file" accept=".csv,text/csv" required class="mt-1 block text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5">
                        <x-input-error class="mt-2" :messages="$errors->get('file')" />
                    </div>
                    <x-primary-button>{{ __('Start import') }}</x-primary-button>
                </form>
            </section>

            <section class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('File') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Imported') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Failed') }}</th>
                            <th class="px-4 py-3">{{ __('By') }}</th>
                            <th class="px-4 py-3">{{ __('Started') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($imports as $import)
                            <tr>
                                <td class="px-4 py-3"><a href="{{ route('admin.imports.show', $import) }}" class="text-indigo-600 hover:underline">{{ $import->original_name }}</a></td>
                                <td class="px-4 py-3">{{ $import->status->label() }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($import->success_rows) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($import->failed_rows) }}</td>
                                <td class="px-4 py-3">{{ $import->user?->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ BusinessTime::format($import->created_at) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">{{ __('No imports yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            {{ $imports->links() }}
        </div>
    </div>
</x-app-layout>
