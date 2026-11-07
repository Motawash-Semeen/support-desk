@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between gap-2">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary btn-sm cursor-not-allowed opacity-50" aria-disabled="true"><x-icon name="chevron-left" class="h-4 w-4" /> {{ __('Previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-secondary btn-sm"><x-icon name="chevron-left" class="h-4 w-4" /> {{ __('Previous') }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-secondary btn-sm">{{ __('Next') }} <x-icon name="chevron-right" class="h-4 w-4" /></a>
        @else
            <span class="btn btn-secondary btn-sm cursor-not-allowed opacity-50" aria-disabled="true">{{ __('Next') }} <x-icon name="chevron-right" class="h-4 w-4" /></span>
        @endif
    </nav>
@endif
