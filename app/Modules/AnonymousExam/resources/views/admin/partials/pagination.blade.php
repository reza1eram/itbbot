@if ($paginator->hasPages())
<nav class="pagination">
    @if ($paginator->onFirstPage())<span class="muted">قبلی</span>@else<a href="{{ $paginator->previousPageUrl() }}">قبلی</a>@endif
    <span>{{ \App\Modules\AnonymousExam\Support\Display::digits($paginator->currentPage()) }} / {{ \App\Modules\AnonymousExam\Support\Display::digits($paginator->lastPage()) }}</span>
    @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">بعدی</a>@else<span class="muted">بعدی</span>@endif
</nav>
@endif
