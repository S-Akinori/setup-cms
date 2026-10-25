@extends('layouts.app')
@php($isPage = ($contentType ?? 'post') === 'page')
@section('title', $isPage ? '固定ページを編集' : '記事を編集')
@section('content')
<div class="page-head"><div><p class="eyebrow">EDIT CONTENT</p><h1>{{ $post->title }}</h1></div></div>
<form method="post" action="{{ route($isPage ? 'admin.pages.update' : 'admin.posts.update', $post) }}" data-unsaved-changes-form>@csrf @method('PUT') @include('admin.posts.form')</form>
@if($post->revisions->isNotEmpty())<section class="panel revisions"><h2>変更履歴</h2>@foreach($post->revisions as $revision)<div><span>Version {{ $revision->version }} · {{ $revision->created_at->format('Y-m-d H:i') }}</span><form method="post" action="{{ route($isPage ? 'admin.pages.revisions.restore' : 'admin.posts.revisions.restore', [$post, $revision]) }}" onsubmit="return confirm('この版を復元しますか？')">@csrf<button class="link-button">復元</button></form></div>@endforeach</section>@endif
<form method="post" action="{{ route($isPage ? 'admin.pages.destroy' : 'admin.posts.destroy', $post) }}" onsubmit="return confirm('{{ $isPage ? 'この固定ページ' : 'この記事' }}をゴミ箱へ移動しますか？')">@csrf @method('DELETE')<button class="button danger">ゴミ箱へ移動</button></form>
@endsection
