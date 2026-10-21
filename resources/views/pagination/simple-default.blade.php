@if ($paginator->hasPages())
    <nav class="pagination pagination-simple" role="navigation" aria-label="ページネーション">
        @if ($paginator->onFirstPage())
            <span class="pagination-link is-disabled" aria-disabled="true">前へ</span>
        @else
            <a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">前へ</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">次へ</a>
        @else
            <span class="pagination-link is-disabled" aria-disabled="true">次へ</span>
        @endif
    </nav>
@endif
