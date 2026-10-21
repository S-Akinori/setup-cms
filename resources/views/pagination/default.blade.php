@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="ページネーション">
        <p class="pagination-summary">全{{ number_format($paginator->total()) }}件中 {{ number_format($paginator->firstItem()) }}〜{{ number_format($paginator->lastItem()) }}件を表示</p>
        <ul class="pagination-list">
            @if ($paginator->onFirstPage())
                <li><span class="pagination-link pagination-arrow is-disabled" aria-disabled="true" aria-label="前のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 15-5-5 5-5"/></svg></span></li>
            @else
                <li><a class="pagination-link pagination-arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="前のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 15-5-5 5-5"/></svg></a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pagination-ellipsis" aria-hidden="true">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span class="pagination-link is-current" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a class="pagination-link" href="{{ $url }}" aria-label="{{ $page }}ページへ移動">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="pagination-link pagination-arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="次のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg></a></li>
            @else
                <li><span class="pagination-link pagination-arrow is-disabled" aria-disabled="true" aria-label="次のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg></span></li>
            @endif
        </ul>
        <div class="pagination-mobile">
            @if ($paginator->onFirstPage())
                <span class="pagination-link pagination-arrow is-disabled" aria-disabled="true" aria-label="前のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 15-5-5 5-5"/></svg></span>
            @else
                <a class="pagination-link pagination-arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="前のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 15-5-5 5-5"/></svg></a>
            @endif
            <span class="pagination-mobile-current">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a class="pagination-link pagination-arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="次のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg></a>
            @else
                <span class="pagination-link pagination-arrow is-disabled" aria-disabled="true" aria-label="次のページ"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg></span>
            @endif
        </div>
    </nav>
@endif
