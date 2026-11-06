<ul class="mt-4 flex flex-wrap gap-2 text-sm">
    @foreach ($files as $file)
        <li>
            <a href="{{ route('tickets.attachments.download', [$ticket, $file]) }}"
               class="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1 text-indigo-600 hover:bg-gray-100">
                {{ $file->original_name }}
                <span class="text-gray-400">({{ Illuminate\Support\Number::fileSize($file->size_bytes) }})</span>
            </a>
        </li>
    @endforeach
</ul>
