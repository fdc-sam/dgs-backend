@props(['paginator'])
@if ($paginator->hasPages())
    <nav class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm max-md:border-t max-md:border-outline-variant" aria-label="Pagination">
        <p class="text-on-surface-variant">
            Showing <span class="font-medium text-on-surface">{{ $paginator->firstItem() }}</span>–<span class="font-medium text-on-surface">{{ $paginator->lastItem() }}</span>
            of <span class="font-medium text-on-surface">{{ number_format($paginator->total()) }}</span>
        </p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full text-outline" aria-disabled="true"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex h-10 w-10 items-center justify-center rounded-full hover:bg-surface-container-high" aria-label="Previous page"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></a>
            @endif
            @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
                @if ($page === $paginator->currentPage())
                    <span class="inline-flex h-10 min-w-10 items-center justify-center rounded-full bg-primary px-3 font-semibold text-on-primary" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" class="inline-flex h-10 min-w-10 items-center justify-center rounded-full px-3 hover:bg-surface-container-high">{{ $page }}</a>
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex h-10 w-10 items-center justify-center rounded-full hover:bg-surface-container-high" aria-label="Next page"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></a>
            @else
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full text-outline" aria-disabled="true"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></span>
            @endif
        </div>
    </nav>
@endif
