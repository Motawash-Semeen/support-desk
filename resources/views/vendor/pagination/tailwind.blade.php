@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-500">
            @if ($paginator->firstItem())
                {{ __('Showing') }}
                <span class="font-medium text-ink-800">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</span>
                {{ __('of') }}
                <span class="font-medium text-ink-800">{{ number_format($paginator->total()) }}</span>
            @endif
        </p>

        <div class="inline-flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-secondary btn-sm cursor-not-allowed opacity-50" aria-disabled="true" aria-label="{{ __('Previous page') }}"><x-icon name="chevron-left" class="h-4 w-4" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-secondary btn-sm" aria-label="{{ __('Previous page') }}"><x-icon name="chevron-left" class="h-4 w-4" /></a>
            @endif

            <span class="hidden items-center gap-1 sm:inline-flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="px-2 text-sm text-ink-400" aria-disabled="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg bg-brand-600 px-2 text-sm font-semibold text-white">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium text-ink-600 hover:bg-ink-100 hover:text-ink-900" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-secondary btn-sm" aria-label="{{ __('Next page') }}"><x-icon name="chevron-right" class="h-4 w-4" /></a>
            @else
                <span class="btn btn-secondary btn-sm cursor-not-allowed opacity-50" aria-disabled="true" aria-label="{{ __('Next page') }}"><x-icon name="chevron-right" class="h-4 w-4" /></span>
            @endif
        </div>
    </nav>
@endif
