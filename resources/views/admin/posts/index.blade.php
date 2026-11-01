@extends('layouts.app')
@php($isPage = ($contentType ?? 'post') === 'page')
@section('title', $isPage ? '固定ページ' : '記事')
@section('content')
<div class="page-head"><div><p class="eyebrow">CONTENT</p><h1>{{ $isPage ? '固定ページ' : '記事' }}</h1></div><a class="button primary" href="{{ route($isPage ? 'admin.pages.create' : 'admin.posts.create') }}">新規作成</a></div>
<form class="content-filters" method="get" action="{{ route($isPage ? 'admin.pages.index' : 'admin.posts.index') }}">
    <label class="filter-title">タイトル検索<input type="search" name="title" value="{{ request('title') }}" maxlength="200" placeholder="タイトルを入力"></label>
    <label>カテゴリー<select name="category"><option value="">すべて</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) request('category') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
    <label>状態<select name="status"><option value="">すべて</option>@foreach(['draft'=>'下書き','scheduled'=>'予約','published'=>'公開','archived'=>'保管'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
    <div class="filter-actions"><button class="button primary" type="submit">検索</button>@if(request()->hasAny(['title', 'category', 'status']))<a class="button secondary" href="{{ route($isPage ? 'admin.pages.index' : 'admin.posts.index') }}">クリア</a>@endif</div>
</form>
<form method="post" action="{{ route($isPage ? 'admin.pages.export-markdown' : 'admin.posts.export-markdown') }}" data-bulk-markdown-form>
    @csrf
    <div class="bulk-actions">
        <label class="bulk-select-all"><input type="checkbox" data-bulk-select-all @disabled($posts->isEmpty())><span>このページをすべて選択</span></label>
        <div class="bulk-actions-submit"><span data-bulk-selected-count aria-live="polite">0件選択</span><button class="button secondary" type="submit" data-bulk-markdown-submit disabled>{{ $isPage ? '選択ページ' : '選択記事' }}をMD出力</button></div>
    </div>
    <div class="table-wrap"><table><thead><tr><th class="row-selector">選択</th><th>タイトル</th><th>状態</th><th>カテゴリー</th><th>更新日時</th></tr></thead><tbody>
    @forelse($posts as $post)<tr><td class="row-selector"><input type="checkbox" name="post_ids[]" value="{{ $post->id }}" data-bulk-markdown-item aria-label="{{ $post->title }}を選択"></td><td><a href="{{ route($isPage ? 'admin.pages.edit' : 'admin.posts.edit', $post) }}"><strong>{{ $post->title }}</strong><small>/{{ $post->slug }}</small></a></td><td><span class="status {{ $post->status }}">{{ $post->status }}</span></td><td>{{ $post->category?->name ?? '—' }}</td><td>{{ $post->updated_at->format('Y-m-d H:i') }}</td></tr>
    @empty<tr><td colspan="5" class="empty">条件に一致する{{ $isPage ? '固定ページ' : '記事' }}はありません。</td></tr>@endforelse
    </tbody></table></div>
</form>
{{ $posts->links() }}
@endsection
