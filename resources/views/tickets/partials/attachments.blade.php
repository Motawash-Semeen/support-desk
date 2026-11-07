<ul class="mt-4 flex flex-wrap gap-2 text-sm">
    @foreach ($files as $file)
        <li class="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1">
            <a href="{{ route('attachments.download', $file) }}" class="text-indigo-600 hover:underline">
                {{ $file->downloadName() }}
            </a>
            <span class="text-gray-400">({{ \App\Support\FileSize::human($file->size_bytes) }})</span>
            @if ($file->canDisplayInline())
                <a href="{{ route('attachments.download', ['attachment' => $file, 'inline' => 1]) }}" target="_blank" rel="noopener" class="text-gray-500 hover:underline">{{ __('view') }}</a>
            @endif
        </li>
    @endforeach
</ul>
