@extends('layouts.app')
@php($isPage = ($contentType ?? 'post') === 'page')
@section('title', $isPage ? '固定ページ' : '記事')
@section('content')
<div class="page-head"><div><p class="eyebrow">CONTENT</p><h1>{{ $isPage ? '固定ページ' : '記事' }}</h1></div><a class="button primary" href="{{ route($isPage ? 'admin.pages.create' : 'admin.posts.create') }}">新規作成</a></div>
<form class="filter" method="get"><label>状態<select name="status" onchange="this.form.submit()"><option value="">すべて</option>@foreach(['draft'=>'下書き','scheduled'=>'予約','published'=>'公開','archived'=>'保管'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></label></form>
<div class="table-wrap"><table><thead><tr><th>タイトル</th><th>状態</th>@unless($isPage)<th>カテゴリー</th>@endunless<th>更新日時</th></tr></thead><tbody>
@forelse($posts as $post)<tr><td><a href="{{ route($isPage ? 'admin.pages.edit' : 'admin.posts.edit', $post) }}"><strong>{{ $post->title }}</strong><small>/{{ $post->slug }}</small></a></td><td><span class="status {{ $post->status }}">{{ $post->status }}</span></td>@unless($isPage)<td>{{ $post->category?->name ?? '—' }}</td>@endunless<td>{{ $post->updated_at->format('Y-m-d H:i') }}</td></tr>
@empty<tr><td colspan="{{ $isPage ? 3 : 4 }}" class="empty">{{ $isPage ? '固定ページ' : '記事' }}はまだありません。</td></tr>@endforelse
</tbody></table></div>{{ $posts->links() }}
@endsection
