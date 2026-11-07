<ul class="mt-4 flex flex-wrap gap-2">
    @foreach ($files as $file)
        <li class="group inline-flex max-w-full items-center gap-2 rounded-lg bg-white py-1.5 pl-2 pr-3 text-sm ring-1 ring-ink-200 hover:ring-brand-300">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-ink-100 text-ink-500 group-hover:bg-brand-50 group-hover:text-brand-600">
                <x-icon name="paperclip" class="h-4 w-4" />
            </span>
            <a href="{{ route('attachments.download', $file) }}" class="truncate font-medium text-ink-800 hover:text-brand-700">{{ $file->downloadName() }}</a>
            <span class="shrink-0 text-xs text-ink-400">{{ \App\Support\FileSize::human($file->size_bytes) }}</span>
            @if ($file->canDisplayInline())
                <a href="{{ route('attachments.download', ['attachment' => $file, 'inline' => 1]) }}" target="_blank" rel="noopener" class="shrink-0 text-xs font-medium text-brand-700 hover:underline">{{ __('view') }}</a>
            @endif
        </li>
    @endforeach
</ul>
